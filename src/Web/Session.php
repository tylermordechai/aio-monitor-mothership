<?php

declare(strict_types=1);

namespace Aio\Web;

/**
 * The dashboard session (MS-SEC-05). PHP's own sessions, stored as files in a
 * directory only the dashboard pool can read (setup/08), so signing in writes
 * nothing to the database except users and audit_log (M-054).
 *
 * - Cookie `__Host-aio`: Secure, HttpOnly, SameSite=Strict, Path=/, no
 *   Domain (the __Host- prefix makes the browser enforce that).
 * - A new session ID at sign-in and sign-out (no fixation).
 * - Ends after `session_idle_s` without a request or `session_max_s` after
 *   sign-in (30 min and 12 h, *(confirm)*).
 * - The session holds the user's ID and the CSRF token, never the password.
 */
final class Session
{
    public const COOKIE = '__Host-aio';

    /** MS-SEC-05: what the pool must set; the __Host- prefix also needs Secure, Path=/ and no Domain. */
    private const REQUIRED_INI = [
        'session.cookie_secure' => '1',
        'session.cookie_httponly' => '1',
        'session.cookie_samesite' => 'strict',
        'session.use_strict_mode' => '1',
        'session.use_only_cookies' => '1',
        'session.cookie_path' => '/',
        'session.cookie_domain' => '',
        'session.cookie_lifetime' => '0',
    ];

    private bool $started = false;

    public function __construct(private readonly int $idleS, private readonly int $maxS)
    {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        // The cookie flags are php_admin settings of the dashboard pool
        // (setup/templates/php-fpm-aio-dashboard.conf), which code can't
        // change; here they are only checked, and the page refuses to run
        // without them rather than hand out a weak cookie.
        foreach (self::REQUIRED_INI as $k => $want) {
            if (strtolower((string) ini_get($k)) !== $want && !($want === '1' && in_array(strtolower((string) ini_get($k)), ['on', 'true'], true))) {
                throw new \RuntimeException("session setting $k is not $want");
            }
        }
        session_name(self::COOKIE);
        session_start();
        $this->started = true;
    }

    /** The signed-in user's ID, or null (also when the session ran out, which ends it). */
    public function userId(int $now): ?int
    {
        $this->start();
        $uid = $_SESSION['uid'] ?? null;
        if (!is_int($uid)) {
            return null;
        }
        if (self::expired((int) ($_SESSION['created'] ?? 0), (int) ($_SESSION['last'] ?? 0), $now, $this->idleS, $this->maxS)) {
            $this->end();
            return null;
        }
        $_SESSION['last'] = $now;
        return $uid;
    }

    /** Pure rule, unit-tested: idle too long, or signed in too long ago. */
    public static function expired(int $created, int $last, int $now, int $idleS, int $maxS): bool
    {
        return $created <= 0 || $last <= 0 || $now - $last > $idleS || $now - $created > $maxS;
    }

    public function signIn(int $userId, int $now): void
    {
        $this->start();
        session_regenerate_id(true);
        $_SESSION = ['uid' => $userId, 'created' => $now, 'last' => $now, 'csrf' => bin2hex(random_bytes(32))];
    }

    public function end(): void
    {
        $this->start();
        $_SESSION = [];
        session_regenerate_id(true);
        session_destroy();
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        $this->started = false;
    }

    /** The CSRF token for forms; a signed-out page (sign-in) gets one too. */
    public function csrfToken(): string
    {
        $this->start();
        if (!is_string($_SESSION['csrf'] ?? null) || strlen($_SESSION['csrf']) !== 64) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** Remembered between a failed sign-in and the page that shows it, then cleared. */
    public function flash(?string $set = null): ?string
    {
        $this->start();
        if ($set !== null) {
            $_SESSION['flash'] = $set;
            return null;
        }
        $v = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_string($v) ? $v : null;
    }
}
