<?php

declare(strict_types=1);

namespace Aio\Ingest;

/**
 * Inventory item keys (M-012).
 *
 * Item records don't carry their key yet (asked of agent 5c), so the
 * mothership derives it with the agent's own rules, read from the agent repo
 * (internal/inventory, master 6bd6de7, 2026-11-19):
 *   apps_windows.go        uninstall|<scope>|<user>|<arch>|<reg subkey>, msix|<full name>
 *   services_windows.go    <service name>; drivers: <name>, or image|<path> for a loaded image
 *   tasks_windows.go       <task path>
 *   startup_windows.go     <location>|<name> (a few COM entries use the CLSID subkey instead)
 *   host_windows.go        host; roles: <name>
 *   iis_windows.go         iis, iis_site|<id>, iis_app_pool|<name>
 *   sqlserver_windows.go   sql_instance|<name>[|32]
 *   serverapps_windows.go  server_app|<product>|<service name or registry key>
 * All lower-cased, as the agent does. A derived key is marked key_source =
 * 'derived', so it is never shown as the agent's own.
 */
final class ItemKeys
{
    public static function derive(string $recordType, \stdClass $d): string
    {
        $s = static fn (string $f): string => isset($d->$f) && is_scalar($d->$f) ? (string) $d->$f : '';
        $key = match ($recordType) {
            'inv_app' => $s('source') === 'msix'
                ? 'msix|' . ($s('package_full_name') !== '' ? $s('package_full_name') : $s('name'))
                : implode('|', ['uninstall', $s('scope'), $s('user'), $s('arch'), $s('reg_key')]),
            'inv_service', 'inv_role' => $s('name'),
            'inv_driver' => $s('name') !== ''
                ? $s('name')
                : 'image|' . (isset($d->file) && $d->file instanceof \stdClass && isset($d->file->path) && is_string($d->file->path) ? $d->file->path : ''),
            'inv_task' => $s('path'),
            'inv_startup' => $s('location') . '|' . $s('name'),
            'host_facts' => 'host',
            'inv_iis' => 'iis',
            'inv_iis_site' => 'iis_site|' . $s('id'),
            'inv_iis_app_pool' => 'iis_app_pool|' . $s('name'),
            'inv_sql_instance' => 'sql_instance|' . $s('instance_name') . ($s('registry_view') === '32' ? '|32' : ''),
            'inv_server_app' => 'server_app|' . $s('product') . '|'
                . ($s('detected_by') === 'registry' ? $s('registry_key') : $s('service_name')),
            default => $recordType,
        };
        return mb_substr(mb_strtolower($key), 0, 1024);
    }
}
