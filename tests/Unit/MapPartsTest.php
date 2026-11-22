<?php

declare(strict_types=1);

namespace Aio\Tests\Unit;

use Aio\Dashboard\AddressClass;
use Aio\Dashboard\FleetQuery;
use Aio\Sim\Network;
use PHPUnit\Framework\TestCase;

/** MS-UI-01 server map parts (M-054). */
final class MapPartsTest extends TestCase
{
    private const NETS = '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,100.64.0.0/10,169.254.0.0/16,fc00::/7,fe80::/10';

    public function testMsUi01AddressesAreInternalInternetOrLocal(): void
    {
        $c = new AddressClass(self::NETS);
        foreach (['10.20.250.1', '172.16.0.1', '172.31.255.255', '192.168.0.191', '100.64.1.1', '169.254.10.1', 'fd12::1', 'fe80::1'] as $ip) {
            self::assertSame('internal', $c->classify($ip), $ip);
        }
        foreach (['172.32.0.1', '8.8.8.8', '203.0.113.20', '2001:db8::1', '192.169.0.1', '100.128.0.1'] as $ip) {
            self::assertSame('internet', $c->classify($ip), $ip);
        }
        foreach (['127.0.0.1', '0.0.0.0', '224.0.0.251', '255.255.255.255', '::1', '::', 'ff02::fb'] as $ip) {
            self::assertSame('local', $c->classify($ip), $ip);
        }
        self::assertSame('internet', $c->classify('not an address'));
        self::assertSame('10.1.2.3', AddressClass::text(inet_pton('10.1.2.3')));
        self::assertNull(AddressClass::text(null));
        self::assertNull(AddressClass::text('abc'));
    }

    public function testBadNetworkSettingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AddressClass('10.0.0.0/33');
    }

    public function testM054SimulatedHostsTalkToEachOtherAndToMoreThan15InternalAddresses(): void
    {
        $net = new Network([1, 2], [3, 4]);
        $laptopToServers = [];
        $serverInbound = [];
        $internal = [];
        $c = new AddressClass(self::NETS);
        $hostIps = array_map([Network::class, 'ipOf'], [1, 2, 3, 4]);
        foreach ([[1, true, 20], [2, true, 20], [3, false, 15], [4, false, 15]] as [$no, $server, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $s = $net->slot($no, $server, $i);
                self::assertSame($s, $net->slot($no, $server, $i), 'a slot is the same connection every window (D-016)');
                if (!$server && in_array($s['remote_addr'], [Network::ipOf(1), Network::ipOf(2)], true)) {
                    $laptopToServers[] = $s['remote_port'];
                }
                if ($server && $s['direction'] === 'inbound') {
                    $serverInbound[] = $s['remote_addr'];
                }
                if ($c->classify($s['remote_addr']) === 'internal' && !in_array($s['remote_addr'], $hostIps, true)) {
                    $internal[$s['remote_addr']] = true;
                }
            }
        }
        self::assertContains(443, $laptopToServers);
        self::assertContains(1433, $laptopToServers);
        self::assertContains(445, $laptopToServers);
        self::assertContains(Network::ipOf(3), $serverInbound);
        self::assertContains(Network::ipOf(4), $serverInbound);
        self::assertGreaterThan(15, count($internal), 'enough to show the "other internal" node');
    }

    public function testAgoText(): void
    {
        self::assertSame('never', FleetQuery::ago(null));
        self::assertSame('45 s ago', FleetQuery::ago(45));
        self::assertSame('5 min ago', FleetQuery::ago(300));
        self::assertSame('3 h ago', FleetQuery::ago(3 * 3600 + 5));
        self::assertSame('9 days ago', FleetQuery::ago(9 * 86_400));
    }
}
