<?php

declare(strict_types=1);

namespace Aio\Enrol;

use Aio\Util\Time;
use Aio\Util\Uuid;
use Aio\Web\Audit;
use PDO;

/**
 * Revoking and unrevoking agents and certificates (ingest-api.md §2.4, §2.5,
 * §8.1 rule 8; MS-SEC-02, MS-SEC-08, M-066). Used by bin/agent.php as
 * aio_maint, and later by the admin page (MPD-40).
 *
 * - Revoking an agent refuses every request it makes from then on, with any
 *   of its certificates (ClientCertAuth checks on every request), and its
 *   agent ID can't enrol again (§2.5).
 * - Unrevoking an agent undoes a mistake: its certificates that aren't
 *   revoked themselves work again, and the agent sends its queue (§8.1 rule 8).
 * - Revoking one certificate is final; the agent keeps working with another
 *   valid or superseded one, or needs a re-enrolment token.
 *
 * Every change needs a reason and writes an audit row (MS-SEC-08).
 */
final class AgentAdmin
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $status = null, int $limit = 500): array
    {
        $sql = 'SELECT a.agent_id, a.status, h.hostname, g.name AS group_name, a.last_checkin_at, a.last_version,
                       UNIX_TIMESTAMP(a.last_checkin_at) AS checkin_epoch, UNIX_TIMESTAMP(a.last_ingest_at) AS ingest_epoch, a.queue_records,
                       (SELECT COUNT(*) FROM agent_certificates c WHERE c.agent_pk = a.id AND c.status <> \'revoked\' AND c.not_after > UTC_TIMESTAMP(6)) AS usable_certs
                  FROM agents a JOIN hosts h ON h.id = a.host_pk JOIN host_groups g ON g.id = h.group_id'
            . ($status === null ? '' : ' WHERE a.status = ?')
            . ' ORDER BY h.hostname, a.agent_id LIMIT ' . max(1, $limit);
        $s = $this->pdo->prepare($sql);
        $s->execute($status === null ? [] : [$status]);
        return $s->fetchAll();
    }

    /** @return array{agent: array<string, mixed>, certificates: list<array<string, mixed>>} */
    public function show(string $agentId): array
    {
        $a = $this->agent($agentId, false);
        $c = $this->pdo->prepare('SELECT serial_hex, status, issued_via, issued_at, not_after, revoked_at, revoke_reason FROM agent_certificates WHERE agent_pk = ? ORDER BY id');
        $c->execute([$a['id']]);
        return ['agent' => $a, 'certificates' => $c->fetchAll()];
    }

    public function revoke(string $agentId, string $reason, string $actor): void
    {
        $reason = self::reason($reason);
        $this->change(function () use ($agentId, $reason, $actor): void {
            $a = $this->agent($agentId, true);
            if ($a['status'] === 'revoked') {
                throw new \InvalidArgumentException("agent $agentId is already revoked");
            }
            $this->pdo->prepare('UPDATE agents SET status = \'revoked\', revoked_at = ?, revoked_by = NULL, revoke_reason = ? WHERE id = ?')
                ->execute([Time::nowDb(), $reason, $a['id']]);
            Audit::write($this->pdo, 'agent_revoked', null, $actor, '', 'agent', $agentId, ['reason' => $reason, 'hostname' => $a['hostname']]);
        });
    }

    public function unrevoke(string $agentId, string $reason, string $actor): void
    {
        $reason = self::reason($reason);
        $this->change(function () use ($agentId, $reason, $actor): void {
            $a = $this->agent($agentId, true);
            if ($a['status'] !== 'revoked') {
                throw new \InvalidArgumentException("agent $agentId is not revoked");
            }
            $this->pdo->prepare('UPDATE agents SET status = \'active\', revoked_at = NULL, revoked_by = NULL, revoke_reason = NULL WHERE id = ?')
                ->execute([$a['id']]);
            Audit::write($this->pdo, 'agent_unrevoked', null, $actor, '', 'agent', $agentId, [
                'reason' => $reason,
                'was_revoked_at' => $a['revoked_at'],
                'was_reason' => $a['revoke_reason'],
            ]);
        });
    }

    public function revokeCertificate(string $serialHex, string $reason, string $actor): void
    {
        $reason = self::reason($reason);
        $serialHex = strtoupper($serialHex);
        if (!preg_match('/^[0-9A-F]{1,40}$/D', $serialHex)) {
            throw new \InvalidArgumentException('a certificate serial is hex digits (from show)');
        }
        $this->change(function () use ($serialHex, $reason, $actor): void {
            $s = $this->pdo->prepare('SELECT c.id, c.status, a.agent_id FROM agent_certificates c JOIN agents a ON a.id = c.agent_pk WHERE c.serial_hex = ? FOR UPDATE');
            $s->execute([$serialHex]);
            $c = $s->fetch();
            if (!is_array($c)) {
                throw new \InvalidArgumentException("no certificate with serial $serialHex");
            }
            if ($c['status'] === 'revoked') {
                throw new \InvalidArgumentException("certificate $serialHex is already revoked");
            }
            $this->pdo->prepare('UPDATE agent_certificates SET status = \'revoked\', revoked_at = ?, revoke_reason = ? WHERE id = ?')
                ->execute([Time::nowDb(), $reason, $c['id']]);
            Audit::write($this->pdo, 'certificate_revoked', null, $actor, '', 'agent', (string) $c['agent_id'], ['serial' => $serialHex, 'reason' => $reason]);
        });
    }

    /** @return array<string, mixed> */
    private function agent(string $agentId, bool $lock): array
    {
        if (!Uuid::isCanonical($agentId)) {
            throw new \InvalidArgumentException('an agent ID is a lower-case UUID (from list)');
        }
        $s = $this->pdo->prepare(
            'SELECT a.id, a.agent_id, a.status, a.enrolled_at, a.revoked_at, a.revoke_reason, a.last_checkin_at, a.last_version,
                    h.hostname, h.machine_guid, g.name AS group_name
               FROM agents a JOIN hosts h ON h.id = a.host_pk JOIN host_groups g ON g.id = h.group_id
              WHERE a.agent_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $s->execute([$agentId]);
        $a = $s->fetch();
        if (!is_array($a)) {
            throw new \InvalidArgumentException("no agent with ID $agentId");
        }
        return $a;
    }

    private static function reason(string $reason): string
    {
        $r = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $reason));
        if ($r === '' || mb_strlen($r) > 500) {
            throw new \InvalidArgumentException('a reason of 1 to 500 characters is needed (--reason="...")');
        }
        return $r;
    }

    private function change(\Closure $fn): void
    {
        $this->pdo->beginTransaction();
        try {
            $fn();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
