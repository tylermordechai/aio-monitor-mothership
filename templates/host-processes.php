<?php
/**
 * Host page, processes tab (MS-UI-02, MS-SEC-07..09, M-056). Processes started
 * in the range, newest first. The command line is never on this page: an
 * analyst or admin opens one with "Show", a POST that is audited
 * (CommandLineReveal); everyone sees whether one is stored and the agent's
 * redaction flag (MS-SEC-09).
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
$p = $v['processes'];
$cert = static fn (array $l): string => '<span class="cert c-' . $e($l[1]) . '">' . $e($l[0]) . '</span>';
$unavail = ['process_exited' => 'not read: the process had exited', 'access_denied' => 'not read: access denied', 'none' => 'no command line', 'error' => 'not read: error'];
?>
<section class="panel">
  <h2>Processes</h2>
  <p class="muted small">
    <?= $e($p['total']) ?> process starts in the range<?= $p['total'] > count($p['rows']) ? $e(', newest ' . count($p['rows']) . ' shown') : '' ?>.
    <?php if ($v['seesCommandLines']) : ?>Command lines: "Show" opens one; every view is recorded in the audit log under your name (MS-SEC-08).
    <?php else : ?>Command lines are shown to analysts and admins only.<?php endif; ?>
  </p>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Started (UTC)</th><th>Process</th><th>Parent</th><th>User</th><th>Command line</th><th>Exited</th></tr></thead>
    <tbody>
    <?php foreach ($p['rows'] as $r) : ?>
      <tr>
        <td class="nowrap"><?= $e($r['start_time'] ?? substr($r['ts'], 0, 19)) ?><?= $r['kind'] !== 'started' ? '<br><span class="muted small">' . $e($r['kind']) . '</span>' : '' ?></td>
        <td><?= $e($r['name'] ?? 'unknown') ?> <span class="muted">(<?= $e($r['pid']) ?>)</span>
          <?php if ($r['exe']) : ?><br><span class="muted small path"><?= $e($r['exe']) ?></span><?php endif; ?>
          <?php if ($r['sha256']) : ?><br><span class="muted small mono" title="SHA-256"><?= $e(substr($r['sha256'], 0, 16)) ?>…</span><?php elseif ($r['exe_error']) : ?><br><span class="cert c-unknown">not hashed: <?= $e($r['exe_error']) ?></span><?php endif; ?>
        </td>
        <td><?= $e($r['parent_pid'] ?? '-') ?></td>
        <td><?= $e($r['user'] ?? '-') ?><?php if ($r['user']) : ?><br><?= $cert($r['user_source']) ?><?php endif; ?></td>
        <td>
          <?php if ($r['has_command_line']) : ?>
            <?php if ($v['seesCommandLines']) : ?>
            <form method="post" action="/hosts/<?= $e($v['host']['host_pk']) ?>/command-line" class="inline">
              <input type="hidden" name="_csrf" value="<?= $e($v['csrf']) ?>">
              <input type="hidden" name="agent_pk" value="<?= $e($r['agent_pk']) ?>">
              <input type="hidden" name="seq" value="<?= $e($r['seq']) ?>">
              <input type="hidden" name="ts" value="<?= $e($r['ts']) ?>">
              <button type="submit" class="link">Show</button>
            </form>
            <?php else : ?><span class="muted">stored, analysts only</span><?php endif; ?>
            <br><span class="muted small"><?= $e($r['cl_length'] !== null ? $r['cl_length'] . ' characters' : '') ?><?= $r['cl_truncated'] ? $e(', cut by the agent') : '' ?></span>
            <?php if ($r['cl_redacted'] === true) : ?><br><span class="cert c-likely">redacted by the agent</span><?php elseif ($r['cl_redacted'] === false) : ?><br><span class="cert c-fact">not redacted</span><?php endif; ?>
          <?php elseif ($r['cl_unavailable']) : ?>
            <span class="cert c-unknown"><?= $e($unavail[$r['cl_unavailable']] ?? 'not read: ' . $r['cl_unavailable']) ?></span>
          <?php else : ?><span class="muted">none stored (never sent, or cleared after 30 days)</span><?php endif; ?>
        </td>
        <td class="nowrap"><?= $r['exit_time'] ? $e($r['exit_time']) . '<br><span class="muted small">code ' . $e($r['exit_code']) . '</span>' : '<span class="muted">no stop seen</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($p['rows'] === []) : ?>
      <tr><td colspan="6" class="muted">No process starts recorded in this range<?= $v['gaps'] !== [] ? ' (see the coverage gaps above)' : '' ?>.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
