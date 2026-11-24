<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $csrf
 * @var array<string, mixed> $account
 * @var bool $isSelf
 * @var array<string, string> $errors
 */
$id = $account['id'];
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
?>
<main class="container py-4" style="max-width: 600px">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin')) ?>">Administration</a></li>
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin/accounts')) ?>">Accounts</a></li>
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin/account?id=' . rawurlencode($id))) ?>"><?= $e($id) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Password</li>
        </ol>
    </nav>

    <h1 class="recipe-title h2 mb-1">Set password</h1>
    <p class="text-body-secondary">Account <strong><?= $e($id) ?></strong><?= $isSelf ? ' (you)' : '' ?></p>

    <?php if (isset($errors['form'])): ?>
        <div class="alert alert-danger" role="alert"><?= $e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($url('/admin/account/password')) ?>" class="card card-body shadow-sm p-4" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">
        <input type="hidden" name="id" value="<?= $e($id) ?>">

        <label class="form-label" for="new_password">New password</label>
        <input class="form-control<?= $invalid('new_password') ?>" type="password" id="new_password" name="new_password" autocomplete="new-password" autofocus>
        <div class="invalid-feedback"><?= $e($errors['new_password'] ?? '') ?></div>

        <label class="form-label mt-3" for="confirm_password">Repeat new password</label>
        <input class="form-control<?= $invalid('confirm_password') ?>" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
        <div class="invalid-feedback"><?= $e($errors['confirm_password'] ?? '') ?></div>

        <div class="form-text mt-2">At least 8 characters. Devices where this account chose “keep me signed in” are signed out.</div>

        <?php if (!$isSelf): ?>
            <hr>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="disable" name="disable" value="1">
                <label class="form-check-label" for="disable">Disable sign-in instead (no password, the account can never sign in)</label>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-end gap-2 mt-4">
            <a class="btn btn-link text-body-secondary" href="<?= $e($url('/admin/account?id=' . rawurlencode($id))) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save password</button>
        </div>
    </form>
</main>
