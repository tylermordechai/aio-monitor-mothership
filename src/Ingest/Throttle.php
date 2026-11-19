<?php

declare(strict_types=1);

namespace Aio\Ingest;

use Aio\Config;
use Aio\Http\ApiError;

/**
 * Back-pressure before any work is done (ingest-api.md §8.2, MS-ING-09, M-009).
 *
 * - Capacity: N ingest slots, each a lock file under state_dir. A request that
 *   finds every slot taken gets 429 slow_down. The lock is released when the
 *   PHP worker ends the request (or dies), so a crash never leaks a slot.
 * - Per agent: more than agent_max_rps requests per second over the window
 *   gets 429 too.
 * - Disk: at disk_full_percent or more, 503 storage_full (MS-OPS-08).
 *
 * No extension or service is needed; the state directory is a tmpfs under
 * /run made by setup/09-app.sh.
 */
final class Throttle
{
    /** @var resource|null */
    private $slot = null;

    public function __construct(private readonly Config $config)
    {
    }

    /** @throws ApiError */
    public function checkDisk(): void
    {
        $path = $this->config->string('ingest', 'disk_path');
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total === false || $free === false || $total <= 0) {
            return;
        }
        $used = 100 * (1 - $free / $total);
        if ($used >= $this->config->int('ingest', 'disk_full_percent')) {
            throw ApiError::storageFull();
        }
    }

    /** @throws ApiError */
    public function acquireSlot(): void
    {
        $dir = $this->config->string('ingest', 'state_dir');
        if (!is_dir($dir) || !is_writable($dir)) {
            return; // not set up (tests, or before 09): no capacity limit
        }
        $n = max(1, $this->config->int('ingest', 'max_concurrent'));
        $start = random_int(0, $n - 1);
        for ($k = 0; $k < $n; $k++) {
            $i = ($start + $k) % $n;
            $h = @fopen("$dir/slot-$i.lock", 'c');
            if ($h === false) {
                continue;
            }
            if (flock($h, LOCK_EX | LOCK_NB)) {
                $this->slot = $h;
                return;
            }
            fclose($h);
        }
        throw ApiError::slowDown($this->retryAfter());
    }

    /** @throws ApiError */
    public function checkAgentRate(int $agentPk, ?float $now = null): void
    {
        $dir = $this->config->string('ingest', 'state_dir');
        if (!is_dir($dir) || !is_writable($dir)) {
            return;
        }
        $now ??= microtime(true);
        $window = max(1, $this->config->int('ingest', 'agent_rate_window_s'));
        $limit = $this->config->int('ingest', 'agent_max_rps') * $window;
        $h = @fopen("$dir/rate-$agentPk", 'c+');
        if ($h === false) {
            return;
        }
        try {
            if (!flock($h, LOCK_EX)) {
                return;
            }
            $times = [];
            foreach (explode("\n", (string) stream_get_contents($h)) as $t) {
                if ($t !== '' && (float) $t > $now - $window) {
                    $times[] = (float) $t;
                }
            }
            $over = count($times) >= $limit;
            if (!$over) {
                $times[] = $now;
            }
            ftruncate($h, 0);
            rewind($h);
            fwrite($h, implode("\n", array_map(static fn (float $t): string => sprintf('%.3f', $t), $times)));
            if ($over) {
                throw ApiError::slowDown($this->retryAfter());
            }
        } finally {
            fclose($h);
        }
    }

    public function release(): void
    {
        if ($this->slot !== null) {
            flock($this->slot, LOCK_UN);
            fclose($this->slot);
            $this->slot = null;
        }
    }

    private function retryAfter(): int
    {
        $min = $this->config->int('ingest', 'retry_after_min_s');
        $max = max($min, $this->config->int('ingest', 'retry_after_max_s'));
        // Spread the retries so a fleet doesn't come back at once.
        return random_int($min, min($max, $min * 3));
    }
}
