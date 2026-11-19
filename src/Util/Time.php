<?php

declare(strict_types=1);

namespace Aio\Util;

/**
 * RFC 3339 times to MariaDB DATETIME(6), UTC (M-010).
 *
 * The agent sends up to 7 fractional digits; anything past the 6th is cut,
 * never rounded, so a resent line always maps to the same value (MS-ING-03).
 */
final class Time
{
    private const RE = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?([Zz]|[+-]\d{2}:\d{2})$/';

    /**
     * Parses an RFC 3339 time.
     *
     * @param bool $requireZ the envelope `ts` must be UTC with a `Z` (ingest-api.md §3.4 rule 4)
     * @return array{db: string, epoch: int, zero: bool}|null null when it isn't a valid time;
     *         `zero` is true for Go's zero time and anything before year 1000, which
     *         DATETIME can't hold and which ingest stores as NULL
     */
    public static function parse(string $s, bool $requireZ = false): ?array
    {
        if (!preg_match(self::RE, $s, $m)) {
            return null;
        }
        [, $y, $mo, $d, $h, $mi, $sec] = $m;
        $frac = $m[7] ?? '';
        $zone = $m[8];
        if ($requireZ && $zone !== 'Z' && $zone !== 'z') {
            return null;
        }
        if (!checkdate((int) $mo, (int) $d, (int) $y) || (int) $h > 23 || (int) $mi > 59 || (int) $sec > 59) {
            return null;
        }
        $micro = str_pad(substr($frac, 0, 6), 6, '0');
        $epoch = gmmktime((int) $h, (int) $mi, (int) $sec, (int) $mo, (int) $d, (int) $y);
        if ($zone !== 'Z' && $zone !== 'z') {
            $sign = $zone[0] === '-' ? 1 : -1;
            $offH = (int) substr($zone, 1, 2);
            $offM = (int) substr($zone, 4, 2);
            if ($offH > 23 || $offM > 59) {
                return null;
            }
            $epoch += $sign * ($offH * 3600 + $offM * 60);
        }
        $year = (int) gmdate('Y', $epoch);
        if ($year < 1000 || (int) $y < 1000) {
            return ['db' => '', 'epoch' => $epoch, 'zero' => true];
        }
        if ($year > 9999) {
            return null;
        }
        return ['db' => gmdate('Y-m-d H:i:s', $epoch) . '.' . $micro, 'epoch' => $epoch, 'zero' => false];
    }

    /** The current UTC time as DATETIME(6). */
    public static function nowDb(?float $now = null): string
    {
        $now ??= microtime(true);
        $sec = (int) floor($now);
        $micro = (int) round(($now - $sec) * 1_000_000);
        if ($micro >= 1_000_000) {
            $sec++;
            $micro -= 1_000_000;
        }
        return gmdate('Y-m-d H:i:s', $sec) . sprintf('.%06d', $micro);
    }

    /** RFC 3339 with a Z, whole seconds, for responses. */
    public static function rfc3339(?float $now = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) ($now ?? time()));
    }
}
