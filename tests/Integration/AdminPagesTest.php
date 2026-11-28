<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Config;
use Aio\Enrol\EnrolToken;
use Aio\Util\Time;
use Aio\Web\App;
use Aio\Web\Session;
use Aio\Web\View;
use Aio\Web\WebRequest;
use Aio\Web\WebResponse;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The admin pages (M-070, MPD-40) through the dashboard's front controller,
 * against MariaDB (aio_test): the enrolment page, a token shown once with
 * Cache-Control: no-store, cancelling, and the host page's revoke and
 * unrevoke buttons. Admins only, CSRF on every change, audited with the
 * user's ID and address (MS-SEC-03, MS-SEC-05, MS-SEC-07, MS-SEC-08, MS-SEC-10).
 */
final class AdminPagesTest extends TestCase
{
    private const CSRF = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';
    private const IP = '192.168.0.50';

    private PDO $pdo;
    private int $crlRequests = 0;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo($this);
        $this->crlRequests = 0;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /** @return array{id: int, name: string} */
    private function signIn(string $role): array
    {
        $name = 'adm' . bin2hex(random_bytes(4));
        $this->pdo->prepare("INSERT INTO users (username, role, auth_source, password_hash, created_at) VALUES (?, ?, 'local', 'x', ?)")
            ->execute([$name, $role, Time::nowDb()]);
        $id = (int) $this->pdo->lastInsertId();
        $_SESSION = ['uid' => $id, 'created' => time(), 'last' => time(), 'csrf' => self::CSRF];
        return ['id' => $id, 'name' => $name];
    }

    private function app(): App
    {
        $pdo = $this->pdo;
        return new App(
            Config::fromArray([]),
            new View(dirname(__DIR__, 2) . '/templates'),
            new Session(1800, 43_200),
            static fn (): PDO => $pdo,
            static fn (): PDO => $pdo,
            function (): void {
                $this->crlRequests++;
            },
        );
    }

    /** @param array<string, string> $post */
    private function post(string $path, array $post, bool $csrf = true): WebResponse
    {
        $post += $csrf ? ['_csrf' => self::CSRF] : [];
        return $this->app()->handle(new WebRequest('POST', $path, [], $post, ['origin' => 'https://192.168.0.191', 'sec-fetch-site' => 'same-origin'], self::IP, '192.168.0.191'));
    }

    private function get(string $path): WebResponse
    {
        return $this->app()->handle(new WebRequest('GET', $path, [], [], [], self::IP, '192.168.0.191'));
    }

    private function tokenCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM enrollment_tokens')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action, string $targetId): array
    {
        $s = $this->pdo->prepare('SELECT user_id, username, INET6_NTOA(source_ip) AS ip, details FROM audit_log WHERE action = ? AND target_id = ? ORDER BY id');
        $s->execute([$action, $targetId]);
        return $s->fetchAll();
    }

    public function testMsSec07ViewersAndAnalystsCantSeeOrUseTheEnrolmentPage(): void
    {
        $this->signIn('viewer');
        self::assertSame(403, $this->get('/admin/enrolment')->status);
        $this->signIn('analyst');
        self::assertSame(403, $this->get('/admin/enrolment')->status);
        $before = $this->tokenCount();
        self::assertSame(403, $this->post('/admin/tokens', ['kind' => 'manual', 'group' => 'default'])->status);
        self::assertSame($before, $this->tokenCount(), 'no token made for an analyst');
        self::assertStringNotContainsString('/admin/enrolment', $this->get('/')->body, 'no Enrolment link for an analyst');
    }

    public function testMsSec03AdminMakesATokenShownOnceWithNoStoreAndAudited(): void
    {
        $u = $this->signIn('admin');
        self::assertSame(200, $this->get('/admin/enrolment')->status);
        $r = $this->post('/admin/tokens', ['kind' => 'deployment', 'days' => '3', 'group' => 'default', 'hostname' => 'SRV-NEW-01', 'note' => 'rollout']);
        self::assertSame(200, $r->status);
        self::assertSame('no-store', $r->headers['Cache-Control'] ?? null, 'M-068: the token page is never cached');
        self::assertSame(1, preg_match('/aioe_[A-Za-z0-9_-]{43}/', $r->body, $m), 'the token is on the page');
        $token = $m[0];
        $s = $this->pdo->prepare('SELECT id, token_kind, created_by, created_by_name, expected_hostname, TIMESTAMPDIFF(HOUR, created_at, expires_at) AS h FROM enrollment_tokens WHERE token_sha256 = ?');
        $s->bindValue(1, EnrolToken::hash($token), PDO::PARAM_LOB);
        $s->execute();
        $row = $s->fetch();
        self::assertIsArray($row, 'only its SHA-256 is stored');
        self::assertSame(['deployment', $u['id'], $u['name'], 'SRV-NEW-01', 72], [$row['token_kind'], (int) $row['created_by'], $row['created_by_name'], $row['expected_hostname'], (int) $row['h']]);
        $a = $this->audit('enrollment_token_created', (string) $row['id']);
        self::assertCount(1, $a);
        self::assertSame([$u['id'], $u['name'], self::IP], [(int) $a[0]['user_id'], $a[0]['username'], $a[0]['ip']]);
        self::assertStringNotContainsString($token, (string) $a[0]['details'], 'MS-SEC-10: no token in the audit log');
        $page = $this->get('/admin/enrolment')->body;
        self::assertStringNotContainsString($token, $page, 'shown once: the enrolment page never shows it');
        self::assertStringContainsString('SRV-NEW-01', $page);
    }

