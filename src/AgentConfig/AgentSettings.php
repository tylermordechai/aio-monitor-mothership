<?php

declare(strict_types=1);

namespace Aio\AgentConfig;

use Aio\Util\Ip;

/**
 * The settings served by GET /api/v1/config (ingest-api.md §5; MS-ING-07,
 * AG-CFG-01..03, M-008, M-065): the built-in defaults, with a host group's
 * stored overrides on top. Every value is checked again when served, so a bad
 * row in agent_config can't reach an agent:
 *
 * - numbers against the agent's own bounds (agent PD-62, D-087); a value out
 *   of bounds, of the wrong type or unknown keeps the default;
 * - the detection list against §5.1: only service-name patterns, HKLM keys
 *   under SOFTWARE or SYSTEM, and registry value names, never a path, command
 *   or URL (CLAUDE.md hard rule). A failing entry is left out;
 * - exclusions and redaction rules against their formats; redaction is
 *   additive only, so nothing here can turn the agent's own redaction off.
 *
 * Everything left out is reported in `rejected` by place and reason, never by
 * value (MS-SEC-10), for the log.
 */
final class AgentSettings
{
    /** Defaults and bounds, [default, min, max] (agent 5c plan §5, PD-62). */
    public const NUMBERS = [
        'intervals' => [
            'snapshot_s' => [300, 60, 3_600],
            'flow_window_s' => [10, 5, 60],
            'inventory_full_h' => [24, 1, 168],
            'inventory_rescan_min' => [15, 5, 1_440],
            'heartbeat_s' => [60, 30, 600],
            'config_poll_min' => [15, 5, 1_440],
        ],
        'transport' => [
            'batch_max_bytes' => [1_048_576, 65_536, 8_388_608],
            'batch_max_s' => [10, 1, 60],
            'queue_cap_bytes' => [524_288_000, 52_428_800, 4_294_967_296],
        ],
        'caps' => [
            'command_line_max_bytes' => [8_192, 1, 32_768],
            'hash_max_file_bytes' => [536_870_912, 0, 2_147_483_648],
            'hash_read_bytes_per_s' => [33_554_432, 1, 268_435_456],
            'flow_table_max' => [32_768, 1_024, 262_144],
            'iis_config_max_bytes' => [16_777_216, 1, 67_108_864],
        ],
    ];
    public const COLLECTORS = [
        'etw' => true,
        'tcp_bytes' => false,
        'include_loopback' => false,
        'processes' => true,
        'inventory' => true,
        'server_software' => true,
    ];
    public const MAX_DETECTION_ENTRIES = 100;
    public const MAX_DETECTION_BYTES = 65_536;

    private const SERVICE = '/^[A-Za-z0-9_.$@#+ -]{1,255}\*?$/D';
    private const KEY_PART = '/^[^\\\\\/:*?"<>|%\x00-\x1f\x7f]{1,255}$/D';
    private const VALUE_NAME = '/^[^\\\\\/:%\x00-\x1f\x7f]{1,255}$/D';
    private const PRODUCT = '/^[^\x00-\x1f\x7f]{1,100}$/uD';
    private const NOTE = '/^[^\x00-\x1f\x7f]{0,200}$/uD';
    private const REDACT_NAME = '/^[A-Za-z0-9_.-]{1,64}$/D';
    private const DETECT_FIELDS = ['product', 'services', 'registry_keys', 'version_values', 'service_value', 'install_value'];

