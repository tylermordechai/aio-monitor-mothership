<?php

declare(strict_types=1);

namespace Aio\Sim;

use Aio\Util\Uuid;

/**
 * Realistic agent records for every schema_version 1 record type
 * (ingest-api.md §9), shaped like the agent's own output (field names from
 * the agent repo's record structs and docs/status.md "Record reference").
 *
 * Used by the simulator (tools/simulator) and the integration tests, so
 * milestones 1 to 6 need no Windows machine (CLAUDE.md). Values are
 * plausible, not real: private addresses, made-up paths and names.
 */
final class RecordFactory
{
    private const EXES = [
        ['C:\\Windows\\System32\\svchost.exe', 'svchost.exe', 'NT AUTHORITY\\SYSTEM', ['Dnscache', 'LanmanWorkstation']],
        ['C:\\Program Files\\Contoso\\Agent\\contoso-agent.exe', 'contoso-agent.exe', 'NT AUTHORITY\\SYSTEM', []],
        ['C:\\Windows\\System32\\inetsrv\\w3wp.exe', 'w3wp.exe', 'IIS APPPOOL\\DefaultAppPool', []],
        ['C:\\Program Files\\Microsoft SQL Server\\MSSQL16.MSSQLSERVER\\MSSQL\\Binn\\sqlservr.exe', 'sqlservr.exe', 'NT Service\\MSSQLSERVER', ['MSSQLSERVER']],
        ['C:\\Windows\\System32\\lsass.exe', 'lsass.exe', 'NT AUTHORITY\\SYSTEM', ['SamSs']],
        ['C:\\Program Files\\Backup\\bkagent.exe', 'bkagent.exe', 'CORP\\svc-backup', ['BkAgent']],
    ];

    private int $seq;
    /** @var list<array{pid: int, exe: array{0:string,1:string,2:string,3:list<string>}, start: string}> */
    private array $procs = [];

    public function __construct(
        public readonly string $agentId,
        public readonly string $hostId,
        public string $hostname,
        int $startSeq = 1,
        public readonly string $agentVersion = '0.5.0',
        private readonly int $hostNo = 1,
    ) {
        $this->seq = $startSeq;
        $boot = gmdate('Y-m-d\TH:i:s', time() - 86_400 * 3) . '.1000000Z';
        foreach (self::EXES as $i => $e) {
            $this->procs[] = ['pid' => 600 + $i * 412 + ($hostNo % 97), 'exe' => $e, 'start' => $boot];
        }
    }

    public function nextSeq(): int
    {
        return $this->seq;
    }

    /** @param array<string, mixed> $data */
    public function envelope(string $type, array $data, ?float $ts = null, ?int $seq = null): array
    {
        $ts ??= microtime(true);
        $env = [
            'schema_version' => 1,
            'agent_id' => $this->agentId,
            'host_id' => $this->hostId,
            'hostname' => $this->hostname,
            'agent_version' => $this->agentVersion,
            'record_type' => $type,
            'seq' => $seq ?? $this->seq++,
            'ts' => self::ts($ts),
            'data' => $data === [] ? new \stdClass() : $data,
        ];
        return $env;
    }

    /** RFC 3339 UTC with 7 fractional digits, as the agent writes it. */
    public static function ts(float $t): string
    {
        $sec = (int) floor($t);
        $frac = (int) floor(($t - $sec) * 10_000_000);
        return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%07dZ', $frac);
    }

    /** @return array<string, mixed> */
    private function proc(int $i): array
    {
        $p = $this->procs[$i % count($this->procs)];
        $out = [
            'pid' => $p['pid'],
            'process_name' => $p['exe'][1],
            'exe' => $p['exe'][0],
            'user' => $p['exe'][2],
            'user_source' => 'token',
            'process_start_time' => $p['start'],
        ];
        if ($p['exe'][3] !== []) {
            $out['services'] = $p['exe'][3];
        }
        return $out;
    }

    private function localIp(): string
    {
        return '10.20.' . intdiv($this->hostNo, 250) % 250 . '.' . ($this->hostNo % 250 + 2);
    }

