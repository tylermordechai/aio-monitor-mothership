<?php

declare(strict_types=1);

namespace Aio\Dashboard;

/**
 * How the host page words the agent's "how do we know" fields (MS-DAT-05),
 * so a guess is never shown as a fact. Each label is [text, certainty], the
 * certainty one of fact, likely, guess or unknown; the page styles guesses
 * and unknowns differently from facts. A value the agent adds later is shown
 * as it is, marked unknown, never dropped.
 */
final class Labels
{
    public const FACT = 'fact';
    public const LIKELY = 'likely';
    public const GUESS = 'guess';
    public const UNKNOWN = 'unknown';

    /** @return array{0: string, 1: string} net_flow / tcp_conn direction_source (D-001, D-014) */
    public static function directionSource(?string $s): array
    {
        return match ($s) {
            'etw' => ['seen by the agent (ETW)', self::FACT],
            'etw_first_packet' => ['from the first packet (ETW)', self::LIKELY],
            'heuristic' => ['from the ports', self::GUESS],
            null, '' => ['not reported', self::UNKNOWN],
            default => [$s, self::UNKNOWN],
        };
    }

    /**
     * user_source (D-012): "etw_event" when the user came from the SID on the
     * process event; empty means the live process token (agent proc.Process).
     *
     * @return array{0: string, 1: string}
     */
    public static function userSource(?string $s, ?string $user): array
    {
        if ($user === null || $user === '') {
            return ['no user reported', self::UNKNOWN];
        }
        return match ($s) {
            'etw_event' => ['from the event', self::FACT],
            null, '' => ['from the process token', self::FACT],
            default => [$s, self::UNKNOWN],
        };
    }

    /** @return array{0: string, 1: string} app.match_source (AG-INV-15, agent status "match_source") */
    public static function matchSource(?string $s): array
    {
        return match ($s) {
            'msi_component' => ['confirmed (Windows Installer)', self::FACT],
            'sql_instance' => ['confirmed (SQL Server registry)', self::FACT],
            'confirmed' => ['confirmed', self::FACT],
            'display_icon' => ['likely (the app\'s icon is this program)', self::LIKELY],
            'install_location' => ['under the app\'s install folder', self::GUESS],
            'guessed' => ['not confirmed', self::GUESS],
            null, '' => ['no link', self::UNKNOWN],
            default => [$s, self::UNKNOWN],
        };
    }

    /** @return array{0: string, 1: string} executable hash_status, with D-056's reason */
    public static function hashStatus(string $s, ?string $deniedReason = null, ?string $privilege = null): array
    {
        if ($s === 'ok') {
            return ['hashed', self::FACT];
        }
        $text = match ($s) {
            'skipped_too_large' => 'not hashed: too large',
            'access_denied' => 'not hashed: access denied',
            'error' => 'not hashed: error',
            default => 'not hashed: ' . $s,
        };
        if ($deniedReason !== null && $deniedReason !== '') {
            $text .= ' (' . $deniedReason . ')';
        }
        if ($privilege !== null && $privilege !== '') {
            $text .= ', would need ' . $privilege;
        }
        return [$text, self::UNKNOWN];
    }

    /** @return array{0: string, 1: string} executable signature.status */
    public static function signature(?string $status, ?string $signer): array
    {
        $by = $signer !== null && $signer !== '' ? ' (' . $signer . ')' : '';
        return match ($status) {
            'valid' => ['signed by ' . ($signer ?? 'unknown signer'), self::FACT],
            'unsigned' => ['not signed', self::FACT],
            'expired', 'not_trusted', 'revoked', 'hash_mismatch', 'untrusted_root' => ['signature ' . str_replace('_', ' ', $status) . $by, self::FACT],
            null, '' => ['not checked', self::UNKNOWN],
            default => ['signature check: ' . str_replace('_', ' ', $status) . $by, self::UNKNOWN],
        };
    }

