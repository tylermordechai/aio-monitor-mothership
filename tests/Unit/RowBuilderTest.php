<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Ingest\FieldError;
use Aio\Ingest\LineValidator;
use Aio\Ingest\ParsedLine;
use Aio\Ingest\RowBuilder;
use PHPUnit\Framework\TestCase;

/** ingest-api.md §3.4 rule 9 and §9: fields to columns (MS-ING-05, D-073, M-010..M-015). */
final class RowBuilderTest extends TestCase
{
    private function parsed(string $type, array $data, int $seq = 42): ParsedLine
    {
        $v = new LineValidator(LineValidatorTest::AGENT, LineValidatorTest::HOST, [], [], 1_048_576, 86_400, 1_794_650_400);
        $r = $v->check(0, LineValidatorTest::line(['record_type' => $type, 'seq' => $seq], $data));
        self::assertInstanceOf(ParsedLine::class, $r, 'test line must pass the envelope checks');
        return $r;
    }

    private function build(string $type, array $data): \Aio\Ingest\BuiltRow
    {
        return (new RowBuilder(7, 3, 99))->build($this->parsed($type, $data));
    }

    private function flow(array $over = []): array
    {
        return array_merge([
            'proto' => 'tcp', 'family' => 'ipv4', 'local_addr' => '10.0.0.5', 'local_port' => 50123,
            'remote_addr' => '203.0.113.9', 'remote_port' => 443, 'direction' => 'outbound',
            'direction_source' => 'etw', 'window_start' => '2026-11-14T09:58:50Z', 'window_end' => '2026-11-14T09:59:00Z',
            'pid' => 1234, 'bytes_sent' => 1500, 'closed' => false, 'services' => ['Dnscache'],
        ], $over);
    }

    public function testFlowRow(): void
    {
        $b = $this->build('net_flow', $this->flow());
        self::assertSame('net_flows', $b->table);
        $r = $b->row;
        self::assertSame(7, $r['agent_pk']);
        self::assertSame(3, $r['host_pk']);
        self::assertSame(99, $r['batch_pk']);
        self::assertSame(42, $r['seq']);
        self::assertSame('2026-11-14 09:59:00.123456', $r['ts']);
        self::assertSame(inet_pton('203.0.113.9'), $r['remote_ip']);
        self::assertSame(inet_pton('10.0.0.5'), $r['local_ip']);
        self::assertSame(0, $r['closed']);
        self::assertSame('["Dnscache"]', $r['services']);
        self::assertSame('2026-11-14 09:58:50.000000', $r['window_start']);
        self::assertArrayNotHasKey('extra', $r);
        self::assertArrayNotHasKey('flow_key', $r, 'generated columns are never written');
    }

    public function testUnknownFieldsGoToExtraNotQuarantine(): void
    {
        $r = $this->build('net_flow', $this->flow(['new_field' => ['a' => 1], 'empty_obj' => new \stdClass()]))->row;
        self::assertSame('{"new_field":{"a":1},"empty_obj":{}}', $r['extra']);
    }

    public function testDataFieldNamedLikeAnEnvelopeColumnGoesToExtra(): void
    {
        $r = $this->build('net_flow', $this->flow(['agent_pk' => 1, 'seq' => 5]))->row;
        self::assertSame(7, $r['agent_pk']);
        self::assertSame(42, $r['seq']);
        self::assertSame('{"agent_pk":1,"seq":5}', $r['extra']);
    }

    public function testMissingRequiredFieldInListOrder(): void
    {
        $f = $this->flow();
        unset($f['proto'], $f['pid']);
        $this->expectReason('missing_field:proto', fn () => $this->build('net_flow', $f));
    }

    public function testNullRequiredFieldIsMissing(): void
    {
        $this->expectReason('missing_field:pid', fn () => $this->build('net_flow', $this->flow(['pid' => null])));
    }

    public function testWrongTypes(): void
    {
        $this->expectReason('invalid_field:local_port', fn () => $this->build('net_flow', $this->flow(['local_port' => '443'])));
        $this->expectReason('invalid_field:local_port', fn () => $this->build('net_flow', $this->flow(['local_port' => 70000])));
        $this->expectReason('invalid_field:closed', fn () => $this->build('net_flow', $this->flow(['closed' => 1])));
        $this->expectReason('invalid_field:window_end', fn () => $this->build('net_flow', $this->flow(['window_end' => 'soon'])));
        $this->expectReason('invalid_field:proto', fn () => $this->build('net_flow', $this->flow(['proto' => str_repeat('t', 9)])));
        $this->expectReason('invalid_field:bytes_sent', fn () => $this->build('net_flow', $this->flow(['bytes_sent' => -1])));
    }

    public function testNewValueOfTheRightTypeIsStoredAsIs(): void
    {
        $r = $this->build('net_flow', $this->flow(['direction_source' => 'something_new']))->row;
        self::assertSame('something_new', $r['direction_source']);
    }

