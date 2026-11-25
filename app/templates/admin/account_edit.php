<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $csrf
 * @var array{isNew: bool, id: string, fullname: string, email: string, roles: list<string>} $form
 * @var array<string, string> $errors
 * @var list<string> $allRoles
 */
$isNew = $form['isNew'];
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
?>
<main class="container py-4" style="max-width: 760px">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin')) ?>">Administration</a></li>
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin/accounts')) ?>">Accounts</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $isNew ? 'New account' : 'Edit ' . $e($form['id']) ?></li>
        </ol>
    </nav>

    <h1 class="recipe-title h2 mb-4"><?= $isNew ? 'New account' : 'Edit account' ?></h1>

    <?php if (isset($errors['form'])): ?>
        <div class="alert alert-danger" role="alert"><?= $e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= $e($url('/admin/account/edit')) ?>" class="card card-body shadow-sm p-4" autocomplete="off" novalidate>
        <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">
        <input type="hidden" name="mode" value="<?= $isNew ? 'new' : 'edit' ?>">

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label" for="id">Account ID *</label>
                <?php if ($isNew): ?>
                    <input class="form-control<?= $invalid('id') ?>" id="id" name="id" value="<?= $e($form['id']) ?>" maxlength="64" autocapitalize="none" required autofocus>
                    <div class="invalid-feedback"><?= $e($errors['id'] ?? '') ?></div>
                    <div class="form-text">Lowercase letters, digits and . _ @ -</div>
                <?php else: ?>
                    <input class="form-control" id="id" value="<?= $e($form['id']) ?>" disabled>
                    <input type="hidden" name="id" value="<?= $e($form['id']) ?>">
                <?php endif; ?>
            </div>
            <div class="col-md-7">
                <label class="form-label" for="fullname">Full name</label>
                <input class="form-control<?= $invalid('fullname') ?>" id="fullname" name="fullname" value="<?= $e($form['fullname']) ?>" maxlength="100"<?= $isNew ? '' : ' autofocus' ?>>
                <div class="invalid-feedback"><?= $e($errors['fullname'] ?? '') ?></div>
            </div>
            <div class="col-12">
                <label class="form-label" for="email">Email</label>
                <input class="form-control<?= $invalid('email') ?>" type="email" id="email" name="email" value="<?= $e($form['email']) ?>" maxlength="254">
                <div class="invalid-feedback"><?= $e($errors['email'] ?? '') ?></div>
            </div>

            <?php if ($isNew): ?>
                <div class="col-md-6">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control<?= $invalid('password') ?>" type="password" id="password" name="password" autocomplete="new-password">
                    <div class="invalid-feedback"><?= $e($errors['password'] ?? '') ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="confirm_password">Repeat password</label>
                    <input class="form-control<?= $invalid('confirm_password') ?>" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password">
                    <div class="invalid-feedback"><?= $e($errors['confirm_password'] ?? '') ?></div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="no_password" name="no_password" value="1">
                        <label class="form-check-label" for="no_password">No password: this account can never sign in</label>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-12 small text-body-secondary">The password is changed on a separate page.</div>
            <?php endif; ?>

            <div class="col-12">
                <h2 class="section-label h6 mt-2">Roles</h2>
                <?php if (isset($errors['roles'])): ?><div class="text-danger small mb-2"><?= $e($errors['roles']) ?></div><?php endif; ?>
                <div class="row g-2">
                    <?php foreach ($allRoles as $role): ?>
                        <div class="col-sm-6 col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="role-<?= $e($role) ?>" name="roles[]" value="<?= $e($role) ?>"<?= in_array($role, $form['roles'], true) ? ' checked' : '' ?>>
                                <label class="form-check-label" for="role-<?= $e($role) ?>"><code><?= $e($role) ?></code></label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="form-text">The account may do everything that any of its roles allows.</div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4 pt-3 border-top">
            <a class="btn btn-link text-body-secondary" href="<?= $e($url($isNew ? '/admin/accounts' : '/admin/account?id=' . rawurlencode($form['id']))) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save account</button>
        </div>
    </form>
</main>
