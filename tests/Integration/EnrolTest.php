<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Api\AgentApi;
use Aio\Auth\ClientCertAuth;
use Aio\Config;
use Aio\Enrol\EnrolToken;
use Aio\Enrol\TokenStore;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Pki\OpensslTool;
use Aio\Pki\Signer;
use Aio\Util\Uuid;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/v1/enroll through AgentApi against aio_test, with a throwaway CA
 * and the Signer in place of the socket (ingest-api.md §2, §7; MS-ING-06,
 * MS-SEC-02, MS-SEC-03, MS-SEC-08, MS-SEC-10, M-062).
 */
final class EnrolTest extends TestCase
{
    private static ?string $dir = null;

    public static function setUpBeforeClass(): void
    {
        if (!is_file('/usr/bin/openssl')) {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/aio-enrol-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0700);
        $d = self::$dir;
        $t = new OpensslTool();
        $t->run(['ecparam', '-name', 'secp384r1', '-genkey', '-noout', '-out', "$d/ca.key"]);
        file_put_contents("$d/ca.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=Test Agent CA\nO=AIO Monitor\n[v3_ca]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
        $t->run(['req', '-new', '-x509', '-key', "$d/ca.key", '-config', "$d/ca.cnf", '-extensions', 'v3_ca', '-days', '30', '-sha384', '-out', "$d/ca.crt"]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== null) {
            array_map('unlink', glob(self::$dir . '/*') ?: []);
            @rmdir(self::$dir);
        }
    }

    private function pdo(): PDO
    {
        if (self::$dir === null || !is_file(self::$dir . '/ca.crt')) {
            $this->markTestSkipped('no /usr/bin/openssl for the test CA');
        }
        return TestDb::pdo($this);
    }

    private function api(PDO $pdo): AgentApi
    {
        $d = (string) self::$dir;
        $config = Config::fromArray(['enrol' => ['agent_ca_public' => "$d/ca.crt"]]);
        return new AgentApi(
            $config,
            static fn (): PDO => $pdo,
            static fn () => new Signer("$d/ca.crt", "$d/ca.key", new OpensslTool(), sys_get_temp_dir()),
        );
    }

    private static function csr(string $cn): string
    {
        $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn], $k, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $pem);
        return $pem;
    }

    private static function ip(): string
    {
        return '10.' . random_int(0, 255) . '.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    /** @param array<string, mixed> $over */
    private function enrol(PDO $pdo, string $token, string $agentId, array $over = [], ?string $ip = null, string $ct = 'application/json'): Response
    {
        $body = $over + [
            'enrollment_token' => $token,
            'csr' => self::csr($agentId),
            'agent_id' => $agentId,
            'host_id' => strtoupper(Uuid::v4()),
            'hostname' => 'SIM-ENROL-' . random_int(1000, 9999),
            'agent_version' => '0.5.0',
        ];
        $req = Request::forTest('POST', '/api/v1/enroll', ['Content-Type' => $ct], (string) json_encode($body), null, 'NONE', $ip ?? self::ip());
        return $this->api($pdo)->handle($req);
    }

    private static function token(PDO $pdo, array $o = []): string
    {
        return (new TokenStore($pdo))->create($o, 'cli:test')['token'];
    }

    private static function code(Response $r): ?string
    {
        return $r->body['error']['code'] ?? null;
    }

    public function testATokenEnrolsANewAgentOnceAndTheCertificateIsItsIdentity(): void
    {
        $pdo = $this->pdo();
        $pdo->exec("INSERT IGNORE INTO host_groups (name) VALUES ('enrol-test')");
        $token = self::token($pdo, ['group' => 'enrol-test']);
        $id = Uuid::v4();
        $ip = self::ip();
        $r = $this->enrol($pdo, $token, $id, ['hostname' => 'SRV-ENROL-1'], $ip);

        self::assertSame(201, $r->status, json_encode($r->body));
        self::assertSame($id, $r->body['agent_id']);
        self::assertSame('enrol-test', $r->body['host_group']);
        self::assertSame(file_get_contents(self::$dir . '/ca.crt'), $r->body['ca_certificate']);
        self::assertSame(1, openssl_x509_verify($r->body['certificate'], $r->body['ca_certificate']));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r->body['not_after']);
        self::assertSame(30 * 86_400, strtotime($r->body['not_after']) - strtotime($r->body['renew_after']));

        // The certificate now authenticates the agent (MS-ING-02).
        $ctx = (new ClientCertAuth($pdo))->authenticate(Request::forTest('POST', '/api/v1/heartbeat', [], '', $r->body['certificate']));
        self::assertSame($id, $ctx->agentId);
        self::assertSame('SRV-ENROL-1', $ctx->hostname);

        $s = $pdo->prepare('SELECT t.used_at IS NOT NULL AS used, a.agent_id, t.used_from_ip FROM enrollment_tokens t JOIN agents a ON a.id = t.used_by_agent_pk WHERE t.token_sha256 = ?');
        $s->bindValue(1, EnrolToken::hash($token), PDO::PARAM_LOB);
        $s->execute();
        $t = $s->fetch();
        self::assertSame($id, $t['agent_id']);
        self::assertSame(inet_pton($ip), $t['used_from_ip']);

        $a = $pdo->prepare('SELECT username, details FROM audit_log WHERE action = \'agent_enrolled\' AND target_id = ?');
        $a->execute([$id]);
        $row = $a->fetch();
        self::assertSame("agent:$id", $row['username']);
        self::assertStringNotContainsString(substr($token, 5), (string) $row['details']);
        self::assertStringNotContainsString('BEGIN', (string) $row['details']);

        // The same token again: one answer for unknown, used or expired.
        $again = $this->enrol($pdo, $token, Uuid::v4(), [], $ip);
        self::assertSame(403, $again->status);
        self::assertSame('enrollment_token_invalid', self::code($again));
    }

    public function testAnEnrolledAgentIdNeedsAReenrolmentTokenAndTheTokenIsNotSpent(): void
    {
        $pdo = $this->pdo();
        $id = Uuid::v4();
        self::assertSame(201, $this->enrol($pdo, self::token($pdo), $id)->status);

        $token = self::token($pdo);
        $r = $this->enrol($pdo, $token, $id);
        self::assertSame(409, $r->status);
        self::assertSame('agent_id_in_use', self::code($r));
        // Rolled back: the token still works for a new agent.
        self::assertSame(201, $this->enrol($pdo, $token, Uuid::v4())->status);
    }

    public function testReenrolmentRevokesTheOldCertificatesAndAuditsBothSteps(): void
    {
        $pdo = $this->pdo();
        $id = Uuid::v4();
        $hostId = Uuid::v4();
        $first = $this->enrol($pdo, self::token($pdo), $id, ['host_id' => $hostId, 'hostname' => 'SRV-RE-1']);
        self::assertSame(201, $first->status);

        // A re-enrolment token for another agent is refused for this one.
        $other = $this->enrol($pdo, self::token($pdo, ['reenroll' => $id]), Uuid::v4());
        self::assertSame('enrollment_token_invalid', self::code($other));

        $second = $this->enrol($pdo, self::token($pdo, ['reenroll' => $id]), $id, ['host_id' => $hostId, 'hostname' => 'SRV-RE-1']);
        self::assertSame(201, $second->status, json_encode($second->body));

        $auth = new ClientCertAuth($pdo);
        try {
            $auth->authenticate(Request::forTest('POST', '/api/v1/heartbeat', [], '', $first->body['certificate']));
            self::fail('the old certificate still works');
        } catch (ApiError $e) {
            self::assertSame('agent_revoked', $e->errorCode);
        }
        self::assertSame($id, $auth->authenticate(Request::forTest('POST', '/api/v1/heartbeat', [], '', $second->body['certificate']))->agentId);

        $n = $pdo->prepare('SELECT COUNT(DISTINCT h.id) FROM agents a JOIN hosts h ON h.id = a.host_pk WHERE a.agent_id = ?');
        $n->execute([$id]);
        self::assertSame(1, (int) $n->fetchColumn(), 'same host row');
        foreach (['agent_certificates_revoked', 'agent_reenrolled'] as $action) {
            $a = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = ? AND target_id = ?');
            $a->execute([$action, $id]);
            self::assertSame(1, (int) $a->fetchColumn(), $action);
        }
    }

    public function testRevokedAgentsBadCsrsAndWrongHostsAreRefusedWithoutSpendingTheToken(): void
    {
        $pdo = $this->pdo();
        $id = Uuid::v4();
        self::assertSame(201, $this->enrol($pdo, self::token($pdo), $id)->status);
        $pdo->prepare('UPDATE agents SET status = \'revoked\' WHERE agent_id = ?')->execute([$id]);

        $token = self::token($pdo, ['hostname' => 'SRV-ONLY']);
        $revoked = $this->enrol($pdo, $token, $id, ['hostname' => 'SRV-ONLY']);
        self::assertSame(403, $revoked->status);
        self::assertSame('agent_revoked', self::code($revoked));

        $new = Uuid::v4();
        $badCsr = $this->enrol($pdo, $token, $new, ['hostname' => 'SRV-ONLY', 'csr' => self::csr(Uuid::v4())]);
        self::assertSame(400, $badCsr->status);
        self::assertSame('csr_invalid', self::code($badCsr));

        $wrongHost = $this->enrol($pdo, $token, $new, ['hostname' => 'SRV-OTHER']);
        self::assertSame('enrollment_token_invalid', self::code($wrongHost));

        self::assertSame(201, $this->enrol($pdo, $token, $new, ['hostname' => 'srv-only'])->status, 'hostname matched without case');
    }

    public function testCancelledExpiredAndMalformedTokensGetTheSameAnswer(): void
    {
        $pdo = $this->pdo();
        $store = new TokenStore($pdo);
        $cancelled = $store->create([], 'cli:test');
        $store->cancel($cancelled['id'], 'cli:test');
        $expired = $store->create([], 'cli:test', microtime(true) - 2 * 86_400)['token'];

        foreach ([$cancelled['token'], $expired, EnrolToken::generate(), 'aioe_short'] as $t) {
            $r = $this->enrol($pdo, $t, Uuid::v4());
            self::assertSame(403, $r->status);
            self::assertSame('enrollment_token_invalid', self::code($r));
            self::assertSame('The enrolment token is unknown, used or expired', $r->body['error']['message']);
        }
    }

    public function testBadRequestsAndTheFailureLimitPerAddress(): void
    {
        $pdo = $this->pdo();
        $ip = self::ip();
        $token = self::token($pdo);

        self::assertSame(415, $this->enrol($pdo, $token, Uuid::v4(), [], $ip, 'text/plain')->status);
        $missing = $this->enrol($pdo, $token, Uuid::v4(), ['hostname' => ''], $ip);
        self::assertSame('missing_field', self::code($missing));
        self::assertStringContainsString('hostname', $missing->body['error']['message']);
        for ($i = 0; $i < 8; $i++) {
            self::assertSame(403, $this->enrol($pdo, EnrolToken::generate(), Uuid::v4(), [], $ip)->status);
        }
        // Ten refusals in ten minutes: even a good token waits.
        $limited = $this->enrol($pdo, $token, Uuid::v4(), [], $ip);
        self::assertSame(429, $limited->status);
        self::assertSame('too_many_enrollments', self::code($limited));
        self::assertTrue($limited->body['error']['retryable']);
        self::assertGreaterThan(0, (int) $limited->headers['Retry-After']);
        self::assertLessThanOrEqual(600, (int) $limited->headers['Retry-After']);

        // Another address is not affected, and the token was never spent.
        self::assertSame(201, $this->enrol($pdo, $token, Uuid::v4())->status);
    }
}
