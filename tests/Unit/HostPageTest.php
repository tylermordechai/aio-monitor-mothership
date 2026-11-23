<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Dashboard\HostQuery;
use Aio\Dashboard\Labels;
use Aio\Web\View;
use PHPUnit\Framework\TestCase;

/** MS-UI-02, MS-UI-07, MS-DAT-05, MS-SEC-07..09: the host page without a database (M-056). */
final class HostPageTest extends TestCase
{
    public function testMsDat05GuessesAreNeverLabelledAsFacts(): void
    {
        self::assertSame(Labels::GUESS, Labels::directionSource('heuristic')[1]);
        self::assertSame(Labels::FACT, Labels::directionSource('etw')[1]);
        self::assertSame(Labels::UNKNOWN, Labels::directionSource(null)[1]);
        self::assertSame(['something_new', Labels::UNKNOWN], Labels::directionSource('something_new'), 'a new agent value is shown, marked unknown');
        self::assertSame(Labels::FACT, Labels::matchSource('msi_component')[1]);
        self::assertSame(Labels::FACT, Labels::matchSource('sql_instance')[1]);
        self::assertSame(Labels::LIKELY, Labels::matchSource('display_icon')[1]);
        self::assertSame(Labels::GUESS, Labels::matchSource('install_location')[1]);
        self::assertSame(['from the process token', Labels::FACT], Labels::userSource(null, 'CORP\\x'), 'empty user_source means the token (agent proc.Process)');
        self::assertSame(Labels::FACT, Labels::userSource('etw_event', 'CORP\\x')[1]);
        self::assertSame(Labels::UNKNOWN, Labels::userSource(null, null)[1]);
    }

    public function testMsDat05HashAndSignatureSayWhy(): void
    {
        self::assertSame(['hashed', Labels::FACT], Labels::hashStatus('ok'));
        $l = Labels::hashStatus('access_denied', 'sharing_violation', 'SeBackupPrivilege');
        self::assertSame(Labels::UNKNOWN, $l[1]);
        self::assertStringContainsString('sharing_violation', $l[0]);
        self::assertStringContainsString('SeBackupPrivilege', $l[0]);
        self::assertSame(Labels::FACT, Labels::signature('valid', 'Contoso')[1]);
        self::assertStringContainsString('revoked', Labels::signature('revoked', 'Bad Co')[0]);
        self::assertSame(Labels::UNKNOWN, Labels::signature(null, null)[1]);
    }

    public function testMsUi02IisReadStatusNeverLooksLikeNoSites(): void
    {
        self::assertSame(Labels::FACT, Labels::iisRead('ok')[1]);
        $l = Labels::iisRead('parse_error');
        self::assertSame(Labels::UNKNOWN, $l[1]);
        self::assertStringContainsString('may be missing', $l[0]);
        self::assertSame(Labels::UNKNOWN, Labels::iisRead(null)[1]);
    }

    public function testMsUi02PortOwnersPreferTheAgentLinkThenConfigThenService(): void
    {
        $l = static fn (string $proto, int $port, ?string $app = null, ?string $ms = null, array $svc = []) => [
            'proto' => $proto, 'local_addr' => '0.0.0.0', 'local_port' => $port, 'process_name' => 'p.exe', 'pid' => 4,
            'services' => $svc, 'app_name' => $app, 'app_match_source' => $ms,
        ];
        $owners = Labels::portOwners(
            [$l('tcp', 1433, 'Microsoft SQL Server 2022', 'sql_instance'), $l('tcp', 443), $l('tcp', 1434), $l('tcp', 135, null, null, ['RpcSs']), $l('udp', 443), $l('tcp', 8080)],
            [['name' => 'Default Web Site', 'bindings' => [['protocol' => 'https', 'ip' => '*', 'port' => 443], ['protocol' => 'net.tcp', 'binding_information' => '808:*']]]],
            [['instance_name' => 'MSSQLSERVER', 'tcp_ports' => [1433], 'tcp_dynamic_ports' => [1434]]],
        );
        $by = [];
        foreach ($owners as $o) {
            $by[$o['proto'] . '/' . $o['port']] = $o;
        }
        self::assertSame(['Microsoft SQL Server 2022', Labels::FACT], [$by['tcp/1433']['owner'], $by['tcp/1433']['certainty']], 'agent link first');
        self::assertStringContainsString('Default Web Site', $by['tcp/443']['owner']);
        self::assertSame(Labels::LIKELY, $by['tcp/443']['certainty'], 'IIS config is not proof');
        self::assertStringContainsString('dynamic', $by['tcp/1434']['owner']);
        self::assertSame('service RpcSs', $by['tcp/135']['owner']);
        self::assertSame(Labels::UNKNOWN, $by['udp/443']['certainty'], 'an IIS binding is TCP only');
        self::assertSame(Labels::UNKNOWN, $by['tcp/8080']['certainty']);
        self::assertSame([135, 443, 443, 1433, 1434, 8080], array_column($owners, 'port'), 'sorted by port');
    }

