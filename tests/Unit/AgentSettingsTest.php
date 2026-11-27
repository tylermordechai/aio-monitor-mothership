<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\AgentConfig\AgentSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What GET /api/v1/config serves (ingest-api.md §5, §5.1; MS-ING-07,
 * AG-CFG-01..03, M-065): defaults, bounds (agent PD-62) and the detection
 * list rule (CLAUDE.md hard rule: service names and HKLM keys only).
 */
final class AgentSettingsTest extends TestCase
{
    /** @param array<string, mixed> $row */
    private static function build(array $row): array
    {
        return AgentSettings::build($row + ['config_version' => 3], 'pilot-web');
    }

    public function testNoRowServesTheAgentDefaults(): void
    {
        $b = AgentSettings::build(null, 'default');
        $c = $b['config'];
        self::assertSame([], $b['rejected']);
        self::assertSame(0, $c['config_version']);
        self::assertSame('default', $c['host_group']);
        self::assertSame(['snapshot_s' => 300, 'flow_window_s' => 10, 'inventory_full_h' => 24, 'inventory_rescan_min' => 15, 'heartbeat_s' => 60, 'config_poll_min' => 15], $c['intervals']);
        self::assertSame(['batch_max_bytes' => 1_048_576, 'batch_max_s' => 10, 'queue_cap_bytes' => 524_288_000], $c['transport']);
        self::assertSame(['etw' => true, 'tcp_bytes' => false, 'include_loopback' => false, 'processes' => true, 'inventory' => true, 'server_software' => true], $c['collectors']);
        self::assertSame(8_192, $c['caps']['command_line_max_bytes']);
        self::assertSame([], $c['exclusions']);
        self::assertSame(['extra_rules' => []], $c['redaction']);
        self::assertSame(['replace_builtin' => false, 'detection_list' => []], $c['server_software']);
    }

    public function testOverridesWithinTheAgentBoundsAreServedAndTheRestKeepTheDefault(): void
    {
        $b = self::build(['settings' => json_encode([
            'intervals' => ['heartbeat_s' => 120, 'snapshot_s' => 10, 'flow_window_s' => '20', 'bogus_s' => 5],
            'transport' => ['queue_cap_bytes' => 0],
            'caps' => ['hash_max_file_bytes' => 0, 'flow_table_max' => 262_144],
            'collectors' => ['tcp_bytes' => true, 'etw' => 'no'],
            'other' => [],
        ])]);
        $c = $b['config'];
        self::assertSame(120, $c['intervals']['heartbeat_s']);
        self::assertSame(300, $c['intervals']['snapshot_s'], 'below 60');
        self::assertSame(10, $c['intervals']['flow_window_s'], 'a string');
        self::assertSame(524_288_000, $c['transport']['queue_cap_bytes'], 'a cap of 0');
        self::assertSame(0, $c['caps']['hash_max_file_bytes'], '0 turns hashing off');
        self::assertSame(262_144, $c['caps']['flow_table_max']);
        self::assertTrue($c['collectors']['tcp_bytes']);
        self::assertTrue($c['collectors']['etw'], 'not a boolean');
        self::assertCount(6, $b['rejected']);
        foreach ($b['rejected'] as $r) {
            self::assertStringNotContainsString("'no'", $r);
        }
    }

    public function testTheContractsExampleListPasses(): void
    {
        $list = [
            ['product' => 'Redis', 'services' => ['Redis*'], 'registry_keys' => ['SOFTWARE\\Redis\\*'], 'version_values' => ['Version'], 'install_value' => 'InstallPath'],
            ['product' => 'RabbitMQ', 'services' => ['RabbitMQ']],
            ['product' => 'PostgreSQL', 'registry_keys' => ['SOFTWARE\\PostgreSQL\\Installations\\*'], 'service_value' => 'Service ID'],
            ['product' => 'Exchange', 'registry_keys' => ['SOFTWARE\\Microsoft\\ExchangeServer\\v15\\Setup'], 'version_values' => ['MsiProductMajor', 'MsiProductMinor', 'MsiBuildMajor', 'MsiBuildMinor']],
            ['product' => 'Svc', 'registry_keys' => ['SYSTEM\\CurrentControlSet\\Services\\X']],
        ];
        $rejected = [];
        self::assertSame($list, AgentSettings::detectionList($list, $rejected));
        self::assertSame([], $rejected);
    }