    public function testZeroTimeIsNullWhenOptional(): void
    {
        $r = $this->build('net_flow', $this->flow(['first_seen' => '0001-01-01T00:00:00Z']))->row;
        self::assertNull($r['first_seen']);
    }

    public function testProcessStartSplitsTheCommandLine(): void
    {
        $b = $this->build('process_start', ['pid' => 10, 'parent_pid' => 4, 'command_line' => 'cmd.exe /c dir', 'command_line_redacted' => true]);
        self::assertSame('process_events', $b->table);
        self::assertSame('cmd.exe /c dir', $b->commandLine);
        self::assertSame(1, $b->row['has_command_line']);
        self::assertSame('process_start', $b->row['record_type']);
        self::assertSame(1, $b->row['command_line_redacted']);
        self::assertArrayNotHasKey('command_line', $b->row);
    }

    public function testProcessWithoutCommandLine(): void
    {
        $b = $this->build('process_snapshot', ['pid' => 10, 'command_line_unavailable' => 'access_denied']);
        self::assertNull($b->commandLine);
        self::assertSame(0, $b->row['has_command_line']);
    }

    public function testProcessStopNeedsExitTime(): void
    {
        $this->expectReason('missing_field:exit_time', fn () => $this->build('process_stop', ['pid' => 10]));
    }

    public function testExecutableRow(): void
    {
        $b = $this->build('executable', [
            'path' => 'C:\\Windows\\System32\\svchost.exe', 'size' => 55320, 'mod_time' => '2026-10-01T08:00:00Z',
            'hash_status' => 'ok', 'sha256' => str_repeat('a', 64),
            'signature' => ['status' => 'valid', 'signer' => 'Microsoft Windows'], 'elapsed_ms' => 12,
        ]);
        $r = $b->row;
        self::assertSame('executable', $b->kind);
        self::assertSame(42, $r['first_seq']);
        self::assertSame(42, $r['last_seq']);
        self::assertSame('2026-11-14 09:59:00.123456', $r['first_seen']);
        self::assertArrayNotHasKey('seq', $r);
        self::assertArrayNotHasKey('ts', $r);
        self::assertSame('{"status":"valid","signer":"Microsoft Windows"}', $r['signature']);
    }

    public function testCoverageGapMapsStartAndEnd(): void
    {
        $r = $this->build('coverage_gap', ['reason' => 'session_stopped', 'start' => '2026-11-14T09:00:00Z', 'end' => '2026-11-14T09:05:00Z', 'events_lost' => 3])->row;
        self::assertSame('2026-11-14 09:00:00.000000', $r['gap_start']);
        self::assertSame('2026-11-14 09:05:00.000000', $r['gap_end']);
        self::assertSame(42, $r['first_seq']);
        self::assertSame('2026-11-14 09:59:00.123456', $r['last_ts']);
    }

    public function testCoverageGapNeedsStart(): void
    {
        $this->expectReason('missing_field:start', fn () => $this->build('coverage_gap', ['reason' => 'events_lost']));
    }

    public function testInventoryKeyDerivedOrFromTheAgent(): void
    {
        $r = $this->build('inv_service', ['name' => 'Spooler', 'state' => 'running'])->row;
        self::assertSame('spooler', $r['item_key']);
        self::assertSame('derived', $r['key_source']);

        $r = $this->build('inv_service', ['name' => 'Spooler', 'key' => 'spooler', 'snapshot_id' => 'snap-1'])->row;
        self::assertSame('spooler', $r['item_key']);
        self::assertSame('agent', $r['key_source']);
        self::assertSame('snap-1', $r['snapshot_id']);
        self::assertArrayNotHasKey('extra', $r);
    }

    public function testInventoryChangeKeepsItsKeyColumn(): void
    {
        $r = $this->build('inventory_change', ['kind' => 'inv_service', 'key' => 'spooler', 'change' => 'modified', 'before' => ['state' => 'stopped'], 'after' => ['state' => 'running']])->row;
        self::assertSame('spooler', $r['key']);
        self::assertSame('{"state":"stopped"}', $r['before']);
    }

    public function testIisSiteIdIsTheSitesOwnField(): void
    {
        $r = $this->build('inv_iis_site', ['name' => 'Default Web Site', 'id' => 1, 'bindings' => [['protocol' => 'https', 'port' => 443]]])->row;
        self::assertSame(1, $r['id']);
        self::assertSame('iis_site|1', $r['item_key']);
    }

    public function testIisReadStatusIsRequired(): void
    {
        $this->expectReason('missing_field:read', fn () => $this->build('inv_iis', ['installed' => true]));
    }

    public function testEveryRecordTypeHasATableInTheMap(): void
    {
        foreach (\Aio\Ingest\RecordTypes::TYPES as $type => $spec) {
            self::assertNotEmpty(\Aio\Ingest\Schema\TableMap::columns($spec['table']), $type);
        }
    }

    private function expectReason(string $reason, callable $f): void
    {
        try {
            $f();
            self::fail("expected $reason");
        } catch (FieldError $e) {
            self::assertSame($reason, $e->getMessage());
        }
    }
}
