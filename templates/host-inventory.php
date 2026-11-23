<?php
/**
 * Host page, inventory tab (MS-UI-02, M-056): the latest complete inventory
 * snapshot (apps, services, scheduled tasks, startup items, drivers, roles)
 * and the inventory changes in the range (time-based: the gaps above apply).
 * Where the agent says a source was partial or failed, that is shown, so a
 * short list is not read as a complete one.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\Labels;

$inv = $v['inventory'];
$items = $v['items'];
$yn = static fn ($b): string => $b === null ? '-' : ($b ? 'yes' : 'no');
$cert = static fn (array $l): string => '<span class="cert c-' . $e($l[1]) . '">' . $e($l[0]) . '</span>';
$sections = [
    'apps' => ['Installed apps', ['Name', 'Version', 'Publisher', 'Installed', 'Scope', 'Source']],
    'services' => ['Services', ['Name', 'Display name', 'State', 'Start', 'Account', 'Program', 'Product (agent link)']],
    'tasks' => ['Scheduled tasks', ['Path', 'Enabled', 'Runs as', 'Level', 'Actions', 'Notes']],
    'startup' => ['Startup items', ['Name', 'Location', 'Command', 'Enabled', 'User']],
    'drivers' => ['Drivers', ['Name', 'Display name', 'Image', 'Start', 'State', 'Loaded']],
    'roles' => ['Server roles and features', ['Name', 'Display name', 'Installed']],
];
?>
<?php if ($inv === null) : ?>
<section class="panel"><h2>Inventory</h2><p class="muted">No complete inventory snapshot from this host yet.</p></section>
<?php else : ?>
<section class="panel">
  <h2>Inventory</h2>
  <p class="muted small">From the latest complete snapshot, <?= $e(substr($inv['end'], 0, 19)) ?> UTC.
  <?php
    $problems = [];
    foreach ($inv['sources'] as $kind => $src) {
        if (is_array($src) && (!empty($src['error']) || !empty($src['partial']))) {
            $problems[] = $kind . ': ' . (!empty($src['error']) ? 'failed (' . $src['error'] . ')' : 'partial');
        }
    }
  ?>
  <?php if ($problems !== []) : ?><br><span class="cert c-unknown">The agent reported incomplete sources: <?= $e(implode('; ', $problems)) ?>. Those lists may be missing items.</span><?php endif; ?>
  </p>
  <nav class="jump small">
    <?php foreach ($sections as $k => [$title]) : ?><a href="#inv-<?= $e($k) ?>"><?= $e($title) ?> (<?= $e(count($items[$k]['rows']) . ($items[$k]['cut'] ? '+' : '')) ?>)</a> <?php endforeach; ?>
    <a href="#inv-changes">Changes in the range (<?= $e(count($v['changes'])) ?>)</a>
  </nav>
</section>

<?php foreach ($sections as $k => [$title, $heads]) : ?>
<section class="panel" id="inv-<?= $e($k) ?>">
  <h2><?= $e($title) ?></h2>
  <?php if ($items[$k]['cut']) : ?><p class="muted small">More than <?= $e($v['limit']) ?>: the first <?= $e($v['limit']) ?> by name are shown.</p><?php endif; ?>
  <div class="scroll">
  <table class="grid">
    <thead><tr><?php foreach ($heads as $hd) : ?><th><?= $e($hd) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($items[$k]['rows'] as $r) : ?>
      <tr>
      <?php switch ($k) :
          case 'apps': ?>
        <td><?= $e($r['name']) ?></td><td><?= $e($r['version'] ?? '-') ?></td><td><?= $e($r['publisher'] ?? '-') ?></td><td><?= $e($r['install_date'] ?? '-') ?></td><td><?= $e($r['scope'] ?? '-') ?><?= $r['arch'] ? $e(', ' . $r['arch']) : '' ?></td><td><?= $e($r['source']) ?></td>
          <?php break;
          case 'services': ?>
        <td><?= $e($r['name']) ?></td><td><?= $e($r['display_name'] ?? '-') ?></td><td><?= $e($r['state'] ?? '-') ?></td><td><?= $e($r['start_type'] ?? '-') ?></td><td><?= $e($r['account'] ?? '-') ?></td><td class="path small"><?= $e($r['binary_path'] ?? '-') ?></td>
        <td><?php if ($r['app_name']) : ?><?= $e($r['app_name']) ?><br><?= $cert(Labels::matchSource($r['app_match_source'])) ?><?php else : ?><span class="muted">no link</span><?php endif; ?></td>
          <?php break;
          case 'tasks':
              $acts = json_decode((string) ($r['actions'] ?? '[]'), true) ?: [];
              $actText = implode('; ', array_map(static fn ($a) => is_array($a) ? (string) ($a['command'] ?? $a['type'] ?? '?') : '?', $acts)); ?>
        <td class="path"><?= $e($r['path']) ?></td><td><?= $e($yn($r['enabled'])) ?></td><td><?= $e($r['run_as'] ?? '-') ?></td><td><?= $e($r['run_level'] ?? '-') ?></td><td class="path small"><?= $e($actText ?: '-') ?></td>
        <td><?= $r['no_security_descriptor'] ? '<span class="cert c-likely">no security descriptor: possibly hidden</span>' : '' ?><?= $r['parse_error'] ? '<span class="cert c-unknown">not fully read: ' . $e($r['parse_error']) . '</span>' : '' ?></td>
          <?php break;
          case 'startup': ?>
        <td><?= $e($r['name']) ?></td><td class="path small"><?= $e($r['location']) ?></td><td class="path small"><?= $e($r['command'] ?? '-') ?></td><td><?= $e($yn($r['enabled'])) ?></td><td><?= $e($r['user'] ?? '-') ?></td>
          <?php break;
          case 'drivers': ?>
        <td><?= $e($r['name'] ?: '(no service)') ?></td><td><?= $e($r['display_name'] ?? '-') ?></td><td class="path small"><?= $e($r['image_path'] ?? '-') ?></td><td><?= $e($r['start_type'] ?? '-') ?></td><td><?= $e($r['state'] ?? '-') ?></td><td><?= $e($yn($r['loaded'])) ?></td>
          <?php break;
          case 'roles': ?>
        <td><?= $e($r['name']) ?></td><td><?= $e($r['display_name'] ?? '-') ?></td><td><?= $e($yn($r['installed'])) ?></td>
          <?php break;
      endswitch; ?>
      </tr>
    <?php endforeach; ?>
    <?php if ($items[$k]['rows'] === []) : ?>
      <tr><td colspan="<?= $e(count($heads)) ?>" class="muted">None in the latest snapshot.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach; ?>
<?php endif; ?>

<section class="panel" id="inv-changes">
  <h2>Inventory changes in the range</h2>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>When (UTC)</th><th>Kind</th><th>Item</th><th>Change</th><th>Why</th></tr></thead>
    <tbody>
    <?php foreach ($v['changes'] as $c) : ?>
      <tr>
        <td class="nowrap"><?= $e(substr((string) $c['ts'], 0, 19)) ?></td>
        <td><?= $e(str_replace('inv_', '', (string) $c['kind'])) ?></td>
        <td class="path"><?= $e($c['item_name'] ?? $c['key']) ?></td>
        <td><?= $e($c['change']) ?></td>
        <td><?= $e($c['reason'] ?? '-') ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($v['changes'] === []) : ?>
      <tr><td colspan="5" class="muted">No inventory changes recorded in this range<?= $v['gaps'] !== [] ? ' (see the coverage gaps above)' : '' ?>.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
