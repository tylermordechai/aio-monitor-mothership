<?php

declare(strict_types=1);

namespace Aio\Auth;

use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Util\Ip;
use Aio\Util\Time;
use PDO;

/**
 * Who sent a request: the verified client certificate, looked up in
 * agent_certificates on every request (ingest-api.md §2.4; MS-ING-02, MS-SEC-02).
 * Never a value in the body. Every authenticated request updates the agent's
 * check-in time, version and source IP, even if it fails later (MS-ING-08).
 */
final class ClientCertAuth
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @throws ApiError 401 client_certificate_required, 403 certificate_unknown or agent_revoked */
    public function authenticate(Request $req): AgentContext
    {
        if ($req->clientVerify !== 'SUCCESS' || $req->clientCertPem === null) {
            throw ApiError::clientCertificateRequired();
        }
        $fp = @openssl_x509_fingerprint($req->clientCertPem, 'sha256', true);
        $cert = @openssl_x509_parse($req->clientCertPem);
        if ($fp === false || $cert === false) {
            throw ApiError::clientCertificateRequired();
        }
        $stmt = $this->pdo->prepare(
            'SELECT c.status AS cert_status, c.not_after, a.id AS agent_pk, a.agent_id, a.status AS agent_status,
                    h.id AS host_pk, h.machine_guid, h.hostname, h.group_id
               FROM agent_certificates c
               JOIN agents a ON a.id = c.agent_pk
               JOIN hosts h ON h.id = a.host_pk
              WHERE c.fingerprint_sha256 = ?'
        );
        $stmt->bindValue(1, $fp, PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row === false) {
            throw ApiError::certificateUnknown();
        }
        $cn = $cert['subject']['CN'] ?? null;
        if (!is_string($cn) || $cn !== $row['agent_id']) {
            // The CN is the agent ID (M-003); a mismatch means a bad registration.
            throw ApiError::certificateUnknown();
        }
        $now = Time::nowDb();
        if ($row['cert_status'] === 'revoked' || $row['agent_status'] === 'revoked') {
            $u = $this->pdo->prepare('UPDATE agents SET last_refused_at = ? WHERE id = ?');
            $u->execute([$now, $row['agent_pk']]);
            throw ApiError::agentRevoked();
        }
        $version = self::versionFrom($req->header('user-agent'));
        $ip = Ip::toBinary($req->remoteAddr);
        $u = $this->pdo->prepare('UPDATE agents SET last_checkin_at = ?, last_checkin_ip = ?, last_version = COALESCE(?, last_version) WHERE id = ?');
        $u->bindValue(1, $now);
        $u->bindValue(2, $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $u->bindValue(3, $version, $version === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $u->bindValue(4, (int) $row['agent_pk'], PDO::PARAM_INT);
        $u->execute();

        return new AgentContext(
            (int) $row['agent_pk'],
            (string) $row['agent_id'],
            (int) $row['host_pk'],
            (string) $row['machine_guid'],
            (string) $row['hostname'],
            (int) $row['group_id'],
            (string) $row['not_after'],
            $version,
            $ip,
        );
    }

    /** `aio-monitor-agent/0.5.0` → `0.5.0` (ingest-api.md §1). */
    public static function versionFrom(?string $userAgent): ?string
    {
        if ($userAgent === null || !preg_match('#^aio-monitor-agent/([0-9A-Za-z.+\-]{1,64})#', $userAgent, $m)) {
            return null;
        }
        return $m[1];
    }
}
