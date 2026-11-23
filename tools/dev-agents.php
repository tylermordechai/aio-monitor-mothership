<?php

/**
 * Simulated agents for milestone 2 (M-043, M-049). Root only, on the VM.
 *
 *   sudo php tools/dev-agents.php create 500   # hosts SIM-0001.., agents, certificates
 *   sudo php tools/dev-agents.php list
 *   sudo php tools/dev-agents.php delete       # every simulator row and file, nothing else
 *
 * Enrolment (MS-ING-06) only arrives in milestone 3, so this issues client
 * certificates straight from the agent CA (P-256, clientAuth, CN = agent ID,
 * 1 year: the profile of ingest-api.md §2.3) and registers them as enrolment
 * would. Every simulated host is in the host group "simulator", which is how
 * `delete` finds them; it never touches another group.
 *
 * Keys and certificates go to /var/lib/aio-mothership/sim (700 root). They
 * are test identities: never copy them to a real host.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Ingest\Schema\TableMap;
use Aio\Util\Time;
use Aio\Util\Uuid;

const SIM_DIR = '/var/lib/aio-mothership/sim';
const CA_DIR = '/etc/aio-mothership/pki/agent-ca';
const GROUP = 'simulator';

if (posix_geteuid() !== 0) {
    fwrite(STDERR, "run it with sudo (it reads the agent CA key)\n");
    exit(2);
}
$cmd = $argv[1] ?? '';
$config = Config::load();
$pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));

match ($cmd) {
    'create' => create($pdo, max(1, (int) ($argv[2] ?? 10))),
    'list' => listAgents($pdo),
    'delete' => deleteAll($pdo),
    default => usage(),
};

function usage(): never
{
    fwrite(STDERR, "usage: sudo php tools/dev-agents.php create N | list | delete\n");
    exit(2);
}

function groupId(PDO $pdo): int
{
    $pdo->prepare('INSERT IGNORE INTO host_groups (name, description) VALUES (?, ?)')
        ->execute([GROUP, 'Simulated hosts (milestone 2 load test); removed by tools/dev-agents.php delete']);
    $s = $pdo->prepare('SELECT id FROM host_groups WHERE name = ?');
    $s->execute([GROUP]);
    return (int) $s->fetchColumn();
}

function create(PDO $pdo, int $n): void
{
    $gid = groupId($pdo);
    // The default group's settings, so heartbeats get a config ETag.
    $pdo->prepare("INSERT IGNORE INTO agent_config (group_id, settings, etag, updated_at) VALUES (?, '{}', '\"cfg-sim-1\"', ?)")
        ->execute([$gid, Time::nowDb()]);
    @mkdir(SIM_DIR . '/certs', 0700, true);
    chmod(SIM_DIR, 0700);
    $list = is_file(SIM_DIR . '/agents.json') ? json_decode((string) file_get_contents(SIM_DIR . '/agents.json'), true) : [];
    $have = count($list);
    $caCert = (string) file_get_contents(CA_DIR . '/ca.crt');
    $caKey = openssl_pkey_get_private((string) file_get_contents(CA_DIR . '/ca.key'));
    if ($caKey === false) {
        fwrite(STDERR, "can't read the agent CA key\n");
        exit(1);
    }
    $extFile = tempnam(sys_get_temp_dir(), 'aio-ext');
    file_put_contents($extFile, "[req]\ndistinguished_name=dn\n[dn]\n[v3_client]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature\nextendedKeyUsage=clientAuth\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid\n");
    $pdo->beginTransaction();
    for ($i = $have + 1; $i <= $have + $n; $i++) {
        $hostname = sprintf('SIM-%04d', $i);
        $agentId = Uuid::v4();
        $guid = Uuid::v4();
        $now = Time::nowDb();
        $pdo->prepare('INSERT INTO hosts (machine_guid, hostname, group_id, first_seen) VALUES (?, ?, ?, ?)')->execute([$guid, $hostname, $gid, $now]);
        $hostPk = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO host_names (host_pk, hostname, first_seen, last_seen) VALUES (?, ?, ?, ?)')->execute([$hostPk, $hostname, $now, $now]);
        $pdo->prepare('INSERT INTO agents (agent_id, host_pk, enrolled_at) VALUES (?, ?, ?)')->execute([$agentId, $hostPk, $now]);
        $agentPk = (int) $pdo->lastInsertId();

        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $agentId], $key, ['digest_alg' => 'sha256', 'config' => $extFile]);
        $serial = random_int(1, PHP_INT_MAX);
        $x509 = openssl_csr_sign($csr, $caCert, $caKey, 365, ['digest_alg' => 'sha384', 'x509_extensions' => 'v3_client', 'config' => $extFile], $serial);
        if ($x509 === false) {
            $pdo->rollBack();
            fwrite(STDERR, 'signing failed: ' . openssl_error_string() . "\n");
            exit(1);
        }
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);
        $info = openssl_x509_parse($certPem);
        $s = $pdo->prepare("INSERT INTO agent_certificates (agent_pk, serial_hex, fingerprint_sha256, not_before, not_after, issued_at, issued_via)
                            VALUES (?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?), ?, 'enroll')");
        $s->bindValue(1, $agentPk, PDO::PARAM_INT);
        $s->bindValue(2, strtoupper(dechex($serial)));
        $s->bindValue(3, openssl_x509_fingerprint($certPem, 'sha256', true), PDO::PARAM_LOB);
        $s->bindValue(4, $info['validFrom_time_t'], PDO::PARAM_INT);
        $s->bindValue(5, $info['validTo_time_t'], PDO::PARAM_INT);
        $s->bindValue(6, $now);
        $s->execute();

        $base = SIM_DIR . "/certs/$agentId";
        file_put_contents("$base.crt", $certPem);
        file_put_contents("$base.key", $keyPem);
        chmod("$base.key", 0600);
        $list[] = ['n' => $i, 'agent_id' => $agentId, 'host_id' => $guid, 'hostname' => $hostname, 'cert' => "$base.crt", 'key' => "$base.key"];
    }
    $pdo->commit();
    unlink($extFile);
    file_put_contents(SIM_DIR . '/agents.json', json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    chmod(SIM_DIR . '/agents.json', 0600);
    echo "created $n simulated agents (" . count($list) . " in total), group '" . GROUP . "'\n";
}

function listAgents(PDO $pdo): void
{
    $r = $pdo->query("SELECT COUNT(*) AS agents, MAX(a.last_checkin_at) AS last_checkin, SUM(a.max_seq_held) AS seq_total
                        FROM agents a JOIN hosts h ON h.id = a.host_pk JOIN host_groups g ON g.id = h.group_id WHERE g.name = '" . GROUP . "'")->fetch();
    echo "simulated agents: {$r['agents']}, last check-in: " . ($r['last_checkin'] ?? 'never') . "\n";
}

function deleteAll(PDO $pdo): void
{
    $s = $pdo->prepare('SELECT a.id AS agent_pk, h.id AS host_pk FROM agents a JOIN hosts h ON h.id = a.host_pk JOIN host_groups g ON g.id = h.group_id WHERE g.name = ?');
    $s->execute([GROUP]);
    $rows = $s->fetchAll();
    $agents = array_map('intval', array_column($rows, 'agent_pk'));
    $hosts = array_values(array_unique(array_map('intval', array_column($rows, 'host_pk'))));
    if ($agents !== []) {
        $in = implode(',', $agents);
        foreach (TableMap::tables() as $t) {
            if (in_array($t, ['agents', 'agent_certificates', 'enrollment_tokens'], true)) {
                continue;
            }
            $cols = TableMap::columns($t);
            if (isset($cols['agent_pk'])) {
                $n = $pdo->exec("DELETE FROM `$t` WHERE agent_pk IN ($in)");
                if ($n > 0) {
                    echo "  $t: $n rows\n";
                }
            }
        }
        // Tables keyed by host only (flow_conn_hourly, M-054): cleared by host.
        $hin = implode(',', $hosts);
        foreach (TableMap::tables() as $t) {
            if (in_array($t, ['hosts', 'host_names', 'agents'], true)) {
                continue;
            }
            $cols = TableMap::columns($t);
            if (isset($cols['host_pk']) && !isset($cols['agent_pk'])) {
                $n = $pdo->exec("DELETE FROM `$t` WHERE host_pk IN ($hin)");
                if ($n > 0) {
                    echo "  $t: $n rows\n";
                }
            }
        }
        $pdo->exec("DELETE FROM agent_certificates WHERE agent_pk IN ($in)");
        $pdo->exec("DELETE FROM agents WHERE id IN ($in)");
        $pdo->exec("DELETE FROM host_names WHERE host_pk IN ($hin)");
        $pdo->exec("DELETE FROM hosts WHERE id IN ($hin)");
    }
    foreach (glob(SIM_DIR . '/certs/*') ?: [] as $f) {
        unlink($f);
    }
    @unlink(SIM_DIR . '/agents.json');
    foreach (glob(SIM_DIR . '/state/*') ?: [] as $f) {
        unlink($f);
    }
    echo 'deleted ' . count($agents) . " simulated agents and their data\n";
}
