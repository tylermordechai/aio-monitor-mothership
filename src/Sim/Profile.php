<?php

declare(strict_types=1);

namespace Aio\Sim;

/**
 * How much one simulated host produces (M-049): a server (DEFAULT) or a
 * Windows 11 laptop (LAPTOP, M-050). These are estimates, not measurements:
 * *(confirm)* against the agent's real output on the owner's dev machine and
 * in the pilot (MPD-35). MS-DAT-06's MB per host per day depends on them.
 */
final class Profile
{
    public const DEFAULT = [
        'flows_per_window' => 20,       // net_flow records per 10 s window
        'snapshot_every_s' => 300,      // network snapshot (D-026)
        'snapshot_conns' => 40,
        'snapshot_listeners' => 12,
        'snapshot_udp' => 6,
        'process_pairs_per_min' => 2,   // process_start + process_stop
        'etw_status_every_s' => 60,
        'executable_every_s' => 300,
        'inventory_every_s' => 86_400,  // full inventory (D-044), also at start
        'inventory_apps' => 60,
        'inventory_services' => 120,
        'inventory_change_every_s' => 3600,
        'server' => 1,              // 1: Windows Server with IIS, SQL Server, roles; 0: Windows 11
    ];

    /** A Windows 11 laptop: more programs and short-lived connections, no server software. */
    public const LAPTOP = [
        'flows_per_window' => 15,
        'snapshot_conns' => 60,
        'snapshot_listeners' => 8,
        'snapshot_udp' => 10,
        'process_pairs_per_min' => 6,
        'inventory_apps' => 120,
        'inventory_services' => 250,
        'server' => 0,
    ];

    /** @param array<string, int> $over @return array<string, int> */
    public static function with(array $over, bool $laptop = false): array
    {
        $base = $laptop ? array_merge(self::DEFAULT, self::LAPTOP) : self::DEFAULT;
        return array_merge($base, array_intersect_key($over, self::DEFAULT));
    }
}
