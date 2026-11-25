<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var int      $status
 * @var string   $message
 */
?>
<main class="container py-5 text-center">
    <div class="display-1">🍽️</div>
    <h1 class="recipe-title mt-3"><?= match ($status) { 401 => 'Sign-in required', 403 => 'Not allowed', 404 => 'Page not found', default => 'Something went wrong' } ?></h1>
    <p class="text-body-secondary"><?= $e($message) ?></p>
    <?php if ($status === 401 || $status === 403): ?>
        <a class="btn btn-primary" href="<?= $e($url('/login')) ?>"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= $e($url('/')) ?>"><i class="bi bi-arrow-left"></i> Back to all recipes</a>
</main>
