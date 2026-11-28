<?php

/**
 * Builds the agent CA's CRL (milestone 3 step 9; M-071, M-068, MS-SEC-02,
 * ingest-api.md §2.4 step 3). Run by aio-crl.service as root: daily from
 * aio-crl.timer, and from aio-crl.path whenever an agent or certificate is
 * revoked or unrevoked (the dashboard and bin/agent.php write the request
 * file). Root only to read maint.cnf and the agent CA key; it prints no key.
 *
 *   sudo systemctl start aio-crl.service          # what the units run
 *   sudo php tools/jobs/crl-build.php             # the same, by hand
 *   sudo php tools/jobs/crl-build.php --no-reload # write the CRL, don't reload Apache
 *
 * Each build: the revoked certificates from the database (CrlBuilder), signed
 * with openssl, verified against the CA certificate and read back, its
 * serials compared with the database's, then written over the old CRL in
 * one rename, and Apache reloaded so 8443 uses it. Takes a second or two.
 * A request that arrives during a build makes it build once more.
 *
 * The state file (public, no secret) says when it was built, until when it
 * is valid, how many serials it lists and the last failure; the dashboard and
 * the health page show it, and warn 7 days before it expires or as soon as a
 * build fails (M-068). On a failure the old CRL stays in place.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Pki\CrlBuilder;
use Aio\Pki\OpensslTool;

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    fwrite(STDERR, "run it as root (sudo): it reads maint.cnf and the agent CA key\n");
    exit(2);
}
$reload = !in_array('--no-reload', $argv, true);
$config = Config::load();
$crlFile = $config->string('crl', 'crl_file');
$stateFile = $config->string('crl', 'state_file');
$requestFile = $config->string('crl', 'request_file');
$include = $config->string('crl', 'apache_include');

$stateDir = dirname($stateFile);
if (!is_dir($stateDir) && !mkdir($stateDir, 0755, true)) {
    fwrite(STDERR, "can't make $stateDir\n");
    exit(1);
}
// One build at a time (the timer, the path unit and a run by hand).
$lock = fopen("$stateDir/.lock", 'c');
if ($lock === false || !flock($lock, LOCK_EX)) {
    fwrite(STDERR, "can't lock $stateDir/.lock\n");
    exit(1);
}

$readState = static function () use ($stateFile): array {
    $s = is_readable($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
    return is_array($s) ? $s : [];
};
$writeState = static function (array $s) use ($stateFile): void {
    $tmp = $stateFile . '.tmp';
    file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    chmod($tmp, 0644);
    rename($tmp, $stateFile);
};
$mtime = static function () use ($requestFile): string {
    clearstatcache(true, $requestFile);
    return is_file($requestFile) ? (string) @file_get_contents($requestFile) . '|' . (string) @filemtime($requestFile) : '';
};

$builder = new CrlBuilder(
    new OpensslTool(),
    $config->string('crl', 'ca_cert'),
    $config->string('crl', 'ca_key'),
    $config->int('crl', 'valid_days'),
);
$pdo = null;
$exit = 0;
for ($pass = 1; $pass <= 5; $pass++) {
    $asked = $mtime();
    $t0 = microtime(true);
    $work = sys_get_temp_dir() . '/aio-crl-' . bin2hex(random_bytes(6));
    try {
        $pdo ??= Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
        $rows = CrlBuilder::revoked($pdo);
        $byAgent = count(array_filter($rows, static fn (array $r): bool => $r['why'] === 'agent'));
        printf("build %d: %d certificates to list (%d of revoked agents, %d revoked on their own)\n", $pass, count($rows), $byAgent, count($rows) - $byAgent);
        if (!mkdir($work, 0700)) {
            throw new \RuntimeException("can't make a work directory");
        }
        $pem = $builder->build($rows, $work);
        $tmp = $crlFile . '.tmp';
        if (file_put_contents($tmp, $pem) === false) {
            throw new \RuntimeException("can't write $tmp");
        }
        chmod($tmp, 0644);
        $got = $builder->inspect($tmp);
        $want = array_map(static fn (array $r): string => CrlBuilder::serial($r['serial_hex']), $rows);
        sort($want);
        $have = $got['serials'];
        sort($have);
        if ($want !== $have) {
            unlink($tmp);
            throw new \RuntimeException('the signed CRL does not list exactly the revoked serials');
        }
        rename($tmp, $crlFile);
        $state = [
            'built_at' => $got['last_update'],
            // Milliseconds, so a check can tell two builds in one second apart.
            'build_ms' => (int) round(microtime(true) * 1000),
            'next_update' => $got['next_update'],
            'entries' => count($rows),
            'by_agent' => $byAgent,
            'last_error' => null,
            'error_at' => null,
        ];
        $msg = '';
        if ($reload && is_file($include)) {
            exec('/usr/bin/systemctl reload apache2 2>&1', $out, $rc);
            if ($rc !== 0) {
                // The new CRL is on disk but 8443 still uses the old one.
                $state['last_error'] = 'the CRL was written but Apache could not be reloaded';
                $state['error_at'] = gmdate('Y-m-d H:i:s');
                $exit = 1;
            } else {
                $msg = '; Apache reloaded';
            }
        } elseif ($reload) {
            $msg = '; Apache not reloaded: 8443 does not load the CRL yet (setup/11-enrolment.sh adds it)';
        }
        $writeState($state);
        printf("CRL written: %d serials, valid until %s UTC%s (%.1f s)\n", count($rows), $got['next_update'], $msg, microtime(true) - $t0);
        if ($exit !== 0) {
            fwrite(STDERR, $state['last_error'] . ': ' . implode(' ', array_slice($out ?? [], 0, 3)) . "\n");
            break;
        }
    } catch (\Throwable $e) {
        $s = $readState();
        $s['last_error'] = mb_substr($e->getMessage(), 0, 300);
        $s['error_at'] = gmdate('Y-m-d H:i:s');
        $writeState($s);
        fwrite(STDERR, 'CRL build failed, the old CRL stays in place: ' . $s['last_error'] . "\n");
        $exit = 1;
        break;
    } finally {
        array_map('unlink', glob("$work/*") ?: []);
        @rmdir($work);
    }
    if ($mtime() === $asked) {
        break;
    }
    echo "another rebuild was asked for during this one; building again\n";
}
flock($lock, LOCK_UN);
exit($exit);