    /**
     * @param array<string, mixed>|null $row the agent_config row (JSON columns as strings), null for none
     * @param int $maxExclusions (confirm) MPD-44
     * @param int $maxRedactionRules (confirm) MPD-44
     * @return array{config: array<string, mixed>, version: int, rejected: list<string>}
     */
    public static function build(?array $row, string $groupName, int $maxExclusions = 500, int $maxRedactionRules = 100): array
    {
        $rejected = [];
        $settings = self::json($row['settings'] ?? null, 'settings', $rejected, true);
        $config = [
            'config_version' => (int) ($row['config_version'] ?? 0),
            'host_group' => $groupName,
        ];
        foreach (self::NUMBERS as $section => $items) {
            $over = is_array($settings[$section] ?? null) ? $settings[$section] : [];
            foreach ($over as $k => $_) {
                if (!isset($items[$k])) {
                    $rejected[] = "settings.$section.$k: unknown";
                }
            }
            foreach ($items as $k => [$default, $min, $max]) {
                $v = $over[$k] ?? null;
                if ($v === null) {
                    $config[$section][$k] = $default;
                } elseif (is_int($v) && $v >= $min && $v <= $max) {
                    $config[$section][$k] = $v;
                } else {
                    $config[$section][$k] = $default;
                    $rejected[] = "settings.$section.$k: not an integer from $min to $max";
                }
            }
        }
        $over = is_array($settings['collectors'] ?? null) ? $settings['collectors'] : [];
        foreach (self::COLLECTORS as $k => $default) {
            $v = $over[$k] ?? null;
            if ($v !== null && !is_bool($v)) {
                $rejected[] = "settings.collectors.$k: not a boolean";
                $v = null;
            }
            $config['collectors'][$k] = $v ?? $default;
        }
        foreach (array_diff(array_keys($over), array_keys(self::COLLECTORS)) as $k) {
            $rejected[] = "settings.collectors.$k: unknown";
        }
        foreach (array_diff(array_keys($settings), ['intervals', 'transport', 'collectors', 'caps']) as $k) {
            $rejected[] = "settings.$k: unknown";
        }

        $config['exclusions'] = self::exclusions(self::json($row['exclusions'] ?? null, 'exclusions', $rejected, false), $maxExclusions, $rejected);
        $config['redaction'] = ['extra_rules' => self::redaction(self::json($row['redaction_rules'] ?? null, 'redaction_rules', $rejected, false), $maxRedactionRules, $rejected)];
        $config['server_software'] = [
            'replace_builtin' => (bool) ($row['replace_builtin_detection'] ?? false),
            'detection_list' => self::detectionList(self::json($row['detection_list'] ?? null, 'detection_list', $rejected, false), $rejected),
        ];
        return ['config' => $config, 'version' => $config['config_version'], 'rejected' => $rejected];
    }

