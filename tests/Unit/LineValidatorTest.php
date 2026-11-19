<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Ingest\AgentIdMismatch;
use Aio\Ingest\LineValidator;
use Aio\Ingest\ParsedLine;
use Aio\Ingest\QuarantinedLine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ingest-api.md §3.4 rules 1 to 8, one reason per line (MS-ING-02, MS-ING-05, M-007). */
final class LineValidatorTest extends TestCase
{
    public const AGENT = '6f1c2a9e-2b7d-4c1e-9f3a-0a1b2c3d4e5f';
    public const HOST = '0e9d8c7b-6a5f-4e3d-2c1b-a09f8e7d6c5b';
    private const NOW = 1_794_650_400; // 2026-11-14T10:00:00Z

    private function validator(array $provisional = []): LineValidator
    {
        return new LineValidator(self::AGENT, self::HOST, $provisional, ['net_flows' => 90], 1_048_576, 86_400, self::NOW);
    }

    /** @param array<string, mixed> $over */
    public static function line(array $over = [], array $data = ['pid' => 4]): string
    {
        $env = [
            'schema_version' => 1,
            'agent_id' => self::AGENT,
            'host_id' => self::HOST,
            'hostname' => 'SRV-01',
            'agent_version' => '0.5.0',
            'record_type' => 'process_start',
            'seq' => 42,
            'ts' => '2026-11-14T09:59:00.1234567Z',
            'data' => $data,
        ];
        foreach ($over as $k => $v) {
            if ($v === '__unset__') {
                unset($env[$k]);
            } else {
                $env[$k] = $v;
            }
        }
        return json_encode($env);
    }

    public function testValidLine(): void
    {
        $r = $this->validator()->check(0, self::line());
        self::assertInstanceOf(ParsedLine::class, $r);
        self::assertSame(42, $r->seq);
        self::assertSame('2026-11-14 09:59:00.123456', $r->ts);
        self::assertSame('process_start', $r->recordType);
    }

    /** @return iterable<string, array{string, string}> */
    public static function badLines(): iterable
    {
        yield 'not json' => ['{"schema_version":1,', 'invalid_json'];
        yield 'json array' => ['[1,2]', 'invalid_json'];
        yield 'bad utf-8' => ["{\"a\":\"\xff\"}", 'invalid_json'];
        yield 'schema 2' => [self::line(['schema_version' => 2]), 'unknown_schema_version'];
        yield 'schema as string' => [self::line(['schema_version' => '1']), 'unknown_schema_version'];
        yield 'no hostname' => [self::line(['hostname' => '__unset__']), 'invalid_envelope:hostname'];
        yield 'seq zero' => [self::line(['seq' => 0]), 'invalid_envelope:seq'];
        yield 'seq string' => [self::line(['seq' => '42']), 'invalid_envelope:seq'];
        yield 'ts with offset' => [self::line(['ts' => '2026-11-14T11:59:00+02:00']), 'invalid_envelope:ts'];
        yield 'data not object' => [self::line(['data' => [1, 2]]), 'invalid_envelope:data'];
        yield 'other host' => [self::line(['host_id' => '11111111-2222-3333-4444-555555555555']), 'host_id_mismatch'];
        yield 'unknown type' => [self::line(['record_type' => 'brand_new_thing']), 'unknown_record_type'];
        yield 'too far ahead' => [self::line(['ts' => '2026-11-16T10:00:00Z']), 'ts_out_of_range'];
        yield 'older than retention' => [self::line(['record_type' => 'net_flow', 'ts' => '2026-07-01T10:00:00Z']), 'ts_out_of_range'];
    }

    #[DataProvider('badLines')]
    public function testQuarantineReasons(string $line, string $reason): void
    {
        $r = $this->validator()->check(3, $line);
        self::assertInstanceOf(QuarantinedLine::class, $r);
        self::assertSame($reason, $r->reason);
        self::assertSame(3, $r->index);
    }

    public function testQuarantineKeepsSeqAndTypeWhenReadable(): void
    {
        $r = $this->validator()->check(0, self::line(['record_type' => 'brand_new_thing', 'seq' => 77]));
        self::assertSame(77, $r->seq);
        self::assertSame('brand_new_thing', $r->recordType);
        self::assertSame(1, $r->schemaVersion);
    }

    public function testLineTooLong(): void
    {
        $v = new LineValidator(self::AGENT, self::HOST, [], [], 100, 86_400, self::NOW);
        self::assertSame('line_too_long', $v->check(0, str_repeat('x', 101))->reason);
    }

    public function testProvisionalTypeUsesTheExactReason(): void
    {
        $r = $this->validator(['inv_new_kind'])->check(0, self::line(['record_type' => 'inv_new_kind']));
        self::assertSame('provisional type, no table yet', $r->reason);
    }

    public function testHostIdIsCaseInsensitive(): void
    {
        self::assertInstanceOf(ParsedLine::class, $this->validator()->check(0, self::line(['host_id' => strtoupper(self::HOST)])));
    }

    public function testAnotherAgentsIdRefusesTheRequest(): void
    {
        $this->expectException(AgentIdMismatch::class);
        $this->validator()->check(0, self::line(['agent_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee']));
    }

    public function testMismatchIsFoundEvenOnALineThatWouldBeQuarantined(): void
    {
        $this->expectException(AgentIdMismatch::class);
        $this->validator()->check(0, self::line(['agent_id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'schema_version' => 9]));
    }
}
