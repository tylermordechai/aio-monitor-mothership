<?php

declare(strict_types=1);

namespace Aio\Enrol;

use Aio\Util\Time;
use Aio\Util\Uuid;
use Aio\Web\Audit;
use PDO;

/**
 * Creating, listing and cancelling enrolment tokens (ingest-api.md §2.1,
 * §2.5; MS-SEC-03, M-021, M-061). Used by bin/token-create.php as aio_maint,
 * and by the admin page (M-070) as aio_admin, which passes the signed-in
 * user's ID and address for the audit row. Every change writes an audit row; the
 * token itself is returned once by create() and never stored, printed to a
 * log or put in the audit details (MS-SEC-10).
 */
final class TokenStore
{
    public const KINDS = ['manual', 'deployment'];
    private const HOSTNAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D';

    /**
     * @param int $manualHours how long a manual token lasts (M-021: 24 h)
     * @param int $deploymentMaxDays the longest a deployment token may last (M-021: 7 days)
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $manualHours = 24,
        private readonly int $deploymentMaxDays = 7,
    ) {
    }

    /**
     * Checks a request before anything is written. Throws \InvalidArgumentException
     * with a message for the person at the terminal.
     *
     * @param array{kind?: string, group?: string, days?: int|string|null, hostname?: string|null, reenroll?: string|null, note?: string|null} $o
     * @return array{kind: string, group: string, seconds: int, hostname: ?string, reenroll: ?string, note: ?string}
     */
    public function check(array $o): array
    {
        $kind = $o['kind'] ?? 'manual';
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('--kind must be manual or deployment');
        }
        $days = $o['days'] ?? null;
        if ($kind === 'manual') {
            if ($days !== null) {
                throw new \InvalidArgumentException("a manual token always lasts {$this->manualHours} hours; --days is only for --kind=deployment (M-021)");
            }
            $seconds = $this->manualHours * 3600;
        } else {
            $d = $days === null ? $this->deploymentMaxDays : (is_int($days) || ctype_digit((string) $days) ? (int) $days : -1);
            if ($d < 1 || $d > $this->deploymentMaxDays) {
                throw new \InvalidArgumentException("--days must be 1 to {$this->deploymentMaxDays} for a deployment token (M-021)");
            }
            $seconds = $d * 86_400;
        }
        $hostname = $o['hostname'] ?? null;
        if ($hostname !== null && !preg_match(self::HOSTNAME, $hostname)) {
            throw new \InvalidArgumentException('--hostname must be a host name: letters, digits, . _ - (up to 255)');
        }
        $re = $o['reenroll'] ?? null;
        if ($re !== null && !Uuid::isCanonical($re)) {
            throw new \InvalidArgumentException('--reenroll must be an agent ID (a lower-case UUID)');
        }
        $note = $o['note'] ?? null;
        if ($note !== null) {
            $note = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $note));
            if (mb_strlen($note) > 500) {
                throw new \InvalidArgumentException('--note is limited to 500 characters');
            }
            $note = $note === '' ? null : $note;
        }
        return [
            'kind' => $kind,
            'group' => (string) ($o['group'] ?? 'default'),
            'seconds' => $seconds,
            'hostname' => $hostname,
            'reenroll' => $re,
            'note' => $note,
        ];
    }

    /**
     * @param array{kind?: string, group?: string, days?: int|string|null, hostname?: string|null, reenroll?: string|null, note?: string|null} $o
     * @return array{id: int, token: string, kind: string, group: string, expires_at: string, hostname: ?string, reenroll: ?string}
     */
    public function create(array $o, string $actor, ?float $now = null, ?int $userId = null, string $ip = ''): array
    {
        $r = $this->check($o);
        $now ??= microtime(true);
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare('SELECT id FROM host_groups WHERE name = ?');
            $s->execute([$r['group']]);
            $groupId = $s->fetchColumn();
            if ($groupId === false) {
                throw new \InvalidArgumentException("no host group named {$r['group']}");
            }
            if ($r['reenroll'] !== null) {
                // §2.5: a re-enrolment token is for an agent that exists; a
                // revoked one is refused at enrolment (403 agent_revoked), so
                // it is refused here too.
                $s = $this->pdo->prepare('SELECT status FROM agents WHERE agent_id = ?');
                $s->execute([$r['reenroll']]);
                $status = $s->fetchColumn();
                if ($status === false) {
                    throw new \InvalidArgumentException("no agent with ID {$r['reenroll']}");
                }
                if ($status !== 'active') {
                    throw new \InvalidArgumentException("agent {$r['reenroll']} is $status; a re-enrolment token is only for an active agent");
                }
            }
            $token = EnrolToken::generate();
            $created = Time::nowDb($now);
            $expires = Time::nowDb($now + $r['seconds']);
            $s = $this->pdo->prepare(
                'INSERT INTO enrollment_tokens (token_sha256, group_id, expected_hostname, reenroll_agent_id, token_kind, note, created_by, created_by_name, created_at, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $s->bindValue(1, EnrolToken::hash($token), PDO::PARAM_LOB);
            $s->bindValue(2, (int) $groupId, PDO::PARAM_INT);
            $s->bindValue(3, $r['hostname']);
            $s->bindValue(4, $r['reenroll']);
            $s->bindValue(5, $r['kind']);
            $s->bindValue(6, $r['note']);
            $s->bindValue(7, $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $s->bindValue(8, mb_substr($actor, 0, 100));
            $s->bindValue(9, $created);
            $s->bindValue(10, $expires);
            $s->execute();
            $id = (int) $this->pdo->lastInsertId();
            Audit::write($this->pdo, 'enrollment_token_created', $userId, $actor, $ip, 'enrollment_token', (string) $id, array_filter([
                'kind' => $r['kind'],
                'group' => $r['group'],
                'expires_at' => $expires,
                'expected_hostname' => $r['hostname'],
                'reenroll_agent_id' => $r['reenroll'],
            ], static fn ($v) => $v !== null));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return [
            'id' => $id,
            'token' => $token,
            'kind' => $r['kind'],
            'group' => $r['group'],
            'expires_at' => $expires,
            'hostname' => $r['hostname'],
            'reenroll' => $r['reenroll'],
        ];
    }

    /**
     * Tokens, newest first, each with its state: open, used, expired or cancelled.
     *
     * @return list<array<string, mixed>>
     */
    public function list(bool $all = false, ?float $now = null, int $limit = 200): array
    {
        $nowDb = Time::nowDb($now);
        $sql = 'SELECT t.id, t.token_kind, g.name AS group_name, t.expected_hostname, t.reenroll_agent_id, t.note,
                       t.created_by_name, t.created_at, t.expires_at, t.used_at, a.agent_id AS used_by_agent_id,
                       t.cancelled_at, t.cancelled_by,
                       CASE WHEN t.used_at IS NOT NULL THEN \'used\'
                            WHEN t.cancelled_at IS NOT NULL THEN \'cancelled\'
                            WHEN t.expires_at <= ? THEN \'expired\'
                            ELSE \'open\' END AS state
                FROM enrollment_tokens t
                JOIN host_groups g ON g.id = t.group_id
                LEFT JOIN agents a ON a.id = t.used_by_agent_pk'
            . ($all ? '' : ' WHERE t.used_at IS NULL AND t.cancelled_at IS NULL AND t.expires_at > ?')
            . ' ORDER BY t.id DESC LIMIT ' . max(1, $limit);
        $s = $this->pdo->prepare($sql);
        $s->execute($all ? [$nowDb] : [$nowDb, $nowDb]);
        return $s->fetchAll();
    }

    /** @return list<string> the host group names, for the admin page's form */
    public function groups(): array
    {
        return array_map('strval', $this->pdo->query('SELECT name FROM host_groups ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Cancels an open token. Throws \InvalidArgumentException if it isn't open. */
    public function cancel(int $id, string $actor, ?float $now = null, ?int $userId = null, string $ip = ''): void
    {
        $nowDb = Time::nowDb($now);
        $this->pdo->beginTransaction();
        try {
            $s = $this->pdo->prepare('SELECT used_at, cancelled_at, expires_at FROM enrollment_tokens WHERE id = ? FOR UPDATE');
            $s->execute([$id]);
            $t = $s->fetch();
            $why = match (true) {
                !is_array($t) => "no token with ID $id",
                $t['used_at'] !== null => "token $id was already used",
                $t['cancelled_at'] !== null => "token $id is already cancelled",
                $t['expires_at'] <= $nowDb => "token $id has expired",
                default => null,
            };
            if ($why !== null) {
                throw new \InvalidArgumentException($why);
            }
            $this->pdo->prepare('UPDATE enrollment_tokens SET cancelled_at = ?, cancelled_by = ? WHERE id = ?')
                ->execute([$nowDb, mb_substr($actor, 0, 100), $id]);
            Audit::write($this->pdo, 'enrollment_token_cancelled', $userId, $actor, $ip, 'enrollment_token', (string) $id);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
