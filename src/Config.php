<?php

declare(strict_types=1);

namespace Aio;

/**
 * Application settings: built-in defaults, overridden by /etc/aio-mothership/app.ini.
 *
 * The defaults are the values agreed in docs/ingest-api.md. Values the plan
 * still marks *(confirm)* stay defaults here and are listed in
 * pending-decisions.md, never picked silently (CLAUDE.md).
 * No secret is kept here; database passwords live in the db/*.cnf files.
 */
final class Config
{
    public const DEFAULT_PATH = '/etc/aio-mothership/app.ini';

    private const DEFAULTS = [
        'db' => [
            'ingest_cnf' => '/etc/aio-mothership/db/ingest.cnf',
            'maint_cnf' => '/etc/aio-mothership/db/maint.cnf',
            'dashboard_cnf' => '/etc/aio-mothership/db/dashboard.cnf',
            // M-053: the only user that can read process_command_lines.
            'analyst_cnf' => '/etc/aio-mothership/db/analyst.cnf',
            'name' => 'aio',
        ],
        'ingest' => [
            // §3.1 limits (M-017).
            'max_compressed_bytes' => 10_485_760,
            'max_raw_bytes' => 67_108_864,
            'max_lines' => 50_000,
            'max_line_bytes' => 1_048_576,
            'quarantine_keep_bytes' => 65_536,
            // §3.4 rule 8 (M-007).
            'max_future_s' => 86_400,
            // §9: record types quarantined as "provisional type, no table yet".
            'provisional_record_types' => [],
            // §3.6, M-030.
            'seq_regression_lines' => 1000,
            // §8.2 (M-009): capacity and per-agent rate before 429.
            'max_concurrent' => 12,
            'agent_max_rps' => 5,
            'agent_rate_window_s' => 10,
            'retry_after_min_s' => 5,
            'retry_after_max_s' => 60,
            'state_dir' => '/run/aio-ingest',
            // MS-OPS-08.
            'disk_path' => '/var/lib/mariadb',
            'disk_full_percent' => 90,
            'rows_per_insert' => 500,
            // Log the phase timings of an ingest request that takes this long (0: off).
            'slow_log_ms' => 0,
        ],
        // Milestone 5 (M-054). Values marked (confirm) are the owner's accepted
        // defaults (2026-11-22), kept here so they can change without code.
        'dashboard' => [
            'password_min_length' => 12,     // (confirm) MS-SEC-06
            'lockout_failures' => 5,         // (confirm) MS-SEC-06
            'lockout_minutes' => 15,         // (confirm) MS-SEC-06
            'session_idle_s' => 1800,        // (confirm) 30 min
            'session_max_s' => 43_200,       // (confirm) 12 h
            'stale_after_s' => 300,          // (confirm) MS-ALR-05: no check-in for 5 minutes
            'map_internal_nodes' => 15,      // busiest unmonitored internal addresses shown on their own
            'host_rows' => 500,              // rows per host page list; a cut list says so (M-056)
            // Address ranges counted as internal; the rest is "internet".
            'internal_networks' => '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,100.64.0.0/10,169.254.0.0/16,fc00::/7,fe80::/10',
            'slow_log_ms' => 0,              // log pages slower than this (0: off)
        ],
        'heartbeat' => [
            'interval_s' => 60,
            'max_bytes' => 65_536,
        ],
    ];

    /** @param array<string, array<string, mixed>> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function load(string $path = self::DEFAULT_PATH): self
    {
        $ini = [];
        if (is_readable($path)) {
            $parsed = parse_ini_file($path, true, INI_SCANNER_TYPED);
            if ($parsed === false) {
                throw new \RuntimeException("can't parse $path");
            }
            $ini = $parsed;
        }
        return self::fromArray($ini);
    }

    /** @param array<string, array<string, mixed>> $overrides */
    public static function fromArray(array $overrides): self
    {
        $v = self::DEFAULTS;
        foreach ($overrides as $section => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $k => $val) {
                $v[$section][$k] = $val;
            }
        }
        // A list from the ini file: provisional_record_types[] = name, or a comma list.
        $p = $v['ingest']['provisional_record_types'];
        if (is_string($p)) {
            $p = array_values(array_filter(array_map('trim', explode(',', $p)), static fn ($s) => $s !== ''));
        }
        $v['ingest']['provisional_record_types'] = array_values((array) $p);
        return new self($v);
    }

    public function get(string $section, string $key): mixed
    {
        if (!array_key_exists($section, $this->values) || !array_key_exists($key, $this->values[$section])) {
            throw new \OutOfBoundsException("no setting $section.$key");
        }
        return $this->values[$section][$key];
    }

    public function int(string $section, string $key): int
    {
        return (int) $this->get($section, $key);
    }

    public function string(string $section, string $key): string
    {
        return (string) $this->get($section, $key);
    }

    /** @return list<string> */
    public function list(string $section, string $key): array
    {
        return array_values(array_map('strval', (array) $this->get($section, $key)));
    }
}
