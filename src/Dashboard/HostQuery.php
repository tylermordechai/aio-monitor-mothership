<?php

declare(strict_types=1);

namespace Aio\Dashboard;

use Aio\Config;
use PDO;

/**
 * The host page (MS-UI-02, M-056): one query set per tab, read as
 * aio_dashboard, which can't read command lines (M-053). Each list is
 * capped at `dashboard.host_rows` rows and says when it was cut, so a page
 * stays fast (MS-UI-08) and a cut list is never read as complete.
 *
 * Time-based tabs (connections, processes, inventory changes) take the page's
 * range and show the host's coverage gaps for it (MS-UI-07). Snapshot tabs
 * (live connections, listening ports, inventory, server software) show the
 * time of the snapshot they come from.
 */
final class HostQuery
{
    /** Tab id => title, in page order. Connections first (owner, milestone 5 scope). */
    public const TABS = [
        'connections' => 'Connections',
        'listening' => 'Listening ports',
        'processes' => 'Processes',
        'executables' => 'Executables',
        'inventory' => 'Inventory',
        'software' => 'Server software',
    ];

    /**
     * Tabs for agent milestone 5d data (MPD-39). Added to TABS, with their
     * queries, once the record formats are final and have tables; until then
     * the records are quarantined and these tabs are not shown.
     */
    public const LATER_TABS = ['security' => 'Security', 'shares' => 'Shares', 'health' => 'Health', 'network' => 'Network', 'signins' => 'Sign-ins'];

    private readonly int $limit;

    public function __construct(private readonly PDO $pdo, Config $config)
    {
        $this->limit = max(10, $config->int('dashboard', 'host_rows'));
    }

