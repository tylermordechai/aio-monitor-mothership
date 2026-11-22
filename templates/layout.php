<?php
/**
 * Page frame. $v['content'] is already-escaped HTML from the page template;
 * everything else printed here goes through $e (MS-SEC-05).
 *
 * The stylesheet's id tells Cytoscape.js its container style is already
 * loaded, so it doesn't insert an inline <style> that the CSP would block.
 *
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
$user = $v['user'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($v['title'] ?? 'Dashboard') ?> · All In 1 Monitor</title>
<link id="__________cytoscape_stylesheet" rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="top">
  <a class="brand" href="/">All In 1 Monitor</a>
  <?php if (is_array($user)) : ?>
  <nav class="main">
    <a href="/">Server map</a>
  </nav>
  <div class="who">
    <span><?= $e($user['display_name'] ?? $user['username']) ?></span>
    <span class="role role-<?= $e($user['role']) ?>"><?= $e($user['role']) ?></span>
    <form method="post" action="/logout" class="inline">
      <input type="hidden" name="_csrf" value="<?= $e($v['csrf'] ?? '') ?>">
      <button type="submit" class="link">Sign out</button>
    </form>
  </div>
  <?php endif; ?>
</header>
<main>
<?= $v['content'] ?>
</main>
</body>
</html>
