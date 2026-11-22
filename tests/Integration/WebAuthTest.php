<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Util\Time;
use Aio\Web\Auth;
use Aio\Web\Lockout;
use PDO;
use PHPUnit\Framework\TestCase;

/** MS-SEC-06 sign-in and MS-SEC-08 audit against MariaDB (aio_test). */
final class WebAuthTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo($this);
    }

    private function user(string $role = 'viewer'): string
    {
        $name = 'u' . bin2hex(random_bytes(4));
        $this->pdo->prepare("INSERT INTO users (username, role, auth_source, password_hash, created_at) VALUES (?, ?, 'local', ?, ?)")
            ->execute([$name, $role, password_hash('a long enough password', PASSWORD_ARGON2ID), Time::nowDb()]);
        return $name;
    }

    /** @return list<array{action: string, details: ?string}> */
    private function audit(string $name): array
    {
        $s = $this->pdo->prepare('SELECT action, details FROM audit_log WHERE target_id = ? OR username = ? ORDER BY id');
        $s->execute([$name, $name]);
        return $s->fetchAll();
    }

    public function testMsSec06GoodPasswordSignsInAndIsAudited(): void
    {
        $name = $this->user('analyst');
        $r = (new Auth($this->pdo, new Lockout(5, 15)))->attempt($name, 'a long enough password', '192.168.0.50');
        self::assertTrue($r['ok']);
        self::assertSame('analyst', $r['user']['role']);
        self::assertArrayNotHasKey('password_hash', $r['user']);
        self::assertSame(['sign_in'], array_column($this->audit($name), 'action'));
    }

    public function testMsSec06FiveFailuresLockTheAccountEvenForTheRightPassword(): void
    {
        $name = $this->user();
        $auth = new Auth($this->pdo, new Lockout(5, 15));
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('bad_password', $auth->attempt($name, 'wrong password ' . $i, '192.168.0.50')['reason']);
        }
        $r = $auth->attempt($name, 'a long enough password', '192.168.0.50');
        self::assertFalse($r['ok']);
        self::assertSame('locked', $r['reason']);
        $actions = array_column($this->audit($name), 'action');
        self::assertSame(1, count(array_keys($actions, 'account_locked', true)));
        self::assertSame(6, count(array_keys($actions, 'sign_in_failed', true)));
        foreach ($this->audit($name) as $row) {
            self::assertStringNotContainsString('wrong password', (string) $row['details'], 'MS-SEC-10: no password in the audit log');
        }
        // tools/users.php unlock does this:
        $this->pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE username = ?')->execute([$name]);
        self::assertTrue($auth->attempt($name, 'a long enough password', '192.168.0.50')['ok']);
    }

    public function testMsSec10UnknownUserIsNotStored(): void
    {
        $typo = 'my secret password typed in the wrong box';
        $r = (new Auth($this->pdo, new Lockout(5, 15)))->attempt($typo, 'x', '192.168.0.50');
        self::assertSame('unknown_user', $r['reason']);
        $n = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE username = ? OR target_id = ? OR details LIKE ?');
        $n->execute([$typo, $typo, '%secret%']);
        self::assertSame(0, (int) $n->fetchColumn());
    }

    public function testDisabledUserCannotSignInAndLoadDropsThem(): void
    {
        $name = $this->user('admin');
        $auth = new Auth($this->pdo, new Lockout(5, 15));
        $id = (int) $auth->attempt($name, 'a long enough password', '192.168.0.50')['user']['id'];
        self::assertSame('admin', $auth->load($id)['role']);
        $this->pdo->prepare('UPDATE users SET disabled_at = ? WHERE id = ?')->execute([Time::nowDb(), $id]);
        self::assertNull($auth->load($id), 'a disabled user is signed out at the next request');
        self::assertSame('disabled', $auth->attempt($name, 'a long enough password', '192.168.0.50')['reason']);
    }
}
