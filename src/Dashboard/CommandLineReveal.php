<?php

declare(strict_types=1);

namespace Aio\Dashboard;

use Aio\Web\Audit;
use Aio\Web\Roles;
use PDO;

/**
 * Showing one command line (MS-SEC-07..09, M-053, M-056).
 *
 * - Only analysts and admins (Roles::seesCommandLines); anyone else gets
 *   "not allowed" and nothing is read.
 * - The process record is found as aio_dashboard, on the host the page is
 *   for, so a request can't reach another host's command line by guessing.
 * - The command line is read as aio_analyst, the only database user that can
 *   read process_command_lines, and its view_command_line audit row is
 *   written on the same connection, in the same transaction: no command line
 *   is shown without its audit row, and a failed audit write shows nothing.
 * - Cleared command lines (older than 30 days *(confirm)*, M-017) are
 *   reported as cleared; that look is audited too.
 */
final class CommandLineReveal
{
    /** @param \Closure(): PDO $analyst opens the aio_analyst connection (analyst.cnf) */
    public function __construct(private readonly PDO $dashboard, private readonly \Closure $analyst)
    {
    }

    /**
     * @param array{id: int, username: string, role: string} $user
     * @return array{status: string, process?: array<string, mixed>, command_line?: string}
     *   status: shown, cleared, not_allowed, not_found
     */
    public function reveal(array $user, int $hostPk, int $agentPk, int $seq, string $ts, string $ip): array
    {
        if (!Roles::seesCommandLines($user['role'])) {
            return ['status' => 'not_allowed'];
        }
        if (!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d{1,6})?$/', $ts)) {
            return ['status' => 'not_found'];
        }
        $s = $this->dashboard->prepare(
            "SELECT p.agent_pk, p.seq, p.ts, p.pid, p.process_name, p.exe, p.start_time, p.`user`, p.has_command_line,
                    p.command_line_length, p.command_line_truncated, p.command_line_redacted, h.hostname
               FROM process_events p JOIN hosts h ON h.id = p.host_pk
              WHERE p.agent_pk = ? AND p.seq = ? AND p.ts = ? AND p.host_pk = ?
                AND p.record_type IN ('process_start', 'process_snapshot')"
        );
        $s->execute([$agentPk, $seq, $ts, $hostPk]);
        $p = $s->fetch();
        if (!is_array($p)) {
            return ['status' => 'not_found'];
        }

        $a = ($this->analyst)();
        $a->beginTransaction();
        try {
            $c = $a->prepare('SELECT command_line FROM process_command_lines WHERE agent_pk = ? AND seq = ? AND ts = ?');
            $c->execute([$agentPk, $seq, $ts]);
            $line = $c->fetchColumn();
            $shown = is_string($line);
            Audit::write($a, Audit::VIEW_COMMAND_LINE, $user['id'], $user['username'], $ip, 'process', "$agentPk:$seq", [
                'host_pk' => $hostPk,
                'hostname' => (string) $p['hostname'],
                'pid' => (int) $p['pid'],
                'process_name' => $p['process_name'],
                'ts' => (string) $p['ts'],
                'result' => $shown ? 'shown' : 'cleared',
            ]);
            $a->commit();
        } catch (\Throwable $e) {
            $a->rollBack();
            throw $e;
        }
        $out = ['status' => $shown ? 'shown' : 'cleared', 'process' => $p];
        if ($shown) {
            $out['command_line'] = $line;
        }
        return $out;
    }
}
