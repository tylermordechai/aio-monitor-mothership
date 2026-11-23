<?php

/**
 * Dashboard page times for the milestone 5 gate (MS-UI-08, M-050): every page
 * under 5 s with 2 simulated servers and 2 laptops and 7 days of data.
 *
 *   sudo php tools/page-times.php <user name> [--runs=3] [--password-stdin]
 *
 * Signs in over HTTPS like a browser (the password is asked at a hidden
 * prompt; never on the command line, never printed or stored), then loads,
 * each --runs times:
 *   - the home page and /api/map, for each range (1 h, 24 h, 7 days);
 *   - for every host in the fleet table: the time-based tabs (connections,
 *     processes, inventory) for each range, and the snapshot tabs (listening
 *     ports, executables, server software) once.
 * It reports, per page, the slowest whole request as curl saw it (TLS,
 * Apache, PHP, database) and the server's own X-Render-Ms, then signs out.
 * The command-line reveal is not timed: each one writes an audit row.
 *
 * The sign-in and sign-out are audited as usual. Database figures (rows and
 * days of data) are read as aio_maint, hence sudo.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Dashboard\HostQuery;
use Aio\Dashboard\MapQuery;
use Aio\Db;

const GATE_MS = 5000;
const TIME_TABS = ['connections', 'processes', 'inventory'];

// Options may come before or after the user name (getopt stops at the first plain argument).
$args = [];
$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $mm)) {
        $o[$mm[1]] = $mm[2] ?? true;
    } else {
        $args[] = $a;
    }
}
$user = $args[0] ?? '';
if ($user === '') {
    fwrite(STDERR, "usage: sudo php tools/page-times.php <user name> [--runs=3] [--password-stdin]\n");
    exit(2);
}
$runs = max(1, (int) ($o['runs'] ?? 3));
$ip = trim((string) shell_exec('. ' . escapeshellarg(dirname(__DIR__) . '/setup/env.sh') . ' && echo $AIO_IP'));
$base = rtrim((string) ($o['url'] ?? "https://$ip"), '/');
$ca = (string) ($o['ca'] ?? '/etc/aio-mothership/pki/public/web-ca.crt');

if (isset($o['password-stdin'])) {
    $pw = rtrim((string) fgets(STDIN), "\r\n");
} else {
    $tty = @fopen('/dev/tty', 'r+');
    if ($tty === false) {
        fwrite(STDERR, "no terminal for the password prompt; use --password-stdin\n");
        exit(2);
    }
    fwrite($tty, "Dashboard password for $user: ");
    shell_exec('stty -echo < /dev/tty');
    $pw = rtrim((string) fgets($tty), "\r\n");
    shell_exec('stty echo < /dev/tty');
    fwrite($tty, "\n");
    fclose($tty);
}

// --- What the pages are timed against -------------------------------------------------
$config = Config::load();
$pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
$facts = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM hosts WHERE retired_at IS NULL) AS hosts,
            (SELECT COUNT(*) FROM net_flows) AS net_flows,
            (SELECT COUNT(*) FROM flow_conn_hourly) AS conn_hours,
            (SELECT COUNT(*) FROM process_events) AS process_events,
            (SELECT COUNT(*) FROM executables) AS executables,
            (SELECT MIN(ts) FROM net_flows) AS first_flow,
            (SELECT MAX(ts) FROM net_flows) AS last_flow"
)->fetch();
$pdo = null;
$days = $facts['first_flow'] === null ? 0 : (strtotime($facts['last_flow'] . ' UTC') - strtotime($facts['first_flow'] . ' UTC')) / 86400;
printf("Data: %d hosts, %.1f days of flows (%s to %s UTC)\n", $facts['hosts'], $days, substr((string) $facts['first_flow'], 0, 16), substr((string) $facts['last_flow'], 0, 16));
printf("      net_flows %s rows, flow_conn_hourly %s, process_events %s, executables %s\n\n",
    number_format((int) $facts['net_flows']), number_format((int) $facts['conn_hours']), number_format((int) $facts['process_events']), number_format((int) $facts['executables']));

// --- HTTP, one connection reused, cookies kept in memory ------------------------------
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_CAINFO => $ca,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEFILE => '',
    CURLOPT_TIMEOUT => 60,
    CURLOPT_USERAGENT => 'aio-page-times',
]);
$hdr = [];
curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($c, string $line) use (&$hdr): int {
    if (str_contains($line, ':')) {
        [$k, $v] = explode(':', $line, 2);
        $hdr[strtolower(trim($k))] = trim($v);
    }
    return strlen($line);
});
/** @return array{status: int, ms: float, render_ms: ?float, body: string, location: ?string} */
$req = static function (string $path, ?array $post = null) use ($ch, $base, &$hdr): array {
    $hdr = [];
    curl_setopt($ch, CURLOPT_URL, $base . $path);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Origin: ' . $base, 'Sec-Fetch-Site: same-origin']);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, []);
    }
    $body = curl_exec($ch);
    if ($body === false) {
        fwrite(STDERR, "request failed: $path: " . curl_error($ch) . "\n");
        exit(1);
    }
    return [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'ms' => curl_getinfo($ch, CURLINFO_TOTAL_TIME_T) / 1000,
        'render_ms' => isset($hdr['x-render-ms']) ? (float) $hdr['x-render-ms'] : null,
        'body' => (string) $body,
        'location' => $hdr['location'] ?? null,
    ];
};
$csrf = static function (string $html): string {
    return preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
};

