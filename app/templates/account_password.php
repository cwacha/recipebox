<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $csrf
 * @var string   $accountId
 * @var array<string, string> $errors
 */
?>
<main class="container py-4" style="max-width: 560px">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/')) ?>">All recipes</a></li>
            <li class="breadcrumb-item active" aria-current="page">Change password</li>
        </ol>
    </nav>

    <h1 class="recipe-title h2 mb-1">Change password</h1>
    <p class="text-body-secondary">Account <strong><?= $e($accountId) ?></strong></p>

    <form method="post" action="<?= $e($url('/account/password')) ?>" class="card card-body shadow-sm p-4" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">

        <?php foreach ([['current_password', 'Current password', 'current-password'], ['new_password', 'New password', 'new-password'], ['confirm_password', 'Repeat new password', 'new-password']] as [$field, $label, $auto]): ?>
            <label class="form-label" for="<?= $field ?>"><?= $e($label) ?></label>
            <input class="form-control mb-1<?= isset($errors[$field]) ? ' is-invalid' : '' ?>" type="password" id="<?= $field ?>" name="<?= $field ?>" autocomplete="<?= $auto ?>" required>
            <div class="invalid-feedback mb-2"><?= $e($errors[$field] ?? '') ?></div>
            <div class="mb-2"></div>
        <?php endforeach; ?>

        <div class="form-text mb-3">At least 8 characters. All devices where you chose “keep me signed in” will be signed out.</div>
        <div class="d-flex justify-content-end gap-2">
            <a class="btn btn-link text-body-secondary" href="<?= $e($url('/')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Change password</button>
        </div>
    </form>
</main>
