<?php
/**
 * Home: the server map and the fleet table (MS-UI-01, MS-UI-07, M-054).
 * The map is drawn by assets/map.js from /api/map; everything here is
 * escaped with $e. Times are UTC.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\FleetQuery;

$status = ['ok' => 'checking in', 'stale' => 'stale', 'no_agent' => 'no agent'];
$fleet = $v['fleet'];
$stale = count(array_filter($fleet, static fn ($h) => $h['status'] !== 'ok'));
?>
<section class="map-head">
  <h1>Server map</h1>
  <nav class="ranges" aria-label="Time range">
    <?php foreach (array_keys($v['ranges']) as $r) : ?>
    <a href="/?range=<?= $e($r) ?>"<?= $r === $v['range'] ? ' class="on" aria-current="page"' : '' ?>><?= $e(['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days'][$r]) ?></a>
    <?php endforeach; ?>
  </nav>
</section>

<section class="summary">
  <span><strong><?= $e(count($fleet)) ?></strong> hosts</span>
  <span class="<?= $stale > 0 ? 'warn' : '' ?>"><strong><?= $e($stale) ?></strong> stale or without an agent (no check-in for <?= $e(intdiv($v['staleAfter'], 60)) ?> minutes)</span>
  <span class="muted">Alerts: not built yet (milestone 7)</span>
</section>

<div class="map-wrap">
  <div id="map" data-range="<?= $e($v['range']) ?>" aria-label="Map of hosts and the addresses they talk to"></div>
  <aside id="info" class="info" aria-live="polite">
    <p class="muted">Point at a host or a line for details. Click a host to open it.</p>
  </aside>
</div>
<p id="map-status" class="muted small">Loading the map…</p>

<section class="legend small">
  <span><i class="sw sw-server"></i>Server</span>
  <span><i class="sw sw-workstation"></i>Workstation</span>
  <span><i class="sw sw-stale"></i>Stale or no agent</span>
  <span><i class="sw sw-gap"></i>Coverage gap in this range</span>
  <span><i class="sw sw-internal"></i>Internal address, not monitored</span>
  <span><i class="sw sw-other"></i>Other internal addresses</span>
  <span><i class="sw sw-internet"></i>Internet</span>
  <span>Lines point from the side that opened the connection; thicker means more bytes.</span>
</section>

<section id="gaps" class="panel" hidden>
  <h2>Coverage gaps in this range</h2>
  <p class="muted small">Data may be missing for these periods, so no activity there is not proof of none (MS-UI-07).</p>
  <ul id="gap-list"></ul>
</section>

<section class="panel">
  <h2>Hosts</h2>
  <table class="grid">
    <thead>
      <tr><th>Host</th><th>Kind</th><th>OS</th><th>Agent</th><th>Last check-in (UTC)</th><th>Status</th><th>Gaps, 24 h</th><th>Queued</th></tr>
    </thead>
    <tbody>
    <?php foreach ($fleet as $h) : ?>
      <tr class="st-<?= $e($h['status']) ?>">
        <td><a href="/hosts/<?= $e($h['host_pk']) ?>"><?= $e($h['hostname']) ?></a></td>
        <td><?= $e($h['kind']) ?></td>
        <td><?= $e($h['os'] ?? 'not reported yet') ?></td>
        <td><?= $e($h['version'] ?? '-') ?></td>
        <td><?= $e($h['last_checkin'] ?? 'never') ?> <span class="muted">(<?= $e(FleetQuery::ago($h['ago_s'])) ?>)</span></td>
        <td><span class="badge b-<?= $e($h['status']) ?>"><?= $e($status[$h['status']] ?? $h['status']) ?></span></td>
        <td><?php if ($h['gaps_24h'] > 0) : ?><span class="badge b-gap" title="<?= $e(implode(', ', $h['gap_reasons'])) ?>"><?= $e($h['gaps_24h']) ?><?= $h['gaps_open'] > 0 ? $e(', ' . $h['gaps_open'] . ' open') : '' ?></span><?php else : ?>0<?php endif; ?></td>
        <td><?= $e($h['queue_records'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($fleet === []) : ?>
      <tr><td colspan="8" class="muted">No hosts yet. Simulated hosts come from tools/dev-agents.php.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</section>

<script src="/assets/vendor/cytoscape-3.34.4/cytoscape.min.js"></script>
<script src="/assets/map.js"></script>
