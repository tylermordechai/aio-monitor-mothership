<?php

/**
 * Where an ingest request's time goes, without HTTP or PHP-FPM (MS-ING-10).
 *
 *   php tools/bench-ingest.php [iterations]       # as aioadmin; uses the aio_test database
 *
 * Runs IngestService directly against aio_test (rebuilt from the migrations,
 * like the integration tests) with two batch shapes: a start-up inventory
 * (about 215 lines over 15 tables) and a 10 s window of flows (about 25 lines).
 * Prints each phase in ms (IngestService::$timings): read, setup, lines (PHP),
 * db (row statements), finish, commit. The first iteration is cold.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Auth\ClientCertAuth;
use Aio\Config;
use Aio\Http\Request;
use Aio\Ingest\IngestService;
use Aio\Sim\RecordFactory;
use Aio\Tests\Integration\TestDb;

$n = max(1, (int) ($argv[1] ?? 10));
$tc = new class ('bench') extends \PHPUnit\Framework\TestCase {
};
$pdo = TestDb::pdo($tc);
$a = TestDb::agent($pdo, 'BENCH-01');
$f = new RecordFactory($a['agent_id'], $a['host_guid'], $a['hostname']);
$svc = new IngestService($pdo, Config::fromArray([]));
$auth = new ClientCertAuth($pdo);

$run = static function (array $records) use ($a, $svc, $auth): array {
    $gz = gzencode(RecordFactory::jsonl($records));
    $req = Request::forTest('POST', '/api/v1/ingest', [
        'Content-Type' => 'application/x-ndjson', 'Content-Encoding' => 'gzip',
        'Content-Length' => (string) strlen($gz), 'User-Agent' => 'aio-monitor-agent/bench',
    ], $gz, $a['cert']);
    $t = microtime(true);
    $agent = $auth->authenticate($req);
    $authMs = round((microtime(true) - $t) * 1000, 1);
    $r = $svc->handle($req, $agent);
    return ['n' => $r->body['lines'], 'auth' => $authMs] + $svc->timings;
};

$shapes = [
    'inventory' => static fn (float $ts): array => $f->inventory($ts),
    'flows' => static fn (float $ts): array => array_merge($f->flows($ts, 20), $f->processes($ts, 2)),
];
foreach ($shapes as $name => $make) {
    echo "== $name batches\n";
    $all = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $run($make(microtime(true) - 5));
        $all[] = $t;
        echo ($i === 0 ? 'cold ' : '     ') . json_encode($t), "\n";
    }
    $warm = array_slice($all, 1) ?: $all;
    $med = [];
    foreach (array_keys($warm[0]) as $k) {
        $v = array_column($warm, $k);
        sort($v);
        $med[$k] = $v[intdiv(count($v), 2)];
    }
    echo 'median (warm) ' . json_encode($med) . "\n";
}
$v = $pdo->query("SELECT @@innodb_flush_log_at_trx_commit AS flush, @@innodb_buffer_pool_size DIV 1048576 AS pool_mb, @@sync_binlog AS sync_binlog, @@log_bin AS log_bin")->fetch();
echo 'server: ' . json_encode($v) . "\n";
