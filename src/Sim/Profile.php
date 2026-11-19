<?php

declare(strict_types=1);

namespace Aio\Sim;

/**
 * How much one simulated server produces (M-049). These are estimates, not
 * measurements: *(confirm)* against the agent's real output on the owner's
 * dev machine and in the pilot. MS-DAT-06's MB per host per day depends on them.
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
    ];

    /** @param array<string, int> $over @return array<string, int> */
    public static function with(array $over): array
    {
        return array_merge(self::DEFAULT, array_intersect_key($over, self::DEFAULT));
    }
}
