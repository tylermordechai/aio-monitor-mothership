<?php
/**
 * Host page, connections tab (MS-UI-02, MS-DAT-04, MS-DAT-05, M-056).
 * Recent: one row per connection (D-016 key) over the range, from
 * flow_conn_hourly. Live: the latest network snapshot. The direction and
 * user columns say how each was decided, so a guess never looks like a fact.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\Labels;

$rec = $v['recent'];
$cert = static fn (array $l): string => '<span class="cert c-' . $e($l[1]) . '">' . $e($l[0]) . '</span>';
$cls = ['host' => 'monitored host', 'internal' => 'internal', 'internet' => 'internet', 'local' => 'this host'];
?>
<section class="panel">
  <h2>Connections</h2>
  <p class="muted small">
    <?= $e($rec['total']) ?> connections in the range<?= $rec['total'] > count($rec['rows']) ? $e(', newest ' . count($rec['rows']) . ' shown') : '' ?>.
    One row per connection: protocol, process, local and remote address and port (D-016), combined from the agent's 10-second flow records.
    Data up to <?= $e($rec['data_until'] ?? 'nothing yet') ?> UTC; this view runs 1 to 3 minutes behind the agent.
  </p>
  <div class="scroll">
  <table class="grid">
    <thead><tr>
      <th>Last seen</th><th>Direction</th><th>Remote</th><th>Local</th><th>Process</th><th>User</th><th>Sent</th><th>Received</th><th>First seen</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rec['rows'] as $c) : ?>
      <tr>
        <td class="nowrap"><?= $e($c['last_seen'] ?? '-') ?></td>
        <td><?= $e($c['direction']) ?><br><?= $cert($c['direction_source']) ?></td>
        <td class="nowrap"><?= $e($c['proto']) ?> <?= $e($c['remote']) ?><br>
          <?php if ($c['peer'] !== null) : ?><a href="/hosts/<?= $e($c['peer']['host_pk']) ?>?tab=connections&range=<?= $e($v['range']) ?>"><?= $e($c['peer']['hostname']) ?></a>
          <?php else : ?><span class="muted small"><?= $e($cls[$c['remote_class']] ?? $c['remote_class']) ?></span><?php endif; ?>
        </td>
        <td class="nowrap"><?= $e($c['local']) ?></td>
        <td><?= $e($c['process'] ?? 'unknown') ?> <span class="muted">(<?= $e($c['pid']) ?>)</span><?php if ($c['exe']) : ?><br><span class="muted small path"><?= $e($c['exe']) ?></span><?php endif; ?></td>
        <td><?= $e($c['user'] ?? '-') ?><?php if ($c['user']) : ?><br><?= $cert($c['user_source']) ?><?php endif; ?></td>
        <td class="num"><?= $e(Labels::bytes($c['bytes_sent'])) ?></td>
        <td class="num"><?= $e(Labels::bytes($c['bytes_recv'])) ?></td>
        <td class="nowrap"><?= $e($c['first_seen'] ?? '-') ?><?= $c['closed'] ? '<br><span class="muted small">closed</span>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($rec['rows'] === []) : ?>
      <tr><td colspan="9" class="muted">No connections recorded in this range<?= $v['gaps'] !== [] ? ' (see the coverage gaps above)' : '' ?>.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="panel">
  <h2>Live: the latest snapshot</h2>
  <?php if ($v['snapshot'] === null) : ?>
  <p class="muted">No network snapshot from this host yet.</p>
  <?php else : ?>
  <p class="muted small">
    TCP connections open at <?= $e(substr((string) $v['snapshot'], 0, 19)) ?> UTC, <?= $e(count($v['live'])) ?> shown<?= count($v['live']) >= $v['limit'] ? $e(' (cut at ' . $v['limit'] . ')') : '' ?>.
    In a snapshot the direction is usually a guess from the ports; the column says so.
  </p>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Remote</th><th>Local</th><th>State</th><th>Direction</th><th>Process</th><th>User</th></tr></thead>
    <tbody>
    <?php foreach ($v['live'] as $c) : ?>
      <tr>
        <td class="nowrap"><?= $e($c['remote']) ?></td>
        <td class="nowrap"><?= $e($c['local']) ?></td>
        <td><?= $e($c['state']) ?></td>
        <td><?= $e($c['direction']) ?><br><?= $cert($c['direction_source']) ?></td>
        <td><?= $e($c['process'] ?? 'unknown') ?> <span class="muted">(<?= $e($c['pid']) ?>)</span><?= $c['services'] !== [] ? '<br><span class="muted small">' . $e(implode(', ', $c['services'])) . '</span>' : '' ?></td>
        <td><?= $e($c['user'] ?? '-') ?><?php if ($c['user']) : ?><br><?= $cert($c['user_source']) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($v['live'] === []) : ?>
      <tr><td colspan="6" class="muted">No TCP connections in the latest snapshot.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
