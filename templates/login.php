<?php
/**
 * Sign-in (MS-SEC-06). One message for every failure, so the page never
 * says whether an account exists.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
?>
<section class="signin">
  <h1>Sign in</h1>
  <?php if (($v['message'] ?? null) !== null) : ?>
  <p class="notice bad" role="alert"><?= $e($v['message']) ?></p>
  <?php endif; ?>
  <form method="post" action="/login" autocomplete="on">
    <input type="hidden" name="_csrf" value="<?= $e($v['csrf']) ?>">
    <label>User name <input name="username" autocomplete="username" required maxlength="100" autofocus></label>
    <label>Password <input name="password" type="password" autocomplete="current-password" required maxlength="1024"></label>
    <button type="submit">Sign in</button>
  </form>
</section>
