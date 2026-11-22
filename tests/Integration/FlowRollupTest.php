<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Auth\ClientCertAuth;
use Aio\Config;
use Aio\Http\Request;
use Aio\Ingest\IngestService;
use Aio\Jobs\FlowRollup;
use Aio\Sim\RecordFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/** MS-DAT-04 / M-054: flow_conn_hourly equals net_flows combined per D-016. */
final class FlowRollupTest extends TestCase
{
    public function testMsDat04HourlyRowsMatchTheFlowRecordsAndReRunsChangeNothing(): void
    {
        $pdo = TestDb::pdo($this);
        $a = TestDb::agent($pdo, 'SIM-ROLLUP');
        $f = new RecordFactory($a['agent_id'], $a['host_guid'], $a['hostname'], 1, '0.5.0', 7);
        $start = floor((time() - 7200) / 3600) * 3600 + 1800; // half past, two hours ago
        $records = [];
        for ($w = 0; $w < 360; $w++) {            // one hour of 10 s windows, across an hour boundary
            $records = array_merge($records, $f->flows($start + $w * 10, 12));
        }
        foreach (array_chunk($records, 900) as $chunk) {
            $this->ingest($pdo, $a, $chunk);
        }

        $job = new FlowRollup($pdo, -60); // no settle time in the test
        do {
            $r = $job->runOnce();
        } while ($r['batches'] > 0);

        $flows = $pdo->prepare('SELECT HEX(flow_key) k, COUNT(*) n, SUM(bytes_sent) bs, SUM(bytes_recv) br, MIN(first_seen) fs, MAX(last_seen) ls FROM net_flows WHERE host_pk = ? GROUP BY flow_key ORDER BY k');
        $flows->execute([$a['host_pk']]);
        $hourly = $pdo->prepare('SELECT HEX(flow_key) k, SUM(windows) n, SUM(bytes_sent) bs, SUM(bytes_recv) br, MIN(first_seen) fs, MAX(last_seen) ls FROM flow_conn_hourly WHERE host_pk = ? GROUP BY flow_key ORDER BY k');
        $hourly->execute([$a['host_pk']]);
        $want = $flows->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(12, $want, '12 connections (D-016 keys)');
        self::assertEquals($want, $hourly->fetchAll(PDO::FETCH_ASSOC));

        $hours = $pdo->prepare('SELECT COUNT(DISTINCT hour) FROM flow_conn_hourly WHERE host_pk = ?');
        $hours->execute([$a['host_pk']]);
        self::assertSame(2, (int) $hours->fetchColumn(), 'split at the hour boundary');

        $count = static function () use ($pdo, $a): int {
            $s = $pdo->prepare('SELECT COUNT(*) FROM flow_conn_hourly WHERE host_pk = ?');
            $s->execute([$a['host_pk']]);
            return (int) $s->fetchColumn();
        };
        $before = $count();
        self::assertSame(0, $job->runOnce()['batches'], 'nothing new');
        $job->rebuild($a['host_pk'], gmdate('Y-m-d H:00:00', (int) $start));
        self::assertSame($before, $count(), 'a rebuild gives the same rows');
    }

    /** @param list<array<string, mixed>> $records */
    private function ingest(PDO $pdo, array $a, array $records): void
    {
        $gz = gzencode(RecordFactory::jsonl($records));
        $req = Request::forTest('POST', '/api/v1/ingest', [
            'Content-Type' => 'application/x-ndjson', 'Content-Encoding' => 'gzip',
            'Content-Length' => (string) strlen($gz), 'User-Agent' => 'aio-monitor-agent/0.5.0',
        ], $gz, $a['cert']);
        $agent = (new ClientCertAuth($pdo))->authenticate($req);
        $resp = (new IngestService($pdo, Config::fromArray([])))->handle($req, $agent);
        self::assertSame(200, $resp->status);
    }
}
