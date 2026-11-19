<?php

declare(strict_types=1);

namespace Aio\Ingest;

/**
 * The agent's record types for schema_version 1 and where each is stored
 * (ingest-api.md §9). "required" are the data fields a line must have
 * (§3.4 rule 9, M-007); a NOT NULL column without a default is required too.
 *
 * kind:
 *   insert     one row per line, INSERT IGNORE on (agent, seq, ts) (M-005)
 *   process    process_events, plus process_command_lines when a command line is sent (M-011)
 *   executable one row per host and file version, updated in place (M-014)
 *   gap        one row per coverage gap, updated in place (M-015)
 *   item       an inventory item: item_key, key_source, snapshot_id (M-012)
 */
final class RecordTypes
{
    /** @var array<string, array{table: string, required: list<string>, kind: string}> */
    public const TYPES = [
        'tcp_conn' => ['table' => 'snap_tcp_conns', 'kind' => 'insert', 'required' => ['family', 'local_addr', 'local_port', 'remote_addr', 'remote_port', 'state', 'direction', 'direction_source', 'pid']],
        'tcp_listener' => ['table' => 'snap_listeners', 'kind' => 'insert', 'required' => ['family', 'local_addr', 'local_port', 'pid']],
        'udp_endpoint' => ['table' => 'snap_udp_endpoints', 'kind' => 'insert', 'required' => ['family', 'local_addr', 'local_port', 'pid']],
        'net_snapshot_summary' => ['table' => 'snapshots', 'kind' => 'insert', 'required' => ['tcp_conns', 'tcp_listeners', 'udp_endpoints']],
        'net_flow' => ['table' => 'net_flows', 'kind' => 'insert', 'required' => ['proto', 'family', 'local_addr', 'local_port', 'remote_addr', 'remote_port', 'direction', 'window_start', 'window_end', 'pid']],
        'etw_status' => ['table' => 'agent_status', 'kind' => 'insert', 'required' => ['event']],
        'coverage_gap' => ['table' => 'coverage_gaps', 'kind' => 'gap', 'required' => ['reason', 'start']],
        'process_start' => ['table' => 'process_events', 'kind' => 'process', 'required' => ['pid']],
        'process_snapshot' => ['table' => 'process_events', 'kind' => 'process', 'required' => ['pid']],
        'process_stop' => ['table' => 'process_events', 'kind' => 'process', 'required' => ['pid', 'exit_time']],
        'executable' => ['table' => 'executables', 'kind' => 'executable', 'required' => ['path', 'size', 'mod_time', 'hash_status']],
        'agent_start' => ['table' => 'agent_lifecycle', 'kind' => 'insert', 'required' => ['mode', 'pid', 'previous_exit']],
        'agent_stop' => ['table' => 'agent_lifecycle', 'kind' => 'insert', 'required' => ['reason']],
        'inventory_snapshot' => ['table' => 'inventory_snapshots', 'kind' => 'insert', 'required' => ['snapshot_id', 'event', 'reason']],
        'inventory_change' => ['table' => 'inventory_changes', 'kind' => 'insert', 'required' => ['kind', 'key', 'change']],
        'inventory_link_index' => ['table' => 'inventory_link_index', 'kind' => 'insert', 'required' => ['reason']],
        'inv_app' => ['table' => 'inv_apps', 'kind' => 'item', 'required' => ['source', 'name']],
        'inv_service' => ['table' => 'inv_services', 'kind' => 'item', 'required' => ['name']],
        'inv_task' => ['table' => 'inv_tasks', 'kind' => 'item', 'required' => ['path']],
        'inv_startup' => ['table' => 'inv_startup', 'kind' => 'item', 'required' => ['location', 'name']],
        'inv_driver' => ['table' => 'inv_drivers', 'kind' => 'item', 'required' => []],
        'inv_role' => ['table' => 'inv_roles', 'kind' => 'item', 'required' => ['name']],
        'host_facts' => ['table' => 'host_facts', 'kind' => 'item', 'required' => ['hostname']],
        'inv_iis' => ['table' => 'inv_iis', 'kind' => 'item', 'required' => ['installed', 'read']],
        'inv_iis_site' => ['table' => 'inv_iis_sites', 'kind' => 'item', 'required' => ['name', 'id']],
        'inv_iis_app_pool' => ['table' => 'inv_iis_app_pools', 'kind' => 'item', 'required' => ['name']],
        'inv_sql_instance' => ['table' => 'inv_sql_instances', 'kind' => 'item', 'required' => ['instance_name']],
        'inv_server_app' => ['table' => 'inv_server_apps', 'kind' => 'item', 'required' => ['product']],
    ];

    /** @return array{table: string, required: list<string>, kind: string}|null */
    public static function get(string $recordType): ?array
    {
        return self::TYPES[$recordType] ?? null;
    }
}