    public static function tab(string $t): string
    {
        return isset(self::TABS[$t]) ? $t : 'connections';
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /** @return ?array<string, mixed> the host, its active agent and latest facts; null if there is no such host */
    public function host(int $hostPk, Config $config, int $now): ?array
    {
        $s = $this->pdo->prepare(
            "SELECT h.id, h.hostname, h.first_seen, h.retired_at, g.name AS grp,
                    a.agent_id, a.last_checkin_at, UNIX_TIMESTAMP(a.last_checkin_at) AS checkin_epoch, a.last_version, a.queue_records, a.status AS agent_status
               FROM hosts h
               JOIN host_groups g ON g.id = h.group_id
               LEFT JOIN agents a ON a.host_pk = h.id AND a.status = 'active'
              WHERE h.id = ?
              ORDER BY a.id LIMIT 1"
        );
        $s->execute([$hostPk]);
        $h = $s->fetch();
        if (!is_array($h)) {
            return null;
        }
        $f = $this->pdo->prepare(
            'SELECT ts, os_product_name, os_display_version, os_version, dns_domain, join_type, last_boot, manufacturer, model, logical_cpus, memory_bytes, interfaces
               FROM host_facts WHERE host_pk = ? ORDER BY ts DESC LIMIT 1'
        );
        $f->execute([$hostPk]);
        $facts = $f->fetch() ?: null;
        $ago = $h['checkin_epoch'] === null ? null : max(0, $now - (int) $h['checkin_epoch']);
        $status = match (true) {
            $h['agent_id'] === null => 'no_agent',
            $ago === null || $ago > $config->int('dashboard', 'stale_after_s') => 'stale',
            default => 'ok',
        };
        $addresses = [];
        if (is_array($facts) && is_string($facts['interfaces'])) {
            foreach (json_decode($facts['interfaces'], true) ?: [] as $if) {
                foreach ((array) ($if['addresses'] ?? []) as $a) {
                    if (is_string($a)) {
                        $addresses[] = $a;
                    }
                }
            }
        }
        $os = is_array($facts) ? $facts['os_product_name'] : null;
        return [
            'host_pk' => (int) $h['id'],
            'hostname' => (string) $h['hostname'],
            'group' => (string) $h['grp'],
            'first_seen' => substr((string) $h['first_seen'], 0, 19),
            'retired' => $h['retired_at'] !== null,
            'agent_id' => $h['agent_id'],
            'version' => $h['last_version'],
            'last_checkin' => $h['last_checkin_at'] === null ? null : substr((string) $h['last_checkin_at'], 0, 19),
            'ago' => FleetQuery::ago($ago),
            'status' => $status,
            'queue_records' => $h['queue_records'],
            'facts' => $facts,
            'os' => $os,
            'kind' => $os !== null && stripos((string) $os, 'server') === false ? 'workstation' : 'server',
            'addresses' => array_values(array_unique($addresses)),
        ];
    }

    /** @return list<array{reason: string, start: string, end: ?string, events_lost: ?int, error: ?string}> MS-UI-07 */
    public function gaps(int $hostPk, int $since, int $now): array
    {
        $s = $this->pdo->prepare(
            'SELECT reason, gap_start, gap_end, events_lost, error FROM coverage_gaps
              WHERE host_pk = ? AND gap_start <= FROM_UNIXTIME(?) AND (gap_end IS NULL OR gap_end >= FROM_UNIXTIME(?))
              ORDER BY gap_start DESC LIMIT 200'
        );
        $s->execute([$hostPk, $now, $since]);
        $out = [];
        foreach ($s as $r) {
            $out[] = [
                'reason' => (string) $r['reason'],
                'start' => substr((string) $r['gap_start'], 0, 19),
                'end' => $r['gap_end'] === null ? null : substr((string) $r['gap_end'], 0, 19),
                'events_lost' => $r['events_lost'] === null ? null : (int) $r['events_lost'],
                'error' => $r['error'],
            ];
        }
        return $out;
    }

    /**
     * Connections in the range from flow_conn_hourly, one row per D-016 key
     * (protocol, PID, local and remote address and port), newest first;
     * the remote end named when it is another monitored host.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, data_until: ?string}
     */
    public function connections(int $hostPk, int $since, Config $config): array
    {
        $sinceHour = gmdate('Y-m-d H:00:00', $since);
        $s = $this->pdo->prepare(
            "SELECT MAX(proto) AS proto, MAX(local_addr) AS local_addr, MAX(local_port) AS local_port,
                    MAX(remote_addr) AS remote_addr, MAX(remote_ip) AS remote_ip, MAX(remote_port) AS remote_port,
                    MAX(pid) AS pid, MAX(process_name) AS process_name, MAX(exe) AS exe, MAX(`user`) AS `user`, MAX(user_source) AS user_source,
                    direction, MAX(direction_source) AS direction_source,
                    MIN(first_seen) AS first_seen, MAX(last_seen) AS last_seen, SUM(windows) AS windows, SUM(connects) AS connects,
                    SUM(bytes_sent) AS bytes_sent, SUM(bytes_recv) AS bytes_recv, MAX(closed) AS closed
               FROM flow_conn_hourly
              WHERE host_pk = ? AND hour >= ? AND (last_seen IS NULL OR last_seen >= FROM_UNIXTIME(?))
              GROUP BY flow_key, direction
              ORDER BY MAX(last_seen) DESC
              LIMIT " . $this->limit
        );
        $s->execute([$hostPk, $sinceHour, $since]);
        $rows = $s->fetchAll();

        $t = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT flow_key), MAX(last_seen) FROM flow_conn_hourly
              WHERE host_pk = ? AND hour >= ? AND (last_seen IS NULL OR last_seen >= FROM_UNIXTIME(?))'
        );
        $t->execute([$hostPk, $sinceHour, $since]);
        [$total, $until] = $t->fetch(PDO::FETCH_NUM);

