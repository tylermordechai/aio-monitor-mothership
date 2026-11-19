<?php

/**
 * Agent simulator (CLAUDE.md "Build and test"; milestone 2 gate, M-043, M-049).
 *
 * Sends realistic batches and heartbeats for the agents made by
 * tools/dev-agents.php, over mTLS to 8443, behaving like agent 5c
 * (ingest-api.md §8.1). Root only (it reads the test keys).
 *
 *   sudo php tools/simulator/simulate.php --agents=10 --duration=120
 *   sudo php tools/simulator/simulate.php --agents=500 --duration=3600 --faults   # the gate run
 *   sudo php tools/simulator/simulate.php --agents=20 --day                       # 24 h of data, fast (MS-DAT-06)
 *
 * Options:
 *   --agents=N        how many of the created agents to run (default: all)
 *   --duration=S      live mode: seconds of sending, then drain (default 60)
 *   --interval=S      batch interval (default 10, AG-DAT-03)
 *   --heartbeat=S     heartbeat interval (default 60, M-030)
 *   --faults          now and then: resend a batch, add a bad or unknown line
 *   --day             make 24 hours of records per agent (timestamps in the past) and send them back to back
 *   --concurrency=N   requests in flight at most (default 64)
 *   --profile=JSON    override Aio\Sim\Profile values, e.g. '{"flows_per_window":40}'
 *
 * Writes /var/lib/aio-mothership/sim/runs/<time>/summary.json and keeps each
 * agent's seq and expected row counts in sim/state/, for tools/reconcile.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Aio\Sim\Profile;
use Aio\Sim\SimAgent;
use Aio\Util\Uuid;

const SIM_DIR = '/var/lib/aio-mothership/sim';

$o = getopt('', ['agents:', 'duration:', 'interval:', 'heartbeat:', 'faults', 'day', 'concurrency:', 'profile:', 'url:', 'ca:']);
$ip = trim((string) shell_exec('. ' . escapeshellarg(dirname(__DIR__, 2) . '/setup/env.sh') . ' && echo $AIO_IP'));
$url = (string) ($o['url'] ?? "https://$ip:8443");
$ca = (string) ($o['ca'] ?? '/etc/aio-mothership/pki/public/agent-ca.crt');
$interval = (int) ($o['interval'] ?? 10);
$hbEvery = (int) ($o['heartbeat'] ?? 60);
$duration = (int) ($o['duration'] ?? 60);
$faults = isset($o['faults']);
$dayMode = isset($o['day']);
$maxConc = (int) ($o['concurrency'] ?? 64);
$profile = Profile::with(isset($o['profile']) ? (array) json_decode((string) $o['profile'], true) : []);

$ids = json_decode((string) @file_get_contents(SIM_DIR . '/agents.json'), true);
if (!is_array($ids) || $ids === []) {
    fwrite(STDERR, "no simulated agents; run: sudo php tools/dev-agents.php create N\n");
    exit(2);
}
$ids = array_slice($ids, 0, (int) ($o['agents'] ?? count($ids)));
@mkdir(SIM_DIR . '/state', 0700, true);
$runDir = SIM_DIR . '/runs/' . gmdate('Ymd\THis\Z');
mkdir($runDir, 0700, true);

$t0 = microtime(true);
$simStart = $dayMode ? $t0 - 86_400 : $t0;
/** @var array<string, SimAgent> $agents */
$agents = [];
foreach ($ids as $id) {
    $statePath = SIM_DIR . "/state/{$id['agent_id']}.json";
    $state = is_file($statePath) ? (array) json_decode((string) file_get_contents($statePath), true) : [];
    $agents[$id['agent_id']] = new SimAgent($id, $profile, $state, $simStart, $interval, $hbEvery, $faults);
}
$saveStates = static function () use (&$agents): void {
    foreach ($agents as $a) {
        file_put_contents(SIM_DIR . "/state/{$a->id['agent_id']}.json", json_encode($a->summary()));
    }
};

$mh = curl_multi_init();
curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, max(64, $maxConc * 2));
/** @var array<int, array{agent: string, kind: string, start: float, h: \CurlHandle}> $running */
$running = [];
$stats = ['requests' => 0, 'by_status' => [], 'lat_ingest' => [], 'lat_heartbeat' => [], 'problems' => [], 'bytes' => 0, 'lines' => 0];
$hbInFlight = [];
$ingestInFlight = [];

