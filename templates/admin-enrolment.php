<?php
/**
 * Enrolment (admins, M-070, MPD-40): make a token, the tokens with their
 * state (cancel an open one), the agents with their certificates, and the
 * revocation list's state (M-071). Every change is a POST with the CSRF token
 * and is audited (MS-SEC-05, MS-SEC-08). A token is never shown here: only
 * the answer to its own POST shows it, once.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
use Aio\Dashboard\AgentStatus;

$f = $v['form'] + ['kind' => 'deployment', 'days' => (string) $v['maxDays'], 'group' => 'default', 'hostname' => '', 'reenroll' => '', 'note' => ''];
$crl = $v['crl'];
$csrf = '<input type="hidden" name="_csrf" value="' . $e($v['csrf']) . '">';
?>
<p class="crumbs small"><a href="/">Server map</a> › Enrolment</p>
<h1>Enrolment</h1>
<?php if (($v['notice'] ?? null) !== null) : ?><p class="notice <?= $e($v['notice'][0]) ?>" role="status"><?= $e($v['notice'][1]) ?></p><?php endif; ?>

<section class="panel">
  <h2>New enrolment token</h2>
  <p class="muted small">An installer needs a token to enrol an agent once. The token is shown once, on the next page; only its SHA-256 is stored (MS-SEC-03).</p>
  <?php if ($v['error'] !== null) : ?><p class="notice bad" role="alert"><?= $e($v['error']) ?></p><?php endif; ?>
  <form method="post" action="/admin/tokens" class="form-grid">
    <?= $csrf ?>
    <label>Kind
      <select name="kind">
        <option value="deployment"<?= $f['kind'] === 'deployment' ? ' selected' : '' ?>>Deployment (1 to <?= $e($v['maxDays']) ?> days)</option>
        <option value="manual"<?= $f['kind'] === 'manual' ? ' selected' : '' ?>>Manual (<?= $e($v['manualHours']) ?> hours)</option>
      </select>
    </label>
    <label>Days (deployment only)
      <select name="days">
        <?php for ($d = 1; $d <= $v['maxDays']; $d++) : ?>
        <option value="<?= $e($d) ?>"<?= (string) $d === $f['days'] ? ' selected' : '' ?>><?= $e($d) ?></option>
        <?php endfor; ?>
      </select>
    </label>
    <label>Host group
      <select name="group">
        <?php foreach ($v['groups'] as $g) : ?>
        <option value="<?= $e($g) ?>"<?= $g === $f['group'] ? ' selected' : '' ?>><?= $e($g) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Only for host name <span class="muted">(optional)</span>
      <input type="text" name="hostname" maxlength="255" value="<?= $e($f['hostname']) ?>" autocomplete="off">
    </label>
    <label>Re-enrol agent ID <span class="muted">(optional, §2.5)</span>
      <input type="text" name="reenroll" maxlength="36" value="<?= $e($f['reenroll']) ?>" autocomplete="off" placeholder="only to re-enrol an agent that lost its certificate">
    </label>
    <label>Note <span class="muted">(optional)</span>
      <input type="text" name="note" maxlength="500" value="<?= $e($f['note']) ?>" autocomplete="off">
    </label>
    <div><button type="submit">Make the token</button></div>
  </form>
</section>

<section class="panel">
  <h2>Tokens <span class="muted small">(newest 100)</span></h2>
  <?php if ($v['tokens'] === []) : ?>
  <p class="muted">No tokens yet.</p>
  <?php else : ?>
  <table class="grid">
    <thead><tr><th>ID</th><th>State</th><th>Kind</th><th>Group</th><th>Host name</th><th>Made by</th><th>Made (UTC)</th><th>Expires (UTC)</th><th>Used by agent</th><th>Note</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($v['tokens'] as $t) : ?>
      <tr>
        <td><?= $e($t['id']) ?></td>
        <td><span class="badge b-tok-<?= $e($t['state']) ?>"><?= $e($t['state']) ?></span></td>
        <td><?= $e($t['token_kind']) ?><?= $t['reenroll_agent_id'] !== null ? ', re-enrol' : '' ?></td>
        <td><?= $e($t['group_name']) ?></td>
        <td><?= $e($t['expected_hostname'] ?? 'any') ?></td>
        <td><?= $e($t['created_by_name']) ?></td>
        <td><?= $e(substr((string) $t['created_at'], 0, 16)) ?></td>
        <td><?= $e(substr((string) $t['expires_at'], 0, 16)) ?></td>
        <td class="mono small"><?= $e($t['used_by_agent_id'] ?? ($t['reenroll_agent_id'] ?? '')) ?></td>
        <td><?= $e($t['note']) ?><?= $t['cancelled_by'] !== null ? $e(' (cancelled by ' . $t['cancelled_by'] . ')') : '' ?></td>
        <td><?php if ($t['state'] === 'open') : ?>
          <form method="post" action="/admin/tokens/<?= $e($t['id']) ?>/cancel" class="inline"><?= $csrf ?><button type="submit" class="link">Cancel</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Agents</h2>
  <p class="muted small">Revoke or unrevoke an agent on its host page. Certificates are listed there too.</p>
  <?php if ($v['agents'] === []) : ?>
  <p class="muted">No agents enrolled yet.</p>
  <?php else : ?>
  <table class="grid">
    <thead><tr><th>Host</th><th>Agent ID</th><th>Status</th><th>Group</th><th>Last check-in (UTC)</th><th>Version</th><th>Usable certificates</th><th>Latest expires (UTC)</th></tr></thead>
    <tbody>
    <?php foreach ($v['agents'] as $a) :
        $st = AgentStatus::of(
            (string) $a['status'] === 'replaced' ? null : (string) $a['status'],
            $a['checkin_epoch'] === null ? null : (int) $a['checkin_epoch'],
            $a['ingest_epoch'] === null ? null : (int) $a['ingest_epoch'],
            $a['queue_records'] === null ? null : (int) $a['queue_records'],
            time(),
            $v['staleAfter'],
        );
        ?>
      <tr>
        <td><a href="/hosts/<?= $e($a['host_pk']) ?>"><?= $e($a['hostname']) ?></a></td>
        <td class="mono small"><?= $e($a['agent_id']) ?></td>
        <td><?= $a['status'] === 'replaced' ? '<span class="badge b-no_agent">replaced</span>' : '<span class="badge b-' . $e($st) . '">' . $e(AgentStatus::label($st)) . '</span>' ?></td>
        <td><?= $e($a['group_name']) ?></td>
        <td><?= $e($a['last_checkin_at'] === null ? 'never' : substr((string) $a['last_checkin_at'], 0, 19)) ?></td>
        <td><?= $e($a['last_version'] ?? '-') ?></td>
        <td><?= $e($a['usable_certs']) ?></td>
        <td><?= $e($a['cert_expires'] === null ? '-' : substr((string) $a['cert_expires'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Revocation list (CRL)</h2>
  <p class="notice <?= $crl['state'] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($crl['message']) ?></p>
  <p class="muted small">
    Apache checks every agent certificate against this list, as a second check behind the database (ingest-api.md §2.4).
    It lists revoked certificates and the certificates of revoked agents<?= $crl['entries'] !== null ? $e(' (' . $crl['entries'] . ' now)') : '' ?>.
    It is rebuilt daily and after every revocation or unrevocation.
  </p>
</section>
