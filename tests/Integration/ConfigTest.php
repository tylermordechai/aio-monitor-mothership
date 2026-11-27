<?php

declare(strict_types=1);

namespace Aio\Tests\Integration;

use Aio\Api\AgentApi;
use Aio\Config;
use Aio\Http\Request;
use Aio\Http\Response;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * GET /api/v1/config through AgentApi against aio_test (ingest-api.md §4, §5;
 * MS-ING-07, AG-CFG-01..03, M-065).
 */
final class ConfigTest extends TestCase
{
    private function get(PDO $pdo, string $cert, ?string $etag = null, string $method = 'GET'): Response
    {
        $h = $etag === null ? [] : ['If-None-Match' => $etag];
        return (new AgentApi(Config::fromArray([]), static fn (): PDO => $pdo))->handle(Request::forTest($method, '/api/v1/config', $h, '', $cert));
    }

    public function testTheGroupsSettingsWithAnEtagAnd304WhenUnchanged(): void
    {
        $pdo = TestDb::pdo($this);
        $a = TestDb::agent($pdo, 'SIM-CFG-1');

        $r = $this->get($pdo, $a['cert']);
        self::assertSame(200, $r->status, json_encode($r->body));
        self::assertSame('default', $r->body['host_group']);
        self::assertSame(1, $r->body['config_version']);
        self::assertSame(60, $r->body['intervals']['heartbeat_s']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $r->body['generated_at']);
        $etag = $r->headers['ETag'];
        self::assertMatchesRegularExpression('/^"cfg-\d+-1-[0-9a-f]{12}"$/', $etag);

        $same = $this->get($pdo, $a['cert'], $etag);
        self::assertSame(304, $same->status);
        self::assertSame($etag, $same->headers['ETag']);
        self::assertSame(200, $this->get($pdo, $a['cert'], '"cfg-0-0-000000000000"')->status);

        // The heartbeat answers with the same ETag, so the agent fetches only on a change (§4).
        $hb = (new AgentApi(Config::fromArray([]), static fn (): PDO => $pdo))->handle(
            Request::forTest('POST', '/api/v1/heartbeat', ['Content-Type' => 'application/json'], '{}', $a['cert'])
        );
        self::assertSame($etag, $hb->body['config_etag']);
    }

    public function testStoredOverridesAreCheckedAgainWhenServed(): void
    {
        $pdo = TestDb::pdo($this);
        $pdo->exec("INSERT IGNORE INTO host_groups (name) VALUES ('cfg-test')");
        $gid = (int) $pdo->query("SELECT id FROM host_groups WHERE name = 'cfg-test'")->fetchColumn();
        $pdo->prepare('REPLACE INTO agent_config (group_id, config_version, settings, exclusions, detection_list, etag, updated_at) VALUES (?, 4, ?, ?, ?, \'"x"\', UTC_TIMESTAMP(6))')->execute([
            $gid,
            json_encode(['intervals' => ['heartbeat_s' => 120, 'snapshot_s' => 1]]),
            json_encode([['type' => 'port', 'value' => '445']]),
            json_encode([
                ['product' => 'Redis', 'services' => ['Redis*']],
                ['product' => 'Bad', 'registry_keys' => ['HKLM\\SOFTWARE\\X']],
                ['product' => 'Worse', 'services' => ['x'], 'path' => 'C:\\Windows\\System32\\cmd.exe'],
            ]),
        ]);
        $a = TestDb::agent($pdo, 'SIM-CFG-2');
        $pdo->prepare('UPDATE hosts SET group_id = ? WHERE id = ?')->execute([$gid, $a['host_pk']]);

        $r = $this->get($pdo, $a['cert']);
        self::assertSame(200, $r->status);
        self::assertSame('cfg-test', $r->body['host_group']);
        self::assertSame(4, $r->body['config_version']);
        self::assertSame(120, $r->body['intervals']['heartbeat_s']);
        self::assertSame(300, $r->body['intervals']['snapshot_s']);
        self::assertSame([['type' => 'port', 'value' => '445']], $r->body['exclusions']);
        self::assertSame([['product' => 'Redis', 'services' => ['Redis*']]], $r->body['server_software']['detection_list']);
        self::assertStringNotContainsString('cmd.exe', (string) json_encode($r->body));
        self::assertStringStartsWith("\"cfg-$gid-4-", $r->headers['ETag']);
    }

    public function testConfigNeedsACertificateAndIsGetOnly(): void
    {
        $pdo = TestDb::pdo($this);
        $a = TestDb::agent($pdo, 'SIM-CFG-3');
        $none = (new AgentApi(Config::fromArray([]), static fn (): PDO => $pdo))->handle(Request::forTest('GET', '/api/v1/config', [], ''));
        self::assertSame(401, $none->status);
        self::assertSame(405, $this->get($pdo, $a['cert'], null, 'POST')->status);
    }
}