    /** The ETag: the group, the version and a hash of what is served, so a change of defaults changes it too. */
    public static function etag(int $groupId, array $config): string
    {
        $h = substr(hash('sha256', (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 12);
        return '"cfg-' . $groupId . '-' . (int) ($config['config_version'] ?? 0) . '-' . $h . '"';
    }

    /**
     * §5.1, checked entry by entry. Returns the entries that pass.
     *
     * @param list<string> $rejected
     * @return list<array<string, mixed>>
     */
    public static function detectionList(array $list, array &$rejected): array
    {
        $out = [];
        $bytes = 2;
        foreach (array_values($list) as $i => $e) {
            $why = self::detectionEntryProblem($e);
            if ($why !== null) {
                $name = is_array($e) && is_string($e['product'] ?? null) && preg_match(self::PRODUCT, $e['product']) ? " ({$e['product']})" : '';
                $rejected[] = "detection_list[$i]$name: $why";
                continue;
            }
            $size = strlen((string) json_encode($e, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) + 1;
            if (count($out) >= self::MAX_DETECTION_ENTRIES || $bytes + $size > self::MAX_DETECTION_BYTES) {
                $rejected[] = "detection_list[$i] ({$e['product']}): over " . self::MAX_DETECTION_ENTRIES . ' entries or ' . self::MAX_DETECTION_BYTES . ' bytes';
                continue;
            }
            $bytes += $size;
            $out[] = $e;
        }
        return $out;
    }

    /** Why a detection entry is refused (§5.1), or null. */
    public static function detectionEntryProblem(mixed $e): ?string
    {
        if (!is_array($e) || array_is_list($e) && $e !== []) {
            return 'not an object';
        }
        foreach (array_keys($e) as $k) {
            if (!in_array($k, self::DETECT_FIELDS, true)) {
                return "unknown field $k";
            }
        }
        if (!is_string($e['product'] ?? null) || !preg_match(self::PRODUCT, $e['product'])) {
            return 'product must be 1 to 100 characters without control characters';
        }
        $services = $e['services'] ?? [];
        $keys = $e['registry_keys'] ?? [];
        $values = $e['version_values'] ?? [];
        foreach (['services' => $services, 'registry_keys' => $keys, 'version_values' => $values] as $f => $v) {
            if (!is_array($v) || !array_is_list($v)) {
                return "$f must be a list";
            }
            foreach ($v as $s) {
                if (!is_string($s)) {
                    return "$f must hold strings";
                }
            }
        }
        if ($services === [] && $keys === []) {
            return 'needs services or registry_keys';
        }
        foreach ($services as $s) {
            if (!preg_match(self::SERVICE, $s)) {
                return 'services: a service name or a prefix ending in *';
            }
        }
        foreach ($keys as $k) {
            $why = self::registryKeyProblem($k);
            if ($why !== null) {
                return "registry_keys: $why";
            }
        }
        foreach ($values as $v) {
            if (!preg_match(self::VALUE_NAME, $v)) {
                return 'version_values: a registry value name';
            }
        }
        foreach (['service_value', 'install_value'] as $f) {
            if (array_key_exists($f, $e) && (!is_string($e[$f]) || !preg_match(self::VALUE_NAME, $e[$f]))) {
                return "$f: a registry value name";
            }
        }
        return null;
    }

    /** §5.1: relative to HKLM, under SOFTWARE or SYSTEM, at most 12 components. */
    public static function registryKeyProblem(string $key): ?string
    {
        $parts = explode('\\', $key);
        if (count($parts) > 12) {
            return 'more than 12 components';
        }
        foreach ($parts as $p) {
            if ($p === '') {
                return 'an empty component or a leading \\';
            }
            if ($p === '..' || $p === '.') {
                return '. or .. is not allowed';
            }
            if ($p !== '*' && !preg_match(self::KEY_PART, $p)) {
                return 'a component must be * or a name without \\ / : * ? " < > | %';
            }
        }
        if (!in_array(strtoupper($parts[0]), ['SOFTWARE', 'SYSTEM'], true)) {
            return 'must start with SOFTWARE or SYSTEM (no hive name)';
        }
        return null;
    }

    /**
     * AG-CFG-03 with the agent's matching rules (agent PD-64).
     *
     * @param list<string> $rejected
     * @return list<array<string, string>>
     */
    public static function exclusions(array $list, int $max, array &$rejected): array
    {
        $out = [];
        foreach (array_values($list) as $i => $e) {
            $why = self::exclusionProblem($e);
            if ($why === null && count($out) >= $max) {
                $why = "over $max rules";
            }
            if ($why !== null) {
                $rejected[] = "exclusions[$i]: $why";
                continue;
            }
            $x = ['type' => $e['type'], 'value' => $e['value']];
            if (isset($e['note']) && $e['note'] !== '') {
                $x['note'] = $e['note'];
            }
            $out[] = $x;
        }
        return $out;
    }

    public static function exclusionProblem(mixed $e): ?string
    {
        if (!is_array($e) || array_diff(array_keys($e), ['type', 'value', 'note']) !== []) {
            return 'not an object of type, value and note';
        }
        $v = $e['value'] ?? null;
        if (!is_string($v) || isset($e['note']) && (!is_string($e['note']) || !preg_match(self::NOTE, $e['note']))) {
            return 'value must be a string, note up to 200 characters';
        }
        return match ($e['type'] ?? null) {
            // An exact full path, or a folder prefix ending in \*.
            'process_path' => preg_match('/^[A-Za-z]:\\\\[^\x00-\x1f\x7f"<>|?*]{0,1020}(\\\\\*)?$/D', $v) && !str_contains($v, '\\..\\') ? null : 'process_path: a full path (C:\\...), or a folder ending in \\*',
            'remote_cidr' => self::cidrOk($v) ? null : 'remote_cidr: an IPv4 or IPv6 CIDR',
            'port' => self::portOk($v) ? null : 'port: a port or a range, 1 to 65535',
            default => 'type must be process_path, remote_cidr or port',
        };
    }

    /**
     * Additive redaction rules (D-030, D-036).
     *
     * @param list<string> $rejected
     * @return list<array{form: string, name: string}>
     */
    public static function redaction(array $list, int $max, array &$rejected): array
    {
        $out = [];
        foreach (array_values($list) as $i => $r) {
            $ok = is_array($r) && array_diff(array_keys($r), ['form', 'name']) === []
                && in_array($r['form'] ?? null, ['next_arg', 'equals', 'conn_string'], true)
                && is_string($r['name'] ?? null) && preg_match(self::REDACT_NAME, $r['name']);
            if (!$ok || count($out) >= $max) {
                $rejected[] = "redaction_rules[$i]: " . ($ok ? "over $max rules" : 'form next_arg, equals or conn_string and a name of 1 to 64 of A-Z a-z 0-9 _ . -');
                continue;
            }
            $out[] = ['form' => $r['form'], 'name' => $r['name']];
        }
        return $out;
    }

    private static function cidrOk(string $v): bool
    {
        if (!preg_match('#^([0-9A-Fa-f:.]+)/(\d{1,3})$#D', $v, $m)) {
            return false;
        }
        $bin = Ip::toBinary($m[1]);
        return $bin !== null && (int) $m[2] <= strlen($bin) * 8;
    }

    private static function portOk(string $v): bool
    {
        if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/D', $v, $m)) {
            return false;
        }
        $lo = (int) $m[1];
        $hi = isset($m[2]) ? (int) $m[2] : $lo;
        return $lo >= 1 && $hi <= 65_535 && $lo <= $hi;
    }

    /**
     * @param list<string> $rejected
     * @return array<mixed>
     */
    private static function json(mixed $raw, string $what, array &$rejected, bool $object): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $v = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($v) || ($object ? ($v !== [] && array_is_list($v)) : !array_is_list($v))) {
            $rejected[] = "$what: not " . ($object ? 'an object' : 'a list');
            return [];
        }
        return $v;
    }
}
