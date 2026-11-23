<?php
/**
 * Host page, server software tab (MS-UI-02, M-056): which product owns each
 * listening port, each link shown as confirmed, likely or a guess; IIS with
 * its read status (so an unreadable configuration never looks like "no
 * sites"), sites with bindings, app pools; SQL Server instances and their
 * ports; other server products from the detection list.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\Labels;

$sw = $v['software'];
$yn = static fn ($b): string => $b === null ? '-' : ($b ? 'yes' : 'no');
$cert = static fn (array $l): string => '<span class="cert c-' . $e($l[1]) . '">' . $e($l[0]) . '</span>';
$ports = static fn (array $p): string => $p === [] ? '-' : implode(', ', $p);
?>
<section class="panel">
  <h2>Who owns each listening port</h2>
  <?php if ($v['snapshot'] === null) : ?>
  <p class="muted">No network snapshot from this host yet.</p>
  <?php else : ?>
  <p class="muted small">Listeners from the snapshot at <?= $e(substr((string) $v['snapshot'], 0, 19)) ?> UTC<?= $v['inventory'] !== null ? $e(', configuration from the inventory at ' . substr($v['inventory']['end'], 0, 19) . ' UTC') : '' ?>.
  The agent's own link comes first; a port found only in IIS or SQL Server configuration is marked "likely".</p>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Port</th><th>Address</th><th>Owner</th><th>How we know</th><th>Process</th></tr></thead>
    <tbody>
    <?php foreach ($v['owners'] as $o) : ?>
      <tr>
        <td class="nowrap"><?= $e($o['proto']) ?>/<?= $e($o['port']) ?></td>
        <td><?= $e($o['address']) ?></td>
        <td><?= $e($o['owner']) ?></td>
        <td><?= $cert([$o['how'], $o['certainty']]) ?></td>
        <td><?= $e($o['process']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($v['owners'] === []) : ?>
      <tr><td colspan="5" class="muted">Nothing listening in the latest snapshot.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<?php if ($sw === null) : ?>
<section class="panel"><h2>Server software</h2><p class="muted">No complete inventory snapshot from this host yet, so IIS, SQL Server and other server products are not known.</p></section>
<?php else : ?>
<section class="panel">
  <h2>IIS</h2>
  <?php $iis = $sw['iis']; $read = Labels::iisRead($iis['read'] ?? null); ?>
  <p><strong>Configuration:</strong> <?= $cert($read) ?>
  <?php if ($iis !== null && $iis['installed']) : ?> · version <?= $e($iis['version'] ?? '?') ?> · <?= $e($iis['sites'] ?? count($sw['sites'])) ?> sites, <?= $e($iis['app_pools'] ?? count($sw['pools'])) ?> app pools<?php endif; ?></p>
  <?php if ($iis !== null && $iis['read'] !== 'ok' && $iis['read'] !== 'not_installed') : ?>
  <p class="cert c-unknown">The sites and app pools below may be incomplete: the agent could not read the IIS configuration<?php
    $pe = json_decode((string) ($iis['parse_error'] ?? ''), true);
    echo is_array($pe) ? $e(' (' . implode(', ', array_filter([$pe['file'] ?? null, isset($pe['line']) ? 'line ' . $pe['line'] : null, $pe['element'] ?? null])) . ')') : '';
  ?>.</p>
  <?php endif; ?>
  <?php if ($sw['sites'] !== []) : ?>
  <h3>Sites</h3>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Site</th><th>ID</th><th>Bindings</th><th>App pool</th><th>Path</th><th>Apps</th><th>Auto start</th></tr></thead>
    <tbody>
    <?php foreach ($sw['sites'] as $s) : ?>
      <tr>
        <td><?= $e($s['name']) ?></td>
        <td><?= $e($s['id']) ?></td>
        <td class="small"><?php foreach ($s['bindings'] as $b) : ?><?= $e(is_array($b) ? (($b['protocol'] ?? '?') . ' ' . (isset($b['port']) ? ($b['ip'] ?? '*') . ':' . $b['port'] : ($b['binding_information'] ?? '')) . (!empty($b['host']) ? ' ' . $b['host'] : '')) : '?') ?><br><?php endforeach; ?></td>
        <td><?= $e($s['app_pool'] ?? '-') ?></td>
        <td class="path small"><?= $e($s['physical_path'] ?? '-') ?></td>
        <td><?= $e($s['applications']) ?></td>
        <td><?= $e($yn($s['auto_start'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <?php if ($sw['pools'] !== []) : ?>
  <h3>App pools</h3>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Pool</th><th>Identity</th><th>Runtime</th><th>Pipeline</th><th>Start</th><th>Product (agent link)</th></tr></thead>
    <tbody>
    <?php foreach ($sw['pools'] as $p) : ?>
      <tr>
        <td><?= $e($p['name']) ?></td>
        <td><?= $e($p['identity_type'] ?? '-') ?><?= $p['account'] ? '<br><span class="muted small">' . $e($p['account']) . '</span>' : '' ?></td>
        <td><?= $e($p['managed_runtime_version'] ?: 'no managed code') ?></td>
        <td><?= $e($p['pipeline_mode'] ?? '-') ?></td>
        <td><?= $e($p['start_mode'] ?? '-') ?></td>
        <td><?php if ($p['app_name']) : ?><?= $e($p['app_name']) ?><br><?= $cert(Labels::matchSource($p['app_match_source'])) ?><?php else : ?><span class="muted">no link</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>SQL Server</h2>
  <?php if ($sw['sql'] === []) : ?>
  <p class="muted">No SQL Server instance in the latest inventory.</p>
  <?php else : ?>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Instance</th><th>Edition</th><th>Version</th><th>Patch level</th><th>Service</th><th>TCP</th><th>Ports</th><th>Product (agent link)</th></tr></thead>
    <tbody>
    <?php foreach ($sw['sql'] as $i) : ?>
      <tr>
        <td><?= $e($i['instance_name']) ?></td>
        <td><?= $e($i['edition'] ?? '-') ?></td>
        <td><?= $e($i['version'] ?? '-') ?></td>
        <td><?= $e($i['patch_level'] ?? '-') ?></td>
        <td><?= $e($i['service_name'] ?? '-') ?><?= $i['service_account'] ? '<br><span class="muted small">' . $e($i['service_account']) . '</span>' : '' ?></td>
        <td><?= $e($yn($i['tcp_enabled'] === null ? null : (bool) $i['tcp_enabled'])) ?></td>
        <td><?= $e($ports($i['tcp_ports'])) ?><?= $i['tcp_dynamic_ports'] !== [] ? '<br><span class="muted small">dynamic: ' . $e($ports($i['tcp_dynamic_ports'])) . '</span>' : '' ?></td>
        <td><?php if ($i['app_name']) : ?><?= $e($i['app_name']) ?><br><?= $cert(Labels::matchSource($i['app_match_source'])) ?><?php else : ?><span class="muted">no link</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Other server products</h2>
  <?php if ($sw['apps'] === []) : ?>
  <p class="muted">None from the detection list in the latest inventory.</p>
  <?php else : ?>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Product</th><th>Version</th><th>Found by</th><th>Service or key</th><th>Installed in</th></tr></thead>
    <tbody>
    <?php foreach ($sw['apps'] as $a) : ?>
      <tr>
        <td><?= $e($a['product']) ?></td>
        <td><?= $e($a['version'] ?? 'unknown') ?><?= $a['version_source'] ? '<br><span class="muted small">from ' . $e(str_replace('_', ' ', $a['version_source'])) . '</span>' : '' ?></td>
        <td><?= $e($a['detected_by'] ?? '-') ?><?= $a['list_source'] ? '<br><span class="muted small">' . $e($a['list_source']) . ' list</span>' : '' ?></td>
        <td class="path small"><?= $e($a['service_name'] ?? $a['registry_key'] ?? '-') ?></td>
        <td class="path small"><?= $e($a['install_path'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
