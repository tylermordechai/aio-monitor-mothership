<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Pki\CertProfile;
use Aio\Pki\CsrCheck;
use Aio\Pki\CsrInvalid;
use Aio\Pki\OpensslTool;
use Aio\Pki\Signer;
use Aio\Pki\SignerProtocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The CSR checks, the certificate profile and the signer (ingest-api.md §2.3;
 * MS-ING-06, MS-SEC-02). The signer tests use a throwaway CA and the openssl
 * tool; they're skipped where no openssl binary is found.
 */
final class SignerTest extends TestCase
{
    private const ID = '290738b4-dc89-4cc4-aa7e-e38885792dd9';
    private static ?string $dir = null;
    private static ?string $openssl = null;

    public static function setUpBeforeClass(): void
    {
        foreach ([getenv('AIO_TEST_OPENSSL') ?: '', '/usr/bin/openssl', 'C:\\Program Files\\Git\\ucrt64\\bin\\openssl.exe', 'C:\\Program Files\\Git\\usr\\bin\\openssl.exe'] as $c) {
            if ($c !== '' && is_file($c)) {
                self::$openssl = $c;
                break;
            }
        }
        if (self::$openssl === null) {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/aio-signer-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0700);
        $d = self::$dir;
        $t = self::tool();
        $t->run(['ecparam', '-name', 'secp384r1', '-genkey', '-noout', '-out', "$d/ca.key"]);
        file_put_contents("$d/ca.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=Test Agent CA\nO=AIO Monitor\n[v3_ca]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
        [$code, , $err] = $t->run(['req', '-new', '-x509', '-key', "$d/ca.key", '-config', "$d/ca.cnf", '-extensions', 'v3_ca', '-days', '30', '-sha384', '-out', "$d/ca.crt"]);
        self::assertSame(0, $code, $err);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== null) {
            array_map('unlink', glob(self::$dir . '/*') ?: []);
            @rmdir(self::$dir);
        }
    }

    private static function tool(): OpensslTool
    {
        return new OpensslTool((string) self::$openssl, PHP_OS_FAMILY === 'Windows' ? null : ['PATH' => '/usr/bin:/bin']);
    }

    private function signer(?\Closure $clock = null): Signer
    {
        if (self::$dir === null) {
            $this->markTestSkipped('no openssl binary found');
        }
        return new Signer(self::$dir . '/ca.crt', self::$dir . '/ca.key', self::tool(), sys_get_temp_dir(), $clock);
    }