    public function testBytes(): void
    {
        self::assertSame('512 B', Labels::bytes(512));
        self::assertSame('1.5 KB', Labels::bytes(1536));
        self::assertSame('20 MB', Labels::bytes(20 * 1048576));
        self::assertSame('-', Labels::bytes(null));
    }

    public function testMsUi02TabsStartWithConnectionsAndLeaveRoomForAgentMilestone5d(): void
    {
        self::assertSame('connections', array_key_first(HostQuery::TABS));
        self::assertSame(['connections', 'listening', 'processes', 'executables', 'inventory', 'software'], array_keys(HostQuery::TABS));
        self::assertSame(['security', 'shares', 'health', 'network', 'signins'], array_keys(HostQuery::LATER_TABS), 'MPD-39');
        self::assertSame([], array_intersect_key(HostQuery::TABS, HostQuery::LATER_TABS), 'not shown before their data has tables');
        self::assertSame('connections', HostQuery::tab('security'));
        self::assertSame('processes', HostQuery::tab('processes'));
    }

    /** @return array<string, mixed> */
    private function base(string $tab, bool $analyst): array
    {
        $evil = '<script>alert(1)</script>';
        return [
            'title' => 'SRV-1', 'csrf' => str_repeat('ab', 32), 'user' => ['id' => 1, 'username' => 'u', 'display_name' => null, 'role' => $analyst ? 'analyst' : 'viewer'],
            'host' => [
                'host_pk' => 7, 'hostname' => 'SRV-1' . $evil, 'group' => 'default', 'first_seen' => '2026-11-15 00:00:00', 'retired' => false,
                'agent_id' => 'x', 'version' => '0.5.0', 'last_checkin' => '2026-11-23 10:00:00', 'ago' => '1 min ago', 'status' => 'ok', 'queue_records' => 0,
                'facts' => ['os_product_name' => 'Windows Server 2022', 'os_display_version' => '21H2', 'os_version' => '10.0.20348', 'dns_domain' => 'corp.example', 'join_type' => 'domain',
                    'last_boot' => '2026-11-10 02:00:00', 'manufacturer' => 'Contoso', 'model' => null, 'logical_cpus' => 4, 'memory_bytes' => 17179869184, 'interfaces' => null],
                'os' => 'Windows Server 2022', 'kind' => 'server', 'addresses' => ['10.20.0.3'],
            ],
            'tab' => $tab, 'range' => '24h', 'ranges' => ['1h' => 1, '24h' => 1, '7d' => 1], 'since' => '2026-11-22 10:00:00', 'limit' => 500,
            'gaps' => [['reason' => 'session_stopped', 'start' => '2026-11-23 08:00:00', 'end' => null, 'events_lost' => 3, 'error' => null]],
            'seesCommandLines' => $analyst,
        ];
    }

