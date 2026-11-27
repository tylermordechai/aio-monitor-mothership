<?php

/**
 * Agents from the command line: list, show, revoke, unrevoke (ingest-api.md
 * §2.4, §2.5, §8.1 rule 8; MS-SEC-02, MS-SEC-08, M-066).
 *
 *   sudo php bin/agent.php list [--status=active|revoked|replaced]
 *   sudo php bin/agent.php show <agent ID>
 *   sudo php bin/agent.php revoke <agent ID> --reason="..."
 *   sudo php bin/agent.php unrevoke <agent ID> --reason="..."
 *   sudo php bin/agent.php revoke-cert <serial> --reason="..."
 *
 * - revoke: every request of the agent is refused from now on (403
 *   agent_revoked), with any of its certificates; the agent keeps its queue
 *   and tries once an hour. Its agent ID can't enrol again.
 * - unrevoke: undoes a revoke; the agent's certificates that aren't revoked
 *   themselves work again, and it sends what it kept.
 * - revoke-cert: one certificate, for good; the agent can go on with another.
 *
 * Every change needs a reason and is written to audit_log as "cli:<your
 * login>" (MS-SEC-08). Runs as root to read the aio_maint credentials (M-036).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Enrol\AgentAdmin;

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? '';
$flags = [];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) {
        $flags[$m[1]] = $m[2] ?? true;
    } else {
        $pos[] = $a;
    }
}
$usage = "usage: sudo php bin/agent.php list [--status=active|revoked|replaced]\n"
    . "       sudo php bin/agent.php show <agent ID>\n"
    . "       sudo php bin/agent.php revoke|unrevoke <agent ID> --reason=\"...\"\n"
    . "       sudo php bin/agent.php revoke-cert <serial> --reason=\"...\"\n";
$known = ['list' => ['status'], 'show' => [], 'revoke' => ['reason'], 'unrevoke' => ['reason'], 'revoke-cert' => ['reason']];
if (!isset($known[$cmd])) {
    fwrite($cmd === 'help' ? STDOUT : STDERR, $usage);
    exit($cmd === 'help' ? 0 : 2);
}
foreach (array_keys($flags) as $f) {
    if (!in_array($f, $known[$cmd], true) || $flags[$f] === true) {
        fwrite(STDERR, "unknown option or missing value: --$f\n" . $usage);
        exit(2);
    }
}
if ($cmd !== 'list' && count($pos) !== 1) {
    fwrite(STDERR, $usage);
    exit(2);
}

$config = Config::load();
try {
    $pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
} catch (\Throwable $e) {
    fwrite(STDERR, "can't connect with " . $config->string('db', 'maint_cnf') . " (run with sudo)\n");
    exit(1);
}
$actor = 'cli:' . (getenv('SUDO_USER') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'root') : 'root'));
$admin = new AgentAdmin($pdo);
$id = $pos[0] ?? '';
$reason = (string) ($flags['reason'] ?? '');

try {
    switch ($cmd) {
        case 'list':
            $status = $flags['status'] ?? null;
            if ($status !== null && !in_array($status, ['active', 'revoked', 'replaced'], true)) {
                fwrite(STDERR, "--status must be active, revoked or replaced\n");
                exit(2);
            }
            $rows = $admin->list($status);
            if ($rows === []) {
                echo "no agents\n";
                break;
            }
            $fmt = "%-36s %-8s %-24s %-14s %-19s %-12s %s\n";
            printf($fmt, 'agent ID', 'status', 'hostname', 'group', 'last check-in (UTC)', 'version', 'usable certs');
            foreach ($rows as $r) {
                printf($fmt, $r['agent_id'], $r['status'], mb_strimwidth((string) $r['hostname'], 0, 24), mb_strimwidth((string) $r['group_name'], 0, 14), substr((string) $r['last_checkin_at'], 0, 19), (string) $r['last_version'], (string) $r['usable_certs']);
            }
            break;

        case 'show':
            $d = $admin->show($id);
            $a = $d['agent'];
            echo "Agent {$a['agent_id']}: {$a['status']}" . ($a['status'] === 'revoked' ? " since {$a['revoked_at']} ({$a['revoke_reason']})" : '') . "\n"
                . "Host {$a['hostname']} (MachineGuid {$a['machine_guid']}), group {$a['group_name']}\n"
                . "Enrolled {$a['enrolled_at']}, last check-in " . ($a['last_checkin_at'] ?? 'never') . ', version ' . ($a['last_version'] ?? '-') . "\n\nCertificates:\n";
            foreach ($d['certificates'] as $c) {
                printf("  %-32s %-10s %-6s issued %s, expires %s%s\n", $c['serial_hex'], $c['status'], $c['issued_via'], substr((string) $c['issued_at'], 0, 19), substr((string) $c['not_after'], 0, 19), $c['revoke_reason'] !== null ? " ({$c['revoke_reason']})" : '');
            }
            break;

        case 'revoke':
            $admin->revoke($id, $reason, $actor);
            echo "agent $id revoked: every request it makes is refused from now on\n";
            break;

        case 'unrevoke':
            $admin->unrevoke($id, $reason, $actor);
            echo "agent $id unrevoked: its certificates that aren't revoked work again\n";
            break;

        case 'revoke-cert':
            $admin->revokeCertificate($id, $reason, $actor);
            echo "certificate $id revoked\n";
            break;
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "; nothing changed\n");
    exit(1);
}
exit(0);