    /** @param array<string, mixed> $key */
    private static function csr(array $dn, array $key = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']): string
    {
        // Windows PHP builds have no default openssl.cnf, so they get a minimal
        // one. Linux uses the system's: with the minimal file, PHP 8.5 there
        // refuses the key ("Private key length must be at least 384 bits,
        // configured to 0"), as the VM showed.
        $opt = [];
        if (PHP_OS_FAMILY === 'Windows') {
            $cnf = sys_get_temp_dir() . '/aio-signer-test-req.cnf';
            if (!is_file($cnf)) {
                file_put_contents($cnf, "[req]\ndistinguished_name=dn\ndefault_bits=2048\n[dn]\n");
            }
            $opt = ['config' => $cnf];
        }
        $k = openssl_pkey_new($key + $opt);
        self::assertNotFalse($k);
        $csr = openssl_csr_new($dn, $k, ['digest_alg' => 'sha256'] + $opt);
        self::assertNotFalse($csr);
        openssl_csr_export($csr, $pem);
        return $pem;
    }

    public function testCsrCheckAcceptsP256AndP384WithTheAgentIdAsCn(): void
    {
        CsrCheck::check(self::csr(['commonName' => self::ID]), self::ID);
        CsrCheck::check(self::csr(['commonName' => self::ID], ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1']), self::ID);
        $this->addToAssertionCount(2);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function refusedCsrs(): iterable
    {
        $other = 'b7e1a7c2-0d55-4f1e-9a3b-2c4d5e6f7a8b';
        yield 'RSA key' => [self::csr(['commonName' => self::ID], ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), 'key_type'];
        yield 'P-521 curve' => [self::csr(['commonName' => self::ID], ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp521r1']), 'curve'];
        yield 'CN of another agent' => [self::csr(['commonName' => $other]), 'cn'];
        yield 'no CN' => [self::csr(['organizationName' => 'x']), 'subject'];
        yield 'not PEM' => ['hello', 'pem'];
        $one = self::csr(['commonName' => self::ID]);
        yield 'two PEM blocks' => [$one . $one, 'pem'];
    }

    #[DataProvider('refusedCsrs')]
    public function testCsrCheckRefuses(string $pem, string $reason): void
    {
        try {
            CsrCheck::check($pem, self::ID);
            self::fail('accepted');
        } catch (CsrInvalid $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    public function testCsrCheckRefusesAnAgentIdThatIsNotALowerCaseUuidV4(): void
    {
        $up = strtoupper(self::ID);
        $this->expectExceptionObject(new CsrInvalid('agent_id'));
        CsrCheck::check(self::csr(['commonName' => $up]), $up);
    }

    public function testSerialsArePositiveSixteenBytesAndDifferent(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $s = CertProfile::newSerialHex();
            self::assertMatchesRegularExpression('/^[4-7][0-9A-F]{31}$/', $s);
            $seen[$s] = true;
        }
        self::assertCount(50, $seen);
    }

    public function testRenewAfterIsThirtyDaysBeforeExpiry(): void
    {
        [$from, $to] = CertProfile::validity(1_800_000_000);
        self::assertSame(1_800_000_000 - 300, $from);
        self::assertSame(1_800_000_000 + 365 * 86_400, $to);
        self::assertSame($to - 30 * 86_400, CertProfile::renewAfter($to));
    }

    public function testSignedCertificateFollowsTheProfileWhateverTheCsrAsks(): void
    {
        $now = time();
        // The CSR asks for more than it gets: extra subject fields are replaced.
        $csr = self::csr(['commonName' => self::ID, 'organizationalUnitName' => 'Evil', 'organizationName' => 'Other']);
        $r = $this->signer(static fn (): int => $now)->sign($csr, self::ID);

        $i = openssl_x509_parse($r['certificate']);
        self::assertIsArray($i);
        self::assertSame(['CN' => self::ID, 'O' => 'AIO Monitor Agent'], $i['subject']);
        self::assertSame('Test Agent CA', $i['issuer']['CN']);
        self::assertSame($r['serial_hex'], strtoupper($i['serialNumberHex']));
        self::assertSame($now - 300, $i['validFrom_time_t']);
        self::assertSame($now + 365 * 86_400, $i['validTo_time_t']);
        self::assertSame('ecdsa-with-SHA384', $i['signatureTypeLN']);
        self::assertSame('CA:FALSE', $i['extensions']['basicConstraints']);
        self::assertSame('Digital Signature', $i['extensions']['keyUsage']);
        self::assertSame('TLS Web Client Authentication', $i['extensions']['extendedKeyUsage']);
        self::assertSame('URI:urn:aio-monitor:agent:' . self::ID, $i['extensions']['subjectAltName']);
        self::assertSame(1, openssl_x509_verify($r['certificate'], (string) file_get_contents(self::$dir . '/ca.crt')));
        self::assertSame(CsrCheck::publicKeyPem($csr), openssl_pkey_get_details(openssl_pkey_get_public($r['certificate']))['key']);
    }

    public function testSignerRefusesACsrWhoseSignatureDoesNotVerify(): void
    {
        $signer = $this->signer();
        $pem = self::csr(['commonName' => self::ID]);
        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem));
        $der[strlen($der) - 5] = chr(ord($der[strlen($der) - 5]) ^ 0x01);
        $bad = "-----BEGIN CERTIFICATE REQUEST-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE REQUEST-----\n";
        try {
            $signer->sign($bad, self::ID);
            self::fail('signed a tampered CSR');
        } catch (CsrInvalid $e) {
            self::assertSame('signature', $e->reason);
        }
    }

    public function testProtocolAnswersAndLogsWithoutTheCsr(): void
    {
        $signer = $this->signer();
        $lines = [];
        $log = static function (string $l) use (&$lines): void {
            $lines[] = $l;
        };
        $csr = self::csr(['commonName' => self::ID]);

        $ok = json_decode(SignerProtocol::handle(json_encode(['v' => 1, 'op' => 'sign', 'agent_id' => self::ID, 'csr' => $csr]), $signer, $log), true);
        self::assertTrue($ok['ok']);
        self::assertStringStartsWith('-----BEGIN CERTIFICATE-----', $ok['certificate']);

        $bad = json_decode(SignerProtocol::handle(json_encode(['v' => 1, 'op' => 'sign', 'agent_id' => 'b7e1a7c2-0d55-4f1e-9a3b-2c4d5e6f7a8b', 'csr' => $csr]), $signer, $log), true);
        self::assertSame(['ok' => false, 'error' => 'csr_invalid', 'reason' => 'cn'], $bad);

        foreach (['not json', json_encode(['v' => 2, 'op' => 'sign']), json_encode(['v' => 1, 'op' => 'crl']), str_repeat('x', 20_000)] as $raw) {
            self::assertSame(['ok' => false, 'error' => 'bad_request'], json_decode(SignerProtocol::handle($raw, $signer, $log), true));
        }
        self::assertCount(6, $lines);
        foreach ($lines as $l) {
            self::assertStringNotContainsString('BEGIN', $l);
        }
        self::assertStringStartsWith('signed agent_id=' . self::ID . ' serial=', $lines[0]);
    }
}
