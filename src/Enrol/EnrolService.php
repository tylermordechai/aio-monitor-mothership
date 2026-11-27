<?php

declare(strict_types=1);

namespace Aio\Enrol;

use Aio\Config;
use Aio\Http\ApiError;
use Aio\Http\Request;
use Aio\Http\Response;
use Aio\Pki\CertSigner;
use Aio\Pki\CsrCheck;
use Aio\Pki\CsrInvalid;
use Aio\Util\Ip;
use Aio\Util\Time;
use Aio\Web\Audit;
use PDO;

/**
 * POST /api/v1/enroll (ingest-api.md §2; MS-ING-06, MS-SEC-03, MS-SEC-10,
 * M-003, M-021, M-062). No client certificate: the one-time token is the
 * credential. Everything happens in one transaction with the token row
 * locked, so a token can't be used twice and nothing is half-done:
 *
 *   token (unknown, used, cancelled, expired or the wrong host: one answer)
 *   → agent ID (new; revoked: 403; enrolled: 409 unless a re-enrolment token for it)
 *   → CSR (checked here, then again by the signer) → signed → host, agent,
 *   certificate and token rows → audit → COMMIT → 201.
 *
 * Every refusal counts towards the per-address limit (10 in 10 minutes, then
 * 429 too_many_enrollments). Never logged or stored: the token, the CSR, the
 * certificate (MS-SEC-10).
 */
final class EnrolService
{
    public const MAX_BODY = CertIssue::MAX_BODY;
    private const FIELDS = ['enrollment_token', 'csr', 'agent_id', 'host_id', 'hostname', 'agent_version'];
    private const GUID = '/^[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}$/D';
    private const HOSTNAME = '/^[^\x00-\x1f\x7f\s\/\\\\]{1,255}$/uD';
    private const VERSION = '/^[0-9A-Za-z.+\-]{1,64}$/D';

    /** @param \Closure(): float|null $clock */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly CertSigner $signer,
        private readonly string $caPem,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** @throws ApiError */
    public function handle(Request $req): Response
    {
        $now = $this->clock !== null ? ($this->clock)() : microtime(true);
        $ip = Ip::toBinary($req->remoteAddr);
        $this->checkRate($ip, $now);
        try {
            return $this->enrol($req, $ip, $now);
        } catch (ApiError $e) {
            if ($e->status === 400 || $e->status === 403 || $e->status === 409 || $e->status === 413 || $e->status === 415) {
                $this->recordFailure($ip, $e->errorCode, $now);
                error_log("aio agent-api enroll refused: {$e->errorCode} from {$req->remoteAddr}");
            }
            throw $e;
        }
    }

