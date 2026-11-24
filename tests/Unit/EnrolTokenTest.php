<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Enrol\EnrolToken;
use Aio\Enrol\TokenStore;
use Aio\Pki\CaExport;
use Aio\Pki\OpensslTool;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Enrolment tokens and the CA export (ingest-api.md §2.1, §2.2; MS-SEC-03,
 * M-021, M-059, M-061). The database side is in tests/Integration/TokenStoreTest.
 */
final class EnrolTokenTest extends TestCase
{
    public function testTokensHaveTheContractFormatAndDiffer(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $t = EnrolToken::generate();
            self::assertMatchesRegularExpression('/^aioe_[A-Za-z0-9_-]{43}$/', $t);
            self::assertTrue(EnrolToken::isWellFormed($t));
            $seen[$t] = true;
        }
        self::assertCount(200, $seen);
    }

    public function testTheStoredHashIsTheSha256OfTheWholeToken(): void
    {
        $t = EnrolToken::generate();
        self::assertSame(hash('sha256', $t, true), EnrolToken::hash($t));
        self::assertSame(32, strlen(EnrolToken::hash($t)));
    }

    public function testMalformedTokensAreRecognised(): void
    {
        $good = EnrolToken::generate();
        foreach (['', 'aioe_', substr($good, 0, -1), $good . 'A', 'AIOE_' . substr($good, 5), str_replace('_', '+', 'aioe_' . str_repeat('a', 42) . '_'), $good . "\n"] as $bad) {
            self::assertFalse(EnrolToken::isWellFormed($bad), var_export($bad, true));
        }
    }

    private static function store(): TokenStore
    {
        // check() never touches the database.
        $pdo = (new \ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
        return new TokenStore($pdo, 24, 7);
    }

    public function testManualTokensLast24HoursAndDeploymentTokensUpTo7Days(): void
    {
        self::assertSame(86_400, self::store()->check([])['seconds']);
        self::assertSame('default', self::store()->check([])['group']);
        self::assertSame(7 * 86_400, self::store()->check(['kind' => 'deployment'])['seconds']);
        self::assertSame(3 * 86_400, self::store()->check(['kind' => 'deployment', 'days' => '3'])['seconds']);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function refused(): iterable
    {
        yield 'unknown kind' => [['kind' => 'forever']];
        yield 'days on a manual token' => [['days' => '2']];
        yield 'zero days' => [['kind' => 'deployment', 'days' => '0']];
        yield 'eight days' => [['kind' => 'deployment', 'days' => '8']];
        yield 'days not a number' => [['kind' => 'deployment', 'days' => '2x']];
        yield 'hostname with a space' => [['hostname' => 'SRV WEB']];
        yield 'hostname with a slash' => [['hostname' => 'a/b']];
        yield 'reenroll not a UUID' => [['reenroll' => 'SRV-WEB-01']];
        yield 'reenroll upper case' => [['reenroll' => '290738B4-DC89-4CC4-AA7E-E38885792DD9']];
        yield 'note too long' => [['note' => str_repeat('x', 501)]];
    }

    /** @param array<string, mixed> $o */
    #[DataProvider('refused')]
    public function testBadRequestsAreRefusedBeforeAnythingIsWritten(array $o): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::store()->check($o);
    }

    public function testNotesLoseControlCharacters(): void
    {
        self::assertSame('a b', self::store()->check(['note' => "a\r\n\tb "])['note']);
        self::assertNull(self::store()->check(['note' => "  \n"])['note']);
    }

    public function testCaExportGivesTheDerSha256LikeTheOpensslTool(): void
    {
        $bin = null;
        foreach ([getenv('AIO_TEST_OPENSSL') ?: '', '/usr/bin/openssl', 'C:\\Program Files\\Git\\ucrt64\\bin\\openssl.exe', 'C:\\Program Files\\Git\\usr\\bin\\openssl.exe'] as $c) {
            if ($c !== '' && is_file($c)) {
                $bin = $c;
                break;
            }
        }
        if ($bin === null) {
            self::markTestSkipped('no openssl binary found');
        }
        $t = new OpensslTool($bin, PHP_OS_FAMILY === 'Windows' ? null : ['PATH' => '/usr/bin:/bin']);
        $d = sys_get_temp_dir() . '/aio-ca-export-' . bin2hex(random_bytes(4));
        mkdir($d, 0700);
        try {
            file_put_contents("$d/ca.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=Test Agent CA\n[v3_ca]\nbasicConstraints=critical,CA:TRUE,pathlen:0\nkeyUsage=critical,keyCertSign,cRLSign\n");
            $t->run(['ecparam', '-name', 'secp384r1', '-genkey', '-noout', '-out', "$d/ca.key"]);
            [$code, , $err] = $t->run(['req', '-new', '-x509', '-key', "$d/ca.key", '-config', "$d/ca.cnf", '-extensions', 'v3_ca', '-days', '30', '-out', "$d/ca.crt"]);
            self::assertSame(0, $code, $err);
            [, $fp] = $t->run(['x509', '-in', "$d/ca.crt", '-noout', '-fingerprint', '-sha256']);
            $want = trim(substr($fp, strpos($fp, '=') + 1));

            $ca = CaExport::read((string) file_get_contents("$d/ca.crt"));
            self::assertSame($want, $ca['sha256_colons']);
            self::assertSame(strtolower(str_replace(':', '', $want)), $ca['sha256']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ca['sha256']);
            self::assertStringStartsWith('-----BEGIN CERTIFICATE-----', $ca['pem']);

            // A leaf certificate isn't accepted as the CA.
            file_put_contents("$d/leaf.cnf", "[req]\ndistinguished_name=dn\nprompt=no\n[dn]\nCN=leaf\n[v3]\nbasicConstraints=critical,CA:FALSE\n");
            $t->run(['req', '-new', '-x509', '-key', "$d/ca.key", '-config', "$d/leaf.cnf", '-extensions', 'v3', '-days', '30', '-out', "$d/leaf.crt"]);
            try {
                CaExport::read((string) file_get_contents("$d/leaf.crt"));
                self::fail('a leaf certificate was accepted');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('not a CA', $e->getMessage());
            }
        } finally {
            array_map('unlink', glob("$d/*") ?: []);
            @rmdir($d);
        }
    }
}