        $peers = $this->peerHosts($sinceHour);
        $classes = new AddressClass($config->string('dashboard', 'internal_networks'));
        $out = [];
        foreach ($rows as $r) {
            $bin = $r['remote_ip'] === null ? null : (string) $r['remote_ip'];
            $peer = $bin !== null ? ($peers[$bin] ?? null) : null;
            $ip = AddressClass::text($bin) ?? (string) $r['remote_addr'];
            $out[] = [
                'proto' => (string) $r['proto'],
                'local' => $r['local_addr'] . ':' . $r['local_port'],
                'remote' => $r['remote_addr'] . ':' . $r['remote_port'],
                'remote_class' => $peer !== null && $peer[0] !== $hostPk ? 'host' : $classes->classify($ip),
                'peer' => $peer !== null && $peer[0] !== $hostPk ? ['host_pk' => $peer[0], 'hostname' => $peer[1]] : null,
                'direction' => (string) $r['direction'],
                'direction_source' => Labels::directionSource($r['direction_source']),
                'process' => $r['process_name'],
                'pid' => (int) $r['pid'],
                'exe' => $r['exe'],
                'user' => $r['user'],
                'user_source' => Labels::userSource($r['user_source'], $r['user']),
                'first_seen' => $r['first_seen'] === null ? null : substr((string) $r['first_seen'], 0, 19),
                'last_seen' => $r['last_seen'] === null ? null : substr((string) $r['last_seen'], 0, 19),
                'windows' => (int) $r['windows'],
                'connects' => (int) $r['connects'],
                'bytes_sent' => (int) $r['bytes_sent'],
                'bytes_recv' => (int) $r['bytes_recv'],
                'closed' => (bool) $r['closed'],
            ];
        }
        return ['rows' => $out, 'total' => (int) $total, 'data_until' => $until === null ? null : substr((string) $until, 0, 19)];
    }

    /** @return array<string, array{0: int, 1: string}> binary IP => [host_pk, hostname] of hosts that still exist */
    private function peerHosts(string $sinceHour): array
    {
        $s = $this->pdo->prepare(
            'SELECT DISTINCT f.local_ip, h.id, h.hostname FROM flow_conn_hourly f JOIN hosts h ON h.id = f.host_pk
              WHERE f.hour >= ? AND f.local_ip IS NOT NULL AND h.retired_at IS NULL'
        );
        $s->execute([$sinceHour]);
        $out = [];
        foreach ($s as $r) {
            $out[(string) $r['local_ip']] = [(int) $r['id'], (string) $r['hostname']];
        }
        return $out;
    }

    /** Time of the host's latest network snapshot (tcp_conn, tcp_listener, udp_endpoint share it, D-008). */
    public function latestSnapshot(int $hostPk): ?string
    {
        $s = $this->pdo->prepare('SELECT MAX(ts) FROM snapshots WHERE host_pk = ?');
        $s->execute([$hostPk]);
        $ts = $s->fetchColumn();
        return $ts === null || $ts === false ? null : (string) $ts;
    }

    /** @return list<array<string, mixed>> the latest snapshot's TCP connections (the "live" view) */
    public function liveConnections(int $hostPk, string $snapTs): array
    {
        $s = $this->pdo->prepare(
            'SELECT local_addr, local_port, remote_addr, remote_port, state, direction, direction_source, listener_pid_match,
                    pid, process_name, `user`, user_source, services
               FROM snap_tcp_conns WHERE host_pk = ? AND ts = ?
              ORDER BY remote_addr, remote_port LIMIT ' . $this->limit
        );
        $s->execute([$hostPk, $snapTs]);
        $out = [];
        foreach ($s as $r) {
            $out[] = [
                'local' => $r['local_addr'] . ':' . $r['local_port'],
                'remote' => $r['remote_addr'] . ':' . $r['remote_port'],
                'state' => (string) $r['state'],
                'direction' => (string) $r['direction'],
                'direction_source' => Labels::directionSource($r['direction_source']),
                'process' => $r['process_name'],
                'pid' => (int) $r['pid'],
                'user' => $r['user'],
                'user_source' => Labels::userSource($r['user_source'], $r['user']),
                'services' => self::jsonList($r['services']),
            ];
        }
        return $out;
    }

    /**
     * The latest snapshot's TCP listeners and UDP endpoints, with the agent's
     * product link (AG-INV-15) and how sure it is.
     *
     * @return list<array{proto: string, local_addr: string, local_port: int, pid: int, process_name: ?string, exe: ?string, user: ?string, services: list<string>, app_name: ?string, app_match_source: ?string, attribution_error: ?string}>
     */
    public function listening(int $hostPk, string $snapTs): array
    {
        $out = [];
        foreach (['tcp' => 'snap_listeners', 'udp' => 'snap_udp_endpoints'] as $proto => $table) {
            $s = $this->pdo->prepare(
                "SELECT local_addr, local_port, pid, process_name, exe, `user`, services, app_name, app_match_source, attribution_error
                   FROM `$table` WHERE host_pk = ? AND ts = ? ORDER BY local_port LIMIT " . $this->limit
            );
            $s->execute([$hostPk, $snapTs]);
            foreach ($s as $r) {
                $out[] = [
                    'proto' => $proto,
                    'local_addr' => (string) $r['local_addr'],
                    'local_port' => (int) $r['local_port'],
                    'pid' => (int) $r['pid'],
                    'process_name' => $r['process_name'],
                    'exe' => $r['exe'],
                    'user' => $r['user'],
                    'services' => self::jsonList($r['services']),
                    'app_name' => $r['app_name'],
                    'app_match_source' => $r['app_match_source'],
                    'attribution_error' => $r['attribution_error'],
                ];
            }
        }
        usort($out, static fn ($a, $b) => [$a['local_port'], $a['proto']] <=> [$b['local_port'], $b['proto']]);
        return $out;
    }

    /**
     * Processes started in the range (and those already running when the
     * agent started: process_snapshot), newest first, with their stop when
     * there is one. The command line is never read here: only the flags,
     * and whether one is stored (MS-SEC-07, M-011).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function processes(int $hostPk, int $since): array
    {
        $from = gmdate('Y-m-d H:i:s', $since);
        $s = $this->pdo->prepare(
            "SELECT p.agent_pk, p.seq, p.ts, p.record_type, p.pid, p.parent_pid, p.session_id, p.start_time, p.process_name, p.exe, p.exe_sha256,
                    p.exe_error, p.`user`, p.user_source, p.has_command_line, p.command_line_length, p.command_line_truncated,
                    p.command_line_redacted, p.command_line_unavailable, s.exit_time, s.exit_code
               FROM process_events p
               LEFT JOIN process_events s
                 ON s.host_pk = p.host_pk AND s.pid = p.pid AND s.start_time = p.start_time AND s.record_type = 'process_stop'
              WHERE p.host_pk = ? AND p.ts >= ? AND p.record_type IN ('process_start', 'process_snapshot')
              ORDER BY p.ts DESC
              LIMIT " . $this->limit
        );
        $s->execute([$hostPk, $from]);
        $out = [];
        foreach ($s as $r) {
            $out[] = [
                'agent_pk' => (int) $r['agent_pk'],
                'seq' => (int) $r['seq'],
                'ts' => (string) $r['ts'],
                'kind' => $r['record_type'] === 'process_snapshot' ? 'running at agent start' : 'started',
                'pid' => (int) $r['pid'],
                'parent_pid' => $r['parent_pid'] === null ? null : (int) $r['parent_pid'],
                'session_id' => $r['session_id'] === null ? null : (int) $r['session_id'],
                'start_time' => $r['start_time'] === null ? null : substr((string) $r['start_time'], 0, 19),
                'name' => $r['process_name'],
                'exe' => $r['exe'],
                'sha256' => $r['exe_sha256'],
                'exe_error' => $r['exe_error'],
                'user' => $r['user'],
                'user_source' => Labels::userSource($r['user_source'], $r['user']),
                'has_command_line' => (bool) $r['has_command_line'],
                'cl_length' => $r['command_line_length'] === null ? null : (int) $r['command_line_length'],
                'cl_truncated' => (bool) $r['command_line_truncated'],
                'cl_redacted' => $r['command_line_redacted'] === null ? null : (bool) $r['command_line_redacted'],
                'cl_unavailable' => $r['command_line_unavailable'],
                'exit_time' => $r['exit_time'] === null ? null : substr((string) $r['exit_time'], 0, 19),
                'exit_code' => $r['exit_code'] === null ? null : (int) $r['exit_code'],
            ];
        }
        $t = $this->pdo->prepare("SELECT COUNT(*) FROM process_events WHERE host_pk = ? AND ts >= ? AND record_type IN ('process_start', 'process_snapshot')");
        $t->execute([$hostPk, $from]);
        return ['rows' => $out, 'total' => (int) $t->fetchColumn()];
    }

    /** @return array{rows: list<array<string, mixed>>, total: int} executables seen on the host, latest first (MS-DAT-05) */
    public function executables(int $hostPk): array
    {
        $s = $this->pdo->prepare(
            "SELECT path, size, mod_time, sha256, hash_status, access_denied_reason, would_need_privilege, error,
                    sig_status, sig_type, sig_signer, product_name, company_name,
                    JSON_VALUE(version_info, '$.file_version') AS file_version, version_info_error, first_seen, last_seen
               FROM executables WHERE host_pk = ? ORDER BY last_seen DESC LIMIT " . $this->limit
        );
        $s->execute([$hostPk]);
        $out = [];
        foreach ($s as $r) {
            $out[] = [
                'path' => (string) $r['path'],
                'size' => (int) $r['size'],
                'mod_time' => substr((string) $r['mod_time'], 0, 19),
                'sha256' => $r['sha256'],
                'hash' => Labels::hashStatus((string) $r['hash_status'], $r['access_denied_reason'], $r['would_need_privilege']),
                'error' => $r['error'],
                'signature' => Labels::signature($r['sig_status'], $r['sig_signer']),
                'sig_type' => $r['sig_type'],
                'product' => $r['product_name'],
                'company' => $r['company_name'],
                'file_version' => $r['file_version'],
                'version_info_error' => $r['version_info_error'],
                'first_seen' => substr((string) $r['first_seen'], 0, 19),
                'last_seen' => substr((string) $r['last_seen'], 0, 19),
            ];
        }
        $t = $this->pdo->prepare('SELECT COUNT(*) FROM executables WHERE host_pk = ?');
        $t->execute([$hostPk]);
        return ['rows' => $out, 'total' => (int) $t->fetchColumn()];
    }

    /**
     * The host's latest complete inventory snapshot: the time between its
     * start and end markers. inventory_current is filled by a later job
     * (milestone 4), so the items are read from the inv_* tables in that
     * window. Null when no snapshot has finished yet.
     *
     * @return ?array{agent_pk: int, snapshot_id: string, start: string, end: string, sources: array<string, mixed>}
     */
    public function latestInventory(int $hostPk): ?array
    {
        $s = $this->pdo->prepare("SELECT agent_pk, snapshot_id, ts, sources, duration_ms FROM inventory_snapshots WHERE host_pk = ? AND event = 'end' ORDER BY ts DESC LIMIT 1");
        $s->execute([$hostPk]);
        $end = $s->fetch();
        if (!is_array($end)) {
            return null;
        }
        $st = $this->pdo->prepare("SELECT MIN(ts) FROM inventory_snapshots WHERE agent_pk = ? AND snapshot_id = ? AND event = 'start'");
        $st->execute([(int) $end['agent_pk'], (string) $end['snapshot_id']]);
        $start = $st->fetchColumn();
        if ($start === null || $start === false) {
            // No start marker kept: go back by the snapshot's own duration.
            $start = gmdate('Y-m-d H:i:s', (int) floor(strtotime((string) $end['ts'] . ' UTC') - ((int) ($end['duration_ms'] ?? 0)) / 1000) - 1);
        }
        return [
            'agent_pk' => (int) $end['agent_pk'],
            'snapshot_id' => (string) $end['snapshot_id'],
            'start' => (string) $start,
            'end' => (string) $end['ts'],
            'sources' => is_string($end['sources']) ? (json_decode($end['sources'], true) ?: []) : [],
        ];
    }

    /**
     * Items of one inventory kind from the snapshot window.
     *
     * @param array{start: string, end: string} $snap
     * @return array{rows: list<array<string, mixed>>, cut: bool}
     */
    public function inventoryItems(int $hostPk, string $kind, array $snap): array
    {
        $cols = match ($kind) {
            'apps' => ['inv_apps', 'name, version, publisher, install_date, scope, arch, source', 'name'],
            'services' => ['inv_services', 'name, display_name, state, start_type, account, binary_path, app_name, app_match_source', 'name'],
            'tasks' => ['inv_tasks', 'path, enabled, run_as, run_level, actions, no_security_descriptor, parse_error', 'path'],
            'startup' => ['inv_startup', 'location, name, command, enabled, `user`', 'name'],
            'drivers' => ['inv_drivers', 'name, display_name, image_path, start_type, state, loaded', 'name'],
            'roles' => ['inv_roles', 'name, display_name, installed', 'name'],
            default => throw new \InvalidArgumentException('unknown inventory kind'),
        };
        $s = $this->pdo->prepare("SELECT {$cols[1]} FROM `{$cols[0]}` WHERE host_pk = ? AND ts BETWEEN ? AND ? ORDER BY {$cols[2]} LIMIT " . ($this->limit + 1));
        $s->execute([$hostPk, $snap['start'], $snap['end']]);
        $rows = $s->fetchAll();
        $cut = count($rows) > $this->limit;
        return ['rows' => array_slice($rows, 0, $this->limit), 'cut' => $cut];
    }

    /** @return list<array<string, mixed>> inventory changes in the range, newest first (time-based: gaps shown) */
    public function inventoryChanges(int $hostPk, int $since): array
    {
        $s = $this->pdo->prepare(
            'SELECT ts, kind, `change`, reason, item_name, `key` FROM inventory_changes
              WHERE host_pk = ? AND ts >= ? ORDER BY ts DESC LIMIT ' . $this->limit
        );
        $s->execute([$hostPk, gmdate('Y-m-d H:i:s', $since)]);
        return $s->fetchAll();
    }

    /**
     * Server software from the latest inventory snapshot (MS-UI-02): IIS with
     * its read status, sites with bindings, app pools, SQL Server instances
     * and their ports, other server products.
     *
     * @param array{start: string, end: string} $snap
     * @return array<string, mixed>
     */
    public function serverSoftware(int $hostPk, array $snap): array
    {
        $w = [$hostPk, $snap['start'], $snap['end']];
        $q = function (string $sql) use ($w): array {
            $s = $this->pdo->prepare($sql);
            $s->execute($w);
            return $s->fetchAll();
        };
        $iis = $q('SELECT installed, version, `read`, parse_error, sites, app_pools, config_path FROM inv_iis WHERE host_pk = ? AND ts BETWEEN ? AND ? ORDER BY ts DESC LIMIT 1');
        $sites = [];
        foreach ($q('SELECT name, id, auto_start, bindings, physical_path, app_pool, applications FROM inv_iis_sites WHERE host_pk = ? AND ts BETWEEN ? AND ? ORDER BY name LIMIT 500') as $r) {
            $sites[] = [
                'name' => (string) $r['name'],
                'id' => (int) $r['id'],
                'auto_start' => $r['auto_start'] === null ? null : (bool) $r['auto_start'],
                'bindings' => self::jsonArray($r['bindings']),
                'physical_path' => $r['physical_path'],
                'app_pool' => $r['app_pool'],
                'applications' => count(self::jsonArray($r['applications'])),
            ];
        }
        $pools = $q('SELECT name, identity_type, account, managed_runtime_version, pipeline_mode, start_mode, auto_start, app_name, app_match_source
                       FROM inv_iis_app_pools WHERE host_pk = ? AND ts BETWEEN ? AND ? ORDER BY name LIMIT 500');
        $sql = [];
        foreach ($q('SELECT instance_name, edition, version, patch_level, service_name, service_account, start_type, tcp_enabled, tcp_ports, tcp_dynamic_ports, app_name, app_match_source
                       FROM inv_sql_instances WHERE host_pk = ? AND ts BETWEEN ? AND ? ORDER BY instance_name LIMIT 100') as $r) {
            $r['tcp_ports'] = array_values(array_filter(self::jsonArray($r['tcp_ports']), 'is_int'));
            $r['tcp_dynamic_ports'] = array_values(array_filter(self::jsonArray($r['tcp_dynamic_ports']), 'is_int'));
            $sql[] = $r;
        }
        $apps = $q('SELECT product, version, version_source, detected_by, service_name, registry_key, install_path, list_source
                      FROM inv_server_apps WHERE host_pk = ? AND ts BETWEEN ? AND ? ORDER BY product LIMIT 200');
        return ['iis' => $iis[0] ?? null, 'sites' => $sites, 'pools' => $pools, 'sql' => $sql, 'apps' => $apps];
    }

    /** @return list<string> */
    private static function jsonList(mixed $json): array
    {
        return array_values(array_filter(self::jsonArray($json), 'is_string'));
    }

    /** @return array<mixed> */
    private static function jsonArray(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $v = json_decode($json, true);
        return is_array($v) ? $v : [];
    }
}
