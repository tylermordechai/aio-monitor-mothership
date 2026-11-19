<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Db;
use Aio\Util\Time;
use Aio\Util\Uuid;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The aio_test database (setup/09-app.sh, M-044): rebuilt from
 * db/migrations once per test run, so the tests also prove the migrations
 * apply in order. Each test makes its own agent, so tests don't share rows.
 */
final class TestDb
{
    private static ?PDO $pdo = null;

    public static function cnf(): string
    {
        return getenv('AIO_TEST_CNF') ?: '/etc/aio-mothership/db/test.cnf';
    }

    public static function pdo(TestCase $t): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (!is_readable(self::cnf())) {
            $t->markTestSkipped('no test database credentials (' . self::cnf() . '); run setup/09-app.sh on the VM');
        }
        $pdo = Db::connect(self::cnf(), 'aio_test');
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM) as [$table]) {
            $pdo->exec("DROP TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $files = glob(dirname(__DIR__, 2) . '/db/migrations/*.sql');
        sort($files);
        foreach ($files as $f) {
            $cmd = sprintf('mariadb --defaults-extra-file=%s aio_test < %s 2>&1', escapeshellarg(self::cnf()), escapeshellarg($f));
            exec($cmd, $out, $rc);
            if ($rc !== 0) {
                throw new \RuntimeException(basename($f) . ' failed: ' . implode("\n", $out));
            }
        }
        return self::$pdo = $pdo;
    }

    /**
     * A host, an agent and a registered certificate (CN = agent ID).
     *
     * @return array{agent_pk: int, agent_id: string, host_pk: int, host_guid: string, hostname: string, cert: string}
     */
    public static function agent(PDO $pdo, string $hostname = 'SIM-T'): array
    {
        $agentId = Uuid::v4();
        $guid = Uuid::v4();
        $now = Time::nowDb();
        $pdo->prepare('INSERT INTO hosts (machine_guid, hostname, group_id, first_seen) SELECT ?, ?, id, ? FROM host_groups WHERE name = \'default\'')
            ->execute([$guid, $hostname, $now]);
        $hostPk = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO host_names (host_pk, hostname, first_seen, last_seen) VALUES (?, ?, ?, ?)')->execute([$hostPk, $hostname, $now, $now]);
        $pdo->prepare('INSERT INTO agents (agent_id, host_pk, enrolled_at) VALUES (?, ?, ?)')->execute([$agentId, $hostPk, $now]);
        $agentPk = (int) $pdo->lastInsertId();
        $cert = self::selfSignedCert($agentId);
        $s = $pdo->prepare('INSERT INTO agent_certificates (agent_pk, serial_hex, fingerprint_sha256, not_before, not_after, issued_at, issued_via)
                            VALUES (?, ?, ?, ?, DATE_ADD(?, INTERVAL 1 YEAR), ?, \'enroll\')');
        $s->bindValue(1, $agentPk, PDO::PARAM_INT);
        $s->bindValue(2, strtoupper(bin2hex(random_bytes(16))));
        $s->bindValue(3, openssl_x509_fingerprint($cert, 'sha256', true), PDO::PARAM_LOB);
        $s->bindValue(4, $now);
        $s->bindValue(5, $now);
        $s->bindValue(6, $now);
        $s->execute();
        return ['agent_pk' => $agentPk, 'agent_id' => $agentId, 'host_pk' => $hostPk, 'host_guid' => $guid, 'hostname' => $hostname, 'cert' => $cert];
    }

    /** Only the fingerprint and CN matter to ClientCertAuth; Apache checks the chain. */
    public static function selfSignedCert(string $cn): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        $x = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($x, $pem);
        return $pem;
    }

    public static function count(PDO $pdo, string $table, int $agentPk): int
    {
        $s = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE agent_pk = ?");
        $s->execute([$agentPk]);
        return (int) $s->fetchColumn();
    }
}
