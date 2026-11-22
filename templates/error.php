<?php
/**
 * @var array<string, mixed> $v
 * @var callable(mixed): string $e
 */
?>
<section class="panel">
  <h1><?= $e($v['title'] ?? 'Error') ?></h1>
  <p><?= $e($v['message'] ?? '') ?></p>
  <p><a href="/">Back to the server map</a></p>
</section>
