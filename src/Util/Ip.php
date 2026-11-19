<?php

declare(strict_types=1);

namespace Aio\Util;

/** IP address text to the VARBINARY(16) form INET6_ATON gives (M-010). */
final class Ip
{
    /**
     * @return string|null 4 bytes for IPv4, 16 for IPv6, null if it doesn't parse.
     *         An IPv6 zone (`fe80::1%12`) and brackets are ignored, as INET6_ATON would need.
     */
    public static function toBinary(string $addr): ?string
    {
        $a = trim($addr);
        if ($a === '') {
            return null;
        }
        if ($a[0] === '[' && str_ends_with($a, ']')) {
            $a = substr($a, 1, -1);
        }
        $pct = strpos($a, '%');
        if ($pct !== false) {
            $a = substr($a, 0, $pct);
        }
        $bin = @inet_pton($a);
        return $bin === false ? null : $bin;
    }
}
