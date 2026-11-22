<?php

declare(strict_types=1);

namespace Aio\Web;

use Aio\Util\Ip;
use Aio\Util\Time;
use PDO;

/**
 * MS-SEC-08 audit log: sign-ins, failed sign-ins, lockouts, sign-outs, every
 * command-line view and every account change. The dashboard's database
 * users can only INSERT here (M-053), so rows can't be changed or deleted
 * from the dashboard. Details never hold a secret (MS-SEC-10): no password,
 * no session ID, no token.
 */
final class Audit
{
    public const SIGN_IN = 'sign_in';
    public const SIGN_IN_FAILED = 'sign_in_failed';
    public const ACCOUNT_LOCKED = 'account_locked';
    public const SIGN_OUT = 'sign_out';
    public const VIEW_COMMAND_LINE = 'view_command_line';

    /** @param array<string, mixed> $details */
    public static function write(
        PDO $pdo,
        string $action,
        ?int $userId,
        ?string $username,
        string $ip,
        ?string $targetType = null,
        ?string $targetId = null,
        array $details = [],
    ): void {
        $s = $pdo->prepare(
            'INSERT INTO audit_log (at, user_id, username, source_ip, action, target_type, target_id, details)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $s->bindValue(1, Time::nowDb());
        $s->bindValue(2, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $s->bindValue(3, $username === null ? null : mb_substr($username, 0, 100));
        $bin = $ip === '' ? null : Ip::toBinary($ip);
        $s->bindValue(4, $bin, $bin === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $s->bindValue(5, $action);
        $s->bindValue(6, $targetType);
        $s->bindValue(7, $targetId === null ? null : mb_substr($targetId, 0, 255));
        $s->bindValue(8, $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $s->execute();
    }
}
