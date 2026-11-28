<?php

declare(strict_types=1);

namespace Aio\Pki;

use PDO;

/**
 * Builds the agent CA's CRL (M-071, M-068, MS-SEC-02, ingest-api.md §2.4
 * step 3). Run only by the root job tools/jobs/crl-build.php: it reads the
 * database as aio_maint and the CA key as root. The signing service never
 * does this (M-060: it has no database).
 *
 * What the list holds, rebuilt from the database each time (nothing is kept
 * between builds, so it can't drift):
 * - every certificate revoked by itself (bin/agent.php revoke-cert, or the
 *   old certificates at re-enrolment);
 * - every certificate of a revoked agent (the owner's rule, M-068): revoking
 *   an agent lists its certificates, unrevoking takes them off at the next
 *   rebuild;
 * - only certificates that haven't expired; an expired one fails the
 *   handshake anyway.
 *
 * openssl ca -gencrl signs it, from a throwaway index.txt written for this
 * build. The CRL number is the build time in milliseconds, so it always
 * grows without a counter file.
 */
final class CrlBuilder
{
    public function __construct(
        private readonly OpensslTool $openssl,
        private readonly string $caCert,
        private readonly string $caKey,
        private readonly int $validDays,
    ) {
        if ($validDays < 1 || $validDays > 365) {
            throw new \InvalidArgumentException('crl.valid_days must be 1 to 365');
        }
    }

    /**
     * The certificates the CRL must list, oldest first.
     *
     * @return list<array{serial_hex: string, not_after: string, revoked_at: string, agent_id: string, why: string}>
     */
    public static function revoked(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT c.serial_hex, c.not_after, a.agent_id,
                    COALESCE(CASE WHEN c.status = \'revoked\' THEN c.revoked_at ELSE a.revoked_at END, c.issued_at) AS revoked_at,
                    CASE WHEN c.status = \'revoked\' THEN \'certificate\' ELSE \'agent\' END AS why
               FROM agent_certificates c JOIN agents a ON a.id = c.agent_pk
              WHERE c.not_after > UTC_TIMESTAMP(6) AND (c.status = \'revoked\' OR a.status = \'revoked\')
              ORDER BY c.id'
        )->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn (array $r): array => [
            'serial_hex' => (string) $r['serial_hex'],
            'not_after' => (string) $r['not_after'],
            'revoked_at' => (string) $r['revoked_at'],
            'agent_id' => (string) $r['agent_id'],
            'why' => (string) $r['why'],
        ], $rows);
    }

    /** A serial as openssl's index.txt and its text output write it: upper-case hex, even length. */
    public static function serial(string $hex): string
    {
        $h = strtoupper(ltrim($hex, '0'));
        $h = $h === '' ? '00' : $h;
        return strlen($h) % 2 === 1 ? '0' . $h : $h;
    }

    /**
     * openssl ca's database: one "R" (revoked) line per certificate.
     *
     * @param list<array{serial_hex: string, not_after: string, revoked_at: string, agent_id: string, why: string}> $rows
     */
    public static function indexTxt(array $rows): string
    {
        $out = '';
        foreach ($rows as $r) {
            if (!preg_match('/^[0-9A-Fa-f]{1,40}$/D', $r['serial_hex']) || !preg_match('/^[0-9a-f-]{36}$/D', $r['agent_id'])) {
                throw new \InvalidArgumentException('a certificate row with a bad serial or agent ID');
            }
            $out .= sprintf(
                "R\t%s\t%s\t%s\tunknown\t/CN=%s\n",
                self::asn1Time($r['not_after']),
                self::asn1Time($r['revoked_at']),
                self::serial($r['serial_hex']),
                $r['agent_id'],
            );
        }
        return $out;
    }

    /**
     * Signs a CRL listing $rows; returns the PEM. $work is an empty private
     * directory, removed by the caller.
     *
     * @param list<array{serial_hex: string, not_after: string, revoked_at: string, agent_id: string, why: string}> $rows
     */
    public function build(array $rows, string $work, ?float $now = null): string
    {
        $now ??= microtime(true);
        $w = rtrim(str_replace('\\', '/', $work), '/');
        $number = strtoupper(dechex((int) round($now * 1000)));
        $number = strlen($number) % 2 === 1 ? '0' . $number : $number;
        $files = [
            'index.txt' => self::indexTxt($rows),
            'index.txt.attr' => "unique_subject = no\n",
            'crlnumber' => $number . "\n",
            'ca.cnf' => "[ca]\ndefault_ca = aio\n\n[aio]\ndatabase = $w/index.txt\ncrlnumber = $w/crlnumber\n"
                . "default_md = sha384\ncrl_extensions = crl_ext\n\n[crl_ext]\nauthorityKeyIdentifier = keyid:always\n",
        ];
        foreach ($files as $name => $body) {
            if (file_put_contents("$w/$name", $body) === false) {
                throw new \RuntimeException("can't write $name in the work directory");
            }
        }
        [$rc, , $err] = $this->openssl->run([
            'ca', '-gencrl', '-batch', '-config', "$w/ca.cnf",
            '-cert', $this->caCert, '-keyfile', $this->caKey,
            '-crldays', (string) $this->validDays, '-out', "$w/crl.pem",
        ]);
        if ($rc !== 0 || !is_file("$w/crl.pem")) {
            throw new \RuntimeException('openssl ca -gencrl failed: ' . self::firstLine($err));
        }
        return (string) file_get_contents("$w/crl.pem");
    }

    /**
     * Checks a CRL file against the CA certificate and reads it back.
     *
     * @return array{last_update: string, next_update: string, serials: list<string>}
     */
    public function inspect(string $crlFile): array
    {
        [$rc, , $err] = $this->openssl->run(['crl', '-in', $crlFile, '-CAfile', $this->caCert, '-noout']);
        if ($rc !== 0 || stripos($err, 'verify OK') === false) {
            throw new \RuntimeException('the CRL does not verify against the agent CA: ' . self::firstLine($err));
        }
        [$rc, $out, $err] = $this->openssl->run(['crl', '-in', $crlFile, '-noout', '-text']);
        if ($rc !== 0) {
            throw new \RuntimeException('openssl crl -text failed: ' . self::firstLine($err));
        }
        $date = static function (string $label) use ($out): string {
            if (!preg_match('/' . $label . ':\s*(.+)$/m', $out, $m) || ($t = strtotime(trim($m[1]))) === false) {
                throw new \RuntimeException("the CRL has no readable $label");
            }
            return gmdate('Y-m-d H:i:s', $t);
        };
        preg_match_all('/Serial Number:\s*([0-9A-Fa-f]+)/', $out, $m);
        return [
            'last_update' => $date('Last Update'),
            'next_update' => $date('Next Update'),
            'serials' => array_map(static fn (string $s): string => self::serial($s), $m[1]),
        ];
    }

    /** YYMMDDHHMMSSZ (UTCTime, years 1950 to 2049) from a UTC database time. */
    private static function asn1Time(string $dbTime): string
    {
        $t = strtotime(substr($dbTime, 0, 19) . ' UTC');
        if ($t === false || (int) gmdate('Y', $t) >= 2050) {
            throw new \InvalidArgumentException("a time the CRL can't hold: $dbTime");
        }
        return gmdate('ymdHis', $t) . 'Z';
    }

    private static function firstLine(string $s): string
    {
        $l = trim(strtok(trim($s), "\n") ?: '');
        return $l === '' ? 'no message' : mb_substr($l, 0, 200);
    }
}
