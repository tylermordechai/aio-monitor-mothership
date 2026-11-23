<?php
/**
 * One command line, shown to an analyst or admin after its audit row was
 * written (MS-SEC-07..09, CommandLineReveal). The agent's redaction flag is
 * shown beside it. The page is not cached (Cache-Control: no-store).
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
$res = $v['res'];
$p = $res['process'];
?>
<p class="crumbs small"><a href="/">Server map</a> › <a href="/hosts/<?= $e($v['host_pk']) ?>?tab=processes"><?= $e($p['hostname']) ?>, processes</a> › command line</p>
<section class="panel">
  <h1>Command line</h1>
  <p><?= $e($p['process_name'] ?? 'unknown') ?> <span class="muted">(PID <?= $e($p['pid']) ?>, started <?= $e(substr((string) ($p['start_time'] ?? $p['ts']), 0, 19)) ?> UTC<?= $p['user'] ? $e(', user ' . $p['user']) : '' ?>)</span></p>
  <?php if ($p['exe']) : ?><p class="muted small path"><?= $e($p['exe']) ?></p><?php endif; ?>
  <?php if ($res['status'] === 'shown') : ?>
  <pre class="cmdline"><?= $e($res['command_line']) ?></pre>
  <p class="small">
    <?php if ($p['command_line_redacted']) : ?><span class="cert c-likely">Redacted by the agent: values that looked like secrets were replaced.</span>
    <?php elseif ($p['command_line_redacted'] === null) : ?><span class="cert c-unknown">The agent did not say whether it redacted this.</span>
    <?php else : ?><span class="cert c-fact">Not redacted by the agent.</span><?php endif; ?>
    <?php if ($p['command_line_truncated']) : ?> <span class="cert c-unknown">Cut by the agent at <?= $e($p['command_line_length'] ?? '?') ?> characters.</span><?php endif; ?>
  </p>
  <?php else : ?>
  <p class="cert c-unknown">This command line has been cleared (command lines are kept 30 days).</p>
  <?php endif; ?>
  <p class="muted small">This view was recorded in the audit log under your name (MS-SEC-08).</p>
  <p><a href="/hosts/<?= $e($v['host_pk']) ?>?tab=processes">Back to the processes</a></p>
</section>
