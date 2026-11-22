<?php

declare(strict_types=1);

namespace Aio\Dashboard;

/**
 * Sorts a remote address for the server map (MS-UI-01, M-054): internal
 * (inside `dashboard.internal_networks`), internet, or local-only (loopback,
 * unspecified, multicast, broadcast: not drawn). Whether an internal address
 * is a monitored host is decided by MapQuery from the hosts' own addresses.
 */
final class AddressClass
{
    public const INTERNAL = 'internal';
    public const INTERNET = 'internet';
    public const LOCAL = 'local';

    /** @var list<array{0: string, 1: int}> network (packed) and prefix length */
    private array $nets = [];

    public function __construct(string $cidrs)
    {
        foreach (explode(',', $cidrs) as $c) {
            $c = trim($c);
            if ($c === '' || !str_contains($c, '/')) {
                continue;
            }
            [$ip, $len] = explode('/', $c, 2);
            $bin = @inet_pton(trim($ip));
            if ($bin === false || !ctype_digit(trim($len)) || (int) $len > strlen($bin) * 8) {
                throw new \InvalidArgumentException("bad network in dashboard.internal_networks: $c");
            }
            $this->nets[] = [$bin, (int) $len];
        }
    }

    public function classify(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return self::INTERNET;
        }
        if (self::isLocalOnly($bin)) {
            return self::LOCAL;
        }
        foreach ($this->nets as [$net, $len]) {
            if (strlen($net) === strlen($bin) && self::inPrefix($bin, $net, $len)) {
                return self::INTERNAL;
            }
        }
        return self::INTERNET;
    }

    /** A packed address (VARBINARY(16) columns) as text, or null. */
    public static function text(?string $bin): ?string
    {
        if ($bin === null || (strlen($bin) !== 4 && strlen($bin) !== 16)) {
            return null;
        }
        $t = @inet_ntop($bin);
        return $t === false ? null : $t;
    }

    private static function inPrefix(string $bin, string $net, int $len): bool
    {
        $bytes = intdiv($len, 8);
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $bits = $len % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $bits)) & 0xFF;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private static function isLocalOnly(string $bin): bool
    {
        if (strlen($bin) === 4) {
            $a = ord($bin[0]);
            return $a === 127 || $a === 0 || $a >= 224;   // loopback, "this network", multicast, reserved, broadcast
        }
        if ($bin === str_repeat("\0", 15) . "\1" || $bin === str_repeat("\0", 16)) {
            return true;                                    // ::1, ::
        }
        return ord($bin[0]) === 0xFF;                       // ff00::/8 multicast
    }
}
