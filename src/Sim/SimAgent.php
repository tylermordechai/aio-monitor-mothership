<?php

declare(strict_types=1);

namespace Aio\Sim;

/**
 * One simulated agent: makes records on the profile's schedule, queues them,
 * and sends them the way agent 5c will (ingest-api.md §8.1):
 * one request in flight, delete only the lines of a 200, backoff with full
 * jitter on 429/5xx/timeouts, Retry-After as a floor, split on 413.
 *
 * It also keeps what it expects the database to hold, so tools/reconcile.php
 * can prove nothing was lost (MS-ING-04, the milestone 2 gate).
 */
final class SimAgent
{
    public readonly RecordFactory $factory;

    /** @var list<array{line: string, seq: int, table: string, kind: string, ident: string, expect: string}> */
    private array $queue = [];

    /** @var list<array{line: string, seq: int, table: string, kind: string, ident: string, expect: string}> the request in flight */
    public array $inFlight = [];
    public bool $inFlightIsResend = false;
    public float $nextSend;
    public float $nextHeartbeat;
    public int $attempt = 0;
    public ?string $lastAckedBatch = null;
    /** @var list<array{line: string, seq: int, table: string, kind: string, ident: string, expect: string}> */
    private array $lastAcked = [];

    /** Expected rows per table, from acknowledged lines only. @var array<string, int> */
    public array $expectRows = [];
    /** @var array<string, true> identities of executables and coverage gaps already counted */
    private array $idents = [];
    public int $ackedLines = 0;
    public int $mismatches = 0;

    private float $lastGen;
    private float $nextSnapshot;
    private float $nextEtw;
    private float $nextExe;
    private float $nextInventory;
    private float $nextChange;
    private int $exeCounter = 0;
    private float $procCarry = 0.0;

    /**
     * @param array{n: int, agent_id: string, host_id: string, hostname: string, cert: string, key: string} $id
     * @param array<string, int> $profile
     * @param array{next_seq?: int, exe_counter?: int, expect_rows?: array<string, int>, acked_lines?: int} $state
     *        carried over from earlier runs, so seq keeps rising and the expected
     *        row counts stay cumulative, like the database
     */
    public function __construct(
        public readonly array $id,
        private readonly array $profile,
        array $state,
        float $now,
        private readonly int $batchIntervalS,
        private readonly int $heartbeatS,
        private readonly bool $faults,
    ) {
        $this->factory = new RecordFactory($id['agent_id'], $id['host_id'], $id['hostname'], (int) ($state['next_seq'] ?? 1), '0.5.0-sim', $id['n']);
        $this->exeCounter = (int) ($state['exe_counter'] ?? 0);
        $this->expectRows = $state['expect_rows'] ?? [];
        $this->ackedLines = (int) ($state['acked_lines'] ?? 0);
        // Spread the fleet over the interval, as real agents start at different times.
        $this->nextSend = $now + (mt_rand() / mt_getrandmax()) * $batchIntervalS;
        $this->nextHeartbeat = $now + (mt_rand() / mt_getrandmax()) * $heartbeatS;
        $this->lastGen = $now;
        $this->nextSnapshot = $now + mt_rand(0, $profile['snapshot_every_s']);
        $this->nextEtw = $now + mt_rand(0, $profile['etw_status_every_s']);
        $this->nextExe = $now + mt_rand(0, $profile['executable_every_s']);
        $this->nextInventory = $now + mt_rand(0, 60); // at start, like the agent
        $this->nextChange = $now + mt_rand(0, $profile['inventory_change_every_s']);
        $this->enqueue([$this->factory->agentStart($now)]);
    }

    /** Makes the records due up to $now. */
    public function generate(float $now): void
    {
        $p = $this->profile;
        $f = $this->factory;
        // Flows per 10 s window ending at or before now.
        $w = floor($this->lastGen / 10) * 10 + 10;
        for (; $w <= $now; $w += 10) {
            $this->enqueue($f->flows($w, $p['flows_per_window']));
        }
        $this->procCarry += ($now - $this->lastGen) / 60 * $p['process_pairs_per_min'];
        $pairs = (int) floor($this->procCarry);
        if ($pairs > 0) {
            $this->procCarry -= $pairs;
            $this->enqueue($f->processes($now - 0.5, $pairs));
        }
        if ($now >= $this->nextSnapshot) {
            $this->enqueue($f->snapshot($now, $p['snapshot_conns'], $p['snapshot_listeners'], $p['snapshot_udp']));
            $this->nextSnapshot += $p['snapshot_every_s'];
        }
        if ($now >= $this->nextEtw) {
            $this->enqueue([$f->etwStatus($now)]);
            $this->nextEtw += $p['etw_status_every_s'];
        }
        if ($now >= $this->nextExe) {
            $this->enqueue([$f->executable($now, $this->exeCounter++)]);
            $this->nextExe += $p['executable_every_s'];
        }
        if ($now >= $this->nextInventory) {
            $this->enqueue($f->inventory($now, $p['inventory_apps'], $p['inventory_services']));
            $this->nextInventory += $p['inventory_every_s'];
        }
        if ($now >= $this->nextChange) {
            $this->enqueue([$f->inventoryChange($now)]);
            $this->nextChange += $p['inventory_change_every_s'];
        }
        $this->lastGen = $now;
    }

