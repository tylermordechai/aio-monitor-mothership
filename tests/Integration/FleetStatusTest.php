<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Api\AgentApi;
use Aio\Config;
use Aio\Dashboard\FleetQuery;
use Aio\Enrol\AgentAdmin;
use Aio\Http\Request;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Silent, behind and revoked agents in the fleet, and the undeliverable count
 * (MS-UI-01, MS-ING-08, MS-ALR-05, M-063, M-067; the milestone 3 gate).
 */
final class FleetStatusTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function row(PDO $pdo, int $hostPk): array
    {
        foreach ((new FleetQuery($pdo, Config::fromArray([])))->hosts() as $h) {
            if ($h['host_pk'] === $hostPk) {
                return $h;
            }
        }
        self::fail("host $hostPk not in the fleet");
    }

    private static function heartbeat(PDO $pdo, string $cert, array $body): int
    {
        $req = Request::forTest('POST', '/api/v1/heartbeat', ['Content-Type' => 'application/json'], (string) json_encode($body), $cert);
        return (new AgentApi(Config::fromArray([]), static fn (): PDO => $pdo))->handle($req)->status;
    }

    public function testASilentAgentIsFlaggedAndAHeartbeatClearsIt(): void
    {
        $pdo = TestDb::pdo($this);
        $a = TestDb::agent($pdo, 'SIM-FLEET-1');
        $pdo->prepare('UPDATE agents SET last_checkin_at = UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE WHERE id = ?')->execute([$a['agent_pk']]);
        self::assertSame('stale', self::row($pdo, $a['host_pk'])['status']);

        self::assertSame(200, self::heartbeat($pdo, $a['cert'], ['queue' => ['records' => 0, 'undeliverable_records' => 3, 'undeliverable_bytes' => 2048, 'dropped_records' => 0]]));
        $h = self::row($pdo, $a['host_pk']);
        self::assertSame('ok', $h['status']);
        self::assertSame(3, $h['undeliverable_records']);
        self::assertSame(0, $h['dropped_records']);
    }

    public function testBehindAndRevokedAreShownAsSuch(): void
    {
        $pdo = TestDb::pdo($this);
        $a = TestDb::agent($pdo, 'SIM-FLEET-2');
        self::assertSame(200, self::heartbeat($pdo, $a['cert'], ['queue' => ['records' => 5000]]));
        self::assertSame('behind', self::row($pdo, $a['host_pk'])['status'], 'queued, nothing ever stored');

        (new AgentAdmin($pdo))->revoke($a['agent_id'], 'test', 'cli:test');
        self::assertSame('revoked', self::row($pdo, $a['host_pk'])['status']);
    }
}
