<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Enrol\EnrolToken;
use Aio\Enrol\TokenStore;
use Aio\Util\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Enrolment tokens in the database (ingest-api.md §2.1, §2.5; MS-SEC-03,
 * MS-SEC-08, MS-SEC-10, M-021, M-061).
 */
final class TokenStoreTest extends TestCase
{
    public function testCreateStoresOnlyTheHashAndAuditsWithoutTheToken(): void
    {
        $pdo = TestDb::pdo($this);
        $store = new TokenStore($pdo);
        $now = microtime(true);
        $t = $store->create(['kind' => 'deployment', 'days' => '3', 'hostname' => 'SRV-WEB-01', 'note' => 'test'], 'cli:test', $now);

        self::assertTrue(EnrolToken::isWellFormed($t['token']));
        $s = $pdo->prepare('SELECT token_sha256, token_kind, expected_hostname, created_by, created_by_name, TIMESTAMPDIFF(SECOND, created_at, expires_at) AS life FROM enrollment_tokens WHERE id = ?');
        $s->execute([$t['id']]);
        $row = $s->fetch();
        self::assertSame(EnrolToken::hash($t['token']), $row['token_sha256']);
        self::assertSame('deployment', $row['token_kind']);
        self::assertSame('SRV-WEB-01', $row['expected_hostname']);
        self::assertNull($row['created_by']);
        self::assertSame('cli:test', $row['created_by_name']);
        self::assertSame(3 * 86_400, (int) $row['life']);

        // No column of any token row and no audit row holds the token (MS-SEC-10).
        $dump = json_encode($pdo->query('SELECT * FROM audit_log')->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertStringNotContainsString(substr($t['token'], 5), (string) $dump);
        $s = $pdo->prepare('SELECT username, details FROM audit_log WHERE action = \'enrollment_token_created\' AND target_id = ?');
        $s->execute([(string) $t['id']]);
        $a = $s->fetch();
        self::assertSame('cli:test', $a['username']);
        self::assertSame('deployment', json_decode($a['details'], true)['kind']);
    }

    public function testListShowsOpenTokensAndCancelClosesThemOnce(): void
    {
        $pdo = TestDb::pdo($this);
        $store = new TokenStore($pdo);
        $t = $store->create([], 'cli:test');
        $ids = static fn (array $rows): array => array_map(static fn ($r) => (int) $r['id'], $rows);

        self::assertContains($t['id'], $ids($store->list()));
        $store->cancel($t['id'], 'cli:test');
        self::assertNotContains($t['id'], $ids($store->list()));
        $all = array_values(array_filter($store->list(true), static fn ($r) => (int) $r['id'] === $t['id']));
        self::assertSame('cancelled', $all[0]['state']);
        self::assertSame('cli:test', $all[0]['cancelled_by']);

        try {
            $store->cancel($t['id'], 'cli:test');
            self::fail('cancelled twice');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already cancelled', $e->getMessage());
        }
        $n = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = \'enrollment_token_cancelled\' AND target_id = ?');
        $n->execute([(string) $t['id']]);
        self::assertSame(1, (int) $n->fetchColumn());
    }

    public function testAnExpiredTokenIsListedAsExpiredAndCannotBeCancelled(): void
    {
        $pdo = TestDb::pdo($this);
        $store = new TokenStore($pdo);
        $t = $store->create([], 'cli:test', microtime(true) - 2 * 86_400);
        $all = array_values(array_filter($store->list(true), static fn ($r) => (int) $r['id'] === $t['id']));
        self::assertSame('expired', $all[0]['state']);
        $this->expectExceptionMessage('has expired');
        $store->cancel($t['id'], 'cli:test');
    }

    public function testReenrolmentTokensNeedAnActiveAgentAndGroupsMustExist(): void
    {
        $pdo = TestDb::pdo($this);
        $store = new TokenStore($pdo);
        $agent = TestDb::agent($pdo, 'SIM-TOKEN');

        $t = $store->create(['reenroll' => $agent['agent_id']], 'cli:test');
        self::assertSame($agent['agent_id'], $t['reenroll']);

        foreach ([['reenroll' => Uuid::v4()], ['group' => 'no-such-group']] as $o) {
            try {
                $store->create($o, 'cli:test');
                self::fail('accepted ' . json_encode($o));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $pdo->prepare('UPDATE agents SET status = \'revoked\' WHERE id = ?')->execute([$agent['agent_pk']]);
        $this->expectExceptionMessage('is revoked');
        $store->create(['reenroll' => $agent['agent_id']], 'cli:test');
    }
}
