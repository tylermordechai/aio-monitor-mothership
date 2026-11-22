<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Web\Auth;
use Aio\Web\Csrf;
use Aio\Web\Lockout;
use Aio\Web\Roles;
use Aio\Web\Session;
use Aio\Web\View;
use Aio\Web\WebRequest;
use PHPUnit\Framework\TestCase;

/** Sign-in, roles, CSRF and escaping rules (MS-SEC-05, 06, 07; M-054). */
final class WebSecurityTest extends TestCase
{
    public function testMsSec07RolesAreOrderedAndAdminSeesCommandLines(): void
    {
        self::assertTrue(Roles::atLeast('admin', 'analyst'));
        self::assertTrue(Roles::atLeast('analyst', 'viewer'));
        self::assertFalse(Roles::atLeast('viewer', 'analyst'));
        self::assertFalse(Roles::atLeast('root', 'viewer'), 'unknown role is never enough');
        self::assertFalse(Roles::atLeast('admin', 'superuser'));
        self::assertFalse(Roles::seesCommandLines('viewer'));
        self::assertTrue(Roles::seesCommandLines('analyst'));
        self::assertTrue(Roles::seesCommandLines('admin'));
    }

    public function testMsSec06LockoutAfterFiveFailuresForFifteenMinutes(): void
    {
        $l = new Lockout(5, 15);
        $now = 1_800_000_000;
        $failed = 0;
        for ($i = 1; $i <= 4; $i++) {
            $r = $l->afterFailure($failed, $now);
            self::assertFalse($r['locked_now']);
            self::assertNull($r['locked_until']);
            $failed = $r['failed'];
        }
        self::assertSame(4, $failed);
        $r = $l->afterFailure($failed, $now);
        self::assertTrue($r['locked_now']);
        self::assertSame($now + 900, $r['locked_until']);
        self::assertSame(0, $r['failed'], 'the count starts again after a lock');
        self::assertTrue($l->isLocked($r['locked_until'], $now + 899));
        self::assertFalse($l->isLocked($r['locked_until'], $now + 900));
        self::assertFalse($l->isLocked(null, $now));
    }

    public function testMsSec05SessionEndsWhenIdleOrTooOld(): void
    {
        $now = 1_800_000_000;
        self::assertFalse(Session::expired($now - 3600, $now - 60, $now, 1800, 43_200));
        self::assertTrue(Session::expired($now - 3600, $now - 1801, $now, 1800, 43_200), 'idle 30 min');
        self::assertTrue(Session::expired($now - 43_201, $now - 10, $now, 1800, 43_200), 'signed in over 12 h ago');
        self::assertTrue(Session::expired(0, $now, $now, 1800, 43_200), 'no sign-in time');
    }

    public function testMsSec05CsrfNeedsTheTokenAndTheSameOrigin(): void
    {
        $token = str_repeat('ab', 32);
        $req = static fn (array $post, array $headers): WebRequest => new WebRequest('POST', '/login', [], $post, $headers, '192.168.0.10', '192.168.0.191');
        self::assertTrue(Csrf::valid($req(['_csrf' => $token], []), $token));
        self::assertTrue(Csrf::valid($req(['_csrf' => $token], ['origin' => 'https://192.168.0.191', 'sec-fetch-site' => 'same-origin']), $token));
        self::assertFalse(Csrf::valid($req([], []), $token), 'no token');
        self::assertFalse(Csrf::valid($req(['_csrf' => str_repeat('cd', 32)], []), $token), 'wrong token');
        self::assertFalse(Csrf::valid($req(['_csrf' => [$token]], []), $token), 'array instead of string');
        self::assertFalse(Csrf::valid($req(['_csrf' => $token], ['origin' => 'https://evil.example']), $token), 'other origin');
        self::assertFalse(Csrf::valid($req(['_csrf' => $token], ['origin' => 'null']), $token), 'opaque origin');
        self::assertFalse(Csrf::valid($req(['_csrf' => $token], ['sec-fetch-site' => 'cross-site']), $token), 'cross-site');
        self::assertFalse(Csrf::valid($req(['_csrf' => ''], []), ''), 'no session token');
    }

    public function testMsSec05EscapingCoversHtmlAndAttributes(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', View::e('<script>alert("x")</script>'));
        self::assertSame('a&apos;b &amp; c', View::e("a'b & c"));
        self::assertSame('', View::e(null));
        self::assertSame('', View::e(['not', 'scalar']));
        self::assertSame('yes', View::e(true));
        self::assertSame("\u{FFFD}", View::e("\xC3"), 'broken UTF-8 is replaced, not passed through');
    }

    public function testMsSec06PasswordRule(): void
    {
        self::assertNull(Auth::passwordProblem('correct horse battery', 'tyler', 12));
        self::assertSame('at least 12 characters', Auth::passwordProblem('short', 'tyler', 12));
        self::assertSame('not the same as the user name', Auth::passwordProblem('administrator', 'Administrator', 12));
        self::assertNotNull(Auth::passwordProblem(str_repeat('x', 1025), 'tyler', 12));
    }

    public function testQueryAndFormValuesAreStringsOnly(): void
    {
        $r = new WebRequest('GET', '/', ['range' => ['7d'], 'ok' => '1h'], ['a' => ['b']], [], '', '');
        self::assertSame('24h', $r->q('range', '24h'));
        self::assertSame('1h', $r->q('ok'));
        self::assertSame('', $r->p('a'));
    }
}