    public function testMsSec05TokenFormWithoutTheCsrfTokenMakesNothing(): void
    {
        $this->signIn('admin');
        $before = $this->tokenCount();
        self::assertSame(400, $this->post('/admin/tokens', ['kind' => 'manual', 'group' => 'default'], false)->status);
        self::assertSame($before, $this->tokenCount());
    }

    public function testM021ABadTokenRequestIsRefusedWithTheReasonAndNothingMade(): void
    {
        $this->signIn('admin');
        $before = $this->tokenCount();
        $r = $this->post('/admin/tokens', ['kind' => 'deployment', 'days' => '8', 'group' => 'default']);
        self::assertSame(400, $r->status);
        self::assertStringContainsString('&quot;days&quot; must be 1 to 7', $r->body);
        self::assertSame(400, $this->post('/admin/tokens', ['kind' => 'manual', 'group' => 'no-such-group'])->status);
        self::assertSame(400, $this->post('/admin/tokens', ['kind' => 'manual', 'group' => 'default', 'hostname' => 'bad name; rm'])->status);
        self::assertSame($before, $this->tokenCount());
    }

    public function testAdminCancelsAnOpenTokenAndItIsAudited(): void
    {
        $u = $this->signIn('admin');
        $this->post('/admin/tokens', ['kind' => 'manual', 'group' => 'default', 'note' => 'to cancel']);
        $id = (int) $this->pdo->query('SELECT MAX(id) FROM enrollment_tokens')->fetchColumn();
        $r = $this->post("/admin/tokens/$id/cancel", []);
        self::assertSame(303, $r->status);
        self::assertSame('/admin/enrolment', $r->headers['Location'] ?? null);
        self::assertStringContainsString("Token $id is cancelled", $this->get('/admin/enrolment')->body);
        self::assertSame([$u['id'], self::IP], [(int) $this->audit('enrollment_token_cancelled', (string) $id)[0]['user_id'], $this->audit('enrollment_token_cancelled', (string) $id)[0]['ip']]);
        $this->post("/admin/tokens/$id/cancel", []);
        self::assertStringContainsString('already cancelled', $this->get('/admin/enrolment')->body);
        self::assertCount(1, $this->audit('enrollment_token_cancelled', (string) $id));
    }

    public function testMsSec02RevokeAndUnrevokeButtonsAreAuditedAndAskForACrlRebuild(): void
    {
        $a = TestDb::agent($this->pdo, 'SIM-ADMIN');
        $u = $this->signIn('admin');
        $page = $this->get('/hosts/' . $a['host_pk'])->body;
        self::assertStringContainsString('Revoke the agent', $page);
        self::assertStringContainsString($a['agent_id'], $page);

        $r = $this->post('/hosts/' . $a['host_pk'] . '/revoke', ['agent_id' => $a['agent_id'], 'reason' => 'stolen laptop, ticket 7']);
        self::assertSame(303, $r->status);
        $s = $this->pdo->prepare('SELECT status, revoked_by, revoke_reason FROM agents WHERE id = ?');
        $s->execute([$a['agent_pk']]);
        self::assertSame(['revoked', $u['id'], 'stolen laptop, ticket 7'], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($s->fetch())));
        self::assertSame(1, $this->crlRequests, 'M-071: a revocation asks for a CRL rebuild');
        $audit = $this->audit('agent_revoked', $a['agent_id']);
        self::assertSame([$u['id'], self::IP], [(int) $audit[0]['user_id'], $audit[0]['ip']]);
        $page = $this->get('/hosts/' . $a['host_pk'])->body;
        self::assertStringContainsString('The agent is revoked', $page);
        self::assertStringContainsString('Unrevoke the agent', $page);

        self::assertSame(303, $this->post('/hosts/' . $a['host_pk'] . '/unrevoke', ['agent_id' => $a['agent_id'], 'reason' => 'found it'])->status);
        $s->execute([$a['agent_pk']]);
        self::assertSame('active', $s->fetch()['status']);
        self::assertSame(2, $this->crlRequests, 'M-071: an unrevocation asks for a rebuild too');
        self::assertCount(1, $this->audit('agent_unrevoked', $a['agent_id']));
    }

    public function testRevokeNeedsAReasonTheRightAgentAndAnAdmin(): void
    {
        $a = TestDb::agent($this->pdo, 'SIM-ADMIN2');
        $status = function () use ($a): string {
            $s = $this->pdo->prepare('SELECT status FROM agents WHERE id = ?');
            $s->execute([$a['agent_pk']]);
            return (string) $s->fetchColumn();
        };
        $this->signIn('admin');
        $this->post('/hosts/' . $a['host_pk'] . '/revoke', ['agent_id' => $a['agent_id'], 'reason' => '  ']);
        self::assertStringContainsString('a reason of 1 to 500 characters is needed', strtolower($this->get('/hosts/' . $a['host_pk'])->body));
        $this->post('/hosts/' . $a['host_pk'] . '/revoke', ['agent_id' => '00000000-0000-4000-8000-000000000000', 'reason' => 'stale page']);
        self::assertStringContainsString('different agent now', $this->get('/hosts/' . $a['host_pk'])->body);
        self::assertSame(400, $this->post('/hosts/' . $a['host_pk'] . '/revoke', ['agent_id' => $a['agent_id'], 'reason' => 'x'], false)->status);
        $this->signIn('analyst');
        self::assertStringNotContainsString('Revoke the agent', $this->get('/hosts/' . $a['host_pk'])->body);
        self::assertSame(403, $this->post('/hosts/' . $a['host_pk'] . '/revoke', ['agent_id' => $a['agent_id'], 'reason' => 'x'])->status);
        self::assertSame('active', $status());
        self::assertSame(0, $this->crlRequests);
        self::assertCount(0, $this->audit('agent_revoked', $a['agent_id']));
    }
}
