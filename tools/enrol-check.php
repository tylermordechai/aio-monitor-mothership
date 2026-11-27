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
 * heartbeat with it (renew_after null) → renewal (M-064): the same key 400, a
 * new key 200, the renewed and the old certificate both working → the same
 * token again (403) → a second token for the same agent ID (409) → the agent
 * revoked with bin/agent.php (M-066) → heartbeats with both certificates
 * refused and no renewal (403 agent_revoked) → unrevoked → its heartbeat
 * works again. Then every row of the "setup-check" group is deleted, with
 * this run's failure rows; the audit rows stay. The keys live only in a 700
 * temporary folder and are deleted. Prints PASS:/FAIL: lines; exit code 1 on
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

/**
 * @param array<string, string> $headers extra request headers
 * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>} status, decoded body, response headers (lower-case names)
 */
$request = static function (string $method, string $path, ?string $json, ?string $certFile, array $headers = []) use ($base, $caFile): array {
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => $caFile, 'peer_name' => (string) parse_url($base, PHP_URL_HOST)];
    if ($certFile !== null) {
        $ssl['local_cert'] = $certFile;
    }
    $h = "User-Agent: aio-monitor-agent/0.0.0-setup-check\r\n" . ($json !== null ? "Content-Type: application/json\r\n" : '');
    foreach ($headers as $k => $v) {
        $h .= "$k: $v\r\n";
    }
    $http = ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'timeout' => 20];
    if ($json !== null) {
        $http['content'] = $json;
    }
    $body = @file_get_contents($base . $path, false, stream_context_create(['http' => $http, 'ssl' => $ssl]));
    $status = 0;
    $out = [];
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
            $status = (int) $m[1];
        } elseif (preg_match('/^([^:]+):\s*(.*)$/', $line, $m)) {
            $out[strtolower($m[1])] = trim($m[2]);
        }
    }
    $d = is_string($body) ? json_decode($body, true) : null;
    return [$status, is_array($d) ? $d : [], $out];
};
/** @return array{0: int, 1: array<string, mixed>} */
$post = static function (string $path, string $json, ?string $certFile = null) use ($request): array {
    [$st, $b] = $request('POST', $path, $json, $certFile);
    return [$st, $b];
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
    $say($st === 200 && array_key_exists('renew_after', $hb) && $hb['renew_after'] === null, 'the heartbeat says no renewal is due yet (renew_after null)');

    // The agent's settings (§5, M-065).
    [$st, $cfg, $ch] = $request('GET', '/api/v1/config', null, "$work/agent.pem");
    $etag = $ch['etag'] ?? '';
    $say($st === 200 && ($cfg['host_group'] ?? '') === GROUP && isset($cfg['intervals']['heartbeat_s'], $cfg['server_software']['detection_list']), "config: 200 with the group's settings (got $st " . $code($cfg) . ')');
    $say($etag !== '' && $etag === ($hb['config_etag'] ?? null), 'its ETag is the one the heartbeat gave');
    [$st, , $ch304] = $request('GET', '/api/v1/config', null, "$work/agent.pem", ['If-None-Match' => $etag]);
    $say($st === 304 && ($ch304['etag'] ?? '') === $etag, "config with If-None-Match: 304 Not Modified (got $st)");
    [$st] = $request('GET', '/api/v1/config', null, null);
    $say($st === 401, "config without a certificate: 401 (got $st)");

    // Renewal (§2.6, M-064): a new key, the same CN, with the current certificate.
    $key2 = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $csr2 = openssl_csr_new(['commonName' => $agentId], $key2, ['digest_alg' => 'sha256']);
    openssl_csr_export($csr2, $csr2Pem);
    openssl_pkey_export($key2, $key2Pem);
    [$st, $sameKey] = $post('/api/v1/renew', (string) json_encode(['csr' => $csrPem]), "$work/agent.pem");
    $say($st === 400 && $code($sameKey) === 'csr_invalid', "renew with the same key: 400 csr_invalid (got $st " . $code($sameKey) . ')');
    [$st, $rn] = $post('/api/v1/renew', (string) json_encode(['csr' => $csr2Pem]), "$work/agent.pem");
    $say($st === 200 && ($rn['agent_id'] ?? '') === $agentId && openssl_x509_verify((string) ($rn['certificate'] ?? ''), $ca) === 1, "renew with a new key: 200 and a certificate from the agent CA (got $st " . $code($rn) . ')');
    file_put_contents("$work/renewed.pem", ($rn['certificate'] ?? '') . $key2Pem);
    chmod("$work/renewed.pem", 0600);
    [$st] = $post('/api/v1/heartbeat', '{}', "$work/renewed.pem");
    $say($st === 200, "a heartbeat with the renewed certificate: 200 (got $st)");
    [$st] = $post('/api/v1/heartbeat', '{}', "$work/agent.pem");
    $say($st === 200, "the old certificate still works until it expires (superseded): 200 (got $st)");

    [$st, $again] = $post('/api/v1/enroll', $enrolBody($token));
    $say($st === 403 && $code($again) === 'enrollment_token_invalid', "the same token again: 403 enrollment_token_invalid (got $st " . $code($again) . ')');

    $second = $store->create(['group' => GROUP, 'note' => 'tools/enrol-check.php'], 'cli:enrol-check');
    [$st, $dup] = $post('/api/v1/enroll', $enrolBody($second['token']));
    $say($st === 409 && $code($dup) === 'agent_id_in_use', "a new token for the same agent ID: 409 agent_id_in_use (got $st " . $code($dup) . ')');
    $store->cancel($second['id'], 'cli:enrol-check');

    // Revoke and unrevoke with bin/agent.php, as an admin would (M-066).
    $cli = static function (string ...$a): int {
        exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__) . '/bin/agent.php', ...$a])) . ' 2>&1', $out, $rc);
        return $rc;
    };
    $say($cli('revoke', $agentId) !== 0, 'bin/agent.php revoke without a reason is refused');
    $say($cli('revoke', $agentId, '--reason=tools/enrol-check.php') === 0, 'bin/agent.php revoke');
    foreach (['agent.pem' => 'old', 'renewed.pem' => 'renewed'] as $file => $which) {
        [$st, $rv] = $post('/api/v1/heartbeat', '{}', "$work/$file");
        $say($st === 403 && $code($rv) === 'agent_revoked', "after revoking it, a heartbeat with the $which certificate: 403 agent_revoked (got $st " . $code($rv) . ')');
    }
    [$st, $rv] = $post('/api/v1/renew', (string) json_encode(['csr' => $csr2Pem]), "$work/renewed.pem");
    $say($st === 403 && $code($rv) === 'agent_revoked', "and it can't renew: 403 agent_revoked (got $st " . $code($rv) . ')');
    $say($cli('unrevoke', $agentId, '--reason=tools/enrol-check.php') === 0, 'bin/agent.php unrevoke');
    [$st] = $post('/api/v1/heartbeat', '{}', "$work/renewed.pem");
    $say($st === 200, "after the unrevoke, its heartbeat: 200 again (got $st)");

    // The gate's second half (M-067): a silent agent is flagged in the fleet.
    $fleetStatus = static function () use ($pdo, $config, $agentId): ?array {
        $s = $pdo->prepare('SELECT host_pk FROM agents WHERE agent_id = ?');
        $s->execute([$agentId]);
        $hostPk = (int) $s->fetchColumn();
        foreach ((new Aio\Dashboard\FleetQuery($pdo, $config))->hosts() as $h) {
            if ($h['host_pk'] === $hostPk) {
                return $h;
            }
        }
        return null;
    };
    $say(($fleetStatus()['status'] ?? '') === 'ok', 'the fleet shows it checking in');
    $pdo->prepare('UPDATE agents SET last_checkin_at = UTC_TIMESTAMP(6) - INTERVAL 10 MINUTE WHERE agent_id = ?')->execute([$agentId]);
    $say(($fleetStatus()['status'] ?? '') === 'stale', 'with no check-in for 10 minutes, the fleet flags it silent');
    [$st] = $post('/api/v1/heartbeat', '{"queue":{"records":0,"undeliverable_records":2,"undeliverable_bytes":512}}', "$work/renewed.pem");
    $f = $fleetStatus();
    $say($st === 200 && ($f['status'] ?? '') === 'ok' && ($f['undeliverable_records'] ?? null) === 2, 'a heartbeat clears the flag, and the fleet shows its undeliverable count (2)');
    $au = $pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE target_id = ? AND action IN (\'agent_revoked\', \'agent_unrevoked\') AND username LIKE \'cli:%\'');
    $au->execute([$agentId]);
    $say((int) $au->fetchColumn() === 2, 'the revoke and the unrevoke are in the audit log');
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
