<?php

declare(strict_types=1);

namespace Aio\Dashboard;

/**
 * One host's agent state for the fleet, host page and map (MS-UI-01,
 * MS-ING-08, MS-ALR-05; milestone 3 gate "silent agent flagged", M-067).
 * Every authenticated request updates the check-in (ClientCertAuth), so
 * "silent" means no request of any kind:
 *
 * - no_agent: the host has no active or revoked agent;
 * - revoked:  its agent is revoked (refused, M-066);
 * - stale:    silent, no check-in for `stale_after_s` (5 minutes, (confirm));
 * - behind:   checking in, but records are queued and no batch has been
 *             stored for `stale_after_s` (ingest failing: "connected, 40 MB
 *             queued", §4);
 * - ok:       checking in.
 */
final class AgentStatus
{
    public const LABELS = [
        'ok' => 'checking in',
        'behind' => 'checking in, ingest behind',
        'stale' => 'silent',
        'revoked' => 'revoked',
        'no_agent' => 'no agent',
    ];

    public static function of(?string $agentStatus, ?int $checkinEpoch, ?int $ingestEpoch, ?int $queueRecords, int $now, int $staleAfter): string
    {
        return match (true) {
            $agentStatus === null => 'no_agent',
            $agentStatus === 'revoked' => 'revoked',
            $checkinEpoch === null || $now - $checkinEpoch > $staleAfter => 'stale',
            ($queueRecords ?? 0) > 0 && ($ingestEpoch === null || $now - $ingestEpoch > $staleAfter) => 'behind',
            default => 'ok',
        };
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }
}
