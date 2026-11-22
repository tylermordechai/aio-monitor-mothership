<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Auth\ClientCertAuth;
use Aio\Config;
use Aio\Dashboard\MapQuery;
use Aio\Http\Request;
use Aio\Ingest\IngestService;
use Aio\Jobs\FlowRollup;
use Aio\Sim\Network;
use Aio\Sim\RecordFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/** MS-UI-01, MS-UI-07: the server map from real rows (M-054). */
final class MapQueryTest extends TestCase
{
    public function testMsUi01HostsLinkToEachOtherTheInternetAndGroupedInternalAddresses(): void
    {
        $pdo = TestDb::pdo($this);
        // Host numbers far from other tests' so the addresses are this test's own.
        $net = new Network([201], [202]);
        $srv = TestDb::agent($pdo, 'MAP-SRV');
        $lap = TestDb::agent($pdo, 'MAP-LAP');
        $fs = new RecordFactory($srv['agent_id'], $srv['host_guid'], $srv['hostname'], 1, '0.5.0', 201, $net, true);
        $fl = new RecordFactory($lap['agent_id'], $lap['host_guid'], $lap['hostname'], 1, '0.5.0', 202, $net, false);
        $t = time() - 600;
        $this->ingest($pdo, $srv, array_merge($fs->flows($t, 20), $fs->flows($t + 10, 20), [$fs->coverageGap($t + 20, $t - 30)]));
        $this->ingest($pdo, $lap, array_merge($fl->flows($t, 15), $fl->flows($t + 10, 15)));
        $job = new FlowRollup($pdo, -60);
        while ($job->runOnce()['batches'] > 0) {
        }

        $config = Config::fromArray(['dashboard' => ['map_internal_nodes' => 3]]);
        $map = (new MapQuery($pdo, $config))->build('1h', time());
        $ids = array_column($map['nodes'], 'id');
        $s = 'h' . $srv['host_pk'];
        $l = 'h' . $lap['host_pk'];
        self::assertContains($s, $ids);
        self::assertContains($l, $ids);
        self::assertContains('internet', $ids);
        self::assertContains('other-internal', $ids, 'more than 3 internal addresses: the rest are grouped');
        self::assertSame(3, count(array_filter($map['nodes'], static fn ($n) => $n['type'] === 'internal')));

        $edges = [];
        foreach ($map['edges'] as $e) {
            $edges[$e['source'] . '>' . $e['target']] = $e;
            self::assertContains($e['source'], $ids, 'every line starts at a node');
            self::assertContains($e['target'], $ids, 'every line ends at a node');
        }
        self::assertArrayHasKey("$l>$s", $edges, 'laptop opened connections to the server');
        self::assertArrayNotHasKey("$s>$l", $edges, 'the server only accepted them');
        $ports = $edges["$l>$s"]['ports'];
        self::assertContains('tcp/443', $ports);
        self::assertArrayHasKey("$s>internet", $edges);

        $srvNode = array_values(array_filter($map['nodes'], static fn ($n) => $n['id'] === $s))[0];
        self::assertGreaterThanOrEqual(1, $srvNode['gaps'], 'MS-UI-07: the gap shows on the host');
        self::assertNotEmpty(array_filter($map['gaps'], static fn ($g) => $g['host_pk'] === $srv['host_pk']));
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
        self::assertSame(200, (new IngestService($pdo, Config::fromArray([])))->handle($req, $agent)->status);
    }
}
