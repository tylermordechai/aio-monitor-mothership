<?php

declare(strict_types=1);

namespace Aio\Web;

use Aio\Util\Time;
use PDO;

/**
 * MS-SEC-06 local sign-in: Argon2id password hashes (password_hash), lockout
 * after repeated failures, one answer for every failure so the sign-in page
 * never tells whether an account exists. Built so Entra ID or AD sign-in can
 * sit beside it later (users.auth_source).
 *
 * Every attempt is audited (MS-SEC-08). A username that matches no account
 * is not stored, in case someone typed their password into the user box
 * (MS-SEC-10).
 */
final class Auth
{
    /** A real Argon2id hash of a random string, so an unknown user costs the same time as a wrong password. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$T3d6VzM1ZmxCcGw4N3I3ZQ$lKKip8tzn/m94pbWy/V8oR03zGubZjzAaunxzlor66I';

    public function __construct(private readonly PDO $pdo, private readonly Lockout $lockout)
    {
    }

    /**
     * @return array{ok: bool, user: ?array<string, mixed>, reason: string}
     *         reason: ok, unknown_user, disabled, locked, bad_password
     */
    public function attempt(string $username, string $password, string $ip): array
    {
        $now = time();
        $username = trim($username);
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare(
                "SELECT id, username, display_name, role, password_hash, failed_logins, disabled_at,
                        UNIX_TIMESTAMP(locked_until) AS locked_until
                 FROM users WHERE username = ? AND auth_source = 'local' FOR UPDATE"
            );
            $s->execute([$username]);
            $u = $s->fetch() ?: null;
            $hash = is_array($u) && is_string($u['password_hash']) && $u['password_hash'] !== '' ? $u['password_hash'] : self::DUMMY_HASH;
            $good = password_verify($password, $hash);

            if ($u === null) {
                Audit::write($this->pdo, Audit::SIGN_IN_FAILED, null, null, $ip, null, null, ['reason' => 'unknown_user']);
                $this->pdo->commit();
                return ['ok' => false, 'user' => null, 'reason' => 'unknown_user'];
            }
            $id = (int) $u['id'];
            $lockedUntil = $u['locked_until'] === null ? null : (int) $u['locked_until'];
            $reason = match (true) {
                $u['disabled_at'] !== null => 'disabled',
                $this->lockout->isLocked($lockedUntil, $now) => 'locked',
                $u['password_hash'] === null || !$good => 'bad_password',
                default => 'ok',
            };

            if ($reason === 'ok') {
                $upd = $this->pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = ? WHERE id = ?');
                $upd->execute([Time::nowDb(), $id]);
                if (password_needs_rehash($hash, PASSWORD_ARGON2ID)) {
                    $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_ARGON2ID), $id]);
                }
                Audit::write($this->pdo, Audit::SIGN_IN, $id, $u['username'], $ip);
                $this->pdo->commit();
                unset($u['password_hash'], $u['failed_logins'], $u['locked_until'], $u['disabled_at']);
                return ['ok' => true, 'user' => $u, 'reason' => 'ok'];
            }

            if ($reason === 'bad_password') {
                $r = $this->lockout->afterFailure((int) $u['failed_logins'], $now);
                $upd = $this->pdo->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?');
                $upd->execute([$r['failed'], $r['locked_until'] === null ? null : gmdate('Y-m-d H:i:s', $r['locked_until']), $id]);
                Audit::write($this->pdo, Audit::SIGN_IN_FAILED, $id, $u['username'], $ip, 'user', $u['username'], ['reason' => 'bad_password']);
                if ($r['locked_now']) {
                    Audit::write($this->pdo, Audit::ACCOUNT_LOCKED, $id, $u['username'], $ip, 'user', $u['username'], ['until' => gmdate('Y-m-d\TH:i:s\Z', (int) $r['locked_until'])]);
                }
            } else {
                Audit::write($this->pdo, Audit::SIGN_IN_FAILED, $id, $u['username'], $ip, 'user', $u['username'], ['reason' => $reason]);
            }
            $this->pdo->commit();
            return ['ok' => false, 'user' => null, 'reason' => $reason];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The signed-in user, read again on every request so a role change, a
     * disabled account or a lock made with tools/users.php applies at once.
     *
     * @return ?array{id: int, username: string, display_name: ?string, role: string}
     */
    public function load(int $id): ?array
    {
        $s = $this->pdo->prepare("SELECT id, username, display_name, role FROM users WHERE id = ? AND disabled_at IS NULL AND auth_source = 'local'");
        $s->execute([$id]);
        $u = $s->fetch();
        if (!is_array($u) || !Roles::valid((string) $u['role'])) {
            return null;
        }
        return ['id' => (int) $u['id'], 'username' => (string) $u['username'], 'display_name' => $u['display_name'] === null ? null : (string) $u['display_name'], 'role' => (string) $u['role']];
    }

    /**
     * MS-SEC-06 password rule: at least $minLength characters *(confirm, 12)*,
     * at most 1024, not the username. Returns the problem, or null when fine.
     */
    public static function passwordProblem(string $password, string $username, int $minLength): ?string
    {
        $len = mb_strlen($password, 'UTF-8');
        if ($len < $minLength) {
            return "at least $minLength characters";
        }
        if ($len > 1024) {
            return 'at most 1024 characters';
        }
        if (strcasecmp(trim($password), trim($username)) === 0) {
            return 'not the same as the user name';
        }
        return null;
    }
}
