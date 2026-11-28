<?php
/**
 * Host page frame (MS-UI-02, MS-UI-07, M-056): the host's header, the tabs,
 * the range, the coverage gaps for the range, then the tab's own HTML
 * ($v['tabHtml'], rendered from templates/host-<tab>.php, already escaped).
 * Times are UTC.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\AgentStatus;
use Aio\Dashboard\HostQuery;
use Aio\Dashboard\Labels;

$h = $v['host'];
$f = $h['facts'];
$base = '/hosts/' . $h['host_pk'];
$timeBased = in_array($v['tab'], ['connections', 'processes', 'inventory'], true);
$rangeNames = ['1h' => 'Last hour', '24h' => 'Last 24 hours', '7d' => 'Last 7 days'];
?>
<p class="crumbs small"><a href="/">Server map</a> › <?= $e($h['hostname']) ?></p>
<?php if (($v['notice'] ?? null) !== null) : ?><p class="notice <?= $e($v['notice'][0]) ?>" role="status"><?= $e($v['notice'][1]) ?></p><?php endif; ?>
<section class="host-head">
  <div>
    <h1><?= $e($h['hostname']) ?> <span class="badge b-<?= $e($h['status']) ?>"><?= $e(AgentStatus::label($h['status'])) ?></span></h1>
    <p class="muted"><?= $e($h['kind']) ?> · <?= $e($h['os'] ?? 'OS not reported yet') ?><?= is_array($f) && $f['os_display_version'] ? $e(' ' . $f['os_display_version']) : '' ?><?= is_array($f) && $f['os_version'] ? $e(' (' . $f['os_version'] . ')') : '' ?></p>
  </div>
  <dl class="facts small">
    <div><dt>Last check-in</dt><dd><?= $e($h['last_checkin'] ?? 'never') ?> <span class="muted">(<?= $e($h['ago']) ?>)</span></dd></div>
    <div><dt>Agent</dt><dd><?= $e($h['version'] ?? '-') ?><?= $h['queue_records'] !== null ? $e(', ' . $h['queue_records'] . ' records queued') : '' ?><?php if ((int) ($h['undeliverable_records'] ?? 0) > 0) : ?> <span class="badge b-gap"><?= $e($h['undeliverable_records']) ?> undeliverable</span><?php endif; ?><?php if ((int) ($h['dropped_records'] ?? 0) > 0) : ?> <span class="badge b-gap"><?= $e($h['dropped_records']) ?> dropped</span><?php endif; ?></dd></div>
    <div><dt>Addresses</dt><dd><?= $e($h['addresses'] === [] ? 'not reported yet' : implode(', ', $h['addresses'])) ?></dd></div>
    <?php if (is_array($f)) : ?>
    <div><dt>Domain</dt><dd><?= $e(($f['dns_domain'] ?? '-') . ($f['join_type'] ? ' (' . $f['join_type'] . ')' : '')) ?></dd></div>
    <div><dt>Last boot</dt><dd><?= $e($f['last_boot'] === null ? '-' : substr((string) $f['last_boot'], 0, 19)) ?></dd></div>
    <div><dt>Hardware</dt><dd><?= $e(trim(($f['manufacturer'] ?? '') . ' ' . ($f['model'] ?? '')) ?: '-') ?><?= $f['logical_cpus'] ? $e(', ' . $f['logical_cpus'] . ' CPUs') : '' ?><?= $f['memory_bytes'] ? $e(', ' . Labels::bytes((int) $f['memory_bytes']) . ' memory') : '' ?></dd></div>
    <?php endif; ?>
    <div><dt>Group</dt><dd><?= $e($h['group']) ?></dd></div>
  </dl>
</section>

<?php if (($v['agentAdmin'] ?? null) !== null) :
    $ag = $v['agentAdmin']['agent'];
    $revoked = $ag['status'] === 'revoked';
    ?>
<details class="panel admin"<?= $revoked ? ' open' : '' ?>>
  <summary><strong>Agent and certificates</strong> <span class="muted small">(admins, M-070)</span> <span class="badge b-<?= $revoked ? 'revoked' : 'ok' ?>"><?= $e($ag['status']) ?></span></summary>
  <p class="small">Agent <span class="mono"><?= $e($ag['agent_id']) ?></span>, enrolled <?= $e(substr((string) $ag['enrolled_at'], 0, 19)) ?> UTC<?= $revoked ? $e(', revoked ' . substr((string) $ag['revoked_at'], 0, 19) . ' UTC: ' . $ag['revoke_reason']) : '' ?>.</p>
  <table class="grid">
    <thead><tr><th>Serial</th><th>Status</th><th>Issued by</th><th>Issued (UTC)</th><th>Expires (UTC)</th><th>Revoked</th></tr></thead>
    <tbody>
    <?php foreach ($v['agentAdmin']['certificates'] as $c) : ?>
      <tr>
        <td class="mono small"><?= $e($c['serial_hex']) ?></td>
        <td><?= $e($c['status']) ?></td>
        <td><?= $e($c['issued_via']) ?></td>
        <td><?= $e(substr((string) $c['issued_at'], 0, 19)) ?></td>
        <td><?= $e(substr((string) $c['not_after'], 0, 19)) ?></td>
        <td><?= $e($c['revoked_at'] === null ? '' : substr((string) $c['revoked_at'], 0, 19) . ($c['revoke_reason'] !== null ? ' (' . $c['revoke_reason'] . ')' : '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <form method="post" action="<?= $e($base . '/' . ($revoked ? 'unrevoke' : 'revoke')) ?>" class="form-row">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf']) ?>">
    <input type="hidden" name="agent_id" value="<?= $e($ag['agent_id']) ?>">
    <label>Reason <span class="muted">(required, kept in the audit log)</span>
      <input type="text" name="reason" required maxlength="500" autocomplete="off">
    </label>
    <?php if ($revoked) : ?>
    <button type="submit">Unrevoke the agent</button>
    <span class="muted small">Undoes a mistake: its certificates that aren't revoked themselves work again, and it sends what it kept.</span>
    <?php else : ?>
    <button type="submit" class="danger">Revoke the agent</button>
    <span class="muted small">Every request it makes is refused from now on, with any of its certificates. The agent keeps its queue; unrevoking lets it send again.</span>
    <?php endif; ?>
  </form>
</details>
<?php endif; ?>

<nav class="tabs" aria-label="Host views">
  <?php foreach (HostQuery::TABS as $id => $title) : ?>
  <a href="<?= $e($base . '?tab=' . $id . '&range=' . $v['range']) ?>"<?= $id === $v['tab'] ? ' class="on" aria-current="page"' : '' ?>><?= $e($title) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($timeBased) : ?>
<nav class="ranges" aria-label="Time range">
  <?php foreach (array_keys($v['ranges']) as $r) : ?>
  <a href="<?= $e($base . '?tab=' . $v['tab'] . '&range=' . $r) ?>"<?= $r === $v['range'] ? ' class="on" aria-current="page"' : '' ?>><?= $e($rangeNames[$r]) ?></a>
  <?php endforeach; ?>
  <span class="muted small">from <?= $e($v['since']) ?> UTC</span>
</nav>
<?php endif; ?>

<section class="panel gaps<?= $v['gaps'] === [] ? ' none' : '' ?>">
  <?php if ($v['gaps'] === []) : ?>
  <p class="small"><strong>Coverage:</strong> no coverage gaps reported for <?= $e(strtolower($rangeNames[$v['range']])) ?>.</p>
  <?php else : ?>
  <h2>Coverage gaps, <?= $e(strtolower($rangeNames[$v['range']])) ?> (<?= $e(count($v['gaps'])) ?>)</h2>
  <p class="muted small">Data may be missing for these periods: no activity there is not proof of none (MS-UI-07).</p>
  <ul class="gap-list">
    <?php foreach ($v['gaps'] as $g) : ?>
    <li><span class="badge b-gap"><?= $e(str_replace('_', ' ', $g['reason'])) ?></span> <?= $e($g['start']) ?> to <?= $e($g['end'] ?? 'still open') ?><?= $g['events_lost'] ? $e(', ' . $g['events_lost'] . ' events lost') : '' ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<?= $v['tabHtml'] ?>
