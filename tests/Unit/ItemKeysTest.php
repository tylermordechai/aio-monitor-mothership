<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Ingest\ItemKeys;
use PHPUnit\Framework\TestCase;

/** M-012: derived keys follow the agent's own rules (internal/inventory). */
final class ItemKeysTest extends TestCase
{
    private static function k(string $type, array $data): string
    {
        return ItemKeys::derive($type, json_decode(json_encode($data)));
    }

    public function testKeys(): void
    {
        self::assertSame('uninstall|machine||x64|{abc-123}', self::k('inv_app', ['source' => 'uninstall', 'scope' => 'machine', 'arch' => 'x64', 'reg_key' => '{ABC-123}', 'name' => 'App']));
        self::assertSame('msix|microsoft.app_1.0_x64__8wekyb', self::k('inv_app', ['source' => 'msix', 'package_full_name' => 'Microsoft.App_1.0_x64__8wekyb', 'name' => 'App']));
        self::assertSame('spooler', self::k('inv_service', ['name' => 'Spooler']));
        self::assertSame('tcpip', self::k('inv_driver', ['name' => 'Tcpip']));
        self::assertSame('image|c:\\windows\\system32\\ntoskrnl.exe', self::k('inv_driver', ['name' => '', 'file' => ['path' => 'C:\\Windows\\System32\\ntoskrnl.exe']]));
        self::assertSame('\\microsoft\\windows\\defrag\\scheduleddefrag', self::k('inv_task', ['path' => '\\Microsoft\\Windows\\Defrag\\ScheduledDefrag']));
        self::assertSame('hklm\\software\\microsoft\\windows\\currentversion\\run|onedrive', self::k('inv_startup', ['location' => 'HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run', 'name' => 'OneDrive']));
        self::assertSame('host', self::k('host_facts', ['hostname' => 'SRV-01']));
        self::assertSame('iis_app_pool|defaultapppool', self::k('inv_iis_app_pool', ['name' => 'DefaultAppPool']));
        self::assertSame('sql_instance|mssqlserver|32', self::k('inv_sql_instance', ['instance_name' => 'MSSQLSERVER', 'registry_view' => '32']));
        self::assertSame('server_app|tomcat|tomcat9', self::k('inv_server_app', ['product' => 'Tomcat', 'detected_by' => 'service', 'service_name' => 'Tomcat9']));
        self::assertSame('server_app|nginx|hklm\\software\\nginx', self::k('inv_server_app', ['product' => 'nginx', 'detected_by' => 'registry', 'registry_key' => 'HKLM\\SOFTWARE\\nginx']));
    }
}
