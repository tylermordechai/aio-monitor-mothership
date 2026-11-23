<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Auth\ClientCertAuth;
use Aio\Config;
use Aio\Dashboard\CommandLineReveal;
use Aio\Dashboard\HostQuery;
use Aio\Dashboard\Labels;
use Aio\Http\Request;
use Aio\Ingest\IngestService;
use Aio\Jobs\FlowRollup;
use Aio\Sim\Network;
use Aio\Sim\RecordFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/** MS-UI-02, MS-UI-07, MS-SEC-07..09: the host page's queries and the audited command-line reveal, on real rows (M-056). */
final class HostPageTest extends TestCase
{
    private PDO $pdo;
    /** @var array{agent_pk: int, agent_id: string, host_pk: int, host_guid: string, hostname: string, cert: string} */
    private array $srv;
    /** @var array{agent_pk: int, agent_id: string, host_pk: int, host_guid: string, hostname: string, cert: string} */
    private array $lap;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo($this);
        // Host numbers no other test uses, so the addresses are this test's own.
        $net = new Network([211], [212]);
        $this->srv = TestDb::agent($this->pdo, 'HOST-SRV');
        $this->lap = TestDb::agent($this->pdo, 'HOST-LAP');
        $fs = new RecordFactory($this->srv['agent_id'], $this->srv['host_guid'], $this->srv['hostname'], 1, '0.5.0', 211, $net, true);
        $fl = new RecordFactory($this->lap['agent_id'], $this->lap['host_guid'], $this->lap['hostname'], 1, '0.5.0', 212, $net, false);
        $t = time() - 600;
        $this->ingest($this->srv, array_merge(
            $fs->snapshot($t, 10, 6, 3),
            $fs->flows($t, 20),
            $fs->processes($t, 2),
            [$fs->executable($t, 0), $fs->executable($t, 1, 'access_denied'), $fs->coverageGap($t + 5, $t - 60, $t - 30)],
            $fs->inventory($t, 5, 6, true),
            [$fs->inventoryChange($t + 1)],
        ));
        $this->ingest($this->lap, array_merge($fl->flows($t, 15), $fl->inventory($t, 3, 4, false)));
        $job = new FlowRollup($this->pdo, -60);
        while ($job->runOnce()['batches'] > 0) {
        }
    }

    private function q(): HostQuery
    {
        return new HostQuery($this->pdo, Config::fromArray([]));
    }

    public function testMsUi02HeaderConnectionsAndPeers(): void
    {
        $now = time();
        $h = $this->q()->host($this->srv['host_pk'], Config::fromArray([]), $now);
        self::assertSame('HOST-SRV', $h['hostname']);
        self::assertSame('server', $h['kind']);
        self::assertSame(['10.20.0.213'], $h['addresses']);
        self::assertNull($this->q()->host(999_999, Config::fromArray([]), $now));

        $c = $this->q()->connections($this->srv['host_pk'], $now - 3600, Config::fromArray([]));
        self::assertSame(20, $c['total'], 'one row per D-016 key');
        self::assertNotNull($c['data_until']);
        $fromLaptop = array_values(array_filter($c['rows'], static fn ($r) => $r['peer'] !== null && $r['peer']['host_pk'] === $this->lap['host_pk']));
        self::assertNotEmpty($fromLaptop, 'the laptop is named as a monitored host');
        self::assertSame('inbound', $fromLaptop[0]['direction']);
        self::assertSame(Labels::FACT, $fromLaptop[0]['direction_source'][1], 'etw: a fact');

        $snap = $this->q()->latestSnapshot($this->srv['host_pk']);
        self::assertNotNull($snap);
        $live = $this->q()->liveConnections($this->srv['host_pk'], $snap);
        self::assertCount(10, $live);
        self::assertSame(Labels::GUESS, $live[0]['direction_source'][1], 'heuristic: a guess');
        self::assertCount(9, $this->q()->listening($this->srv['host_pk'], $snap), '6 TCP listeners and 3 UDP endpoints');
    }

    public function testMsUi07GapsForTheRange(): void
    {
        $now = time();
        $gaps = $this->q()->gaps($this->srv['host_pk'], $now - 3600, $now);
        self::assertCount(1, $gaps);
        self::assertSame('session_stopped', $gaps[0]['reason']);
        self::assertSame([], $this->q()->gaps($this->lap['host_pk'], $now - 3600, $now));
        self::assertSame([], $this->q()->gaps($this->srv['host_pk'], $now - 60, $now), 'a gap before the range is not shown');
    }

    public function testMsUi02ProcessesExecutablesInventoryAndServerSoftware(): void
    {
        $now = time();
        $p = $this->q()->processes($this->srv['host_pk'], $now - 3600);
        self::assertSame(2, $p['total']);
        self::assertTrue($p['rows'][0]['has_command_line']);
        self::assertTrue($p['rows'][0]['cl_redacted']);
        self::assertNotNull($p['rows'][0]['exit_time'], 'joined to its process_stop');
        self::assertArrayNotHasKey('command_line', $p['rows'][0], 'MS-SEC-07: never read as aio_dashboard');

        $x = $this->q()->executables($this->srv['host_pk']);
        self::assertSame(2, $x['total']);
        $denied = array_values(array_filter($x['rows'], static fn ($r) => $r['sha256'] === null))[0];
        self::assertStringContainsString('SeBackupPrivilege', $denied['hash'][0]);

        $inv = $this->q()->latestInventory($this->srv['host_pk']);
        self::assertNotNull($inv);
        self::assertCount(5, $this->q()->inventoryItems($this->srv['host_pk'], 'apps', $inv)['rows']);
        self::assertCount(6, $this->q()->inventoryItems($this->srv['host_pk'], 'services', $inv)['rows']);
        self::assertCount(1, $this->q()->inventoryItems($this->srv['host_pk'], 'roles', $inv)['rows']);
        self::assertCount(1, $this->q()->inventoryChanges($this->srv['host_pk'], $now - 3600));

        $sw = $this->q()->serverSoftware($this->srv['host_pk'], $inv);
        self::assertSame('ok', $sw['iis']['read']);
        self::assertSame(443, $sw['sites'][0]['bindings'][0]['port']);
        self::assertSame([1433], $sw['sql'][0]['tcp_ports']);
        self::assertSame('Tomcat', $sw['apps'][0]['product']);
        $owners = Labels::portOwners($this->q()->listening($this->srv['host_pk'], (string) $this->q()->latestSnapshot($this->srv['host_pk'])), $sw['sites'], $sw['sql']);
        $p443 = array_values(array_filter($owners, static fn ($o) => $o['proto'] === 'tcp' && $o['port'] === 443))[0];
        self::assertStringContainsString('Default Web Site', $p443['owner']);

        $lapInv = $this->q()->latestInventory($this->lap['host_pk']);
        $lapSw = $this->q()->serverSoftware($this->lap['host_pk'], $lapInv);
        self::assertNull($lapSw['iis'], 'a laptop has no IIS record: shown as "no IIS record", not "no sites"');
        self::assertSame([], $lapSw['sql']);
    }

    public function testMsSec07To09CommandLineOnlyForAnalystsAndAlwaysAudited(): void
    {
        $row = $this->q()->processes($this->srv['host_pk'], time() - 3600)['rows'][0];
        $reveal = new CommandLineReveal($this->pdo, fn (): PDO => $this->pdo);
        $viewer = ['id' => 901, 'username' => 'v-' . bin2hex(random_bytes(3)), 'role' => 'viewer'];
        $analyst = ['id' => 902, 'username' => 'a-' . bin2hex(random_bytes(3)), 'role' => 'analyst'];
        $admin = ['id' => 903, 'username' => 'm-' . bin2hex(random_bytes(3)), 'role' => 'admin'];

        self::assertSame('not_allowed', $reveal->reveal($viewer, $this->srv['host_pk'], $row['agent_pk'], $row['seq'], $row['ts'], '192.168.0.50')['status']);
        self::assertSame(0, $this->audits($viewer['username']), 'nothing read, nothing to audit');

        $r = $reveal->reveal($analyst, $this->srv['host_pk'], $row['agent_pk'], $row['seq'], $row['ts'], '192.168.0.50');
        self::assertSame('shown', $r['status']);
        self::assertStringContainsString('nightly.cmd', $r['command_line']);
        self::assertSame(1, $this->audits($analyst['username']));
        $d = $this->pdo->prepare("SELECT action, target_type, target_id, details FROM audit_log WHERE username = ?");
        $d->execute([$analyst['username']]);
        $a = $d->fetch();
        self::assertSame(['view_command_line', 'process', $row['agent_pk'] . ':' . $row['seq']], [$a['action'], $a['target_type'], $a['target_id']]);
        self::assertStringNotContainsString('nightly.cmd', (string) $a['details'], 'the audit row names the process, not the command line');

        self::assertSame('shown', $reveal->reveal($admin, $this->srv['host_pk'], $row['agent_pk'], $row['seq'], $row['ts'], '192.168.0.50')['status'], 'admin includes analyst');
        self::assertSame(1, $this->audits($admin['username']));

        self::assertSame('not_found', $reveal->reveal($analyst, $this->lap['host_pk'], $row['agent_pk'], $row['seq'], $row['ts'], '192.168.0.50')['status'], 'not through another host\'s page');
        self::assertSame('not_found', $reveal->reveal($analyst, $this->srv['host_pk'], $row['agent_pk'], $row['seq'], 'bogus', '192.168.0.50')['status']);
        self::assertSame(1, $this->audits($analyst['username']), 'refused looks write nothing');

        // Cleared (as the 30-day retention will): the look is still audited, nothing is shown.
        $this->pdo->prepare('DELETE FROM process_command_lines WHERE agent_pk = ? AND seq = ? AND ts = ?')->execute([$row['agent_pk'], $row['seq'], $row['ts']]);
        $c = $reveal->reveal($analyst, $this->srv['host_pk'], $row['agent_pk'], $row['seq'], $row['ts'], '192.168.0.50');
        self::assertSame('cleared', $c['status']);
        self::assertArrayNotHasKey('command_line', $c);
        self::assertSame(2, $this->audits($analyst['username']));
    }

    private function audits(string $username): int
    {
        $s = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE username = ? AND action = 'view_command_line'");
        $s->execute([$username]);
        return (int) $s->fetchColumn();
    }

    /** @param list<array<string, mixed>> $records */
    private function ingest(array $a, array $records): void
    {
        $gz = gzencode(RecordFactory::jsonl($records));
        $req = Request::forTest('POST', '/api/v1/ingest', [
            'Content-Type' => 'application/x-ndjson', 'Content-Encoding' => 'gzip',
            'Content-Length' => (string) strlen($gz), 'User-Agent' => 'aio-monitor-agent/0.5.0',
        ], $gz, $a['cert']);
        $agent = (new ClientCertAuth($this->pdo))->authenticate($req);
        self::assertSame(200, (new IngestService($this->pdo, Config::fromArray([])))->handle($req, $agent)->status);
    }
}
