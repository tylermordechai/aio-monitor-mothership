<?php
/**
 * Host page, listening ports tab (MS-UI-02, M-056): TCP listeners and UDP
 * endpoints from the latest network snapshot, with the agent's product link
 * and how sure it is (AG-INV-15, MS-DAT-05).
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\Labels;

$cert = static fn (array $l): string => '<span class="cert c-' . $e($l[1]) . '">' . $e($l[0]) . '</span>';
?>
<section class="panel">
  <h2>Listening ports</h2>
  <?php if ($v['snapshot'] === null) : ?>
  <p class="muted">No network snapshot from this host yet.</p>
  <?php else : ?>
  <p class="muted small">From the snapshot at <?= $e(substr((string) $v['snapshot'], 0, 19)) ?> UTC. Which product owns each port is on the Server software tab.</p>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Port</th><th>Address</th><th>Process</th><th>Services</th><th>User</th><th>Product (agent link)</th></tr></thead>
    <tbody>
    <?php foreach ($v['listening'] as $l) : ?>
      <tr>
        <td class="nowrap"><?= $e($l['proto']) ?>/<?= $e($l['local_port']) ?></td>
        <td><?= $e($l['local_addr']) ?></td>
        <td><?= $e($l['process_name'] ?? 'unknown') ?> <span class="muted">(<?= $e($l['pid']) ?>)</span><?php if ($l['exe']) : ?><br><span class="muted small path"><?= $e($l['exe']) ?></span><?php endif; ?><?php if ($l['attribution_error']) : ?><br><span class="cert c-unknown">process not identified: <?= $e($l['attribution_error']) ?></span><?php endif; ?></td>
        <td><?= $e($l['services'] === [] ? '-' : implode(', ', $l['services'])) ?></td>
        <td><?= $e($l['user'] ?? '-') ?></td>
        <td><?php if ($l['app_name']) : ?><?= $e($l['app_name']) ?><br><?= $cert(Labels::matchSource($l['app_match_source'])) ?><?php else : ?><span class="muted">no link</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($v['listening'] === []) : ?>
      <tr><td colspan="6" class="muted">Nothing listening in the latest snapshot.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
