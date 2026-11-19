<?php

declare(strict_types=1);

namespace Aio\Heartbeat;

use Aio\Auth\AgentContext;
use Aio\Config;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Util\Time;
use PDO;

/**
 * POST /api/v1/heartbeat (ingest-api.md §4; MS-ING-12, MS-ING-08, AG-PRF-06, M-006).
 *
 * The body says how the agent is doing now; it has no agent_id (the
 * certificate says who it is). Every field is optional and type-checked;
 * a field of the wrong type is stored as NULL in its column, and the whole
 * body, unknown fields included, is kept in agent_heartbeats.body.
 */
final class HeartbeatService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
    ) {
    }

    /** @throws ApiError */
    public function handle(Request $req, AgentContext $agent): Response
    {
        $ct = strtolower(trim(explode(';', (string) $req->header('content-type'))[0]));
        if ($ct !== 'application/json') {
            throw ApiError::unsupportedMediaType('Content-Type must be application/json');
        }
        $max = $this->config->int('heartbeat', 'max_bytes');
        $raw = $req->body($max);
        if (strlen($raw) > $max) {
            throw new ApiError(413, 'batch_too_large', "Heartbeat over $max bytes", false);
        }
        try {
            $hb = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::invalidJson();
        }
        if (!$hb instanceof \stdClass) {
            throw ApiError::invalidJson();
        }
        $now = microtime(true);
        $nowDb = Time::nowDb($now);

        $agentTime = self::time($hb->agent_time ?? null);
        $skewMs = null;
        if ($agentTime !== null) {
            $t = Time::parse((string) $hb->agent_time);
            if ($t !== null && !$t['zero']) {
                $frac = (float) ('0.' . substr($t['db'], 20));
                $skewMs = (int) round((($t['epoch'] + $frac) - $now) * 1000);
                $skewMs = max(-2_147_483_648, min(2_147_483_647, $skewMs));
            }
        }
        $queue = isset($hb->queue) && $hb->queue instanceof \stdClass ? $hb->queue : new \stdClass();
        $transport = isset($hb->transport) && $hb->transport instanceof \stdClass ? $hb->transport : new \stdClass();
        $qRecords = self::uint($queue->records ?? null);
        $qBytes = self::uint($queue->bytes ?? null);
        $qOldest = self::time($queue->oldest_ts ?? null);
        $qDropped = self::uint($queue->dropped_records ?? null);
        $lastError = isset($transport->last_error) && is_string($transport->last_error) ? mb_substr($transport->last_error, 0, 64) : null;
        $etag = isset($hb->config_etag) && is_string($hb->config_etag) ? mb_substr($hb->config_etag, 0, 100) : null;
        $uptime = isset($hb->uptime_s) && (is_int($hb->uptime_s) || is_float($hb->uptime_s)) ? (float) $hb->uptime_s : null;
        $failures = self::uint($transport->consecutive_failures ?? null);
        $failures = $failures !== null && $failures > 4_294_967_295 ? null : $failures;

        $ins = $this->pdo->prepare(
            'INSERT IGNORE INTO agent_heartbeats (agent_pk, received_at, agent_time, source_ip, agent_version, uptime_s,
                    queue_records, queue_bytes, queue_oldest_ts, queue_dropped_records, last_acked_seq,
                    consecutive_failures, last_error, body)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $vals = [
            $agent->agentPk, $nowDb, $agentTime, $agent->sourceIp, $agent->version, $uptime,
            $qRecords, $qBytes, $qOldest, $qDropped, self::uint($transport->last_acked_seq ?? null),
            $failures, $lastError,
            json_encode($hb, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        ];
        foreach ($vals as $i => $v) {
            $type = match (true) {
                $v === null => PDO::PARAM_NULL,
                is_int($v) => PDO::PARAM_INT,
                $i === 3 => PDO::PARAM_LOB,
                default => PDO::PARAM_STR,
            };
            $ins->bindValue($i + 1, is_float($v) ? (string) $v : $v, $type);
        }
        $ins->execute();

        $u = $this->pdo->prepare(
            'UPDATE agents SET last_heartbeat_at = ?, clock_skew_ms = ?, queue_records = ?, queue_bytes = ?,
                    queue_oldest_ts = ?, queue_dropped_records = ?, config_etag = ? WHERE id = ?'
        );
        $u->execute([$nowDb, $skewMs, $qRecords, $qBytes, $qOldest, $qDropped, $etag, $agent->agentPk]);

        $cfg = $this->pdo->prepare('SELECT etag FROM agent_config WHERE group_id = ?');
        $cfg->execute([$agent->groupId]);
        $serverEtag = $cfg->fetchColumn();

        return new Response(200, [
            'server_time' => Time::rfc3339($now),
            'heartbeat_s' => $this->config->int('heartbeat', 'interval_s'),
            'config_etag' => $serverEtag === false ? null : (string) $serverEtag,
            // Renewal (MS-ING-11) arrives with enrolment in milestone 3.
            'renew_after' => null,
        ]);
    }

    private static function uint(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : null;
    }

    private static function time(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $t = Time::parse($v);
        return $t === null || $t['zero'] ? null : $t['db'];
    }
}