    /** inv_iis.read: ok means the sites shown are all there are (MS-UI-02). */
    public static function iisRead(?string $read): array
    {
        return match ($read) {
            'ok' => ['read', self::FACT],
            'not_installed' => ['IIS not installed', self::FACT],
            null, '' => ['no IIS record in the latest inventory', self::UNKNOWN],
            default => ['could not be read: ' . str_replace('_', ' ', $read) . '; sites may be missing', self::UNKNOWN],
        };
    }

    public static function bytes(int|float|null $n): string
    {
        if ($n === null) {
            return '-';
        }
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $n = (float) $n;
        while ($n >= 1024 && $i < count($u) - 1) {
            $n /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) (int) $n : number_format($n, $n < 10 ? 1 : 0)) . ' ' . $u[$i];
    }

    /**
     * Which product owns each listening port (MS-UI-02, server software tab).
     * In order: the agent's link on the listener (with its match_source);
     * an IIS site binding or SQL Server port in the latest inventory, which
     * is configuration, not proof the port is that product's (likely); the
     * listener's services; else only the process.
     *
     * @param list<array{proto: string, local_addr: string, local_port: int, process_name: ?string, pid: int, services: list<string>, app_name: ?string, app_match_source: ?string}> $listeners
     * @param list<array{name: string, bindings: list<array<string, mixed>>}> $sites
     * @param list<array{instance_name: string, tcp_ports: list<int>, tcp_dynamic_ports: list<int>}> $sql
     * @return list<array{proto: string, address: string, port: int, process: string, owner: string, how: string, certainty: string}>
     */
    public static function portOwners(array $listeners, array $sites, array $sql): array
    {
        $iis = [];
        foreach ($sites as $s) {
            foreach ($s['bindings'] as $b) {
                if (isset($b['port']) && is_int($b['port'])) {
                    $iis[$b['port']][] = 'IIS site "' . $s['name'] . '" (' . ($b['protocol'] ?? '?') . ')';
                }
            }
        }
        $sqlPorts = [];
        foreach ($sql as $i) {
            foreach ($i['tcp_ports'] as $p) {
                $sqlPorts[$p][] = 'SQL Server ' . $i['instance_name'];
            }
            foreach ($i['tcp_dynamic_ports'] as $p) {
                $sqlPorts[$p][] = 'SQL Server ' . $i['instance_name'] . ' (dynamic port)';
            }
        }
        $out = [];
        foreach ($listeners as $l) {
            $port = $l['local_port'];
            $proc = ($l['process_name'] ?? 'unknown process') . ' (PID ' . $l['pid'] . ')';
            if ($l['app_name'] !== null && $l['app_name'] !== '') {
                [$how, $c] = self::matchSource($l['app_match_source']);
                $row = [$l['app_name'], 'agent link: ' . $how, $c];
            } elseif ($l['proto'] === 'tcp' && isset($iis[$port])) {
                $row = [implode(', ', array_unique($iis[$port])), 'IIS configuration has this port', self::LIKELY];
            } elseif ($l['proto'] === 'tcp' && isset($sqlPorts[$port])) {
                $row = [implode(', ', array_unique($sqlPorts[$port])), 'SQL Server configuration has this port', self::LIKELY];
            } elseif ($l['services'] !== []) {
                $row = ['service ' . implode(', ', $l['services']), 'the process runs this service', self::FACT];
            } else {
                $row = [$l['process_name'] ?? 'unknown process', 'process only, no product link', self::UNKNOWN];
            }
            $out[] = ['proto' => $l['proto'], 'address' => $l['local_addr'], 'port' => $port, 'process' => $proc, 'owner' => $row[0], 'how' => $row[1], 'certainty' => $row[2]];
        }
        usort($out, static fn ($a, $b) => [$a['port'], $a['proto'], $a['address']] <=> [$b['port'], $b['proto'], $b['address']]);
        return $out;
    }
}
