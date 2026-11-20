<?php

/**
 * How long one disk sync takes on this VM (MS-ING-10).
 *
 *   php tools/fsync-test.php [dir] [count]     # default: your home directory, 200 syncs
 *
 * Every ingest COMMIT waits for one sync of the InnoDB redo log
 * (innodb_flush_log_at_trx_commit = 1), so this is the floor under every
 * acknowledgement. Writes 4 KB and syncs it, count times, in a temporary file
 * that is deleted afterwards. Run it on the same disk as /var/lib/mariadb.
 */

declare(strict_types=1);

$dir = $argv[1] ?? (getenv('HOME') ?: sys_get_temp_dir());
$n = max(10, (int) ($argv[2] ?? 200));
$path = rtrim($dir, '/') . '/aio-fsync-test.' . getmypid();
$fh = fopen($path, 'c+');
if ($fh === false) {
    fwrite(STDERR, "can't create $path\n");
    exit(1);
}
$block = str_repeat("\0", 4096);
$ms = [];
try {
    for ($i = 0; $i < $n; $i++) {
        fseek($fh, ($i % 256) * 4096);
        fwrite($fh, $block);
        $t = hrtime(true);
        fsync($fh);
        $ms[] = (hrtime(true) - $t) / 1e6;
    }
} finally {
    fclose($fh);
    unlink($path);
}
sort($ms);
$p = static fn (float $q): float => round($ms[min($n - 1, (int) floor($q * $n))], 2);
printf("fsync of 4 KB, %d times in %s: p50 %.2f ms, p95 %.2f ms, p99 %.2f ms, max %.2f ms\n", $n, $dir, $p(0.5), $p(0.95), $p(0.99), round(end($ms), 2));