    /** @param list<array<string, mixed>> $records */
    public function enqueue(array $records, string $expect = 'stored'): void
    {
        foreach ($records as $r) {
            $type = (string) $r['record_type'];
            $spec = \Aio\Ingest\RecordTypes::get($type);
            $ident = '';
            if ($spec !== null && $spec['kind'] === 'executable') {
                $ident = 'exe|' . $r['data']['path'] . '|' . $r['data']['size'] . '|' . $r['data']['mod_time'];
            } elseif ($spec !== null && $spec['kind'] === 'gap') {
                $ident = 'gap|' . $r['data']['reason'] . '|' . $r['data']['start'];
            }
            $this->queue[] = [
                'line' => json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'seq' => (int) $r['seq'],
                'table' => $spec['table'] ?? 'quarantine',
                'kind' => $spec['kind'] ?? 'insert',
                'ident' => $ident,
                'expect' => $spec === null ? 'quarantined' : $expect,
            ];
        }
    }

    public function queued(): int
    {
        return count($this->queue);
    }

    /**
     * Takes the next batch off the queue (1 MB or everything, AG-DAT-03).
     * With faults on, now and then the last acknowledged batch is sent again
     * (all duplicates), or a bad line is added (quarantined).
     *
     * @return string|null the gzip body, or null when there is nothing to send
     */
    public function takeBatch(int $maxBytes = 1_000_000): ?string
    {
        if ($this->inFlight === [] && $this->faults && $this->lastAcked !== [] && mt_rand(1, 200) === 1) {
            $this->inFlight = $this->lastAcked;
            $this->inFlightIsResend = true;
        } elseif ($this->inFlight === []) {
            if ($this->faults && mt_rand(1, 100) === 1) {
                $this->addFault();
            }
            $bytes = 0;
            $take = 0;
            foreach ($this->queue as $q) {
                if ($take > 0 && $bytes + strlen($q['line']) > $maxBytes) {
                    break;
                }
                $bytes += strlen($q['line']) + 1;
                $take++;
            }
            if ($take === 0) {
                return null;
            }
            $this->inFlight = array_splice($this->queue, 0, $take);
            $this->inFlightIsResend = false;
        }
        return gzencode(implode("\n", array_column($this->inFlight, 'line')) . "\n", 6);
    }

    private function addFault(): void
    {
        $f = $this->factory;
        $now = microtime(true);
        switch (mt_rand(1, 3)) {
            case 1:
                $r = $f->envelope('net_flow', ['proto' => 'tcp'], $now); // missing_field:family
                $this->enqueue([$r], 'quarantined');
                break;
            case 2:
                $this->enqueue([$f->envelope('sim_unknown_type', ['x' => 1], $now)]);
                break;
            default:
                $seq = $f->nextSeq();
                $f->envelope('etw_status', [], $now); // burn the seq
                $this->queue[] = ['line' => '{"schema_version":1,"seq":' . $seq . ',"broken', 'seq' => $seq, 'table' => 'quarantine', 'kind' => 'insert', 'ident' => '', 'expect' => 'quarantined'];
        }
    }

