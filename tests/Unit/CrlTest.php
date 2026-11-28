<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Pki\CrlBuilder;
use Aio\Pki\CrlTrigger;
use Aio\Pki\OpensslTool;
use PHPUnit\Framework\TestCase;

/**
 * The agent CA's CRL (M-071, M-068, MS-SEC-02): openssl's index lines, a
 * real CRL signed by a throwaway P-384 CA and read back, and the state the
 * dashboard and health page show. The openssl tests are skipped where no
 * openssl binary is found.
 */
final class CrlTest extends TestCase
{
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
        self::$dir = sys_get_temp_dir() . '/aio-crl-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dir, 0700);
        $d = self::$dir;
        $t = self::tool();
        $t->run(['ecparam', '-name', 'secp384r1', '-genkey', '-noout', '-out', "$d/ca.key"]);
        file_put_contents("$d/req.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=Test Agent CA\nO=AIO Monitor\n[v3_ca]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n");
        [$code, , $err] = $t->run(['req', '-new', '-x509', '-key', "$d/ca.key", '-config', "$d/req.cnf", '-extensions', 'v3_ca', '-days', '30', '-sha384', '-out', "$d/ca.crt"]);
        self::assertSame(0, $code, $err);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== null) {
            foreach (glob(self::$dir . '/*/*') ?: [] as $f) {
                unlink($f);
            }
            foreach (glob(self::$dir . '/*') ?: [] as $f) {
                is_dir($f) ? rmdir($f) : unlink($f);
            }
            @rmdir(self::$dir);
        }
    }

    private static function tool(): OpensslTool
    {
        return new OpensslTool((string) self::$openssl, PHP_OS_FAMILY === 'Windows' ? null : ['PATH' => '/usr/bin:/bin']);
    }

    private function builder(int $days = 30): CrlBuilder
    {
        if (self::$dir === null) {
            $this->markTestSkipped('no openssl binary found');
        }
        return new CrlBuilder(self::tool(), self::$dir . '/ca.crt', self::$dir . '/ca.key', $days);
    }

    private function work(): string
    {
        $w = self::$dir . '/w' . bin2hex(random_bytes(3));
        mkdir($w, 0700);
        return $w;
    }

    /** @return array{serial_hex: string, not_after: string, revoked_at: string, agent_id: string, why: string} */
    private static function row(string $serial, string $why = 'agent'): array
    {
        return ['serial_hex' => $serial, 'not_after' => '2027-11-28 10:00:00.000000', 'revoked_at' => '2026-11-28 09:30:00.000000', 'agent_id' => '290738b4-dc89-4cc4-aa7e-e38885792dd9', 'why' => $why];
    }

    public function testM071IndexLinesAreOpensslsRevokedFormat(): void
    {
        self::assertSame(
            "R\t271128100000Z\t261128093000Z\t0ABC\tunknown\t/CN=290738b4-dc89-4cc4-aa7e-e38885792dd9\n",
            CrlBuilder::indexTxt([self::row('abc')]),
        );
        self::assertSame('0F', CrlBuilder::serial('00f'));
        self::assertSame('00', CrlBuilder::serial('0'));
        $this->expectException(\InvalidArgumentException::class);
        CrlBuilder::indexTxt([['serial_hex' => "1\tR"] + self::row('1')]);
    }

    public function testMsSec02ACrlListsEveryRevokedCertificateAndVerifies(): void
    {
        $b = $this->builder();
        $serials = [strtoupper(bin2hex(random_bytes(16))), '0' . strtoupper(bin2hex(random_bytes(15))) . 'A', '1F'];
        $w = $this->work();
        $pem = $b->build(array_map(fn (string $s) => self::row($s), $serials), $w, 1_795_000_000.0);
        self::assertStringStartsWith('-----BEGIN X509 CRL-----', $pem);
        file_put_contents("$w/out.crl", $pem);
        $i = $b->inspect("$w/out.crl");
        self::assertEqualsCanonicalizing(array_map([CrlBuilder::class, 'serial'], $serials), $i['serials']);
        $days = (strtotime($i['next_update'] . ' UTC') - strtotime($i['last_update'] . ' UTC')) / 86_400;
        self::assertEqualsWithDelta(30, $days, 0.01, 'M-068: valid 30 days');
    }

    public function testAnEmptyCrlIsValidAndTheNumberGrows(): void
    {
        $b = $this->builder(2);
        $w1 = $this->work();
        $w2 = $this->work();
        file_put_contents("$w1/a.crl", $b->build([], $w1, 1_795_000_000.0));
        file_put_contents("$w2/b.crl", $b->build([], $w2, 1_795_000_001.5));
        self::assertSame([], $b->inspect("$w1/a.crl")['serials']);
        $num = function (string $f): int {
            [, $out] = self::tool()->run(['crl', '-in', $f, '-noout', '-crlnumber']);
            return (int) hexdec(trim(explode('=', $out)[1] ?? '0'));
        };
        self::assertGreaterThan($num("$w1/a.crl"), $num("$w2/b.crl"));
    }

    public function testACrlFromAnotherCaDoesNotVerify(): void
    {
        $b = $this->builder();
        $w = $this->work();
        $t = self::tool();
        $t->run(['ecparam', '-name', 'secp384r1', '-genkey', '-noout', '-out', "$w/other.key"]);
        $t->run(['req', '-new', '-x509', '-key', "$w/other.key", '-config', self::$dir . '/req.cnf', '-extensions', 'v3_ca', '-days', '30', '-sha384', '-out', "$w/other.crt"]);
        $other = new CrlBuilder($t, "$w/other.crt", "$w/other.key", 30);
        $w2 = $this->work();
        file_put_contents("$w/x.crl", $other->build([], $w2));
        $this->expectException(\RuntimeException::class);
        $b->inspect("$w/x.crl");
    }

    public function testM068StateIsOkThenWarnsSevenDaysBeforeExpiryOrAfterAFailedRebuild(): void
    {
        $now = (int) strtotime('2026-11-28 12:00:00 UTC');
        $s = ['built_at' => '2026-11-28 03:15:00', 'next_update' => '2026-12-28 03:15:00', 'entries' => 2, 'last_error' => null, 'error_at' => null];
        self::assertSame('ok', CrlTrigger::judge($s, $now, 7)['state']);
        self::assertSame('warn', CrlTrigger::judge(['next_update' => '2026-12-05 03:15:00'] + $s, $now, 7)['state'], '7 days before');
        self::assertSame('ok', CrlTrigger::judge(['next_update' => '2026-12-05 12:00:01'] + $s, $now, 7)['state']);
        self::assertSame('expired', CrlTrigger::judge(['next_update' => '2026-11-28 11:59:59'] + $s, $now, 7)['state']);
        $failed = ['last_error' => 'openssl ca -gencrl failed', 'error_at' => '2026-11-28 11:00:00'] + $s;
        self::assertSame('warn', CrlTrigger::judge($failed, $now, 7)['state'], 'M-068: warn as soon as a rebuild fails');
        self::assertStringContainsString('still in use', CrlTrigger::judge($failed, $now, 7)['message']);
        self::assertSame('ok', CrlTrigger::judge(['built_at' => '2026-11-28 11:30:00'] + $failed, $now, 7)['state'], 'a later good build clears it');
        self::assertSame('never', CrlTrigger::judge(['built_at' => null, 'next_update' => null] + $s, $now, 7)['state']);
    }

    public function testStatusReadsTheStateFileAndRequestNeedsTheFile(): void
    {
        $d = sys_get_temp_dir() . '/aio-crl-state-' . bin2hex(random_bytes(4));
        mkdir($d);
        try {
            $t = new CrlTrigger("$d/rebuild", "$d/state.json");
            self::assertSame('never', $t->status(time())['state'], 'no state file yet');
            try {
                $t->request();
                self::fail('a missing request file is reported');
            } catch (\RuntimeException) {
            }
            touch("$d/rebuild");
            $t->request(1_795_000_000.25);
            self::assertSame("1795000000.250000\n", file_get_contents("$d/rebuild"));
            file_put_contents("$d/state.json", json_encode(['built_at' => gmdate('Y-m-d H:i:s'), 'next_update' => gmdate('Y-m-d H:i:s', time() + 30 * 86_400), 'entries' => 3, 'last_error' => null, 'error_at' => null]));
            $st = $t->status(time());
            self::assertSame(['ok', 3], [$st['state'], $st['entries']]);
        } finally {
            array_map('unlink', glob("$d/*") ?: []);
            rmdir($d);
        }
    }
}
