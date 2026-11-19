<?php

/**
 * "0 lost" check for the milestone 2 gate (MS-ING-04, M-043).
 *
 *   sudo php tools/reconcile.php
 *
 * For every simulated agent, compares the rows the simulator expects (from
 * the lines it got a 200 for, kept in sim/state/) with the rows in the
 * database, table by table. Fewer rows than expected is a LOSS; more is
 * reported separately (for example a batch stored whose answer never came
 * back, which the agent would send again as duplicates).
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Aio\Config;
use Aio\Db;

const SIM_DIR = '/var/lib/aio-mothership/sim';

$config = Config::load();
$pdo = Db::connect($config->string('db', 'maint_cnf'), $config->string('db', 'name'));
$ids = json_decode((string) @file_get_contents(SIM_DIR . '/agents.json'), true) ?: [];

$expect = [];   // agent_id => table => rows
foreach ($ids as $id) {
    $p = SIM_DIR . "/state/{$id['agent_id']}.json";
    if (is_file($p)) {
        $s = json_decode((string) file_get_contents($p), true);
        $expect[$id['agent_id']] = $s['expect_rows'] ?? [];
    }
}
if ($expect === []) {
    fwrite(STDERR, "no simulator state yet; run tools/simulator/simulate.php first\n");
    exit(2);
}
$pk = [];
$in = implode(',', array_fill(0, count($expect), '?'));
$s = $pdo->prepare("SELECT agent_id, id FROM agents WHERE agent_id IN ($in)");
$s->execute(array_keys($expect));
foreach ($s->fetchAll() as $r) {
    $pk[(int) $r['id']] = $r['agent_id'];
}
$tables = [];
foreach ($expect as $rows) {
    $tables += array_fill_keys(array_keys($rows), true);
}
$found = [];
$pkIn = implode(',', array_keys($pk));
foreach (array_keys($tables) as $t) {
    foreach ($pdo->query("SELECT agent_pk, COUNT(*) AS n FROM `$t` WHERE agent_pk IN ($pkIn) GROUP BY agent_pk") as $r) {
        $found[$pk[(int) $r['agent_pk']]][$t] = (int) $r['n'];
    }
}

$tot = [];
$lost = 0;
$extra = 0;
$badAgents = [];
foreach ($expect as $aid => $rows) {
    foreach ($rows as $t => $n) {
        $f = $found[$aid][$t] ?? 0;
        $tot[$t]['expected'] = ($tot[$t]['expected'] ?? 0) + $n;
        $tot[$t]['found'] = ($tot[$t]['found'] ?? 0) + $f;
        if ($f < $n) {
            $lost += $n - $f;
            $badAgents[$aid] = true;
        } elseif ($f > $n) {
            $extra += $f - $n;
        }
    }
}
ksort($tot);
printf("%-24s %12s %12s %8s\n", 'table', 'expected', 'found', 'diff');
foreach ($tot as $t => $v) {
    printf("%-24s %12d %12d %8d\n", $t, $v['expected'], $v['found'], $v['found'] - $v['expected']);
}
printf("\nagents checked: %d\nlines lost: %d\nextra rows: %d%s\n", count($expect), $lost, $extra, $extra > 0 ? ' (stored, but the answer never reached the simulator; not a loss)' : '');
if ($badAgents !== []) {
    echo 'agents with losses: ' . implode(', ', array_slice(array_keys($badAgents), 0, 10)) . "\n";
}
echo $lost === 0 ? "RESULT: 0 LOST\n" : "RESULT: LOST $lost\n";
exit($lost === 0 ? 0 : 1);
