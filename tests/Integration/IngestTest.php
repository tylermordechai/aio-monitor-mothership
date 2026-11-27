<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Auth\ClientCertAuth;
use Aio\Config;
use Aio\Heartbeat\HeartbeatService;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Ingest\IngestService;
use Aio\Ingest\RecordTypes;
use Aio\Sim\RecordFactory;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Ingest against a real MariaDB (aio_test): storage, acknowledgement,
 * dedup, quarantine and the agent ID check (MS-ING-01 to 05, 08, 12; M-004,
 * M-005, M-011, M-014, M-015, M-046).
 */
final class IngestTest extends TestCase
{
    private PDO $pdo;
    /** @var array{agent_pk: int, agent_id: string, host_pk: int, host_guid: string, hostname: string, cert: string} */
    private array $a;
    private RecordFactory $f;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo($this);
        $this->a = TestDb::agent($this->pdo);
        $this->f = new RecordFactory($this->a['agent_id'], $this->a['host_guid'], $this->a['hostname']);
    }

    /** @param list<array<string, mixed>|string> $records */
    private function ingest(array $records, ?string $cert = null): Response
    {
        $body = '';
        foreach ($records as $r) {
            $body .= (is_string($r) ? $r : json_encode($r, JSON_UNESCAPED_SLASHES)) . "\n";
        }
        $gz = gzencode($body);
        $req = Request::forTest('POST', '/api/v1/ingest', [
            'Content-Type' => 'application/x-ndjson',
            'Content-Encoding' => 'gzip',
            'Content-Length' => (string) strlen($gz),
            'User-Agent' => 'aio-monitor-agent/0.5.0',
        ], $gz, $cert ?? $this->a['cert']);
        $agent = (new ClientCertAuth($this->pdo))->authenticate($req);
        return (new IngestService($this->pdo, Config::fromArray([])))->handle($req, $agent);
    }

    private function rows(string $table): int
    {
        return TestDb::count($this->pdo, $table, $this->a['agent_pk']);
    }

    public function testEveryRecordTypeIsStoredAndAcknowledged(): void
    {
        $records = $this->f->oneOfEach(microtime(true) - 5);
        $r = $this->ingest($records);
        self::assertSame(200, $r->status);
        $n = count($records);
        self::assertSame($n, $r->body['lines']);
        self::assertSame($n, $r->body['stored'], 'every line stored');
        self::assertSame(0, $r->body['duplicates']);
        self::assertSame(0, $r->body['quarantined']);
        self::assertSame(max(array_column($records, 'seq')), $r->body['acked_seq']);
        self::assertSame($r->body['acked_seq'], $r->body['max_seq_held']);

        $perTable = [];
        foreach ($records as $rec) {
            $t = RecordTypes::TYPES[$rec['record_type']]['table'];
            $perTable[$t] = ($perTable[$t] ?? 0) + 1;
        }
        foreach ($perTable as $table => $expected) {
            self::assertSame($expected, $this->rows($table), $table);
        }
        self::assertSame(1, $this->rows('process_command_lines'), 'command line stored apart (M-011)');
        $b = $this->pdo->prepare('SELECT line_count, stored_count, duplicate_count, quarantined_count, http_status FROM ingest_batches WHERE agent_pk = ?');
        $b->execute([$this->a['agent_pk']]);
        self::assertSame(['line_count' => $n, 'stored_count' => $n, 'duplicate_count' => 0, 'quarantined_count' => 0, 'http_status' => 200], $b->fetch());
        $ag = $this->pdo->query('SELECT last_version, last_checkin_at IS NOT NULL AS checked_in FROM agents WHERE id = ' . $this->a['agent_pk'])->fetch();
        self::assertSame('0.5.0', $ag['last_version'], 'MS-ING-08');
        self::assertSame(1, (int) $ag['checked_in']);
    }

    public function testAResendIsAllDuplicatesAndChangesNothing(): void
    {
        $records = $this->f->oneOfEach(microtime(true) - 5);
        $this->ingest($records);
        $before = $this->rows('net_flows') + $this->rows('executables') + $this->rows('coverage_gaps');
        $r = $this->ingest($records);
        self::assertSame(200, $r->status);
        self::assertSame(0, $r->body['stored']);
        self::assertSame(count($records), $r->body['duplicates']);
        self::assertSame($before, $this->rows('net_flows') + $this->rows('executables') + $this->rows('coverage_gaps'));
    }

    public function testBadLinesAreQuarantinedAndTheRestStored(): void
    {
        $ts = microtime(true) - 5;
        $good = $this->f->etwStatus($ts);
        $unknown = $this->f->envelope('brand_new_type', ['x' => 1], $ts);
        $missing = $this->f->envelope('net_flow', ['proto' => 'tcp'], $ts);
        $r = $this->ingest([$good, $unknown, $missing, '{not json']);
        self::assertSame(200, $r->status);
        self::assertSame(4, $r->body['lines']);
        self::assertSame(1, $r->body['stored']);
        self::assertSame(3, $r->body['quarantined']);
        $q = $this->pdo->prepare('SELECT reason FROM quarantine WHERE agent_pk = ? ORDER BY reason');
        $q->execute([$this->a['agent_pk']]);
        self::assertSame(['invalid_json', 'missing_field:family', 'unknown_record_type'], $q->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(max($good['seq'], $unknown['seq'], $missing['seq']), $r->body['acked_seq'], 'quarantined lines are acknowledged too');
    }

    public function testAnotherAgentsIdStoresNothing(): void
    {
        $ts = microtime(true) - 5;
        $good = $this->f->etwStatus($ts);
        $bad = $this->f->etwStatus($ts);
        $bad['agent_id'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
        try {
            $this->ingest([$good, $bad]);
            self::fail('expected 403');
        } catch (ApiError $e) {
            self::assertSame(403, $e->status);
            self::assertSame('agent_id_mismatch', $e->errorCode);
        }
        self::assertSame(0, $this->rows('agent_status'));
        self::assertSame(0, $this->rows('quarantine'));
        $s = $this->pdo->prepare('SELECT http_status, error_code FROM ingest_batches WHERE agent_pk = ?');
        $s->execute([$this->a['agent_pk']]);
        self::assertSame(['http_status' => 403, 'error_code' => 'agent_id_mismatch'], $s->fetch());
    }

    public function testExecutableIsUpdatedOnlyByANewerRecord(): void
    {
        $ts = microtime(true) - 60;
        $denied = $this->f->executable($ts, 1, 'access_denied');
        $this->ingest([$denied]);
        $ok = $this->f->executable($ts + 10, 1, 'ok');
        $r = $this->ingest([$ok]);
        self::assertSame(1, $r->body['stored']);
        $row = $this->pdo->query('SELECT hash_status, sha256 IS NOT NULL AS has_hash, first_seq, last_seq, access_denied_reason FROM executables WHERE agent_pk = ' . $this->a['agent_pk'])->fetchAll();
        self::assertCount(1, $row, 'one row per host and file version (M-014)');
        self::assertSame('ok', $row[0]['hash_status']);
        self::assertSame($denied['seq'], (int) $row[0]['first_seq']);
        self::assertSame($ok['seq'], (int) $row[0]['last_seq']);
        self::assertNull($row[0]['access_denied_reason'], 'the newer record replaces the details');
        // The older record arriving late changes nothing but is still a new line.
        $r = $this->ingest([$denied]);
        self::assertSame(1, $r->body['duplicates']);
        self::assertSame('ok', $this->pdo->query('SELECT hash_status FROM executables WHERE agent_pk = ' . $this->a['agent_pk'])->fetchColumn());
    }

    public function testCoverageGapOpenAndCloseLandOnOneRow(): void
    {
        $start = microtime(true) - 300;
        $open = $this->f->coverageGap($start + 1, $start);
        $close = $this->f->coverageGap($start + 120, $start, $start + 119);
        $this->ingest([$open]);
        $this->ingest([$close]);
        $rows = $this->pdo->query('SELECT gap_end IS NOT NULL AS closed, first_seq, last_seq FROM coverage_gaps WHERE agent_pk = ' . $this->a['agent_pk'])->fetchAll();
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['closed']);
        self::assertSame($open['seq'], (int) $rows[0]['first_seq']);
        self::assertSame($close['seq'], (int) $rows[0]['last_seq']);
    }

    public function testSeqRollbackIsStoredAndFlagged(): void
    {
        $f = new RecordFactory($this->a['agent_id'], $this->a['host_guid'], $this->a['hostname'], 5000);
        $this->ingest([$f->agentStart(microtime(true) - 100), $f->etwStatus(microtime(true) - 99)]);
        // A restored VM snapshot: the same agent, the counter back at 10 (§3.6).
        $rolled = new RecordFactory($this->a['agent_id'], $this->a['host_guid'], $this->a['hostname'], 10);
        $r = $this->ingest([$rolled->agentStart(microtime(true) - 5, 'crash', 5000)]);
        self::assertSame(1, $r->body['stored'], 'same seq, new ts: kept, not taken for a resend (M-046)');
        self::assertSame(2, $this->rows('agent_lifecycle'));
        $flag = $this->pdo->query('SELECT seq_regression_at IS NOT NULL FROM agents WHERE id = ' . $this->a['agent_pk'])->fetchColumn();
        self::assertSame(1, (int) $flag);
    }

    public function testRenameIsKept(): void
    {
        $this->f->hostname = 'SIM-RENAMED';
        $this->ingest([$this->f->etwStatus(microtime(true) - 5)]);
        self::assertSame('SIM-RENAMED', $this->pdo->query('SELECT hostname FROM hosts WHERE id = ' . $this->a['host_pk'])->fetchColumn());
        $names = $this->pdo->query('SELECT hostname FROM host_names WHERE host_pk = ' . $this->a['host_pk'] . ' ORDER BY hostname')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['SIM-RENAMED', 'SIM-T'], $names);
    }

    public function testRevokedAgentIsRefused(): void
    {
        $this->pdo->exec("UPDATE agents SET status = 'revoked' WHERE id = " . $this->a['agent_pk']);
        try {
            $this->ingest([$this->f->etwStatus(microtime(true))]);
            self::fail('expected 403');
        } catch (ApiError $e) {
            self::assertSame('agent_revoked', $e->errorCode);
        }
    }

    public function testUnknownCertificateIsRefused(): void
    {
        try {
            $this->ingest([$this->f->etwStatus(microtime(true))], TestDb::selfSignedCert($this->a['agent_id']));
            self::fail('expected 403');
        } catch (ApiError $e) {
            self::assertSame('certificate_unknown', $e->errorCode);
        }
    }

    public function testHeartbeatIsStored(): void
    {
        $body = json_encode([
            'agent_time' => RecordFactory::ts(microtime(true) + 2),
            'uptime_s' => 3600.5,
            'queue' => ['records' => 120, 'bytes' => 40_960, 'oldest_ts' => RecordFactory::ts(microtime(true) - 30), 'dropped_records' => 0],
            'transport' => ['last_acked_seq' => 77, 'consecutive_failures' => 0, 'last_error' => null],
            'health' => ['etw' => 'running'],
            'config_etag' => '"cfg-1-1"',
            'something_new' => true,
        ]);
        $req = Request::forTest('POST', '/api/v1/heartbeat', ['Content-Type' => 'application/json', 'User-Agent' => 'aio-monitor-agent/0.5.1'], $body, $this->a['cert']);
        $agent = (new ClientCertAuth($this->pdo))->authenticate($req);
        $r = (new HeartbeatService($this->pdo, Config::fromArray([])))->handle($req, $agent);
        self::assertSame(200, $r->status);
        self::assertSame(60, $r->body['heartbeat_s']);
        // The ETag the config endpoint gives for the agent's group (M-065).
        self::assertMatchesRegularExpression('/^"cfg-\d+-1-[0-9a-f]{12}"$/', $r->body['config_etag']);
        $hb = $this->pdo->query('SELECT queue_records, last_acked_seq, body FROM agent_heartbeats WHERE agent_pk = ' . $this->a['agent_pk'])->fetch();
        self::assertSame(120, (int) $hb['queue_records']);
        self::assertSame(77, (int) $hb['last_acked_seq']);
        // MariaDB's JSON_VALUE turns true into '1', so check the stored JSON itself.
        self::assertTrue(json_decode((string) $hb['body'], true)['something_new'] ?? null, 'unknown fields kept');
        $ag = $this->pdo->query('SELECT queue_records, clock_skew_ms, last_version FROM agents WHERE id = ' . $this->a['agent_pk'])->fetch();
        self::assertSame(120, (int) $ag['queue_records']);
        self::assertGreaterThan(1000, (int) $ag['clock_skew_ms']);
        self::assertSame('0.5.1', $ag['last_version']);
    }
}
