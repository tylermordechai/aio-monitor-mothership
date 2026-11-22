<?php

declare(strict_types=1);

namespace Aio\Web;

/**
 * MS-SEC-06 sign-in lockout: after `lockout_failures` wrong passwords in a
 * row the account is locked for `lockout_minutes` (5 and 15, *(confirm)*).
 * The count starts again after a lock or a good sign-in. Pure logic, so it is
 * unit-tested; Auth stores the result in users.failed_logins / locked_until.
 */
final class Lockout
{
    public function __construct(private readonly int $maxFailures, private readonly int $minutes)
    {
    }

    public function isLocked(?int $lockedUntil, int $now): bool
    {
        return $lockedUntil !== null && $lockedUntil > $now;
    }

    /**
     * @return array{failed: int, locked_until: ?int, locked_now: bool}
     */
    public function afterFailure(int $failed, int $now): array
    {
        $failed++;
        if ($failed >= $this->maxFailures) {
            return ['failed' => 0, 'locked_until' => $now + $this->minutes * 60, 'locked_now' => true];
        }
        return ['failed' => $failed, 'locked_until' => null, 'locked_now' => false];
    }
}