// --- Sign in -------------------------------------------------------------------------------
$login = $req('/login');
$r = $req('/login', ['_csrf' => $csrf($login['body']), 'username' => $user, 'password' => $pw]);
$pw = '';
if ($r['status'] !== 303 || $r['location'] !== '/') {
    fwrite(STDERR, "sign-in failed (check the user name and password; 5 failures lock the account for 15 minutes)\n");
    exit(1);
}
$home = $req('/');
if ($home['status'] !== 200) {
    fwrite(STDERR, "signed in, but / answered {$home['status']}\n");
    exit(1);
}
preg_match_all('#href="/hosts/(\d+)"#', $home['body'], $m);
$hosts = array_values(array_unique(array_map('intval', $m[1])));
echo 'Signed in as ' . $user . '; ' . count($hosts) . " hosts in the fleet table; $runs runs per page\n\n";

// --- The pages -----------------------------------------------------------------------------
$pages = [];
foreach (array_keys(MapQuery::RANGES) as $range) {
    $pages[] = ['home', "/?range=$range"];
    $pages[] = ['map data', "/api/map?range=$range"];
}
foreach ($hosts as $h) {
    foreach (array_keys(HostQuery::TABS) as $tab) {
        foreach (in_array($tab, TIME_TABS, true) ? array_keys(MapQuery::RANGES) : ['24h'] as $range) {
            $pages[] = ["host: $tab", "/hosts/$h?tab=$tab&range=$range"];
        }
    }
}

$results = [];
$bad = [];
$t0 = microtime(true);
foreach ($pages as [$kind, $path]) {
    $worst = ['ms' => 0.0, 'render_ms' => 0.0];
    for ($i = 0; $i < $runs; $i++) {
        $r = $req($path);
        if ($r['status'] !== 200) {
            $bad[] = "$path answered {$r['status']}";
            continue;
        }
        $worst['ms'] = max($worst['ms'], $r['ms']);
        $worst['render_ms'] = max($worst['render_ms'], (float) $r['render_ms']);
        $worst['bytes'] = strlen($r['body']);
    }
    $results[] = ['kind' => $kind, 'path' => $path] + $worst;
}
$elapsed = microtime(true) - $t0;

// --- Sign out --------------------------------------------------------------------------------
$req('/logout', ['_csrf' => $csrf($req('/')['body'])]);
curl_close($ch);

// --- Report ----------------------------------------------------------------------------------
$byKind = [];
foreach ($results as $r) {
    $byKind[$r['kind']][] = $r;
}
printf("%-22s %5s %10s %10s %12s   %s\n", 'Page', 'URLs', 'p50 ms', 'max ms', 'server max', 'slowest');
foreach ($byKind as $kind => $list) {
    $ms = array_column($list, 'ms');
    sort($ms);
    usort($list, static fn ($a, $b) => $b['ms'] <=> $a['ms']);
    printf("%-22s %5d %10.0f %10.0f %12.0f   %s\n", $kind, count($list), $ms[intdiv(count($ms) - 1, 2)], $list[0]['ms'], max(array_column($list, 'render_ms')), $list[0]['path']);
}
usort($results, static fn ($a, $b) => $b['ms'] <=> $a['ms']);
$max = $results[0]['ms'] ?? 0.0;
printf("\n%d URLs x %d runs in %.0f s. Slowest page: %.0f ms (%s), server side %.0f ms.\n", count($results), $runs, $elapsed, $max, $results[0]['path'] ?? '-', $results[0]['render_ms'] ?? 0);
foreach ($bad as $b) {
    echo "NOT 200: $b\n";
}
$pass = $bad === [] && $max < GATE_MS && $results !== [];
printf("GATE (M-050, every page under %d ms): %s\n", GATE_MS, $pass ? 'PASS' : 'FAIL');
exit($pass ? 0 : 1);
