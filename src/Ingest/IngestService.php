<?php

declare(strict_types=1);

namespace Aio\Ingest;

use Aio\Auth\AgentContext;
use Aio\Config;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Util\Time;
use PDO;

/**
 * POST /api/v1/ingest (ingest-api.md §3; MS-ING-01 to 05, 09).
 *
 * Order (§3.2): headers and sizes, inflate and split, then in ONE transaction
 * the batch row, every line (stored, duplicate or quarantined) and the agent's
 * state; the 200 with acked_seq is sent only after COMMIT (MS-ING-04).
 * One line naming another agent rolls everything back (403, MS-ING-02).
 */
final class IngestService
{
    /**
     * Where the time of the last request went, in ms: read (body, inflate,
     * split), setup (retention, lock, batch row), lines (validate and build,
     * without the database), db (the row statements), finish (agent and host
     * updates), commit. For the slow-request log and tools/bench-ingest.php.
     *
     * @var array<string, float>
     */
    public array $timings = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /** @throws ApiError */
    public function handle(Request $req, AgentContext $agent): Response
    {
        $start = microtime(true);
        $this->checkHeaders($req);
        $max = $this->config->int('ingest', 'max_compressed_bytes');
        $declared = (int) $req->header('content-length');
        if ($declared > $max) {
            throw ApiError::batchTooLarge('compressed size');
        }
        $body = $req->body($max);
        $reader = new BatchReader($this->config);
        $lines = $reader->read($body);
        $tRead = microtime(true);

        $batchId = self::batchId($req->header('x-aio-batch-id'));
        $receivedAt = Time::nowDb($start);
        $keepDays = $this->keepDays();

        $this->pdo->beginTransaction();
        try {
            $state = $this->pdo->prepare('SELECT max_seq_held FROM agents WHERE id = ? FOR UPDATE');
            $state->execute([$agent->agentPk]);
            $maxSeqHeld = (int) $state->fetchColumn();

            $batchPk = $this->insertBatch($agent, $receivedAt, $batchId, strlen($body));
            $validator = new LineValidator(
                $agent->agentId,
                $agent->hostGuid,
                $this->config->list('ingest', 'provisional_record_types'),
                $keepDays,
                $this->config->int('ingest', 'max_line_bytes'),
                $this->config->int('ingest', 'max_future_s'),
                (int) $start,
            );
            $builder = new RowBuilder($agent->agentPk, $agent->hostPk, $batchPk);
            $writer = new Writer(
                $this->pdo,
                $agent->agentPk,
                $batchPk,
                $receivedAt,
                $maxSeqHeld,
                $this->config->int('ingest', 'rows_per_insert'),
                $this->config->int('ingest', 'quarantine_keep_bytes'),
            );

            $tSetup = microtime(true);
            $minSeq = null;
            $ackedSeq = 0;
            $maxStoredSeq = 0;
            $latestName = null;
            $latestNameSeq = -1;
            foreach ($lines as $i => $raw) {
                $r = $validator->check($i, $raw);
                if ($r instanceof ParsedLine) {
                    try {
                        $writer->add($builder->build($r));
                        $maxStoredSeq = max($maxStoredSeq, $r->seq);
                        if ($r->seq > $latestNameSeq) {
                            $latestNameSeq = $r->seq;
                            $latestName = $r->hostname;
                        }
                    } catch (FieldError $e) {
                        $writer->addQuarantine(new QuarantinedLine($i, $raw, $e->getMessage(), $r->seq, $r->recordType, 1));
                    }
                    $seq = $r->seq;
                } else {
                    $writer->addQuarantine($r);
                    $seq = $r->seq;
                }
                if ($seq !== null) {
                    $ackedSeq = max($ackedSeq, $seq);
                    $minSeq = $minSeq === null ? $seq : min($minSeq, $seq);
                }
            }
            $writer->finish();
            $tLines = microtime(true);

            $regression = $writer->belowAgentStartsStored > 0
                || $writer->belowStored > $this->config->int('ingest', 'seq_regression_lines');
            $newMax = max($maxSeqHeld, $maxStoredSeq);
            $u = $this->pdo->prepare(
                'UPDATE agents SET max_seq_held = ?, last_ingest_at = ?,
                        seq_regression_at = IF(?, ?, seq_regression_at) WHERE id = ?'
            );
            $u->execute([$newMax, $receivedAt, $regression ? 1 : 0, $receivedAt, $agent->agentPk]);
            $this->touchHost($agent, $latestName, $receivedAt);

            $durationMs = (int) round((microtime(true) - $start) * 1000);
            $b = $this->pdo->prepare(
                'UPDATE ingest_batches SET bytes_raw = ?, line_count = ?, stored_count = ?, duplicate_count = ?,
                        quarantined_count = ?, min_seq = ?, max_seq = ?, duration_ms = ?
                  WHERE id = ? AND received_at = ?'
            );
            $b->execute([
                $reader->rawBytes, count($lines), $writer->stored, $writer->duplicates, $writer->quarantined,
                $minSeq, $ackedSeq === 0 ? null : $ackedSeq, $durationMs, $batchPk, $receivedAt,
            ]);
            $tFinish = microtime(true);
            $this->pdo->commit();
            $tCommit = microtime(true);
            $ms = static fn (float $a, float $b): float => round(($b - $a) * 1000, 1);
            $this->timings = [
                'read' => $ms($start, $tRead),
                'setup' => $ms($tRead, $tSetup),
                'lines' => round($ms($tSetup, $tLines) - $writer->dbSeconds * 1000, 1),
                'db' => round($writer->dbSeconds * 1000, 1),
                'finish' => $ms($tLines, $tFinish),
                'commit' => $ms($tFinish, $tCommit),
                'total' => $ms($start, $tCommit),
            ];
            $slow = $this->config->int('ingest', 'slow_log_ms');
            if ($slow > 0 && $this->timings['total'] >= $slow) {
                // Counts and timings only, never content (MS-SEC-10).
                error_log(sprintf('aio ingest slow: lines=%d %s', count($lines), http_build_query($this->timings, '', ' ')));
            }
        } catch (AgentIdMismatch) {
            $this->pdo->rollBack();
            $this->recordRefusedBatch($agent, $receivedAt, $batchId, strlen($body), 403, 'agent_id_mismatch', $start);
            throw ApiError::agentIdMismatch();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return new Response(200, [
            'acked_seq' => $ackedSeq,
            'lines' => count($lines),
            'stored' => $writer->stored,
            'duplicates' => $writer->duplicates,
            'quarantined' => $writer->quarantined,
            'max_seq_held' => $newMax,
            'batch_id' => $batchId,
        ]);
    }

    private function checkHeaders(Request $req): void
    {
        $ct = strtolower(trim(explode(';', (string) $req->header('content-type'))[0]));
        if ($ct !== 'application/x-ndjson') {
            throw ApiError::unsupportedMediaType('Content-Type must be application/x-ndjson');
        }
        if (strtolower(trim((string) $req->header('content-encoding'))) !== 'gzip') {
            throw ApiError::unsupportedMediaType('Content-Encoding must be gzip');
        }
        $len = $req->header('content-length');
        if ($len === null || !ctype_digit($len)) {
            throw ApiError::lengthRequired();
        }
    }

    private function insertBatch(AgentContext $agent, string $receivedAt, string $batchId, int $bytes): int
    {
        $s = $this->pdo->prepare(
            'INSERT INTO ingest_batches (received_at, agent_pk, batch_id, source_ip, agent_version, http_status, bytes_compressed, duration_ms)
             VALUES (?, ?, ?, ?, ?, 200, ?, 0)'
        );
        $s->bindValue(1, $receivedAt);
        $s->bindValue(2, $agent->agentPk, PDO::PARAM_INT);
        $s->bindValue(3, $batchId);
        $s->bindValue(4, $agent->sourceIp, $agent->sourceIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $s->bindValue(5, $agent->version, $agent->version === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $s->bindValue(6, $bytes, PDO::PARAM_INT);
        $s->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** A refused request still leaves a trace in ingest_batches (nothing else is stored). */
    private function recordRefusedBatch(AgentContext $agent, string $receivedAt, string $batchId, int $bytes, int $status, string $code, float $start): void
    {
        try {
            $s = $this->pdo->prepare(
                'INSERT INTO ingest_batches (received_at, agent_pk, batch_id, source_ip, agent_version, http_status, error_code, bytes_compressed, duration_ms)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $s->bindValue(1, $receivedAt);
            $s->bindValue(2, $agent->agentPk, PDO::PARAM_INT);
            $s->bindValue(3, $batchId);
            $s->bindValue(4, $agent->sourceIp, $agent->sourceIp === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $s->bindValue(5, $agent->version, $agent->version === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $s->bindValue(6, $status, PDO::PARAM_INT);
            $s->bindValue(7, $code);
            $s->bindValue(8, $bytes, PDO::PARAM_INT);
            $s->bindValue(9, (int) round((microtime(true) - $start) * 1000), PDO::PARAM_INT);
            $s->execute();
        } catch (\PDOException) {
            // Tracing only; the 403 goes out regardless.
        }
    }

    /** A rename is kept: hosts.hostname moves on, host_names keeps the history (§3.4). */
    private function touchHost(AgentContext $agent, ?string $latestName, string $now): void
    {
        $name = $latestName !== null && $latestName !== $agent->hostname && mb_strlen($latestName) <= 255 ? $latestName : null;
        $u = $this->pdo->prepare('UPDATE hosts SET last_seen = ?, hostname = COALESCE(?, hostname) WHERE id = ?');
        $u->execute([$now, $name, $agent->hostPk]);
        if ($name !== null) {
            $h = $this->pdo->prepare(
                'INSERT INTO host_names (host_pk, hostname, first_seen, last_seen) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)'
            );
            $h->execute([$agent->hostPk, $name, $now, $now]);
        }
    }

    /** @return array<string, int> */
    private function keepDays(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT table_name, keep_days FROM retention_policies') as $r) {
            $out[(string) $r['table_name']] = (int) $r['keep_days'];
        }
        return $out;
    }

    private static function batchId(?string $h): string
    {
        if ($h !== null && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $h)) {
            return $h;
        }
        return \Aio\Util\Uuid::v4();
    }
}
