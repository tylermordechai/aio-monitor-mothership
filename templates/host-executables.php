<?php
/**
 * Host page, executables tab (MS-UI-02, MS-DAT-05, M-056): each program file
 * version seen on the host, with its hash and signature status and, when it
 * couldn't be hashed, why and what privilege it would need (D-056).
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\Labels;

$x = $v['executables'];
$cert = static fn (array $l): string => '<span class="cert c-' . $e($l[1]) . '">' . $e($l[0]) . '</span>';
?>
<section class="panel">
  <h2>Executables</h2>
  <p class="muted small"><?= $e($x['total']) ?> program file versions seen on this host<?= $x['total'] > count($x['rows']) ? $e(', the ' . count($x['rows']) . ' seen most recently shown') : '' ?>.</p>
  <div class="scroll">
  <table class="grid">
    <thead><tr><th>Path</th><th>Product</th><th>Hash (SHA-256)</th><th>Signature</th><th>Size</th><th>Modified</th><th>Last seen</th></tr></thead>
    <tbody>
    <?php foreach ($x['rows'] as $r) : ?>
      <tr>
        <td class="path"><?= $e($r['path']) ?></td>
        <td><?= $e($r['product'] ?? '-') ?><?= $r['company'] ? '<br><span class="muted small">' . $e($r['company']) . '</span>' : '' ?><?= $r['file_version'] ? '<br><span class="muted small">' . $e($r['file_version']) . '</span>' : '' ?></td>
        <td><?php if ($r['sha256']) : ?><span class="mono small" title="<?= $e($r['sha256']) ?>"><?= $e(substr($r['sha256'], 0, 16)) ?>…</span><?php else : ?><?= $cert($r['hash']) ?><?php endif; ?></td>
        <td><?= $cert($r['signature']) ?><?= $r['sig_type'] ? '<br><span class="muted small">' . $e($r['sig_type']) . '</span>' : '' ?></td>
        <td class="num"><?= $e(Labels::bytes($r['size'])) ?></td>
        <td class="nowrap"><?= $e($r['mod_time']) ?></td>
        <td class="nowrap"><?= $e($r['last_seen']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($x['rows'] === []) : ?>
      <tr><td colspan="7" class="muted">No executables reported by this host yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
</section>
