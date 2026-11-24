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
    <h1 class="recipe-title mt-3"><?= $status === 404 ? 'Page not found' : 'Something went wrong' ?></h1>
    <p class="text-body-secondary"><?= $e($message) ?></p>
    <a class="btn btn-primary" href="<?= $e($url('/')) ?>"><i class="bi bi-arrow-left"></i> Back to all recipes</a>
</main>
