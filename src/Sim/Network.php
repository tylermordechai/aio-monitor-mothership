<?php

declare(strict_types=1);

namespace Aio\Sim;

/**
 * The simulated site (M-054), so the server map has real links: the
 * simulated hosts talk to each other, to a few unmonitored servers, to
 * printers and devices, and to the internet.
 *
 * - Simulated host N has 10.20.x.y from ipOf(N).
 * - Unmonitored servers: a domain controller and DNS (10.20.250.1), a file
 *   server (10.20.250.10) and an update server (10.20.250.11).
 * - Devices: 10.20.251.10 to .49 (printers and the like).
 * - Internet: the documentation ranges 203.0.113.0/24 and 198.51.100.0/24.
 *
 * flowPlan() gives each flow slot of a host a fixed peer, so one slot is one
 * connection across windows (D-016 combines it).
 */
final class Network
{
    public const DC = '10.20.250.1';
    public const FILES = '10.20.250.10';
    public const UPDATES = '10.20.250.11';

    /**
     * @param list<int> $servers host numbers of the simulated servers
     * @param list<int> $laptops host numbers of the simulated laptops
     */
    public function __construct(public readonly array $servers, public readonly array $laptops)
    {
    }

    public static function ipOf(int $hostNo): string
    {
        return '10.20.' . intdiv($hostNo, 250) % 250 . '.' . ($hostNo % 250 + 2);
    }

    private static function device(int $hostNo, int $slot): string
    {
        return '10.20.251.' . (10 + ($hostNo * 7 + $slot * 3) % 40);
    }

    private static function internet(int $hostNo, int $slot): string
    {
        $k = $hostNo * 13 + $slot * 5;
        return ($k % 2 === 0 ? '203.0.113.' : '198.51.100.') . (10 + $k % 200);
    }

    /**
     * The flow for slot $i of host $hostNo: protocol, local port, peer and
     * peer port, and which side started it.
     *
     * @return array{proto: string, local_port: int, remote_addr: string, remote_port: int, direction: string}
     */
    public function slot(int $hostNo, bool $server, int $i): array
    {
        $out = static fn (string $peer, int $port, string $proto = 'tcp'): array => [
            'proto' => $proto, 'local_port' => 49_800 + $i, 'remote_addr' => $peer, 'remote_port' => $port, 'direction' => 'outbound',
        ];
        $in = static fn (string $peer, int $port, int $peerNo): array => [
            'proto' => 'tcp', 'local_port' => $port, 'remote_addr' => $peer, 'remote_port' => 50_000 + ($peerNo * 37 + $i) % 15_000, 'direction' => 'inbound',
        ];
        $otherServers = array_values(array_filter($this->servers, static fn (int $n): bool => $n !== $hostNo));
        $j = $i % 20;
        if ($server) {
            if ($j <= 2) {
                // Inbound from the laptops: their side is in their own flowPlan.
                if ($this->laptops === []) {
                    return $in(self::device($hostNo, $j), 443, $hostNo + $j);
                }
                $peer = $this->laptops[$j % count($this->laptops)];
                return $in(self::ipOf($peer), [443, 443, 1433][$j], $peer);
            }
            return match ($j) {
                3 => $out(self::DC, 389),
                4 => $out(self::DC, 88),
                5 => $out(self::DC, 53, 'udp'),
                6 => $out(self::FILES, 445),
                7 => $out(self::UPDATES, 8530),
                8 => $otherServers === [] ? $out(self::FILES, 445) : $out(self::ipOf($otherServers[0]), 1433),
                9, 10, 11, 12 => $out(self::internet($hostNo, $j), 443),
                13, 14, 15 => $out(self::device($hostNo, $j), 161, 'udp'),
                16 => $out(self::internet($hostNo, $j), 80),
                default => $out(self::internet($hostNo, $j), 443),
            };
        }
        $srv = $this->servers === [] ? [self::FILES] : array_map([self::class, 'ipOf'], $this->servers);
        return match ($j) {
            0 => $out($srv[0], 443),
            1 => $out(self::DC, 389),
            4 => $out($srv[1 % count($srv)], 443),
            5 => $out(self::DC, 53, 'udp'),
            8 => $out($srv[0], 1433),
            9 => $out(self::FILES, 445),
            12 => $out(self::DC, 88),
            13 => $out($srv[1 % count($srv)], 445),
            10 => $out(self::UPDATES, 8530),
            3, 6, 7, 11, 14, 18 => $out(self::device($hostNo, $j), 9100),
            default => $out(self::internet($hostNo, $j), 443),
        };
    }
}
