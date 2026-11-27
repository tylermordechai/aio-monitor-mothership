<?php

/**
 * Simulated agents (M-043, M-049, M-068). Root only, on the VM.
 *
 *   sudo php tools/dev-agents.php create 500 [--url=https://192.168.0.191:8443]   # enrols SIM-0001..
 *   sudo php tools/dev-agents.php list
 *   sudo php tools/dev-agents.php delete       # every simulator row and file, nothing else
 *
 * Since milestone 3 step 10 (M-068) each simulated agent enrols like a real
 * one (MS-ING-06): a one-time deployment token for its hostname (TokenStore),
 * a P-256 key made here, and POST /api/v1/enroll over 8443, so the signing
 * service issues its certificate. This tool no longer reads the CA key.
 * Every simulated host is in the host group "simulator", which is how
 * `delete` finds them; it never touches another group.
 *
 * Keys and certificates go to /var/lib/aio-mothership/sim (700 root). They
 * are test identities: never copy them to a real host.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Enrol\TokenStore;
use Aio\Ingest\Schema\TableMap;
use Aio\Util\Time;
use Aio\Util\Uuid;

const SIM_DIR = '/var/lib/aio-mothership/sim';
const GROUP = 'simulator';

if (posix_geteuid() !== 0) {
    fwrite(STDERR, "run it with sudo (aio_maint credentials and the simulator folder)\n");
    exit(2);
}
$cmd = $argv[1] ?? '';
$config = Config::load();
$pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
$ca = $config->string('enrol', 'agent_ca_public');
$url = 'https://' . trim((string) shell_exec('. ' . escapeshellarg(dirname(__DIR__) . '/setup/env.sh') . ' && echo $AIO_IP')) . ':8443';
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--url=')) {
        $url = rtrim(substr($a, 6), '/');
    }
}

match ($cmd) {
    'create' => create($pdo, max(1, (int) ($argv[2] ?? 10)), $url, $ca),
    'list' => listAgents($pdo),
    'delete' => deleteAll($pdo),
    default => usage(),
};

function usage(): never
{
    fwrite(STDERR, "usage: sudo php tools/dev-agents.php create N [--url=https://IP:8443] | list | delete\n");
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

function create(PDO $pdo, int $n, string $url, string $ca): void
{
    groupId($pdo);
    @mkdir(SIM_DIR . '/certs', 0700, true);
    chmod(SIM_DIR, 0700);
    $list = is_file(SIM_DIR . '/agents.json') ? json_decode((string) file_get_contents(SIM_DIR . '/agents.json'), true) : [];
    $have = count($list);
    $store = new TokenStore($pdo);
    $made = 0;
    try {
        for ($i = $have + 1; $i <= $have + $n; $i++) {
            $hostname = sprintf('SIM-%04d', $i);
            $agentId = Uuid::v4();
            $guid = Uuid::v4();
            // M-068: like a real agent, a one-time token for this host, a key
            // made here and a CSR, through POST /api/v1/enroll (§2).
            $token = $store->create(['kind' => 'deployment', 'days' => '1', 'group' => GROUP, 'hostname' => $hostname, 'note' => 'tools/dev-agents.php'], 'cli:dev-agents')['token'];
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $csr = openssl_csr_new(['commonName' => $agentId], $key, ['digest_alg' => 'sha256']);
            openssl_csr_export($csr, $csrPem);
            openssl_pkey_export($key, $keyPem);
            [$status, $body] = enrol($url, $ca, [
                'enrollment_token' => $token,
                'csr' => $csrPem,
                'agent_id' => $agentId,
                'host_id' => $guid,
                'hostname' => $hostname,
                'agent_version' => '0.5.0-sim',
            ]);
            if ($status !== 201 || !is_string($body['certificate'] ?? null)) {
                throw new RuntimeException("enrolment of $hostname answered $status " . (string) ($body['error']['code'] ?? ''));
            }
            $base = SIM_DIR . "/certs/$agentId";
            file_put_contents("$base.key", $keyPem);
            chmod("$base.key", 0600);
            file_put_contents("$base.crt", $body['certificate']);
            $list[] = ['n' => $i, 'agent_id' => $agentId, 'host_id' => $guid, 'hostname' => $hostname, 'cert' => "$base.crt", 'key' => "$base.key"];
            $made++;
            if ($made % 50 === 0) {
                echo "  enrolled $made of $n\n";
            }
        }
    } finally {
        file_put_contents(SIM_DIR . '/agents.json', json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod(SIM_DIR . '/agents.json', 0600);
    }
    echo "enrolled $made simulated agents through $url/api/v1/enroll (" . count($list) . " in total), group '" . GROUP . "'\n";
}

/**
 * @param array<string, string> $body
 * @return array{0: int, 1: array<string, mixed>}
 */
function enrol(string $url, string $ca, array $body): array
{
    $h = curl_init($url . '/api/v1/enroll');
    curl_setopt_array($h, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => (string) json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: aio-monitor-agent/0.5.0-sim', 'Expect:'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CAINFO => $ca,
        CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $out = curl_exec($h);
    $status = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    if ($out === false) {
        throw new RuntimeException('enrol request failed: ' . curl_error($h));
    }
    $d = json_decode((string) $out, true);
    return [$status, is_array($d) ? $d : []];
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
        // Enrolment tokens stay, as a record, without the link to the agent (M-068).
        $pdo->exec("UPDATE enrollment_tokens SET used_by_agent_pk = NULL WHERE used_by_agent_pk IN ($in)");
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
