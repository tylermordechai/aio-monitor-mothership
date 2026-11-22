<?php

declare(strict_types=1);

namespace Aio\Dashboard;

use Aio\Config;
use PDO;

/**
 * The server map's data (MS-UI-01, MS-UI-07, M-054), as nodes and edges for
 * Cytoscape.js (public/dashboard/assets/map.js).
 *
 * - One node per host: name, version, last check-in, stale, coverage gaps.
 * - Lines from each host to what it talks to in the chosen range, from
 *   flow_conn_hourly (connections combined per D-016):
 *   - another monitored host, matched by the hosts' own local addresses;
 *     both hosts may report the same connection, so a host-to-host line
 *     takes the larger of the two reports, not the sum;
 *   - the busiest `map_internal_nodes` (15) unmonitored internal addresses
 *     as their own nodes, the rest as one "other internal" node;
 *   - every other address as one "internet" node.
 * - Direction: a line points from the side that opened the connection.
 */
final class MapQuery
{
    /** @var array<string, int> */
    public const RANGES = ['1h' => 3600, '24h' => 86_400, '7d' => 604_800];

    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public static function range(string $r): string
    {
        return isset(self::RANGES[$r]) ? $r : '24h';
    }

    /** @return array<string, mixed> */
    public function build(string $range, int $now): array
    {
        $since = $now - self::RANGES[self::range($range)];
        $sinceHour = gmdate('Y-m-d H:00:00', $since);
        $classes = new AddressClass($this->config->string('dashboard', 'internal_networks'));
        $maxInternal = max(0, $this->config->int('dashboard', 'map_internal_nodes'));

        // Hosts, as in the fleet table.
        $fleet = (new FleetQuery($this->pdo, $this->config))->hosts($now);
        $hostIds = array_column($fleet, 'host_pk');

        // Each host's own addresses, so a remote address can be matched to a host.
        $ipToHost = [];
        $s = $this->pdo->prepare('SELECT DISTINCT host_pk, local_ip FROM flow_conn_hourly WHERE hour >= ? AND local_ip IS NOT NULL');
        $s->execute([$sinceHour]);
        foreach ($s as $r) {
            $ipToHost[(string) $r['local_ip']] = (int) $r['host_pk'];
        }

        // Connections in the range, per host, remote address, direction and service port.
        $s = $this->pdo->prepare(
            "SELECT host_pk, remote_ip, MAX(remote_addr) AS remote_addr, direction, proto,
                    IF(direction = 'inbound', local_port, remote_port) AS svc_port,
                    SUM(bytes_sent + bytes_recv) AS bytes, COUNT(DISTINCT flow_key) AS conns, MAX(last_seen) AS last_seen
               FROM flow_conn_hourly
              WHERE hour >= ? AND (last_seen IS NULL OR last_seen >= FROM_UNIXTIME(?))
              GROUP BY host_pk, remote_ip, direction, proto, svc_port"
        );
        $s->execute([$sinceHour, $since]);

        $edges = [];        // key => edge
        $internalBytes = []; // ip => bytes
        $internalEdges = []; // ip => list of [src|dst, host, direction, bytes, conns, port]
        $internet = [];     // set of addresses
        $seenUntil = null;
        foreach ($s as $r) {
            $host = (int) $r['host_pk'];
            if (!in_array($host, $hostIds, true)) {
                continue;
            }
            $bin = $r['remote_ip'] === null ? null : (string) $r['remote_ip'];
            $ip = AddressClass::text($bin) ?? (string) $r['remote_addr'];
            $bytes = (int) $r['bytes'];
            $conns = (int) $r['conns'];
            $port = $r['proto'] . '/' . $r['svc_port'];
            $out = $r['direction'] !== 'inbound';
            if ($r['last_seen'] !== null && ($seenUntil === null || $r['last_seen'] > $seenUntil)) {
                $seenUntil = (string) $r['last_seen'];
            }
            $peer = $bin !== null ? ($ipToHost[$bin] ?? null) : null;
            if ($peer !== null && !in_array($peer, $hostIds, true)) {
                $peer = null; // a retired host's old address: drawn as an address
            }
            if ($peer !== null && $peer !== $host) {
                $this->addEdge($edges, $out ? "h$host" : "h$peer", $out ? "h$peer" : "h$host", $bytes, $conns, $port, "h$host");
                continue;
            }
            if ($peer === $host) {
                continue; // a host talking to itself on its own address
            }
            $class = $classes->classify($ip);
            if ($class === AddressClass::LOCAL) {
                continue;
            }
            if ($class === AddressClass::INTERNAL) {
                $internalBytes[$ip] = ($internalBytes[$ip] ?? 0) + $bytes;
                $internalEdges[$ip][] = [$host, $out, $bytes, $conns, $port];
                continue;
            }
            $internet[$ip] = true;
            $this->addEdge($edges, $out ? "h$host" : 'internet', $out ? 'internet' : "h$host", $bytes, $conns, $port, "h$host");
        }

        // The busiest internal addresses get their own node; the rest share one.
        arsort($internalBytes);
        $own = array_slice(array_keys($internalBytes), 0, $maxInternal);
        $ownSet = array_flip($own);
        $otherCount = 0;
        foreach ($internalEdges as $ip => $list) {
            $node = isset($ownSet[$ip]) ? 'ip:' . $ip : 'other-internal';
            if (!isset($ownSet[$ip])) {
                $otherCount++;
            }
            foreach ($list as [$host, $out, $bytes, $conns, $port]) {
                $this->addEdge($edges, $out ? "h$host" : $node, $out ? $node : "h$host", $bytes, $conns, $port, "h$host");
            }
        }

        // Coverage gaps in the range (MS-UI-07).
        $g = $this->pdo->prepare(
            'SELECT host_pk, reason, gap_start, gap_end FROM coverage_gaps
              WHERE gap_start <= FROM_UNIXTIME(?) AND (gap_end IS NULL OR gap_end >= FROM_UNIXTIME(?))
              ORDER BY gap_start'
        );
        $g->execute([$now, $since]);
        $gaps = [];
        $gapCount = [];
        foreach ($g as $r) {
            $pk = (int) $r['host_pk'];
            $gapCount[$pk] = ($gapCount[$pk] ?? 0) + 1;
            $gaps[] = ['host_pk' => $pk, 'reason' => (string) $r['reason'], 'start' => substr((string) $r['gap_start'], 0, 19), 'end' => $r['gap_end'] === null ? null : substr((string) $r['gap_end'], 0, 19)];
        }

        $nodes = [];
        $names = [];
        foreach ($fleet as $h) {
            $names[$h['host_pk']] = $h['hostname'];
            $nodes[] = [
                'id' => 'h' . $h['host_pk'],
                'type' => 'host',
                'host_pk' => $h['host_pk'],
                'label' => $h['hostname'],
                'kind' => $h['kind'],
                'os' => $h['os'],
                'version' => $h['version'],
                'last_checkin' => $h['last_checkin'],
                'ago' => FleetQuery::ago($h['ago_s']),
                'status' => $h['status'],
                'gaps' => $gapCount[$h['host_pk']] ?? 0,
            ];
        }
        foreach ($own as $ip) {
            $nodes[] = ['id' => 'ip:' . $ip, 'type' => 'internal', 'label' => $ip, 'bytes' => $internalBytes[$ip]];
        }
        if ($otherCount > 0) {
            $nodes[] = ['id' => 'other-internal', 'type' => 'other-internal', 'label' => "Other internal ($otherCount)", 'addresses' => $otherCount];
        }
        if ($internet !== []) {
            $nodes[] = ['id' => 'internet', 'type' => 'internet', 'label' => 'Internet (' . count($internet) . ')', 'addresses' => count($internet)];
        }
        foreach ($gaps as &$gp) {
            $gp['hostname'] = $names[$gp['host_pk']] ?? ('host ' . $gp['host_pk']);
        }
        unset($gp);

        $outEdges = [];
        foreach ($edges as $e) {
            $bytes = max($e['by']);
            arsort($e['ports']);
            $ports = array_slice(array_keys($e['ports']), 0, 4);
            $outEdges[] = [
                'id' => $e['source'] . '>' . $e['target'],
                'source' => $e['source'],
                'target' => $e['target'],
                'bytes' => $bytes,
                'conns' => max($e['conns']),
                'ports' => $ports,
                'label' => implode(' ', $ports) . (count($e['ports']) > 4 ? ' …' : ''),
            ];
        }
        return [
            'range' => self::range($range),
            'since' => gmdate('Y-m-d H:i:s', $since),
            'until' => gmdate('Y-m-d H:i:s', $now),
            'data_until' => $seenUntil === null ? null : substr($seenUntil, 0, 19),
            'nodes' => $nodes,
            'edges' => $outEdges,
            'gaps' => $gaps,
        ];
    }

    /**
     * Adds to the line source -> target. Bytes and connection counts are kept
     * per reporting host, and the line shows the largest report, so two hosts
     * that both see one connection don't count it twice.
     *
     * @param array<string, array{source: string, target: string, by: array<string, int>, conns: array<string, int>, ports: array<string, int>}> $edges
     */
    private function addEdge(array &$edges, string $source, string $target, int $bytes, int $conns, string $port, string $reporter): void
    {
        $k = $source . '>' . $target;
        $edges[$k] ??= ['source' => $source, 'target' => $target, 'by' => [], 'conns' => [], 'ports' => []];
        $edges[$k]['by'][$reporter] = ($edges[$k]['by'][$reporter] ?? 0) + $bytes;
        $edges[$k]['conns'][$reporter] = ($edges[$k]['conns'][$reporter] ?? 0) + $conns;
        $edges[$k]['ports'][$port] = ($edges[$k]['ports'][$port] ?? 0) + $bytes;
    }
}