    /** @return array<string, mixed> */
    private function tabData(string $tab): array
    {
        $evil = '<script>alert(1)</script>';
        $listener = ['proto' => 'tcp', 'local_addr' => '0.0.0.0', 'local_port' => 443, 'pid' => 4, 'process_name' => 'System', 'exe' => null, 'user' => null, 'services' => [], 'app_name' => $evil, 'app_match_source' => 'install_location', 'attribution_error' => null];
        $snap = ['agent_pk' => 1, 'snapshot_id' => 's', 'start' => '2026-11-23 09:00:00', 'end' => '2026-11-23 09:00:03', 'sources' => ['apps' => ['items' => 1, 'partial' => true]]];
        return match ($tab) {
            'connections' => [
                'recent' => ['total' => 1, 'data_until' => '2026-11-23 09:58:00', 'rows' => [[
                    'proto' => 'tcp', 'local' => '10.20.0.3:49700', 'remote' => '203.0.113.5:443', 'remote_class' => 'internet', 'peer' => null, 'direction' => 'outbound',
                    'direction_source' => Labels::directionSource('etw'), 'process' => $evil, 'pid' => 4, 'exe' => 'C:\\x.exe', 'user' => 'CORP\\a', 'user_source' => Labels::userSource(null, 'CORP\\a'),
                    'first_seen' => '2026-11-23 09:00:00', 'last_seen' => '2026-11-23 09:58:00', 'windows' => 3, 'connects' => 1, 'bytes_sent' => 10, 'bytes_recv' => 20, 'closed' => false,
                ]]],
                'snapshot' => '2026-11-23 09:59:00.000000',
                'live' => [['local' => 'a:1', 'remote' => 'b:2', 'state' => 'established', 'direction' => 'inbound', 'direction_source' => Labels::directionSource('heuristic'), 'process' => 'p', 'pid' => 1, 'user' => null, 'user_source' => Labels::userSource(null, null), 'services' => []]],
            ],
            'listening' => ['snapshot' => '2026-11-23 09:59:00', 'listening' => [$listener]],
            'processes' => ['processes' => ['total' => 2, 'rows' => [
                ['agent_pk' => 1, 'seq' => 9, 'ts' => '2026-11-23 09:00:00.123456', 'kind' => 'started', 'pid' => 100, 'parent_pid' => 4, 'session_id' => 0, 'start_time' => '2026-11-23 09:00:00',
                    'name' => 'cmd.exe', 'exe' => 'C:\\Windows\\System32\\cmd.exe', 'sha256' => null, 'exe_error' => null, 'user' => 'CORP\\svc', 'user_source' => Labels::userSource(null, 'CORP\\svc'),
                    'has_command_line' => true, 'cl_length' => 58, 'cl_truncated' => false, 'cl_redacted' => true, 'cl_unavailable' => null, 'exit_time' => null, 'exit_code' => null],
                ['agent_pk' => 1, 'seq' => 10, 'ts' => '2026-11-23 09:01:00', 'kind' => 'started', 'pid' => 101, 'parent_pid' => null, 'session_id' => null, 'start_time' => null,
                    'name' => null, 'exe' => null, 'sha256' => null, 'exe_error' => null, 'user' => null, 'user_source' => Labels::userSource(null, null),
                    'has_command_line' => false, 'cl_length' => null, 'cl_truncated' => false, 'cl_redacted' => null, 'cl_unavailable' => 'access_denied', 'exit_time' => '2026-11-23 09:02:00', 'exit_code' => 1],
            ]]],
            'executables' => ['executables' => ['total' => 1, 'rows' => [[
                'path' => 'C:\\a.exe', 'size' => 1000, 'mod_time' => '2026-09-01 00:00:00', 'sha256' => null, 'hash' => Labels::hashStatus('access_denied', 'sharing_violation', 'SeBackupPrivilege'),
                'error' => null, 'signature' => Labels::signature(null, null), 'sig_type' => null, 'product' => null, 'company' => null, 'file_version' => null, 'version_info_error' => null,
                'first_seen' => '2026-11-20 00:00:00', 'last_seen' => '2026-11-23 00:00:00',
            ]]]],
            'inventory' => ['inventory' => $snap, 'changes' => [['ts' => '2026-11-23 09:30:00', 'kind' => 'inv_service', 'change' => 'modified', 'reason' => 'rescan', 'item_name' => $evil, 'key' => 'svc3']], 'items' => [
                'apps' => ['cut' => true, 'rows' => [['name' => $evil, 'version' => '1', 'publisher' => 'C', 'install_date' => '20260901', 'scope' => 'machine', 'arch' => 'x64', 'source' => 'uninstall']]],
                'services' => ['cut' => false, 'rows' => [['name' => 'Svc', 'display_name' => 'S', 'state' => 'running', 'start_type' => 'auto', 'account' => 'LocalSystem', 'binary_path' => 'C:\\s.exe', 'app_name' => null, 'app_match_source' => null]]],
                'tasks' => ['cut' => false, 'rows' => [['path' => '\\T', 'enabled' => 1, 'run_as' => 'SYSTEM', 'run_level' => null, 'actions' => '[{"type":"exec","command":"C:\\\\x.cmd"}]', 'no_security_descriptor' => 1, 'parse_error' => null]]],
                'startup' => ['cut' => false, 'rows' => [['location' => 'HKLM\\Run', 'name' => 'Tray', 'command' => 'tray.exe', 'enabled' => 1, 'user' => null]]],
                'drivers' => ['cut' => false, 'rows' => [['name' => '', 'display_name' => null, 'image_path' => 'x.sys', 'start_type' => 'boot', 'state' => 'running', 'loaded' => 1]]],
                'roles' => ['cut' => false, 'rows' => []],
            ]],
            'software' => ['inventory' => $snap, 'snapshot' => '2026-11-23 09:59:00', 'owners' => Labels::portOwners([$listener], [], []), 'software' => [
                'iis' => ['installed' => 1, 'version' => '10.0', 'read' => 'parse_error', 'parse_error' => '{"file":"applicationHost.config","line":12,"element":"site"}', 'sites' => 1, 'app_pools' => 1, 'config_path' => 'x'],
                'sites' => [['name' => 'Default Web Site', 'id' => 1, 'auto_start' => true, 'bindings' => [['protocol' => 'https', 'ip' => '*', 'port' => 443, 'host' => 'intranet'], ['protocol' => 'net.tcp', 'binding_information' => '808:*']], 'physical_path' => 'C:\\inetpub', 'app_pool' => 'DefaultAppPool', 'applications' => 2]],
                'pools' => [['name' => 'DefaultAppPool', 'identity_type' => 'ApplicationPoolIdentity', 'account' => null, 'managed_runtime_version' => '', 'pipeline_mode' => 'Integrated', 'start_mode' => null, 'auto_start' => 1, 'app_name' => null, 'app_match_source' => null]],
                'sql' => [['instance_name' => 'MSSQLSERVER', 'edition' => 'Standard', 'version' => '16.0', 'patch_level' => '16.0.4135.4', 'service_name' => 'MSSQLSERVER', 'service_account' => null, 'start_type' => 'auto', 'tcp_enabled' => 1, 'tcp_ports' => [1433], 'tcp_dynamic_ports' => [], 'app_name' => 'SQL', 'app_match_source' => 'sql_instance']],
                'apps' => [['product' => 'Apache Tomcat', 'version' => null, 'version_source' => null, 'detected_by' => 'service', 'service_name' => 'Tomcat9', 'registry_key' => null, 'install_path' => null, 'list_source' => 'builtin']],
            ]],
        };
    }