$start = static function (SimAgent $a, string $kind, string $body, string $ctype) use ($mh, $url, $ca, &$running): void {
    $h = curl_init($url . ($kind === 'ingest' ? '/api/v1/ingest' : '/api/v1/heartbeat'));
    $headers = ["Content-Type: $ctype", 'User-Agent: aio-monitor-agent/0.5.0-sim', 'X-AIO-Batch-Id: ' . Uuid::v4(), 'Expect:'];
    if ($kind === 'ingest') {
        $headers[] = 'Content-Encoding: gzip';
    }
    curl_setopt_array($h, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSLCERT => $a->id['cert'],
        CURLOPT_SSLKEY => $a->id['key'],
        CURLOPT_CAINFO => $ca,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    curl_multi_add_handle($mh, $h);
    $running[spl_object_id($h)] = ['agent' => $a->id['agent_id'], 'kind' => $kind, 'start' => microtime(true), 'h' => $h];
};

$end = $t0 + $duration;
$draining = false;
$drainDeadline = null;
$nextReport = $t0 + 10;
$nextSave = $t0 + 60;
$dayCursor = $simStart;
fprintf(STDOUT, "%s: %d agents, %s, faults %s, target %s\n", gmdate('H:i:s'), count($agents), $dayMode ? '24 h of data back to back' : "live for {$duration}s", $faults ? 'on' : 'off', $url);

while (true) {
    $now = microtime(true);
    if ($dayMode) {
        // Move simulated time on, one 10 s window at a time, while the queues are short.
        $backlog = 0;
        foreach ($agents as $a) {
            $backlog += $a->queued();
        }
        while ($dayCursor < $t0 && $backlog < 200_000) {
            $dayCursor = min($t0, $dayCursor + 10);
            foreach ($agents as $a) {
                $a->generate($dayCursor);
                $backlog += 0;
            }
            $backlog = array_sum(array_map(static fn (SimAgent $a) => $a->queued(), $agents));
        }
        if ($dayCursor >= $t0 && !$draining) {
            $draining = true;
            $drainDeadline = $now + 3600;
        }
    } elseif (!$draining && $now >= $end) {
        $draining = true;
        $drainDeadline = $now + 120;
    }

    foreach ($agents as $aid => $a) {
        if (count($running) >= $maxConc) {
            break;
        }
        if (!isset($ingestInFlight[$aid]) && $now >= $a->nextSend) {
            if (!$dayMode && !$draining) {
                $a->generate($now);
            }
            $body = $a->takeBatch();
            if ($body === null) {
                $a->scheduleNext($now);
            } else {
                $ingestInFlight[$aid] = true;
                $stats['bytes'] += strlen($body);
                $start($a, 'ingest', $body, 'application/x-ndjson');
            }
        }
        if (!$dayMode && !$draining && !isset($hbInFlight[$aid]) && $now >= $a->nextHeartbeat && count($running) < $maxConc) {
            $hbInFlight[$aid] = true;
            $a->nextHeartbeat = $now + $hbEvery;
            $start($a, 'heartbeat', json_encode($a->heartbeatBody($now)), 'application/json');
        }
    }

    do {
        $st = curl_multi_exec($mh, $active);
    } while ($st === CURLM_CALL_MULTI_PERFORM);
    while ($info = curl_multi_info_read($mh)) {
        $h = $info['handle'];
        $r = $running[spl_object_id($h)];
        unset($running[spl_object_id($h)]);
        $a = $agents[$r['agent']];
        $done = microtime(true);
        $ms = ($done - $r['start']) * 1000;
        $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
        $raw = (string) curl_multi_getcontent($h);
        $hdrSize = (int) curl_getinfo($h, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $hdrSize);
        $resp = json_decode(substr($raw, $hdrSize), true);
        $retryAfter = preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m) ? (int) $m[1] : null;
        $key = $info['result'] !== CURLE_OK ? 'curl_' . $info['result'] : (string) $status;
        $stats['requests']++;
        $stats['by_status'][$r['kind'] . ' ' . $key] = ($stats['by_status'][$r['kind'] . ' ' . $key] ?? 0) + 1;
        curl_multi_remove_handle($mh, $h);
        if ($r['kind'] === 'heartbeat') {
            unset($hbInFlight[$r['agent']]);
            $stats['lat_heartbeat'][] = $ms;
            continue;
        }
        unset($ingestInFlight[$r['agent']]);
        if ($status === 200 && is_array($resp)) {
            $stats['lat_ingest'][] = $ms;
            $stats['lines'] += (int) ($resp['lines'] ?? 0);
            foreach ($a->acked($resp) as $p) {
                if (count($stats['problems']) < 200) {
                    $stats['problems'][] = $a->id['hostname'] . ': ' . $p;
                }
            }
            $a->scheduleNext($done);
            if ($dayMode || $draining) {
                $a->nextSend = $done;
            }
        } else {
            $a->failed($done, $retryAfter, $status === 413);
            if (!in_array($status, [429, 503], true) && count($stats['problems']) < 200) {
                $stats['problems'][] = $a->id['hostname'] . ": HTTP $key " . substr(substr($raw, $hdrSize), 0, 200);
            }
        }
    }

    if ($now >= $nextReport) {
        $lat = $stats['lat_ingest'];
        $queued = array_sum(array_map(static fn (SimAgent $x) => $x->queued(), $agents));
        fprintf(STDOUT, "%s: %d requests, %d lines, in flight %d, queued %d, ingest p95 %s ms%s\n",
            gmdate('H:i:s'), $stats['requests'], $stats['lines'], count($running), $queued, pct($lat, 95),
            $dayMode ? ', simulated ' . gmdate('Y-m-d H:i', (int) $dayCursor) : '');
        $nextReport = $now + 10;
    }
    if ($now >= $nextSave) {
        $saveStates();
        $nextSave = $now + 60;
    }
    if ($draining) {
        $left = count($running) + array_sum(array_map(static fn (SimAgent $x) => $x->queued() + count($x->inFlight), $agents));
        if ($left === 0 || $now >= $drainDeadline) {
            break;
        }
    }
    if ($active > 0) {
        curl_multi_select($mh, 0.05);
    } else {
        usleep(20_000);
    }
}
$saveStates();