    /**
     * A 200 for the batch in flight: those lines are done (deleted, AG-DAT-04).
     *
     * @param array<string, mixed> $resp
     * @return list<string> problems found in the answer
     */
    public function acked(array $resp): array
    {
        $problems = [];
        $lines = $this->inFlight;
        $expStored = 0;
        $expQuar = 0;
        foreach ($lines as $l) {
            $l['expect'] === 'quarantined' ? $expQuar++ : $expStored++;
        }
        if ($this->inFlightIsResend) {
            if (($resp['duplicates'] ?? -1) + ($resp['quarantined'] ?? 0) !== count($lines) || ($resp['stored'] ?? -1) !== 0) {
                $problems[] = 'resend not all duplicates: ' . json_encode($resp);
            }
        } else {
            $maxSeq = max(array_column($lines, 'seq'));
            if (($resp['acked_seq'] ?? null) !== $maxSeq) {
                $problems[] = "acked_seq {$resp['acked_seq']} != highest sent $maxSeq";
            }
            // Executables and gaps of a file version or gap already held count as duplicates.
            $expDup = 0;
            foreach ($lines as $l) {
                if ($l['ident'] !== '' && isset($this->idents[$l['ident']])) {
                    $expDup++;
                }
            }
            if (($resp['quarantined'] ?? -1) !== $expQuar || ($resp['stored'] ?? -1) + ($resp['duplicates'] ?? 0) !== $expStored) {
                $problems[] = "counts: expected stored+dup $expStored (dup $expDup), quarantined $expQuar; got " . json_encode($resp);
            }
            foreach ($lines as $l) {
                if ($l['expect'] === 'quarantined') {
                    $this->expectRows['quarantine'] = ($this->expectRows['quarantine'] ?? 0) + 1;
                    continue;
                }
                if ($l['ident'] !== '') {
                    if (isset($this->idents[$l['ident']])) {
                        continue;
                    }
                    $this->idents[$l['ident']] = true;
                }
                $this->expectRows[$l['table']] = ($this->expectRows[$l['table']] ?? 0) + 1;
                if ($l['table'] === 'process_events' && str_contains($l['line'], '"command_line":')) {
                    $this->expectRows['process_command_lines'] = ($this->expectRows['process_command_lines'] ?? 0) + 1;
                }
            }
            $this->ackedLines += count($lines);
            $this->lastAcked = $lines;
        }
        $this->mismatches += count($problems);
        $this->inFlight = [];
        $this->inFlightIsResend = false;
        $this->attempt = 0;
        return $problems;
    }

    /** Not acknowledged: keep the lines, back off (ingest-api.md §8.1 rules 2, 3). */
    public function failed(float $now, ?int $retryAfter, bool $split = false): void
    {
        if ($split && count($this->inFlight) > 1) {
            // 413: send each half on its own (rule 6).
            $half = intdiv(count($this->inFlight), 2);
            $back = array_splice($this->inFlight, $half);
            array_splice($this->queue, 0, 0, $back);
            $this->nextSend = $now;
            return;
        }
        if ($this->inFlightIsResend) {
            $this->inFlight = [];
            $this->inFlightIsResend = false;
        }
        $this->attempt++;
        $cap = min(300, 2 * (2 ** min($this->attempt, 10)));
        $wait = (mt_rand() / mt_getrandmax()) * $cap;
        if ($retryAfter !== null) {
            $wait = max($wait, min(3600, $retryAfter));
        }
        $this->nextSend = $now + $wait;
    }

    public function scheduleNext(float $now): void
    {
        // A backlog drains back to back (rule 5); otherwise every interval.
        $this->nextSend = $this->queued() > 2000 ? $now : $now + $this->batchIntervalS;
    }

    /** @return array<string, mixed> */
    public function heartbeatBody(float $now): array
    {
        return [
            'agent_time' => RecordFactory::ts($now),
            'uptime_s' => 3600,
            'queue' => ['records' => $this->queued(), 'bytes' => array_sum(array_map(static fn ($q) => strlen($q['line']), $this->queue)), 'cap_bytes' => 524_288_000, 'oldest_ts' => null, 'dropped_records' => 0, 'dropped_bytes' => 0],
            'transport' => ['last_acked_seq' => null, 'consecutive_failures' => $this->attempt, 'last_error' => null],
            'health' => ['etw' => 'running', 'priority_class' => 'below_normal', 'open_coverage_gap' => false],
            'config_etag' => '"cfg-sim-1"',
            'cert_not_after' => '2027-11-19T00:00:00Z',
        ];
    }

    /** @return array<string, mixed> the state file, and what reconcile.php checks */
    public function summary(): array
    {
        return [
            'agent_id' => $this->id['agent_id'],
            'next_seq' => $this->factory->nextSeq(),
            'exe_counter' => $this->exeCounter,
            'acked_lines' => $this->ackedLines,
            'queued' => $this->queued() + count($this->inFlight),
            'mismatches' => $this->mismatches,
            'expect_rows' => $this->expectRows,
        ];
    }
}
