<?php

declare(strict_types=1);

namespace Aio\Dashboard;

use Aio\Config;
use PDO;

/**
 * The fleet (MS-UI-01): every host that isn't retired, its active agent, last
 * check-in, version, OS and coverage gaps in the last 24 hours. A host is
 * "stale" when its agent hasn't checked in for `stale_after_s` (5 minutes,
 * *(confirm)*, MS-ALR-05), and "no agent" when none is active (revoked or
 * replaced).
 */
final class FleetQuery
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    /**
     * @return list<array{host_pk: int, hostname: string, group: string, os: ?string, kind: string,
     *     version: ?string, last_checkin: ?string, ago_s: ?int, status: string, queue_records: ?int,
     *     gaps_24h: int, gaps_open: int, gap_reasons: list<string>}>
     */
    public function hosts(?int $now = null): array
    {
        $now ??= time();
        $stale = $this->config->int('dashboard', 'stale_after_s');
        $rows = $this->pdo->query(
            "SELECT h.id AS host_pk, h.hostname, g.name AS grp,
                    a.id AS agent_pk, a.last_checkin_at, UNIX_TIMESTAMP(a.last_checkin_at) AS checkin_epoch,
                    a.last_version, a.queue_records,
                    (SELECT f.os_product_name FROM host_facts f WHERE f.host_pk = h.id ORDER BY f.ts DESC LIMIT 1) AS os
               FROM hosts h
               JOIN host_groups g ON g.id = h.group_id
               LEFT JOIN agents a ON a.host_pk = h.id AND a.status = 'active'
              WHERE h.retired_at IS NULL
              ORDER BY h.hostname, a.id"
        )->fetchAll();
        $gaps = [];
        $g = $this->pdo->prepare(
            "SELECT host_pk, COUNT(*) AS n, SUM(gap_end IS NULL) AS open_n, GROUP_CONCAT(DISTINCT reason ORDER BY reason) AS reasons
               FROM coverage_gaps
              WHERE gap_start <= FROM_UNIXTIME(?) AND (gap_end IS NULL OR gap_end >= FROM_UNIXTIME(?))
              GROUP BY host_pk"
        );
        $g->execute([$now, $now - 86_400]);
        foreach ($g as $r) {
            $gaps[(int) $r['host_pk']] = $r;
        }
        $out = [];
        foreach ($rows as $r) {
            $pk = (int) $r['host_pk'];
            if (isset($out[$pk])) {
                continue; // one active agent per host; keep the first
            }
            $ago = $r['checkin_epoch'] === null ? null : max(0, $now - (int) $r['checkin_epoch']);
            $status = match (true) {
                $r['agent_pk'] === null => 'no_agent',
                $ago === null || $ago > $stale => 'stale',
                default => 'ok',
            };
            $os = $r['os'] === null ? null : (string) $r['os'];
            $gp = $gaps[$pk] ?? null;
            $out[$pk] = [
                'host_pk' => $pk,
                'hostname' => (string) $r['hostname'],
                'group' => (string) $r['grp'],
                'os' => $os,
                'kind' => $os !== null && stripos($os, 'server') === false ? 'workstation' : 'server',
                'version' => $r['last_version'] === null ? null : (string) $r['last_version'],
                'last_checkin' => $r['last_checkin_at'] === null ? null : substr((string) $r['last_checkin_at'], 0, 19),
                'ago_s' => $ago,
                'status' => $status,
                'queue_records' => $r['queue_records'] === null ? null : (int) $r['queue_records'],
                'gaps_24h' => $gp === null ? 0 : (int) $gp['n'],
                'gaps_open' => $gp === null ? 0 : (int) $gp['open_n'],
                'gap_reasons' => $gp === null || $gp['reasons'] === null ? [] : explode(',', (string) $gp['reasons']),
            ];
        }
        return array_values($out);
    }

    /** "3 min ago", "2 h ago", for the tables. */
    public static function ago(?int $s): string
    {
        return match (true) {
            $s === null => 'never',
            $s < 60 => $s . ' s ago',
            $s < 3600 => intdiv($s, 60) . ' min ago',
            $s < 172_800 => intdiv($s, 3600) . ' h ago',
            default => intdiv($s, 86_400) . ' days ago',
        };
    }
}
