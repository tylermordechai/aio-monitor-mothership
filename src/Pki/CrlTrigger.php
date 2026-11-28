<?php

declare(strict_types=1);

namespace Aio\Pki;

use Aio\Config;

/**
 * The agent CA's CRL, seen from outside the root job that builds it (M-071,
 * MS-SEC-02, ingest-api.md §2.4 step 3).
 *
 * - request(): asks for a rebuild by writing the time into the request file;
 *   systemd's aio-crl.path starts aio-crl.service when it changes. The
 *   dashboard pool may write that one file and nothing else in its directory
 *   (setup/11-enrolment.sh). bin/agent.php does the same as root.
 * - status(): what the last build left in its state file (public, no secret):
 *   when it was built, until when it is valid, how many serials it lists, and
 *   the last failure. "warn" from 7 days before it expires, and as soon as a
 *   rebuild has failed (M-068).
 */
final class CrlTrigger
{
    public function __construct(
        private readonly string $requestFile,
        private readonly string $stateFile,
        private readonly int $warnDays = 7,
    ) {
    }

    public static function fromConfig(Config $c): self
    {
        return new self($c->string('crl', 'request_file'), $c->string('crl', 'state_file'), $c->int('crl', 'warn_days'));
    }

    /** Throws \RuntimeException when the request can't be written. */
    public function request(?float $now = null): void
    {
        if (!is_file($this->requestFile) || !is_writable($this->requestFile)) {
            throw new \RuntimeException('the CRL request file is missing or not writable');
        }
        if (@file_put_contents($this->requestFile, sprintf("%.6f\n", $now ?? microtime(true)), LOCK_EX) === false) {
            throw new \RuntimeException('could not write the CRL request file');
        }
    }

    /**
     * @return array{state: string, message: string, built_at: ?string, next_update: ?string, entries: ?int, last_error: ?string, error_at: ?string}
     *   state: ok, warn (expires within warn_days, or the last rebuild failed), expired, never
     */
    public function status(int $now): array
    {
        $s = is_readable($this->stateFile) ? json_decode((string) @file_get_contents($this->stateFile), true) : null;
        $s = is_array($s) ? $s : [];
        $built = is_string($s['built_at'] ?? null) ? $s['built_at'] : null;
        $next = is_string($s['next_update'] ?? null) ? $s['next_update'] : null;
        $error = is_string($s['last_error'] ?? null) ? $s['last_error'] : null;
        $errorAt = is_string($s['error_at'] ?? null) ? $s['error_at'] : null;
        $out = [
            'built_at' => $built,
            'next_update' => $next,
            'entries' => isset($s['entries']) && is_int($s['entries']) ? $s['entries'] : null,
            'last_error' => $error,
            'error_at' => $errorAt,
        ];
        return self::judge($out, $now, $this->warnDays);
    }

    /**
     * @param array{built_at: ?string, next_update: ?string, entries: ?int, last_error: ?string, error_at: ?string} $s
     * @return array{state: string, message: string, built_at: ?string, next_update: ?string, entries: ?int, last_error: ?string, error_at: ?string}
     */
    public static function judge(array $s, int $now, int $warnDays): array
    {
        $nextTs = $s['next_update'] === null ? false : strtotime($s['next_update'] . ' UTC');
        // A failure counts until a build after it succeeds.
        $failed = $s['last_error'] !== null
            && ($s['built_at'] === null || ($s['error_at'] !== null && strcmp($s['error_at'], $s['built_at']) > 0));
        if ($s['built_at'] === null || $nextTs === false) {
            [$state, $msg] = ['never', 'The revocation list has not been built yet.' . ($failed ? ' The last try failed: ' . $s['last_error'] . '.' : '')];
        } elseif ($nextTs <= $now) {
            [$state, $msg] = ['expired', 'The revocation list has EXPIRED: Apache refuses every agent certificate until it is rebuilt (sudo systemctl start aio-crl.service).'];
        } elseif ($failed) {
            [$state, $msg] = ['warn', 'The last rebuild of the revocation list failed (' . $s['last_error'] . '); the list from ' . $s['built_at'] . ' UTC is still in use, valid until ' . $s['next_update'] . ' UTC.'];
        } elseif ($nextTs - $now <= $warnDays * 86_400) {
            [$state, $msg] = ['warn', 'The revocation list expires on ' . $s['next_update'] . ' UTC; after that Apache refuses every agent. The daily rebuild has not renewed it.'];
        } else {
            [$state, $msg] = ['ok', 'Built ' . $s['built_at'] . ' UTC, valid until ' . $s['next_update'] . ' UTC.'];
        }
        return ['state' => $state, 'message' => $msg] + $s;
    }
}