$t1 = microtime(true);
$summary = [
    'mode' => $dayMode ? 'day' : 'live',
    'started' => gmdate('c', (int) $t0),
    'ended' => gmdate('c', (int) $t1),
    'seconds' => round($t1 - $t0, 1),
    'agents' => count($agents),
    'faults' => $faults,
    'profile' => $profile,
    'requests' => $stats['requests'],
    'requests_per_s' => round($stats['requests'] / max(1, $t1 - $t0), 1),
    'lines_acked' => $stats['lines'],
    'lines_per_s' => round($stats['lines'] / max(1, $t1 - $t0), 1),
    'compressed_mb_sent' => round($stats['bytes'] / 1_048_576, 1),
    'by_status' => $stats['by_status'],
    'ingest_ms' => ['p50' => pct($stats['lat_ingest'], 50), 'p95' => pct($stats['lat_ingest'], 95), 'p99' => pct($stats['lat_ingest'], 99), 'max' => $stats['lat_ingest'] === [] ? null : round(max($stats['lat_ingest']), 1)],
    'heartbeat_ms' => ['p50' => pct($stats['lat_heartbeat'], 50), 'p95' => pct($stats['lat_heartbeat'], 95)],
    'unsent_lines' => array_sum(array_map(static fn (SimAgent $x) => $x->queued() + count($x->inFlight), $agents)),
    'response_mismatches' => array_sum(array_map(static fn (SimAgent $x) => $x->mismatches, $agents)),
    'problems' => $stats['problems'],
];
file_put_contents("$runDir/summary.json", json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
unset($summary['problems']);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
if ($stats['problems'] !== []) {
    echo count($stats['problems']) . " problems; the first few:\n  " . implode("\n  ", array_slice($stats['problems'], 0, 5)) . "\n";
}
echo "summary: $runDir/summary.json\nnext: sudo php tools/reconcile.php\n";

/** @param list<float> $v */
function pct(array $v, int $p): ?float
{
    if ($v === []) {
        return null;
    }
    sort($v);
    return round($v[(int) min(count($v) - 1, max(0, ceil($p / 100 * count($v)) - 1))], 1);
}
