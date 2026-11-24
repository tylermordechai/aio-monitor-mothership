<?php

/**
 * One-time enrolment tokens from the command line (ingest-api.md §2.1, §2.5;
 * MS-SEC-03, M-021, M-061).
 *
 *   sudo php bin/token-create.php create [--kind=manual|deployment] [--days=1..7]
 *        [--group=<host group>] [--hostname=<name>] [--reenroll=<agent ID>] [--note="..."]
 *   sudo php bin/token-create.php list [--all]
 *   sudo php bin/token-create.php cancel <token ID>
 *
 * - manual (the default) lasts 24 h; deployment lasts --days (default and
 *   most 7), for an Intune or GPO package (M-021).
 * - --group puts the host in that group (default "default").
 * - --hostname: only a host with this name may use it.
 * - --reenroll: a re-enrolment token for an agent that lost its certificate
 *   (§2.5); its old certificates are revoked when it is used.
 *
 * The token is printed once, by create, and only its SHA-256 is stored. When
 * standard output isn't a terminal (for example `> token.txt`), only the
 * token goes there and the details go to the terminal, so the file can be
 * given to the installer as it is. Every change is written to audit_log as
 * "cli:<your login>" (MS-SEC-08); the token is never in it (MS-SEC-10).
 *
 * Runs as root to read the aio_maint credentials (root-only, M-036).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Enrol\TokenStore;

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

$usage = "usage: sudo php bin/token-create.php create [--kind=manual|deployment] [--days=N] [--group=NAME] [--hostname=NAME] [--reenroll=AGENT_ID] [--note=TEXT]\n"
    . "       sudo php bin/token-create.php list [--all]\n"
    . "       sudo php bin/token-create.php cancel <token ID>\n";
if (!in_array($cmd, ['create', 'list', 'cancel'], true)) {
    fwrite($cmd === 'help' ? STDOUT : STDERR, $usage);
    exit($cmd === 'help' ? 0 : 2);
}
$known = ['create' => ['kind', 'days', 'group', 'hostname', 'reenroll', 'note'], 'list' => ['all'], 'cancel' => []][$cmd];
foreach (array_keys($flags) as $f) {
    if (!in_array($f, $known, true)) {
        fwrite(STDERR, "unknown option --$f for $cmd\n" . $usage);
        exit(2);
    }
    if ($f !== 'all' && $flags[$f] === true) {
        fwrite(STDERR, "--$f needs a value (--$f=...)\n");
        exit(2);
    }
}

$config = Config::load();
try {
    $pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
} catch (\Throwable $e) {
    fwrite(STDERR, "can't connect with " . $config->string('db', 'maint_cnf') . " (run with sudo)\n");
    exit(1);
}
$actor = 'cli:' . (getenv('SUDO_USER') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'root') : 'root'));
$store = new TokenStore($pdo, $config->int('enrol', 'manual_token_hours'), $config->int('enrol', 'deployment_max_days'));

try {
    switch ($cmd) {
        case 'create':
            $t = $store->create([
                'kind' => $flags['kind'] ?? 'manual',
                'days' => $flags['days'] ?? null,
                'group' => $flags['group'] ?? 'default',
                'hostname' => $flags['hostname'] ?? null,
                'reenroll' => $flags['reenroll'] ?? null,
                'note' => $flags['note'] ?? null,
            ], $actor);
            $details = "Token ID {$t['id']}: {$t['kind']}, group {$t['group']}, expires {$t['expires_at']} UTC"
                . ($t['hostname'] !== null ? ", only for host {$t['hostname']}" : '')
                . ($t['reenroll'] !== null ? ", re-enrolment of agent {$t['reenroll']}" : '')
                . ".\nIt is shown only now and works once. Cancel it with: sudo php bin/token-create.php cancel {$t['id']}\n";
            if (stream_isatty(STDOUT)) {
                fwrite(STDOUT, "\n  {$t['token']}\n\n$details");
            } else {
                fwrite(STDOUT, $t['token'] . "\n");
                fwrite(STDERR, $details);
            }
            break;

        case 'list':
            $rows = $store->list(isset($flags['all']));
            if ($rows === []) {
                fwrite(STDOUT, isset($flags['all']) ? "no tokens\n" : "no open tokens (--all shows used, expired and cancelled ones)\n");
                break;
            }
            $fmt = "%-6s %-10s %-9s %-14s %-19s %-19s %s\n";
            printf($fmt, 'ID', 'kind', 'state', 'group', 'created (UTC)', 'expires (UTC)', 'limits, note');
            foreach ($rows as $r) {
                $extra = [];
                if ($r['expected_hostname'] !== null) {
                    $extra[] = "host {$r['expected_hostname']}";
                }
                if ($r['reenroll_agent_id'] !== null) {
                    $extra[] = "re-enrol {$r['reenroll_agent_id']}";
                }
                if ($r['used_by_agent_id'] !== null) {
                    $extra[] = "used by {$r['used_by_agent_id']}";
                }
                if ($r['cancelled_by'] !== null) {
                    $extra[] = "cancelled by {$r['cancelled_by']}";
                }
                if ($r['note'] !== null) {
                    $extra[] = '"' . $r['note'] . '"';
                }
                printf(
                    $fmt,
                    $r['id'],
                    $r['token_kind'],
                    $r['state'],
                    mb_strimwidth((string) $r['group_name'], 0, 14),
                    substr((string) $r['created_at'], 0, 19),
                    substr((string) $r['expires_at'], 0, 19),
                    implode(', ', $extra)
                );
            }
            break;

        case 'cancel':
            $id = $pos[0] ?? '';
            if (!ctype_digit($id)) {
                fwrite(STDERR, "a token ID (a number, from list) is needed\n" . $usage);
                exit(2);
            }
            $store->cancel((int) $id, $actor);
            fwrite(STDOUT, "token $id cancelled\n");
            break;
    }
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "; nothing changed\n");
    exit(1);
}
exit(0);
