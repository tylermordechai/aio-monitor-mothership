<?php
/**
 * The new token, shown once (M-068, M-070, MS-SEC-03). This is the answer to
 * the POST that made it, sent with Cache-Control: no-store; it is not in a
 * URL, a redirect, a log or the audit row. Reloading asks to send the form
 * again, which would make another token.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
$t = $v['t'];
?>
<p class="crumbs small"><a href="/">Server map</a> › <a href="/admin/enrolment">Enrolment</a> › New token</p>
<section class="panel">
  <h1>New enrolment token</h1>
  <p class="notice ok"><strong>Copy the token now.</strong> It is shown only on this page and can't be shown again; only its SHA-256 is stored.</p>
  <pre class="cmdline token" aria-label="Enrolment token"><?= $e($t['token']) ?></pre>
  <dl class="facts small">
    <div><dt>Token ID</dt><dd><?= $e($t['id']) ?> (for cancelling; not the token)</dd></div>
    <div><dt>Kind</dt><dd><?= $e($t['kind']) ?><?= $t['reenroll'] !== null ? $e(', re-enrolment of ' . $t['reenroll']) : '' ?></dd></div>
    <div><dt>Host group</dt><dd><?= $e($t['group']) ?></dd></div>
    <div><dt>Only for host name</dt><dd><?= $e($t['hostname'] ?? 'any') ?></dd></div>
    <div><dt>Expires</dt><dd><?= $e(substr((string) $t['expires_at'], 0, 19)) ?> UTC</dd></div>
  </dl>
  <p class="small">Each token enrols one agent once. Treat it like a password until it is used or expires. If it leaks, cancel it on the enrolment page.</p>
  <p><a href="/admin/enrolment">Back to enrolment</a></p>
</section>
