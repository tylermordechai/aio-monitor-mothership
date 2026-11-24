<?php

/**
 * Enrols a throwaway agent through the real agent API on 8443, the way the
 * agent will (ingest-api.md §2; MS-ING-06, MS-SEC-02, MS-SEC-03, M-062), then
 * removes it. Run by setup/11-enrolment.sh as root:
 *
 *   sudo php tools/enrol-check.php [https://192.168.0.191:8443]
 *
 * Steps: a token in the host group "setup-check" → POST /api/v1/enroll with a
 * fresh P-256 key → 201 and a certificate that chains to the agent CA → a
 * heartbeat with it → the same token again (403) → a second token for the
 * same agent ID (409) → the agent revoked → its heartbeat refused (403
 * agent_revoked). Then every row of the "setup-check" group is deleted, with
 * this run's failure rows; the audit rows stay. The key lives only in a 700
 * temporary folder and is deleted. Prints PASS:/FAIL: lines; exit code 1 on
 * any failure.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Enrol\TokenStore;
use Aio\Ingest\Schema\TableMap;
use Aio\Util\Ip;
use Aio\Util\Time;
use Aio\Util\Uuid;

const GROUP = 'setup-check';

if (posix_geteuid() !== 0) {
    fwrite(STDERR, "run it with sudo (aio_maint credentials)\n");
    exit(2);
}
$base = rtrim($argv[1] ?? 'https://192.168.0.191:8443', '/');
$config = Config::load();
$caFile = $config->string('enrol', 'agent_ca_public');
$pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
$started = Time::nowDb(microtime(true) - 1);
$fails = 0;
$say = static function (bool $ok, string $what) use (&$fails): bool {
    echo ($ok ? 'PASS: ' : 'FAIL: ') . $what . "\n";
    $fails += $ok ? 0 : 1;
    return $ok;
};

/** @return array{0: int, 1: array<string, mixed>} status and decoded body */
$post = static function (string $path, string $json, ?string $certFile = null) use ($base, $caFile): array {
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $caFile, 'peer_name' => (string) parse_url($base, PHP_URL_HOST)];
    if ($certFile !== null) {
        $ssl['local_cert'] = $certFile;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nUser-Agent: aio-monitor-agent/0.0.0-setup-check\r\n",
            'content' => $json,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
        'ssl' => $ssl,
    ]);
    $body = @file_get_contents($base . $path, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    $d = is_string($body) ? json_decode($body, true) : null;
    return [$status, is_array($d) ? $d : []];
};
$code = static fn (array $b): string => (string) ($b['error']['code'] ?? '');

$work = sys_get_temp_dir() . '/aio-enrol-check-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$agentId = Uuid::v4();
$store = new TokenStore($pdo);