    private function render(string $tab, bool $analyst): string
    {
        $view = new View(dirname(__DIR__, 2) . '/templates');
        $v = $this->base($tab, $analyst) + $this->tabData($tab);
        $v['tabHtml'] = $view->render('host-' . $tab, $v, null);
        return $view->render('host', $v);
    }

    public function testMsUi02EveryTabRendersEscapedWithItsGaps(): void
    {
        foreach (array_keys(HostQuery::TABS) as $tab) {
            $html = $this->render($tab, false);
            self::assertStringNotContainsString('<script>alert(1)</script>', $html, "$tab: MS-SEC-05 escaping");
            self::assertStringContainsString('session stopped', $html, "$tab: MS-UI-07 gaps on the page");
            self::assertStringContainsString('aria-current="page">' . HostQuery::TABS[$tab], $html, "$tab: current tab marked");
        }
        self::assertStringContainsString('c-guess', $this->render('connections', false), 'the live snapshot direction is a guess');
        self::assertStringContainsString('may be missing', $this->render('software', false), 'IIS read status shown');
        self::assertStringContainsString('applicationHost.config', $this->render('software', false));
        self::assertStringContainsString('incomplete sources', $this->render('inventory', false), 'a partial source is shown');
        self::assertStringContainsString('SeBackupPrivilege', $this->render('executables', false));
    }

    public function testMsSec07ViewersGetNoRevealButtonAndNobodyGetsTheCommandLineInThePage(): void
    {
        $viewer = $this->render('processes', false);
        self::assertStringNotContainsString('/command-line', $viewer);
        self::assertStringContainsString('analysts only', $viewer);
        self::assertStringContainsString('redacted by the agent', $viewer, 'MS-SEC-09: the flag is shown to everyone');
        $analyst = $this->render('processes', true);
        self::assertStringContainsString('action="/hosts/7/command-line"', $analyst);
        self::assertStringContainsString('name="_csrf" value="' . str_repeat('ab', 32) . '"', $analyst);
        self::assertStringContainsString('audit log', $analyst);
        self::assertStringNotContainsString('nightly.cmd', $analyst, 'the page itself never holds a command line');
        self::assertStringContainsString('not read: access denied', $analyst);
    }
}
