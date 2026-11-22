<?php

/**
 * Connections per hour for the dashboard (M-054): runs from the systemd timer
 * aio-flow-rollup.timer every minute, as root (to read maint.cnf), database
 * user aio_maint. Catches up for up to 50 s, then stops; the next run goes on.
 *
 *   sudo php tools/jobs/flow-rollup.php            # what the timer runs
 *   sudo php tools/jobs/flow-rollup.php --all      # until caught up, however long (after a --days load)
 *
 * Prints one line per pass: batches, hours rebuilt, connection rows.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;
use Aio\Jobs\FlowRollup;

$all = in_array('--all', $argv, true);
$config = Config::load();
$pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
// One run at a time: the timer and a manual --all must not rebuild the same hour together.
if ((int) $pdo->query("SELECT GET_LOCK('aio_flow_rollup', 0)")->fetchColumn() !== 1) {
    echo "another flow-rollup run is busy; nothing done\n";
    exit(0);
}
$job = new FlowRollup($pdo);
$t0 = microtime(true);
$total = ['batches' => 0, 'hours' => 0, 'rows' => 0];
do {
    $r = $job->runOnce();
    foreach ($total as $k => $_) {
        $total[$k] += $r[$k];
    }
    if ($r['batches'] > 0) {
        printf("batches %d..%d: %d batches, %d hours, %d connection rows (%.1f s)\n", $r['from'] + 1, $r['to'], $r['batches'], $r['hours'], $r['rows'], microtime(true) - $t0);
    }
} while ($r['batches'] > 0 && ($all || microtime(true) - $t0 < 50));
printf("done: %d batches, %d hours, %d connection rows in %.1f s; up to batch %d\n", $total['batches'], $total['hours'], $total['rows'], microtime(true) - $t0, $job->position());
