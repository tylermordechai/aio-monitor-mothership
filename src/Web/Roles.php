<?php

declare(strict_types=1);

namespace Aio\Web;

/**
 * MS-SEC-07 roles, in order: viewer < analyst < admin (M-054). Each role can
 * do everything the roles below it can, so admin also sees command lines;
 * every command-line view is still audited (MS-SEC-08).
 */
final class Roles
{
    public const VIEWER = 'viewer';
    public const ANALYST = 'analyst';
    public const ADMIN = 'admin';

    private const LEVEL = [self::VIEWER => 1, self::ANALYST => 2, self::ADMIN => 3];

    public static function valid(string $role): bool
    {
        return isset(self::LEVEL[$role]);
    }

    /** True when $role is $needed or above. An unknown role is never enough. */
    public static function atLeast(string $role, string $needed): bool
    {
        return isset(self::LEVEL[$role], self::LEVEL[$needed]) && self::LEVEL[$role] >= self::LEVEL[$needed];
    }

    /** MS-SEC-07, MS-SEC-09: who may see a command line. */
    public static function seesCommandLines(string $role): bool
    {
        return self::atLeast($role, self::ANALYST);
    }

    /** M-070: the enrolment page, tokens and the revoke button (MS-SEC-07). */
    public static function managesAgents(string $role): bool
    {
        return self::atLeast($role, self::ADMIN);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LEVEL);
    }
}
