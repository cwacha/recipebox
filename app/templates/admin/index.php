<?php

/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $can
 * @var int    $accountCount
 * @var int    $signInCount
 * @var int    $roleCount
 * @var int    $permissionCount
 * @var bool   $defaultPassword
 * @var string $version           the application version from the root VERSION file
 */
$tiles = [
    ['/admin/accounts', 'bi-people', $accountCount, 'Accounts', $signInCount . ' can sign in', '/admin/accounts'],
    ['/admin/roles', 'bi-diagram-3', $roleCount, 'Roles', $permissionCount . ' permissions in total', '/admin/roles'],
];
?>
<main class="container py-4">
    <h1 class="recipe-title h2 mb-3"><i class="bi bi-shield-lock"></i> Administration</h1>
    <?= $this->render('admin/_nav', ['section' => 'overview']) ?>

    <?php if ($defaultPassword): ?>
        <div class="alert alert-warning d-flex gap-3 align-items-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-4"></i>
            <div>
                <strong>The administrator still uses the default password.</strong>
                Anybody who knows it can manage this installation.
                <a class="alert-link" href="<?= $e($url('/admin/account/password?id=admin')) ?>">Set a new password now</a>.
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <?php foreach ($tiles as [$permission, $icon, $count, $label, $detail, $path]): ?>
            <?php if ($can($permission)): ?>
                <div class="col-md-6">
                    <a class="card admin-tile h-100 text-decoration-none" href="<?= $e($url($path)) ?>">
                        <div class="card-body d-flex align-items-center gap-3">
                            <i class="bi <?= $icon ?> display-5 text-primary"></i>
                            <div>
                                <div class="display-6 lh-1"><?= $count ?></div>
                                <div class="fw-semibold"><?= $e($label) ?></div>
                                <div class="small text-body-secondary"><?= $e($detail) ?></div>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <h2 class="section-label h6">Quick actions</h2>
    <div class="d-flex flex-wrap gap-2 mb-4">
        <?php if ($can('/admin/account/edit')): ?>
            <a class="btn btn-primary" href="<?= $e($url('/admin/account/edit')) ?>"><i class="bi bi-person-plus"></i> New account</a>
        <?php endif; ?>
        <?php if ($can('/admin/role/edit')): ?>
            <a class="btn btn-outline-primary" href="<?= $e($url('/admin/role/edit')) ?>"><i class="bi bi-plus-circle"></i> New role</a>
        <?php endif; ?>
        <?php if ($can('/admin/config')): ?>
            <a class="btn btn-outline-secondary" href="<?= $e($url('/admin/config')) ?>"><i class="bi bi-sliders"></i> Configuration</a>
        <?php endif; ?>
    </div>

    <h2 class="section-label h6">How access works</h2>
    <ul class="text-body-secondary">
        <li>An <strong>account</strong> signs in with its ID and password. It is given one or more <strong>roles</strong>.</li>
        <li>A <strong>role</strong> is a list of <strong>permissions</strong>. <em>Views</em> start with a slash (<code>/admin/roles</code>) and allow opening a page; <em>actions</em> are plain words (<code>recipe_delete</code>) and allow doing something.</li>
        <li>An account may do everything that any of its roles allows. Everybody who is identified may always <code>show</code> recipes.</li>
        <li>The role <code>all</code> lists every permission that exists. It is kept up to date automatically.</li>
    </ul>
</main>
<footer class="py-4 text-center text-body-secondary small no-print">
    Recipe Box <span class="mx-3"></span> <i class="bi bi-tag"></i> Version <?= $e($version) ?>
</footer>