    /** @return iterable<string, array{0: mixed}> §5.1's refused examples and more */
    public static function refusedEntries(): iterable
    {
        yield 'hive name' => [['product' => 'X', 'registry_keys' => ['HKLM\\SOFTWARE\\X']]];
        yield 'HKEY_ hive' => [['product' => 'X', 'registry_keys' => ['HKEY_LOCAL_MACHINE\\SOFTWARE\\X']]];
        yield 'dot dot' => [['product' => 'X', 'registry_keys' => ['SOFTWARE\\..\\SAM']]];
        yield 'not SOFTWARE or SYSTEM' => [['product' => 'X', 'registry_keys' => ['SAM\\Domains']]];
        yield 'leading backslash' => [['product' => 'X', 'registry_keys' => ['\\SOFTWARE\\X']]];
        yield '13 components' => [['product' => 'X', 'registry_keys' => ['SOFTWARE' . str_repeat('\\a', 12)]]];
        yield 'percent in a key' => [['product' => 'X', 'registry_keys' => ['SOFTWARE\\%x%']]];
        yield 'a path as a service' => [['product' => 'X', 'services' => ['C:\\Program Files\\x.exe']]];
        yield 'two stars' => [['product' => 'X', 'services' => ['a*b*']]];
        yield 'a star in the middle' => [['product' => 'X', 'services' => ['a*b']]];
        yield 'a URL as a service' => [['product' => 'X', 'services' => ['http://x']]];
        yield 'env var as a value' => [['product' => 'X', 'services' => ['x'], 'version_values' => ['%ProgramFiles%\\x']]];
        yield 'path in install_value' => [['product' => 'X', 'services' => ['x'], 'install_value' => 'C:\\x']];
        yield 'a command field' => [['product' => 'X', 'services' => ['x'], 'command' => 'whoami']];
        yield 'a path field' => [['product' => 'X', 'services' => ['x'], 'path' => 'C:\\x']];
        yield 'neither services nor keys' => [['product' => 'X']];
        yield 'no product' => [['services' => ['x']]];
        yield 'product with a newline' => [['product' => "X\nY", 'services' => ['x']]];
        yield 'services not a list' => [['product' => 'X', 'services' => 'x']];
        yield 'not an object' => ['Redis'];
    }

    #[DataProvider('refusedEntries')]
    public function testDetectionEntriesThatBreakTheRuleAreLeftOut(mixed $entry): void
    {
        self::assertNotNull(AgentSettings::detectionEntryProblem($entry));
        $rejected = [];
        self::assertSame([], AgentSettings::detectionList([$entry], $rejected));
        self::assertCount(1, $rejected);
    }

    public function testTheDetectionListIsCutAt100Entries(): void
    {
        $list = [];
        for ($i = 0; $i < 105; $i++) {
            $list[] = ['product' => "P$i", 'services' => ["S$i"]];
        }
        $rejected = [];
        self::assertCount(100, AgentSettings::detectionList($list, $rejected));
        self::assertCount(5, $rejected);
    }

    public function testExclusionsAndRedactionRules(): void
    {
        $b = self::build([
            'exclusions' => json_encode([
                ['type' => 'remote_cidr', 'value' => '10.20.30.0/24', 'note' => 'backup network'],
                ['type' => 'remote_cidr', 'value' => 'fd00::/8'],
                ['type' => 'process_path', 'value' => 'C:\\Program Files\\Backup\\*'],
                ['type' => 'process_path', 'value' => 'C:\\Tools\\agent.exe'],
                ['type' => 'port', 'value' => '9000-9100'],
                ['type' => 'port', 'value' => '0'],
                ['type' => 'remote_cidr', 'value' => '10.0.0.0/33'],
                ['type' => 'process_path', 'value' => 'agent.exe'],
                ['type' => 'process_path', 'value' => 'C:\\a\\..\\b'],
                ['type' => 'user', 'value' => 'x'],
            ]),
            'redaction_rules' => json_encode([
                ['form' => 'next_arg', 'name' => '-AccessKey'],
                ['form' => 'equals', 'name' => 'password'],
                ['form' => 'disable', 'name' => 'all'],
                ['form' => 'next_arg', 'name' => 'a b'],
            ]),
        ]);
        self::assertCount(5, $b['config']['exclusions']);
        self::assertSame(['type' => 'remote_cidr', 'value' => '10.20.30.0/24', 'note' => 'backup network'], $b['config']['exclusions'][0]);
        self::assertSame([['form' => 'next_arg', 'name' => '-AccessKey'], ['form' => 'equals', 'name' => 'password']], $b['config']['redaction']['extra_rules']);
        self::assertCount(7, $b['rejected']);
    }

    public function testBrokenJsonColumnsAreReportedAndIgnored(): void
    {
        $b = self::build(['settings' => 'not json', 'exclusions' => '{"a":1}', 'detection_list' => '"x"']);
        self::assertCount(3, $b['rejected']);
        self::assertSame(60, $b['config']['intervals']['heartbeat_s']);
        self::assertSame([], $b['config']['exclusions']);
    }

    public function testTheEtagChangesWithWhatIsServed(): void
    {
        $a = AgentSettings::build(null, 'default')['config'];
        $b = self::build(['settings' => json_encode(['intervals' => ['heartbeat_s' => 120]])])['config'];
        self::assertMatchesRegularExpression('/^"cfg-1-0-[0-9a-f]{12}"$/', AgentSettings::etag(1, $a));
        self::assertSame(AgentSettings::etag(1, $a), AgentSettings::etag(1, AgentSettings::build(null, 'default')['config']));
        self::assertNotSame(AgentSettings::etag(1, $a), AgentSettings::etag(2, $a));
        self::assertNotSame(AgentSettings::etag(5, $b), AgentSettings::etag(5, self::build([])['config']));
    }
}