try {
    $pdo->prepare('INSERT IGNORE INTO host_groups (name, description) VALUES (?, ?)')
        ->execute([GROUP, 'Throwaway agents of setup/11-enrolment.sh; removed by tools/enrol-check.php']);
    $token = $store->create(['group' => GROUP, 'note' => 'tools/enrol-check.php'], 'cli:enrol-check')['token'];

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr = openssl_csr_new(['commonName' => $agentId], $key, ['digest_alg' => 'sha256']);
    openssl_csr_export($csr, $csrPem);
    openssl_pkey_export($key, $keyPem);
    $enrolBody = static fn (string $t): string => (string) json_encode([
        'enrollment_token' => $t,
        'csr' => $csrPem,
        'agent_id' => $agentId,
        'host_id' => Uuid::v4(),
        'hostname' => 'AIO-ENROL-CHECK',
        'agent_version' => '0.0.0-setup-check',
    ]);

    [$st, $b] = $post('/api/v1/enroll', $enrolBody($token));
    $ok = $say($st === 201 && ($b['agent_id'] ?? '') === $agentId, "enrol: 201 for a new agent (got $st " . $code($b) . ')');
    if (!$ok) {
        throw new RuntimeException('stop');
    }
    $ca = (string) file_get_contents($caFile);
    $say(openssl_x509_verify((string) $b['certificate'], $ca) === 1, 'the certificate chains to the agent CA');
    $say(trim((string) $b['ca_certificate']) === trim($ca), 'ca_certificate is the agent CA');
    $say(($b['host_group'] ?? '') === GROUP, 'host_group is the token\'s group');
    $say(strtotime((string) $b['not_after']) - strtotime((string) $b['renew_after']) === 30 * 86_400, 'renew_after is 30 days before not_after');
    file_put_contents("$work/agent.pem", $b['certificate'] . $keyPem);
    chmod("$work/agent.pem", 0600);

    [$st, $hb] = $post('/api/v1/heartbeat', '{"agent_time":"' . gmdate('Y-m-d\TH:i:s\Z') . '"}', "$work/agent.pem");
    $say($st === 200, "a heartbeat with the new certificate: 200 (got $st " . $code($hb) . ')');

    [$st, $again] = $post('/api/v1/enroll', $enrolBody($token));
    $say($st === 403 && $code($again) === 'enrollment_token_invalid', "the same token again: 403 enrollment_token_invalid (got $st " . $code($again) . ')');

    $second = $store->create(['group' => GROUP, 'note' => 'tools/enrol-check.php'], 'cli:enrol-check');
    [$st, $dup] = $post('/api/v1/enroll', $enrolBody($second['token']));
    $say($st === 409 && $code($dup) === 'agent_id_in_use', "a new token for the same agent ID: 409 agent_id_in_use (got $st " . $code($dup) . ')');
    $store->cancel($second['id'], 'cli:enrol-check');

    $pdo->prepare('UPDATE agents SET status = \'revoked\', revoked_at = ?, revoke_reason = ? WHERE agent_id = ?')
        ->execute([Time::nowDb(), 'tools/enrol-check.php', $agentId]);
    [$st, $rv] = $post('/api/v1/heartbeat', '{}', "$work/agent.pem");
    $say($st === 403 && $code($rv) === 'agent_revoked', "after revoking it, its heartbeat: 403 agent_revoked (got $st " . $code($rv) . ')');
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'stop') {
        $say(false, 'error: ' . $e->getMessage());
    }
} finally {
    array_map('unlink', glob("$work/*") ?: []);
    @rmdir($work);
    $removed = cleanUp($pdo, (string) parse_url($base, PHP_URL_HOST), $started);
    $say($removed >= 0, "removed the throwaway agent and its rows ($removed agents)");
}
exit($fails === 0 ? 0 : 1);

/** Deletes every agent and host of the "setup-check" group; keeps audit rows. */
function cleanUp(PDO $pdo, string $ip, string $since): int
{
    $s = $pdo->prepare('SELECT a.id AS agent_pk, h.id AS host_pk FROM hosts h JOIN host_groups g ON g.id = h.group_id LEFT JOIN agents a ON a.host_pk = h.id WHERE g.name = ?');
    $s->execute([GROUP]);
    $rows = $s->fetchAll();
    $agents = array_values(array_filter(array_map('intval', array_column($rows, 'agent_pk'))));
    $hosts = array_values(array_unique(array_map('intval', array_column($rows, 'host_pk'))));
    $pdo->beginTransaction();
    if ($agents !== []) {
        $in = implode(',', $agents);
        $pdo->exec("UPDATE enrollment_tokens SET used_by_agent_pk = NULL WHERE used_by_agent_pk IN ($in)");
        foreach (TableMap::tables() as $t) {
            if (!in_array($t, ['agents', 'enrollment_tokens'], true) && isset(TableMap::columns($t)['agent_pk'])) {
                $pdo->exec("DELETE FROM `$t` WHERE agent_pk IN ($in)");
            }
        }
        $pdo->exec("DELETE FROM agents WHERE id IN ($in)");
    }
    if ($hosts !== []) {
        $hin = implode(',', $hosts);
        foreach (TableMap::tables() as $t) {
            if (!in_array($t, ['hosts', 'agents'], true) && isset(TableMap::columns($t)['host_pk'])) {
                $pdo->exec("DELETE FROM `$t` WHERE host_pk IN ($hin)");
            }
        }
        $pdo->exec("DELETE FROM hosts WHERE id IN ($hin)");
    }
    // This run's deliberate refusals don't count towards the address limit.
    $bin = Ip::toBinary($ip);
    if ($bin !== null) {
        $d = $pdo->prepare('DELETE FROM enrollment_failures WHERE source_ip = ? AND at >= ?');
        $d->bindValue(1, $bin, PDO::PARAM_LOB);
        $d->bindValue(2, $since);
        $d->execute();
    }
    $pdo->commit();
    return count($agents);
}
