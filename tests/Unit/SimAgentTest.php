<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Sim\Profile;
use Aio\Sim\SimAgent;
use PHPUnit\Framework\TestCase;

/** The simulator behaves like agent 5c and counts what it expects stored (milestone 2 gate). */
final class SimAgentTest extends TestCase
{
    private function agent(array $state = []): SimAgent
    {
        $id = ['n' => 1, 'agent_id' => LineValidatorTest::AGENT, 'host_id' => LineValidatorTest::HOST, 'hostname' => 'SIM-0001', 'cert' => '', 'key' => ''];
        return new SimAgent($id, Profile::DEFAULT, $state, 1_794_650_400.0, 10, 60, false);
    }

    /** @return list<array<string, mixed>> */
    private static function lines(string $gz): array
    {
        return array_map(static fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", gzdecode($gz)))));
    }

    public function testBatchAckAndExpectedRows(): void
    {
        $a = $this->agent();
        $a->generate(1_794_650_400.0 + 30);
        $lines = self::lines((string) $a->takeBatch());
        self::assertSame('agent_start', $lines[0]['record_type']);
        $seqs = array_column($lines, 'seq');
        self::assertSame(range(1, count($lines)), $seqs, 'seq rises by one');
        $problems = $a->acked(['acked_seq' => max($seqs), 'stored' => count($lines), 'duplicates' => 0, 'quarantined' => 0]);
        self::assertSame([], $problems);
        self::assertSame(count($lines), $a->ackedLines);
        $flows = count(array_filter($lines, static fn ($l) => $l['record_type'] === 'net_flow'));
        self::assertSame($flows, $a->expectRows['net_flows']);
        self::assertSame(0, $a->queued());
    }

    public function testWrongAckedSeqIsAProblem(): void
    {
        $a = $this->agent();
        $lines = self::lines((string) $a->takeBatch());
        $problems = $a->acked(['acked_seq' => 999, 'stored' => count($lines), 'duplicates' => 0, 'quarantined' => 0]);
        self::assertCount(1, $problems);
    }

    public function testFailureKeepsTheLinesAndBacksOff(): void
    {
        $a = $this->agent();
        $a->takeBatch();
        $a->failed(1000.0, 30);
        self::assertGreaterThanOrEqual(1030.0, $a->nextSend, 'Retry-After is a floor');
        $again = self::lines((string) $a->takeBatch());
        self::assertSame('agent_start', $again[0]['record_type'], 'the same lines go again');
    }

    public function testStateCarriesOver(): void
    {
        $a = $this->agent(['next_seq' => 500, 'expect_rows' => ['net_flows' => 7], 'acked_lines' => 7]);
        $lines = self::lines((string) $a->takeBatch());
        self::assertSame(500, $lines[0]['seq']);
        $a->acked(['acked_seq' => 500, 'stored' => 1, 'duplicates' => 0, 'quarantined' => 0]);
        self::assertSame(7, $a->expectRows['net_flows']);
        self::assertSame(1, $a->expectRows['agent_lifecycle']);
        self::assertSame(501, $a->summary()['next_seq']);
    }
}
