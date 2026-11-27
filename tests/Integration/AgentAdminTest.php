<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Auth\ClientCertAuth;
use Aio\Enrol\AgentAdmin;
use Aio\Http\ApiError;
use Aio\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

/** Revoke and unrevoke (ingest-api.md §2.4; MS-SEC-02, MS-SEC-08, M-066). */
final class AgentAdminTest extends TestCase
{
    private static function refused(PDO $pdo, string $cert): ?string
    {
        try {
            (new ClientCertAuth($pdo))->authenticate(Request::forTest('POST', '/api/v1/heartbeat', [], '', $cert));
            return null;
        } catch (ApiError $e) {
            return $e->errorCode;
        }
    }

    private static function audits(PDO $pdo, string $action, string $agentId): int
    {
        $s = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ? AND target_id = ? AND username = \'cli:test\'');
        $s->execute([$action, $agentId]);
        return (int) $s->fetchColumn();
    }

    public function testARevokedAgentIsRefusedAtOnceAndAnUnrevokeLetsItBackIn(): void
    {
        $pdo = TestDb::pdo($this);
        $a = TestDb::agent($pdo, 'SIM-REVOKE-1');
        $admin = new AgentAdmin($pdo);
        self::assertNull(self::refused($pdo, $a['cert']));

        $admin->revoke($a['agent_id'], 'lost laptop, ticket 12', 'cli:test');
        self::assertSame('agent_revoked', self::refused($pdo, $a['cert']));
        $d = $admin->show($a['agent_id']);
        self::assertSame('revoked', $d['agent']['status']);
        self::assertSame('lost laptop, ticket 12', $d['agent']['revoke_reason']);
        self::assertContains($a['agent_id'], array_column($admin->list('revoked'), 'agent_id'));

        try {
            $admin->revoke($a['agent_id'], 'again', 'cli:test');
            self::fail('revoked twice');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('already revoked', $e->getMessage());
        }

        $admin->unrevoke($a['agent_id'], 'found it', 'cli:test');
        self::assertNull(self::refused($pdo, $a['cert']));
        self::assertSame('active', $admin->show($a['agent_id'])['agent']['status']);
        self::assertSame(1, self::audits($pdo, 'agent_revoked', $a['agent_id']));
        self::assertSame(1, self::audits($pdo, 'agent_unrevoked', $a['agent_id']));
    }

    public function testRevokingACertificateIsFinalAndReasonsAreRequired(): void
    {
        $pdo = TestDb::pdo($this);
        $a = TestDb::agent($pdo, 'SIM-REVOKE-2');
        $admin = new AgentAdmin($pdo);
        $serial = $admin->show($a['agent_id'])['certificates'][0]['serial_hex'];

        foreach (['', "  \n "] as $bad) {
            try {
                $admin->revoke($a['agent_id'], $bad, 'cli:test');
                self::fail('no reason accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertNull(self::refused($pdo, $a['cert']));

        $admin->revokeCertificate(strtolower($serial), 'key copied', 'cli:test');
        self::assertSame('agent_revoked', self::refused($pdo, $a['cert']));
        self::assertSame('active', $admin->show($a['agent_id'])['agent']['status'], 'the agent itself stays active');
        self::assertSame(1, self::audits($pdo, 'certificate_revoked', $a['agent_id']));

        $this->expectExceptionMessage('is not revoked');
        $admin->unrevoke($a['agent_id'], 'no', 'cli:test');
    }
}
