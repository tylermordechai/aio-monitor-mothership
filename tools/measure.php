<?php

/**
 * Load-test figures for milestone 2 (MS-ING-10, MS-DAT-06; CLAUDE.md "Load tests").
 *
 *   sudo php tools/measure.php latency [--since="2026-11-20 10:00:00"] [--until=...]
 *       Server side: p50/p95/p99 ingest time from ingest_batches.duration_ms,
 *       requests and rows per second; and the same from Apache's access log
 *       (%D, the whole request as Apache saw it). Default window: the last
 *       simulator run's start and end.
 *
 *   sudo php tools/measure.php size [--hosts=N] [--days=D]
 *       Database size per host per day: table sizes (data + indexes, after
 *       ANALYZE TABLE) divided by hosts and days. Defaults: the simulated
 *       hosts with data, and the span of their net_flow timestamps.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Ingest\Schema\TableMap;

const SIM_DIR = '/var/lib/aio-mothership/sim';

$cmd = $argv[1] ?? '';
$o = getopt('', ['since:', 'until:', 'hosts:', 'days:']);
$config = Config::load();
$db = $config->string('db', 'name');
$pdo = Db::connect($config->string('db', 'maint_cnf'), $db);

if ($cmd === 'latency') {
    [$since, $until] = window($o);
    $s = $pdo->prepare('SELECT duration_ms, stored_count, duplicate_count, quarantined_count, http_status FROM ingest_batches WHERE received_at BETWEEN ? AND ?');
    $s->execute([$since, $until]);
    $d = [];
    $rows = 0;
    $byStatus = [];
    foreach ($s as $r) {
        $byStatus[$r['http_status']] = ($byStatus[$r['http_status']] ?? 0) + 1;
        if ((int) $r['http_status'] === 200) {
            $d[] = (float) $r['duration_ms'];
            $rows += (int) $r['stored_count'];
        }
    }
    $secs = max(1, strtotime($until . ' UTC') - strtotime($since . ' UTC'));
    echo "window: $since .. $until UTC ({$secs} s)\n";
    echo "ingest_batches (PHP, from start of the request to COMMIT):\n";
    printf("  batches %d (by status %s), %.1f per s, rows stored %d, %.0f rows per s\n", count($d), json_encode($byStatus), count($d) / $secs, $rows, $rows / $secs);
    printf("  p50 %s ms, p95 %s ms, p99 %s ms, max %s ms\n", pct($d, 50), pct($d, 95), pct($d, 99), $d === [] ? '-' : max($d));
    $log = '/var/log/apache2/aio-agent-api.access.log';
    $a = apacheTimes($log, strtotime($since . ' UTC'), strtotime($until . ' UTC'));
    echo "Apache access log (%D, whole request incl. TLS and upload):\n";
    printf("  ingest requests %d: p50 %s ms, p95 %s ms, p99 %s ms\n", count($a), pct($a, 50), pct($a, 95), pct($a, 99));
    exit(0);
}

if ($cmd === 'size') {
    $sim = $pdo->query("SELECT a.id FROM agents a JOIN hosts h ON h.id = a.host_pk JOIN host_groups g ON g.id = h.group_id WHERE g.name = 'simulator'")->fetchAll(PDO::FETCH_COLUMN);
    $in = $sim === [] ? '0' : implode(',', array_map('intval', $sim));
    $hosts = (int) ($o['hosts'] ?? $pdo->query("SELECT COUNT(DISTINCT agent_pk) FROM net_flows WHERE agent_pk IN ($in)")->fetchColumn());
    $span = $pdo->query("SELECT TIMESTAMPDIFF(SECOND, MIN(ts), MAX(ts)) FROM net_flows WHERE agent_pk IN ($in)")->fetchColumn();
    $days = (float) ($o['days'] ?? max(1 / 24, ((int) $span) / 86_400));
    if ($hosts < 1) {
        fwrite(STDERR, "no simulated data; run the simulator first\n");
        exit(2);
    }
    $total = 0;
    $lines = [];
    foreach (TableMap::tables() as $t) {
        $pdo->query("ANALYZE TABLE `$t`")->fetchAll();
    }
    $s = $pdo->prepare('SELECT TABLE_NAME, DATA_LENGTH + INDEX_LENGTH AS bytes, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY bytes DESC');
    $s->execute([$db]);
    foreach ($s as $r) {
        $total += (int) $r['bytes'];
        if ((int) $r['bytes'] > 1_048_576) {
            $lines[] = sprintf('  %-24s %9.1f MB  %12d rows  %8.2f MB/host/day', $r['TABLE_NAME'], $r['bytes'] / 1_048_576, $r['TABLE_ROWS'], $r['bytes'] / 1_048_576 / $hosts / $days);
        }
    }
    printf("hosts %d, days %.2f (from the net_flow timestamps unless given)\n", $hosts, $days);
    echo implode("\n", $lines), "\n";
    $per = $total / 1_048_576 / $hosts / $days;
    printf("total %.1f MB: %.1f MB per host per day (MS-DAT-06)\n", $total / 1_048_576, $per);
    printf("at 500 hosts: %.1f GB per day; 90 days of detail ~ %.0f GB (before summaries, without the 2-year tables)\n", $per * 500 / 1024, $per * 500 * 90 / 1024);

    // Servers and laptops apart (M-050): each table's size shared out by the
    // rows each simulated host has in it; the kind comes from host_facts.
    $kinds = [];
    foreach ($pdo->query("SELECT a.id, MAX(f.os_product_name LIKE 'Windows Server%') AS srv FROM agents a JOIN host_facts f ON f.host_pk = a.host_pk WHERE a.id IN ($in) GROUP BY a.id") as $r) {
        $kinds[(int) $r['id']] = (int) $r['srv'] === 1 ? 'server' : 'laptop';
    }
    $hostOf = $pdo->query("SELECT host_pk, id FROM agents WHERE id IN ($in)")->fetchAll(PDO::FETCH_KEY_PAIR);
    $sizes = [];
    $s->execute([$db]);
    foreach ($s as $r) {
        $sizes[$r['TABLE_NAME']] = (int) $r['bytes'];
    }
    $byKind = ['server' => 0.0, 'laptop' => 0.0];
    foreach (TableMap::tables() as $t) {
        $cols = TableMap::columns($t);
        $col = isset($cols['agent_pk']) ? 'agent_pk' : (isset($cols['host_pk']) ? 'host_pk' : null);
        if ($col === null || ($sizes[$t] ?? 0) === 0) {
            continue;
        }
        $counts = $pdo->query("SELECT `$col`, COUNT(*) FROM `$t` GROUP BY `$col`")->fetchAll(PDO::FETCH_KEY_PAIR);
        $all = array_sum($counts);
        foreach ($counts as $k => $c) {
            $agent = $col === 'agent_pk' ? (int) $k : (int) ($hostOf[$k] ?? 0);
            if ($all > 0 && isset($kinds[$agent])) {
                $byKind[$kinds[$agent]] += $sizes[$t] * $c / $all;
            }
        }
    }
    $n = array_count_values($kinds);
    foreach ($byKind as $kind => $bytes) {
        if (($n[$kind] ?? 0) > 0) {
            printf("%s: %d hosts, %.1f MB per host per day (table sizes shared out by rows)\n", $kind, $n[$kind], $bytes / 1_048_576 / $n[$kind] / $days);
        }
    }
    echo "note: includes every table in the database, sizes are InnoDB page counts (estimates)\n";
    exit(0);
}

fwrite(STDERR, "usage: sudo php tools/measure.php latency [--since=..] [--until=..] | size [--hosts=N] [--days=D]\n");
exit(2);

/** @return array{0: string, 1: string} */
function window(array $o): array
{
    if (isset($o['since'])) {
        return [(string) $o['since'], (string) ($o['until'] ?? gmdate('Y-m-d H:i:s'))];
    }
    $runs = glob(SIM_DIR . '/runs/*/summary.json') ?: [];
    sort($runs);
    $last = $runs === [] ? null : json_decode((string) file_get_contents(end($runs)), true);
    if ($last === null) {
        return [gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s')];
    }
    return [gmdate('Y-m-d H:i:s', strtotime($last['started'])), gmdate('Y-m-d H:i:s', strtotime($last['ended']))];
}

/** @return list<float> ms */
function apacheTimes(string $log, int $from, int $to): array
{
    $out = [];
    $h = @fopen($log, 'r');
    if ($h === false) {
        return $out;
    }
    // aio_tls: %a %t "%r" %>s %b %D ...
    while (($line = fgets($h)) !== false) {
        if (!preg_match('#\[([^\]]+)\] "POST /api/v1/ingest [^"]*" (\d{3}) \S+ (\d+)#', $line, $m)) {
            continue;
        }
        $t = strtotime($m[1]);
        if ($t !== false && $t >= $from && $t <= $to && $m[2] === '200') {
            $out[] = (int) $m[3] / 1000;
        }
    }
    fclose($h);
    return $out;
}

/** @param list<float> $v */
function pct(array $v, int $p): string
{
    if ($v === []) {
        return '-';
    }
    sort($v);
    return (string) round($v[(int) min(count($v) - 1, max(0, ceil($p / 100 * count($v)) - 1))], 1);
}
