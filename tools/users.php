<?php

/**
 * Dashboard accounts from the command line (MS-SEC-06, 07, 08; M-054).
 * There is no users page in milestone 5; this is how accounts are made.
 *
 *   sudo php tools/users.php add <name> --role=viewer|analyst|admin [--display="Full Name"]
 *   sudo php tools/users.php list
 *   sudo php tools/users.php role <name> <viewer|analyst|admin>
 *   sudo php tools/users.php password <name>
 *   sudo php tools/users.php unlock <name>        # clears a lockout and the failure count
 *   sudo php tools/users.php disable <name>       # signs the user out at their next click
 *   sudo php tools/users.php enable <name>
 *
 * Passwords are typed at a hidden prompt, twice; `--password-stdin` reads one
 * line from standard input instead (for scripts). A password is never
 * printed, logged or put in the audit details (MS-SEC-10). Every change is
 * written to audit_log as "cli:<your login>" (MS-SEC-08).
 *
 * Runs as root to read the dashboard's database credentials; the database
 * user is aio_dashboard, which may change only `users` and add to
 * `audit_log` (M-053).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Util\Time;
use Aio\Web\Audit;
use Aio\Web\Auth;
use Aio\Web\Roles;

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? '';
$flags = [];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $flags[$m[1]] = $m[2] ?? true;
    } else {
        $pos[] = $a;
    }
}

$usage = "usage: sudo php tools/users.php add <name> --role=viewer|analyst|admin [--display=\"Name\"] [--password-stdin]\n"
    . "       sudo php tools/users.php list | role <name> <role> | password <name> [--password-stdin] | unlock <name> | disable <name> | enable <name>\n";
if ($cmd === '' || $cmd === 'help' || isset($flags['help'])) {
    fwrite(STDOUT, $usage);
    exit($cmd === '' ? 2 : 0);
}

$config = Config::load();
try {
    $pdo = Db::connect($config->string('db', 'dashboard_cnf'), $config->string('db', 'name'));
} catch (\Throwable $e) {
    fwrite(STDERR, "can't connect with " . $config->string('db', 'dashboard_cnf') . " (run with sudo)\n");
    exit(1);
}
$actor = 'cli:' . (getenv('SUDO_USER') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'root') : 'root'));
$minLen = $config->int('dashboard', 'password_min_length');

$audit = static function (string $action, string $name, array $details = []) use ($pdo, $actor): void {
    Audit::write($pdo, $action, null, $actor, '', 'user', $name, $details);
};

$find = static function (string $name) use ($pdo): array {
    $s = $pdo->prepare('SELECT id, username, role, disabled_at, locked_until, failed_logins FROM users WHERE username = ?');
    $s->execute([$name]);
    $u = $s->fetch();
    if (!is_array($u)) {
        fwrite(STDERR, "no user named $name\n");
        exit(1);
    }
    return $u;
};

$readPassword = static function (string $name) use ($flags, $minLen): string {
    if (isset($flags['password-stdin'])) {
        $pw = rtrim((string) fgets(STDIN), "\r\n");
    } else {
        $ask = static function (string $prompt): string {
            $tty = @fopen('/dev/tty', 'r+');
            if ($tty === false) {
                fwrite(STDERR, "no terminal for the password prompt; use --password-stdin\n");
                exit(1);
            }
            fwrite($tty, $prompt);
            shell_exec('stty -echo < /dev/tty');
            $line = fgets($tty);
            shell_exec('stty echo < /dev/tty');
            fwrite($tty, "\n");
            fclose($tty);
            return rtrim((string) $line, "\r\n");
        };
        $pw = $ask("Password for $name (at least $minLen characters): ");
        if ($ask('Same password again: ') !== $pw) {
            fwrite(STDERR, "the two passwords differ; nothing changed\n");
            exit(1);
        }
    }
    $problem = Auth::passwordProblem($pw, $name, $minLen);
    if ($problem !== null) {
        fwrite(STDERR, "password not accepted: $problem; nothing changed\n");
        exit(1);
    }
    return $pw;
};

$name = $pos[0] ?? '';
if ($cmd !== 'list' && !preg_match('/^[A-Za-z0-9._@-]{1,100}$/', $name)) {
    fwrite(STDERR, "a user name of 1 to 100 letters, digits or . _ @ - is needed\n" . $usage);
    exit(2);
}

switch ($cmd) {
    case 'list':
        $rows = $pdo->query('SELECT username, role, display_name, disabled_at, locked_until, failed_logins, last_login_at FROM users ORDER BY username')->fetchAll();
        printf("%-24s %-8s %-10s %-20s %s\n", 'user', 'role', 'state', 'last sign-in (UTC)', 'name');
        foreach ($rows as $u) {
            $state = $u['disabled_at'] !== null ? 'disabled' : ($u['locked_until'] !== null && strtotime($u['locked_until'] . ' UTC') > time() ? 'locked' : 'active');
            printf("%-24s %-8s %-10s %-20s %s\n", $u['username'], $u['role'], $state, substr((string) ($u['last_login_at'] ?? '-'), 0, 19), $u['display_name'] ?? '');
        }
        exit(0);

    case 'add':
        $role = (string) ($flags['role'] ?? '');
        if (!Roles::valid($role)) {
            fwrite(STDERR, "--role must be one of " . implode(', ', Roles::all()) . "\n");
            exit(2);
        }
        $s = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $s->execute([$name]);
        if ((int) $s->fetchColumn() > 0) {
            fwrite(STDERR, "user $name already exists\n");
            exit(1);
        }
        $pw = $readPassword($name);
        $display = is_string($flags['display'] ?? null) ? mb_substr($flags['display'], 0, 200) : null;
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO users (username, display_name, role, auth_source, password_hash, created_at, password_changed_at) VALUES (?, ?, ?, 'local', ?, ?, ?)")
            ->execute([$name, $display, $role, password_hash($pw, PASSWORD_ARGON2ID), Time::nowDb(), Time::nowDb()]);
        $audit('user_add', $name, ['role' => $role]);
        $pdo->commit();
        unset($pw);
        echo "added $name ($role)\n";
        exit(0);

    case 'role':
        $role = $pos[1] ?? '';
        if (!Roles::valid($role)) {
            fwrite(STDERR, "role must be one of " . implode(', ', Roles::all()) . "\n");
            exit(2);
        }
        $u = $find($name);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $u['id']]);
        $audit('user_role', $name, ['from' => $u['role'], 'to' => $role]);
        $pdo->commit();
        echo "$name is now $role\n";
        exit(0);

    case 'password':
        $u = $find($name);
        $pw = $readPassword($name);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET password_hash = ?, password_changed_at = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
            ->execute([password_hash($pw, PASSWORD_ARGON2ID), Time::nowDb(), $u['id']]);
        $audit('user_password', $name);
        $pdo->commit();
        unset($pw);
        echo "password changed for $name\n";
        exit(0);

    case 'unlock':
        $u = $find($name);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$u['id']]);
        $audit('user_unlock', $name, ['was_locked_until' => $u['locked_until'], 'failed_logins' => (int) $u['failed_logins']]);
        $pdo->commit();
        echo "unlocked $name\n";
        exit(0);

    case 'disable':
    case 'enable':
        $u = $find($name);
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE users SET disabled_at = ? WHERE id = ?')->execute([$cmd === 'disable' ? Time::nowDb() : null, $u['id']]);
        $audit('user_' . $cmd, $name);
        $pdo->commit();
        echo "{$cmd}d $name\n";
        exit(0);
}

fwrite(STDERR, "unknown command $cmd\n" . $usage);
exit(2);
