<?php

declare(strict_types=1);

namespace Aio\Ingest;

use Aio\Ingest\Schema\TableMap;
use PDO;
use PDOStatement;

/**
 * Writes one request's rows inside the caller's transaction (MS-ING-03, 04).
 *
 * - Record tables: multi-row INSERT IGNORE on the (agent, seq, ts) key, so a
 *   resent line is dropped and counted as a duplicate (M-005). Any warning
 *   other than a duplicate key fails the batch, so IGNORE can never hide a
 *   truncation or a bad value.
 * - executables and coverage_gaps: one row per natural key, updated in place
 *   only by a newer record (M-014, M-015); a resend changes nothing and
 *   counts as a duplicate.
 * - Lines below the agent's max_seq_held go in their own statements, so the
 *   seq rollback check (§3.6) counts only lines that were really new.
 */
final class Writer
{
    private const BYTES_PER_STATEMENT = 4_000_000;

    /** @var array<string, list<array{row: array<string, mixed>, bytes: int}>> */
    private array $pending = [];

    /** @var array<string, int> */
    private array $pendingBytes = [];

    /** @var array<string, PDOStatement> */
    private array $stmtCache = [];

    public int $stored = 0;
    public int $duplicates = 0;
    public int $quarantined = 0;
    /** Lines below max_seq_held that were stored (not duplicates): the rollback signal (§3.6). */
    public int $belowStored = 0;
    public int $belowAgentStartsStored = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $agentPk,
        private readonly int $batchPk,
        private readonly string $receivedAt,
        private readonly int $maxSeqHeld,
        private readonly int $rowsPerStatement,
        private readonly int $quarantineKeepBytes,
    ) {
    }

    public function add(BuiltRow $r): void
    {
        $below = $r->line->seq < $this->maxSeqHeld;
        if ($r->kind === 'executable' || $r->kind === 'gap') {
            $changed = $r->kind === 'executable' ? $this->upsertExecutable($r->row) : $this->upsertGap($r->row);
            $changed ? $this->stored++ : $this->duplicates++;
            if ($changed && $below) {
                $this->belowStored++;
            }
            return;
        }
        $group = $r->table . ($below ? '#below' : '') . ($below && $r->line->recordType === 'agent_start' ? '#start' : '');
        $this->queue($group, $r->table, $r->row, strlen($r->line->raw));
        if ($r->commandLine !== null) {
            // Command lines live apart (M-011); they follow their event's outcome.
            $this->queue('#cmd:' . $group, 'process_command_lines', [
                'agent_pk' => $r->row['agent_pk'],
                'host_pk' => $r->row['host_pk'],
                'seq' => $r->row['seq'],
                'ts' => $r->row['ts'],
                'command_line' => $r->commandLine,
            ], strlen($r->commandLine));
        }
    }

    public function addQuarantine(QuarantinedLine $q): void
    {
        $raw = $q->raw;
        $keep = $q->reason === 'line_too_long' ? substr($raw, 0, $this->quarantineKeepBytes) : $raw;
        $this->queue('#quarantine', 'quarantine', [
            'received_day' => substr($this->receivedAt, 0, 10),
            'received_at' => $this->receivedAt,
            'agent_pk' => $this->agentPk,
            'batch_pk' => $this->batchPk,
            'seq' => $q->seq,
            'record_type' => $q->recordType,
            'schema_version' => $q->schemaVersion,
            'reason' => mb_substr($q->reason, 0, 200),
            'line_sha256' => hash('sha256', $raw, true),
            'line_bytes' => strlen($raw),
            'line' => $keep,
        ], strlen($keep));
    }

    /** Writes everything still queued. */
    public function finish(): void
    {
        foreach (array_keys($this->pending) as $group) {
            $this->flush($group);
        }
    }

    /** @param array<string, mixed> $row */
    private function queue(string $group, string $table, array $row, int $bytes): void
    {
        $key = $table . '|' . $group;
        $this->pending[$key][] = ['row' => $row, 'bytes' => $bytes];
        $this->pendingBytes[$key] = ($this->pendingBytes[$key] ?? 0) + $bytes;
        if (count($this->pending[$key]) >= $this->rowsPerStatement || $this->pendingBytes[$key] >= self::BYTES_PER_STATEMENT) {
            $this->flush($key);
        }
    }

    private function flush(string $key): void
    {
        $items = $this->pending[$key] ?? [];
        unset($this->pending[$key], $this->pendingBytes[$key]);
        if ($items === []) {
            return;
        }
        [$table, $group] = explode('|', $key, 2);
        $rows = array_column($items, 'row');
        $inserted = $this->insertIgnore($table, $rows);
        $n = count($rows);
        if ($table === 'quarantine') {
            // A quarantined line sent again the same day is kept once (§3.5);
            // either way the line is held, so it counts as quarantined.
            $this->quarantined += $n;
            return;
        }
        if ($table === 'process_command_lines') {
            return; // counted with their process_events rows
        }
        $this->stored += $inserted;
        $this->duplicates += $n - $inserted;
        if (str_contains($group, '#below')) {
            $this->belowStored += $inserted;
            if (str_contains($group, '#start')) {
                $this->belowAgentStartsStored += $inserted;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return int rows inserted (the rest were duplicates)
     */
    private function insertIgnore(string $table, array $rows): int
    {
        $cols = [];
        foreach ($rows as $row) {
            $cols += array_fill_keys(array_keys($row), true);
        }
        $cols = array_keys($cols);
        $meta = TableMap::columns($table);
        $sig = 'ii|' . $table . '|' . implode(',', $cols) . '|' . count($rows);
        $stmt = $this->stmtCache[$sig] ??= $this->pdo->prepare(
            'INSERT IGNORE INTO `' . $table . '` (' . self::colList($cols) . ') VALUES '
            . implode(',', array_fill(0, count($rows), '(' . implode(',', array_fill(0, count($cols), '?')) . ')'))
        );
        $i = 1;
        foreach ($rows as $row) {
            foreach ($cols as $c) {
                self::bind($stmt, $i++, $row[$c] ?? null, $meta[$c]['type']);
            }
        }
        $stmt->execute();
        $inserted = $stmt->rowCount();
        $this->checkWarnings();
        return $inserted;
    }

    /**
     * executables: one row per host and file version (M-014). A newer record
     * (higher seq) replaces the file details; an older or resent one only
     * widens first_seen/first_seq.
     *
     * @param array<string, mixed> $row
     * @return bool true when the row was inserted or changed
     */
    private function upsertExecutable(array $row): bool
    {
        $identity = ['host_pk', 'path', 'size', 'mod_time'];
        return $this->upsert('executables', $row, $identity, false, ['first_seen' => 'LEAST', 'first_seq' => 'LEAST', 'last_seen' => 'GREATEST']);
    }

    /**
     * coverage_gaps: the open and the close record of one gap land on one row
     * (M-015); a newer record fills in what it adds (the end, the counts).
     *
     * @param array<string, mixed> $row
     */
    private function upsertGap(array $row): bool
    {
        $identity = ['agent_pk', 'reason', 'gap_start'];
        return $this->upsert('coverage_gaps', $row, $identity, true, ['first_ts' => 'LEAST', 'first_seq' => 'LEAST', 'last_ts' => 'GREATEST']);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $identity
     * @param array<string, string> $minMax
     */
    private function upsert(string $table, array $row, array $identity, bool $keepOld, array $minMax): bool
    {
        $meta = TableMap::columns($table);
        $cols = [];
        foreach ($meta as $c => $m) {
            if (!$m['generated'] && $c !== 'id') {
                $cols[] = $c;
            }
        }
        $sig = 'up|' . $table;
        if (!isset($this->stmtCache[$sig])) {
            $set = [];
            foreach ($cols as $c) {
                if (in_array($c, $identity, true) || $c === 'last_seq' || isset($minMax[$c])) {
                    continue;
                }
                $new = $keepOld ? "COALESCE(VALUES(`$c`), `$c`)" : "VALUES(`$c`)";
                $set[] = "`$c` = IF(VALUES(`last_seq`) > `last_seq`, $new, `$c`)";
            }
            foreach ($minMax as $c => $fn) {
                $set[] = "`$c` = $fn(`$c`, VALUES(`$c`))";
            }
            // last_seq last: the IF()s above compare against its old value.
            $set[] = '`last_seq` = GREATEST(`last_seq`, VALUES(`last_seq`))';
            $this->stmtCache[$sig] = $this->pdo->prepare(
                'INSERT INTO `' . $table . '` (' . self::colList($cols) . ') VALUES ('
                . implode(',', array_fill(0, count($cols), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $set)
            );
        }
        $stmt = $this->stmtCache[$sig];
        $i = 1;
        foreach ($cols as $c) {
            self::bind($stmt, $i++, $row[$c] ?? null, $meta[$c]['type']);
        }
        $stmt->execute();
        $this->checkWarnings();
        // 1 inserted, 2 updated, 0 found but unchanged (a resend).
        return $stmt->rowCount() > 0;
    }

    /** Any warning except a duplicate key (1062) fails the batch (M-005). */
    private function checkWarnings(): void
    {
        $count = (int) $this->pdo->query('SELECT @@warning_count')->fetchColumn();
        if ($count === 0) {
            return;
        }
        foreach ($this->pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_NUM) as $w) {
            if ((int) $w[1] !== 1062) {
                throw new WriteWarning("MariaDB warning {$w[1]} during ingest");
            }
        }
    }

    private static function bind(PDOStatement $stmt, int $i, mixed $v, string $type): void
    {
        if ($v === null) {
            $stmt->bindValue($i, null, PDO::PARAM_NULL);
        } elseif (is_int($v)) {
            $stmt->bindValue($i, $v, PDO::PARAM_INT);
        } elseif (in_array($type, ['binary', 'varbinary', 'blob', 'mediumblob', 'longblob'], true)) {
            $stmt->bindValue($i, $v, PDO::PARAM_LOB);
        } else {
            $stmt->bindValue($i, (string) $v, PDO::PARAM_STR);
        }
    }

    /** @param list<string> $cols */
    private static function colList(array $cols): string
    {
        return implode(',', array_map(static fn (string $c): string => "`$c`", $cols));
    }
}