    private function enrol(Request $req, ?string $ip, float $now): Response
    {
        $b = $this->readBody($req);
        $token = $b['enrollment_token'];
        $agentId = $b['agent_id'];
        if (!EnrolToken::isWellFormed($token)) {
            throw self::tokenInvalid();
        }
        if (!preg_match(self::GUID, $b['host_id'])) {
            throw new ApiError(400, 'missing_field', 'host_id must be the MachineGuid (a GUID)', false);
        }
        if (!preg_match(self::HOSTNAME, $b['hostname'])) {
            throw new ApiError(400, 'missing_field', 'hostname must be a computer name', false);
        }
        if (!preg_match(self::VERSION, $b['agent_version'])) {
            throw new ApiError(400, 'missing_field', 'agent_version must be a version string', false);
        }
        // The CSR is checked before the token is touched: a broken CSR costs
        // nothing, and the signer checks it all again (M-060).
        try {
            CsrCheck::check($b['csr'], $agentId);
        } catch (CsrInvalid $e) {
            throw CertIssue::csrInvalid($e->reason);
        }

        $nowDb = Time::nowDb($now);
        $this->pdo->beginTransaction();
        try {
            $t = $this->lockToken($token, $nowDb);
            if ($t['expected_hostname'] !== null && strcasecmp($t['expected_hostname'], $b['hostname']) !== 0) {
                throw self::tokenInvalid();
            }
            $reenrol = $t['reenroll_agent_id'];
            $a = $this->pdo->prepare('SELECT id, status FROM agents WHERE agent_id = ? FOR UPDATE');
            $a->execute([$agentId]);
            $existing = $a->fetch();
            if ($reenrol !== null && $reenrol !== $agentId) {
                // A re-enrolment token is for that agent only.
                throw self::tokenInvalid();
            }
            if (is_array($existing)) {
                if ($existing['status'] === 'revoked') {
                    throw ApiError::agentRevoked();
                }
                if ($reenrol === null) {
                    throw new ApiError(409, 'agent_id_in_use', 'This agent ID is already enrolled; it needs a re-enrolment token', false);
                }
            } elseif ($reenrol !== null) {
                throw self::tokenInvalid();
            }

            [$hostPk, $groupName] = $this->host(strtolower($b['host_id']), $b['hostname'], (int) $t['group_id'], $nowDb);
            $revoked = [];
            if (is_array($existing)) {
                $agentPk = (int) $existing['id'];
                $revoked = $this->revokeOldCertificates($agentPk, $nowDb);
                $u = $this->pdo->prepare('UPDATE agents SET host_pk = ?, status = \'active\', last_checkin_at = ?, last_checkin_ip = ?, last_version = ? WHERE id = ?');
                $u->bindValue(1, $hostPk, PDO::PARAM_INT);
                $u->bindValue(2, $nowDb);
                $u->bindValue(3, $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
                $u->bindValue(4, $b['agent_version']);
                $u->bindValue(5, $agentPk, PDO::PARAM_INT);
                $u->execute();
            } else {
                $ins = $this->pdo->prepare('INSERT INTO agents (agent_id, host_pk, enrolled_at, last_checkin_at, last_checkin_ip, last_version) VALUES (?, ?, ?, ?, ?, ?)');
                $ins->bindValue(1, $agentId);
                $ins->bindValue(2, $hostPk, PDO::PARAM_INT);
                $ins->bindValue(3, $nowDb);
                $ins->bindValue(4, $nowDb);
                $ins->bindValue(5, $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
                $ins->bindValue(6, $b['agent_version']);
                $ins->execute();
                $agentPk = (int) $this->pdo->lastInsertId();
            }

            // Signed after the old certificates are revoked, so the new one
            // is the only valid one (§2.5).
            $signed = CertIssue::signAndStore($this->pdo, $this->signer, $b['csr'], $agentId, $agentPk, 'enroll', $nowDb);
            $notAfter = $signed['not_after'];

            $u = $this->pdo->prepare('UPDATE enrollment_tokens SET used_at = ?, used_by_agent_pk = ?, used_from_ip = ? WHERE id = ?');
            $u->bindValue(1, $nowDb);
            $u->bindValue(2, $agentPk, PDO::PARAM_INT);
            $u->bindValue(3, $ip, $ip === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $u->bindValue(4, (int) $t['id'], PDO::PARAM_INT);
            $u->execute();

            $who = 'agent:' . $agentId;
            if (is_array($existing)) {
                // §2.5: both steps of a re-enrolment are in the audit log.
                Audit::write($this->pdo, 'agent_certificates_revoked', null, $who, $req->remoteAddr, 'agent', $agentId, [
                    'reason' => 're-enrolment',
                    'serials' => $revoked,
                    'token_id' => (int) $t['id'],
                ]);
            }
            Audit::write($this->pdo, is_array($existing) ? 'agent_reenrolled' : 'agent_enrolled', null, $who, $req->remoteAddr, 'agent', $agentId, [
                'serial' => $signed['serial_hex'],
                'token_id' => (int) $t['id'],
                'token_kind' => $t['token_kind'],
                'host_group' => $groupName,
                'hostname' => $b['hostname'],
                'agent_version' => $b['agent_version'],
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return CertIssue::answer(201, $agentId, $signed['certificate'], $notAfter, $this->caPem, $groupName);
    }

    /** @return array<string, string> the six fields, all strings */
    private function readBody(Request $req): array
    {
        return CertIssue::jsonBody($req, self::FIELDS, 'Enrolment');
    }

    /** @return array<string, mixed> the locked, usable token row */
    private function lockToken(string $token, string $nowDb): array
    {
        $s = $this->pdo->prepare(
            'SELECT id, group_id, expected_hostname, reenroll_agent_id, token_kind, expires_at, used_at, cancelled_at
               FROM enrollment_tokens WHERE token_sha256 = ? FOR UPDATE'
        );
        $s->bindValue(1, EnrolToken::hash($token), PDO::PARAM_LOB);
        $s->execute();
        $t = $s->fetch();
        if (!is_array($t) || $t['used_at'] !== null || $t['cancelled_at'] !== null || $t['expires_at'] <= $nowDb) {
            throw self::tokenInvalid();
        }
        return $t;
    }

    /**
     * §2.5: the host is found by MachineGuid and hostname together (clones can
     * share a MachineGuid, D-003), or made in the token's group. An existing
     * host keeps its group.
     *
     * @return array{0: int, 1: string} host_pk and its group name
     */
    private function host(string $guid, string $hostname, int $groupId, string $nowDb): array
    {
        $s = $this->pdo->prepare(
            'SELECT h.id, g.name FROM hosts h JOIN host_groups g ON g.id = h.group_id
              WHERE h.machine_guid = ? AND h.hostname = ? AND h.retired_at IS NULL
              ORDER BY h.id DESC LIMIT 1 FOR UPDATE'
        );
        $s->execute([$guid, $hostname]);
        $h = $s->fetch();
        if (is_array($h)) {
            $hostPk = (int) $h['id'];
            $group = (string) $h['name'];
            $this->pdo->prepare('UPDATE hosts SET last_seen = ? WHERE id = ?')->execute([$nowDb, $hostPk]);
        } else {
            $ins = $this->pdo->prepare('INSERT INTO hosts (machine_guid, hostname, group_id, first_seen, last_seen) VALUES (?, ?, ?, ?, ?)');
            $ins->bindValue(1, $guid);
            $ins->bindValue(2, $hostname);
            $ins->bindValue(3, $groupId, PDO::PARAM_INT);
            $ins->bindValue(4, $nowDb);
            $ins->bindValue(5, $nowDb);
            $ins->execute();
            $hostPk = (int) $this->pdo->lastInsertId();
            $g = $this->pdo->prepare('SELECT name FROM host_groups WHERE id = ?');
            $g->execute([$groupId]);
            $group = (string) $g->fetchColumn();
        }
        $this->pdo->prepare(
            'INSERT INTO host_names (host_pk, hostname, first_seen, last_seen) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_seen = VALUES(last_seen)'
        )->execute([$hostPk, $hostname, $nowDb, $nowDb]);
        return [$hostPk, $group];
    }

    /** @return list<string> the serials revoked */
    private function revokeOldCertificates(int $agentPk, string $nowDb): array
    {
        $s = $this->pdo->prepare('SELECT serial_hex FROM agent_certificates WHERE agent_pk = ? AND status <> \'revoked\' FOR UPDATE');
        $s->execute([$agentPk]);
        $serials = array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
        $this->pdo->prepare(
            'UPDATE agent_certificates SET status = \'revoked\', revoked_at = ?, revoke_reason = \'re-enrolment\' WHERE agent_pk = ? AND status <> \'revoked\''
        )->execute([$nowDb, $agentPk]);
        return $serials;
    }

    /** 429 after too many refusals from one address (ingest-api.md §7). */
    private function checkRate(?string $ip, float $now): void
    {
        if ($ip === null) {
            return;
        }
        $window = $this->config->int('enrol', 'failure_window_s');
        $max = $this->config->int('enrol', 'max_failures');
        $s = $this->pdo->prepare('SELECT COUNT(*) AS n, MIN(at) AS oldest FROM enrollment_failures WHERE source_ip = ? AND at > ?');
        $s->bindValue(1, $ip, PDO::PARAM_LOB);
        $s->bindValue(2, Time::nowDb($now - $window));
        $s->execute();
        $r = $s->fetch();
        if ((int) $r['n'] < $max) {
            return;
        }
        $oldest = Time::parse(str_replace(' ', 'T', (string) $r['oldest']) . 'Z');
        $wait = $oldest === null ? $window : max(1, (int) ceil($oldest['epoch'] + $window - $now));
        throw new ApiError(429, 'too_many_enrollments', 'Too many failed enrolments from this address; retry later', true, min($wait, $window));
    }

    private function recordFailure(?string $ip, string $code, float $now): void
    {
        if ($ip === null) {
            return;
        }
        $s = $this->pdo->prepare('INSERT INTO enrollment_failures (source_ip, at, code) VALUES (?, ?, ?)');
        $s->bindValue(1, $ip, PDO::PARAM_LOB);
        $s->bindValue(2, Time::nowDb($now));
        $s->bindValue(3, $code);
        $s->execute();
    }

    private static function tokenInvalid(): ApiError
    {
        return new ApiError(403, 'enrollment_token_invalid', 'The enrolment token is unknown, used or expired', false);
    }
}
