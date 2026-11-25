<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $csrf
 * @var string   $next
 * @var string   $username
 * @var ?string  $error
 * @var bool     $guestsAllowed
 */
?>
<main class="container py-5" style="max-width: 460px">
    <div class="text-center mb-4">
        <div class="display-4">🍳</div>
        <h1 class="recipe-title h2">Sign in</h1>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger" role="alert"><?= $e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($url('/login')) ?>" class="card card-body shadow-sm p-4">
        <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">
        <input type="hidden" name="next" value="<?= $e($next) ?>">

        <label class="form-label" for="username">Account</label>
        <input class="form-control mb-3" id="username" name="username" value="<?= $e($username) ?>" autocomplete="username" autocapitalize="none" required autofocus>

        <label class="form-label" for="password">Password</label>
        <input class="form-control mb-3" type="password" id="password" name="password" autocomplete="current-password" required>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
            <label class="form-check-label" for="remember">Keep me signed in on this device</label>
        </div>

        <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
    </form>

    <?php if ($guestsAllowed): ?>
        <p class="text-center mt-3"><a href="<?= $e($url('/')) ?>">Continue as guest</a> <span class="text-body-secondary small">(read only)</span></p>
    <?php endif; ?>
</main>