    /**
     * One network snapshot: listeners, UDP endpoints, connections and the
     * summary, all sharing one ts (D-008).
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(float $ts, int $conns = 40, int $listeners = 12, int $udp = 6): array
    {
        $out = [];
        for ($i = 0; $i < $listeners; $i++) {
            $out[] = $this->envelope('tcp_listener', ['family' => 'ipv4', 'local_addr' => '0.0.0.0', 'local_port' => [135, 445, 443, 1433, 3389, 5985, 49664, 49665, 49666, 49667, 49668, 8080][$i % 12]] + $this->proc($i), $ts);
        }
        for ($i = 0; $i < $udp; $i++) {
            $out[] = $this->envelope('udp_endpoint', ['family' => 'ipv4', 'local_addr' => '0.0.0.0', 'local_port' => [123, 137, 138, 500, 4500, 5353][$i % 6]] + $this->proc($i + 2), $ts);
        }
        for ($i = 0; $i < $conns; $i++) {
            $inbound = $i % 4 === 0;
            $out[] = $this->envelope('tcp_conn', [
                'family' => 'ipv4',
                'local_addr' => $this->localIp(),
                'local_port' => $inbound ? 443 : 49_700 + $i,
                'remote_addr' => $i % 3 === 0 ? '203.0.113.' . (10 + $i % 200) : '10.20.0.' . (1 + $i % 50),
                'remote_port' => $inbound ? 50_000 + $i : [443, 445, 1433, 389, 88][$i % 5],
                'state' => 'established',
                'direction' => $inbound ? 'inbound' : 'outbound',
                'direction_source' => 'heuristic',
                'listener_pid_match' => $inbound,
            ] + $this->proc($i), $ts);
        }
        $out[] = $this->envelope('net_snapshot_summary', [
            'tcp_conns' => $conns, 'tcp_listeners' => $listeners, 'udp_endpoints' => $udp,
            'loopback_skipped' => 8, 'include_loopback' => false, 'attribution_errors' => 0, 'duration_ms' => 14,
        ], $ts);
        return $out;
    }

    /** @return list<array<string, mixed>> net_flow records for one 10 s window */
    public function flows(float $windowEnd, int $n = 20): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->envelope('net_flow', [
                'proto' => $i % 5 === 0 ? 'udp' : 'tcp',
                'family' => 'ipv4',
                'local_addr' => $this->localIp(),
                'local_port' => 49_800 + $i,
                'remote_addr' => $i % 2 === 0 ? '203.0.113.' . (20 + $i) : '10.20.0.' . (5 + $i),
                'remote_port' => [443, 53, 445, 80, 1433][$i % 5],
                'direction' => 'outbound',
                'direction_source' => 'etw',
                'first_seen' => self::ts($windowEnd - 10 - ($i % 3) * 10),
                'last_seen' => self::ts($windowEnd - 1),
                'window_start' => self::ts($windowEnd - 10),
                'window_end' => self::ts($windowEnd),
                'connects' => $i % 3 === 0 ? 1 : 0,
                'packets_sent' => 10 + $i,
                'packets_recv' => 12 + $i,
                'bytes_sent' => 1200 + 97 * $i,
                'bytes_recv' => 5400 + 331 * $i,
                'closed' => $i % 7 === 0,
            ] + $this->proc($i), $windowEnd);
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function processes(float $ts, int $starts = 2): array
    {
        $out = [];
        for ($i = 0; $i < $starts; $i++) {
            $pid = 10_000 + random_int(0, 50_000) * 4;
            $out[] = $this->envelope('process_start', [
                'pid' => $pid,
                'parent_pid' => $this->procs[0]['pid'],
                'parent_start_time' => $this->procs[0]['start'],
                'session_id' => 0,
                'start_time' => self::ts($ts - 0.2),
                'exe' => 'C:\\Windows\\System32\\cmd.exe',
                'process_name' => 'cmd.exe',
                'exe_size' => 289_792,
                'exe_mod_time' => '2026-10-08T03:12:44.0000000Z',
                'command_line' => 'cmd.exe /c "C:\\Scripts\\nightly.cmd" --password=<redacted>',
                'command_line_length' => 58,
                'command_line_redacted' => true,
                'user' => 'CORP\\svc-tasks',
                'user_source' => 'token',
            ], $ts);
            $out[] = $this->envelope('process_stop', [
                'pid' => $pid,
                'start_time' => self::ts($ts - 0.2),
                'exit_time' => self::ts($ts),
                'exit_code' => 0,
                'exe' => 'C:\\Windows\\System32\\cmd.exe',
                'process_name' => 'cmd.exe',
            ], $ts);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function executable(float $ts, int $i = 0, string $hashStatus = 'ok'): array
    {
        $e = self::EXES[$i % count(self::EXES)];
        $data = [
            'path' => $e[0],
            'size' => 100_000 + 4096 * $i,
            'mod_time' => '2026-09-1' . ($i % 9) . 'T08:00:00.0000000Z',
            'hash_status' => $hashStatus,
            'elapsed_ms' => 18,
        ];
        if ($hashStatus === 'ok') {
            $data['sha256'] = hash('sha256', $e[0]);
            $data['signature'] = ['status' => 'valid', 'type' => 'embedded', 'signer' => 'Contoso Ltd', 'issuer' => 'Contoso Code Signing CA', 'thumbprint' => strtoupper(sha1($e[0])), 'serial_number' => '0A1B2C'];
            $data['version_info'] = ['product_name' => 'Contoso Product', 'company_name' => 'Contoso Ltd', 'file_version' => '1.2.3.4'];
        } else {
            $data['access_denied_reason'] = 'sharing_violation';
            $data['would_need_privilege'] = 'SeBackupPrivilege';
        }
        return $this->envelope('executable', $data, $ts);
    }

    /** @return array<string, mixed> */
    public function etwStatus(float $ts, string $event = 'periodic'): array
    {
        return $this->envelope('etw_status', [
            'event' => $event, 'events' => 182_311, 'failed_connects' => 3, 'loopback_skipped' => 1201,
            'flows_active' => 77, 'flows_dropped' => 0, 'decode_errors' => 0, 'agent_dropped' => 0,
            'processes_tracked' => 143, 'tcp_bytes' => true, 'go_heap_bytes' => 21_000_000,
            'working_set_bytes' => 48_000_000, 'priority_class' => 'below_normal', 'priority_boosts' => 0,
            'processes' => ['hash_queued' => 4, 'hashed' => 120, 'hash_access_denied_needs_backup_privilege' => 1],
        ], $ts);
    }

    /** @return array<string, mixed> */
    public function coverageGap(float $ts, float $start, ?float $end = null, string $reason = 'session_stopped'): array
    {
        $d = ['reason' => $reason, 'start' => self::ts($start)];
        if ($end !== null) {
            $d['end'] = self::ts($end);
            $d['events_lost'] = 0;
        }
        return $this->envelope('coverage_gap', $d, $ts);
    }

    /** @return array<string, mixed> */
    public function agentStart(float $ts, string $previousExit = 'clean', ?int $seq = null): array
    {
        return $this->envelope('agent_start', [
            'mode' => 'service', 'service_name' => 'AioMonitorAgent', 'pid' => 4120,
            'previous_exit' => $previousExit, 'restarts_since_clean' => 0,
            'data_dir' => 'C:\\ProgramData\\AioMonitor', 'priority_class' => 'below_normal',
            'privileges' => ['SeBackupPrivilege' => 'enabled'],
        ], $ts, $seq);
    }

    /** @return array<string, mixed> */
    public function agentStop(float $ts): array
    {
        return $this->envelope('agent_stop', ['reason' => 'service_stop', 'uptime_s' => 3600.5], $ts);
    }

    /**
     * A full inventory snapshot: start marker, items of every kind, end marker.
     * A laptop ($server false, M-050) has no roles, IIS, SQL Server or server apps.
     *
     * @return list<array<string, mixed>>
     */
    public function inventory(float $ts, int $apps = 60, int $services = 120, bool $server = true): array
    {
        $snap = 'inv-' . substr(Uuid::v4(), 0, 8);
        $out = [$this->envelope('inventory_snapshot', ['snapshot_id' => $snap, 'event' => 'start', 'reason' => 'scheduled'], $ts)];
        for ($i = 0; $i < $apps; $i++) {
            $out[] = $this->envelope('inv_app', [
                'source' => 'uninstall', 'name' => "Contoso App $i", 'version' => "1.$i.0", 'publisher' => 'Contoso Ltd',
                'install_date' => '20260901', 'scope' => 'machine', 'arch' => 'x64', 'reg_key' => sprintf('{%08X-0000-0000-0000-000000000000}', $i),
            ], $ts);
        }
        for ($i = 0; $i < $services; $i++) {
            $out[] = $this->envelope('inv_service', [
                'name' => "Svc$i", 'display_name' => "Service $i", 'binary_path' => "C:\\Windows\\System32\\svc$i.exe",
                'account' => 'LocalSystem', 'start_type' => $i % 3 === 0 ? 'manual' : 'auto', 'type' => 'own_process',
                'state' => $i % 3 === 0 ? 'stopped' : 'running', 'pid' => $i % 3 === 0 ? 0 : 2000 + $i,
                'exe' => ['path' => "C:\\Windows\\System32\\svc$i.exe", 'size' => 40_960, 'mod_time' => '2026-09-01T00:00:00Z'],
            ], $ts);
        }
        $out[] = $this->envelope('inv_task', ['path' => '\\Contoso\\Nightly', 'enabled' => true, 'run_as' => 'CORP\\svc-tasks', 'actions' => [['type' => 'exec', 'command' => 'C:\\Scripts\\nightly.cmd']], 'triggers' => [['type' => 'daily']]], $ts);
        $out[] = $this->envelope('inv_startup', ['location' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run', 'name' => 'ContosoTray', 'command' => '"C:\\Program Files\\Contoso\\tray.exe"', 'enabled' => true], $ts);
        $out[] = $this->envelope('inv_driver', ['name' => 'Tcpip', 'display_name' => 'TCP/IP Protocol Driver', 'image_path' => '\\SystemRoot\\System32\\drivers\\tcpip.sys', 'start_type' => 'boot', 'type' => 'kernel', 'state' => 'running', 'loaded' => true], $ts);
        $os = $server
            ? ['os_product_name' => 'Windows Server 2022 Standard', 'os_version' => '10.0.20348', 'os_display_version' => '21H2', 'manufacturer' => 'Contoso Virtual', 'if' => 'Ethernet']
            : ['os_product_name' => 'Windows 11 Pro', 'os_version' => '10.0.26100', 'os_display_version' => '24H2', 'manufacturer' => 'Contoso Laptops', 'if' => 'Wi-Fi'];
        $out[] = $this->envelope('host_facts', [
            'hostname' => $this->hostname, 'dns_domain' => 'corp.example', 'join_type' => 'domain', 'os_product_name' => $os['os_product_name'],
            'os_version' => $os['os_version'], 'os_display_version' => $os['os_display_version'], 'last_boot' => '2026-11-10T02:00:00Z', 'logical_cpus' => $server ? 4 : 8,
            'memory_bytes' => 17_179_869_184, 'manufacturer' => $os['manufacturer'], 'interfaces' => [['name' => $os['if'], 'addresses' => [$this->localIp()]]],
        ], $ts);
        if (!$server) {
            $out[] = $this->envelope('inventory_link_index', ['reason' => 'scheduled', 'duration_ms' => 180, 'component_keys' => 9000, 'products' => $apps + 1], $ts);
            $out[] = $this->envelope('inventory_snapshot', ['snapshot_id' => $snap, 'event' => 'end', 'reason' => 'scheduled', 'duration_ms' => 3100, 'sources' => ['apps' => ['items' => $apps], 'services' => ['items' => $services]]], $ts);
            return $out;
        }
        $out[] = $this->envelope('inv_role', ['name' => 'Web-Server', 'display_name' => 'Web Server (IIS)', 'install_state' => 1, 'installed' => true], $ts);
        $out[] = $this->envelope('inv_iis', ['installed' => true, 'version' => '10.0', 'read' => 'ok', 'sites' => 1, 'app_pools' => 1, 'config_path' => 'C:\\Windows\\System32\\inetsrv\\config\\applicationHost.config'], $ts);
        $out[] = $this->envelope('inv_iis_site', ['name' => 'Default Web Site', 'id' => 1, 'auto_start' => true, 'bindings' => [['protocol' => 'https', 'ip' => '*', 'port' => 443, 'host' => '']], 'physical_path' => '%SystemDrive%\\inetpub\\wwwroot', 'app_pool' => 'DefaultAppPool'], $ts);
        $out[] = $this->envelope('inv_iis_app_pool', ['name' => 'DefaultAppPool', 'managed_runtime_version' => 'v4.0', 'pipeline_mode' => 'integrated', 'identity_type' => 'ApplicationPoolIdentity', 'auto_start' => true], $ts);
        $out[] = $this->envelope('inv_sql_instance', ['instance_name' => 'MSSQLSERVER', 'edition' => 'Standard Edition', 'version' => '16.0', 'patch_level' => '16.0.4135.4', 'service_name' => 'MSSQLSERVER', 'tcp_enabled' => true, 'tcp_ports' => [1433], 'app' => ['name' => 'Microsoft SQL Server 2022', 'match_source' => 'confirmed']], $ts);
        $out[] = $this->envelope('inv_server_app', ['product' => 'Tomcat', 'version' => '9.0.89', 'version_source' => 'registry', 'detected_by' => 'service', 'service_name' => 'Tomcat9', 'list_source' => 'builtin'], $ts);
        $out[] = $this->envelope('inventory_link_index', ['reason' => 'scheduled', 'duration_ms' => 210, 'component_keys' => 5000, 'products' => 61], $ts);
        $out[] = $this->envelope('inventory_snapshot', ['snapshot_id' => $snap, 'event' => 'end', 'reason' => 'scheduled', 'duration_ms' => 2400, 'sources' => ['apps' => ['items' => $apps], 'services' => ['items' => $services]]], $ts);
        return $out;
    }

    /** @return array<string, mixed> */
    public function inventoryChange(float $ts): array
    {
        return $this->envelope('inventory_change', ['kind' => 'inv_service', 'key' => 'svc3', 'change' => 'modified', 'reason' => 'rescan', 'before' => ['state' => 'stopped'], 'after' => ['state' => 'running']], $ts);
    }

    /** One of every record type (for tests): @return list<array<string, mixed>> */
    public function oneOfEach(float $ts): array
    {
        $out = $this->snapshot($ts, 2, 1, 1);
        $out = array_merge($out, $this->flows($ts, 2), $this->processes($ts, 1));
        $out[] = $this->executable($ts);
        $out[] = $this->etwStatus($ts);
        $out[] = $this->coverageGap($ts, $ts - 60);
        $out[] = $this->agentStart($ts);
        $out[] = $this->agentStop($ts);
        $out = array_merge($out, $this->inventory($ts, 1, 1));
        $out[] = $this->inventoryChange($ts);
        $p = $this->proc(1);
        $out[] = $this->envelope('process_snapshot', [
            'pid' => $p['pid'], 'parent_pid' => 4, 'start_time' => $p['process_start_time'], 'exe' => $p['exe'],
            'process_name' => $p['process_name'], 'user' => $p['user'], 'user_source' => 'token',
            'command_line_unavailable' => 'access_denied',
        ], $ts);
        return $out;
    }

    /** @param list<array<string, mixed>> $records */
    public static function jsonl(array $records): string
    {
        $s = '';
        foreach ($records as $r) {
            $s .= json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        }
        return $s;
    }
}
