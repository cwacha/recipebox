<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $csrf
 * @var \Closure $can
 * @var array{isNew: bool, id: string, permissions: list<string>, extra: string} $form
 * @var array<string, string> $errors
 * @var list<string> $known    every permission that exists
 * @var list<string> $custom   permissions of this role that are not known yet
 * @var list<string> $members  accounts that have the role
 * @var bool $isProtected
 */
use App\Auth\PermissionCatalog;

$isNew = $form['isNew'];
$groups = [
    'Views (pages)' => array_values(array_filter($known, PermissionCatalog::isView(...))),
    'Actions' => array_values(array_filter($known, static fn (string $p): bool => !PermissionCatalog::isView($p))),
];
$invalid = static fn (string $field): string => isset($errors[$field]) ? ' is-invalid' : '';
?>
<main class="container py-4" style="max-width: 860px">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin')) ?>">Administration</a></li>
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin/roles')) ?>">Roles</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?= $isNew ? 'New role' : $e($form['id']) ?></li>
        </ol>
    </nav>

    <h1 class="recipe-title h2 mb-4"><?= $isNew ? 'New role' : ($isProtected ? 'Role ' . $e($form['id']) : 'Edit role') ?></h1>

    <?php if (isset($errors['form'])): ?>
        <div class="alert alert-danger" role="alert"><?= $e($errors['form']) ?></div>
    <?php endif; ?>
    <?php if ($isProtected): ?>
        <div class="alert alert-info"><i class="bi bi-info-circle"></i> The role <code>all</code> lists every permission that exists. It is maintained automatically and cannot be changed or deleted.</div>
    <?php endif; ?>

    <form method="post" action="<?= $e($url('/admin/role/edit')) ?>" class="card card-body shadow-sm p-4" novalidate>
        <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">
        <input type="hidden" name="mode" value="<?= $isNew ? 'new' : 'edit' ?>">

        <div class="mb-3" style="max-width: 320px">
            <label class="form-label" for="id">Role ID *</label>
            <?php if ($isNew): ?>
                <input class="form-control<?= $invalid('id') ?>" id="id" name="id" value="<?= $e($form['id']) ?>" maxlength="32" autocapitalize="none" required autofocus>
                <div class="invalid-feedback"><?= $e($errors['id'] ?? '') ?></div>
                <div class="form-text">Lowercase letters, digits, - and _</div>
            <?php else: ?>
                <input class="form-control" id="id" value="<?= $e($form['id']) ?>" disabled>
                <input type="hidden" name="id" value="<?= $e($form['id']) ?>">
            <?php endif; ?>
        </div>

        <h2 class="section-label h6">Permissions</h2>
        <?php if (isset($errors['permissions'])): ?><div class="text-danger small mb-2"><?= $e($errors['permissions']) ?></div><?php endif; ?>

        <?php foreach ($groups as $label => $permissions): ?>
            <div class="mb-3">
                <div class="small fw-semibold text-body-secondary mb-1"><?= $e($label) ?></div>
                <div class="row g-1">
                    <?php foreach ($permissions as $permission): ?>
                        <?php $fieldId = 'perm-' . md5($permission); ?>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="<?= $fieldId ?>" name="permissions[]" value="<?= $e($permission) ?>"
                                    <?= in_array($permission, $form['permissions'], true) || $isProtected ? 'checked' : '' ?><?= $isProtected ? ' disabled' : '' ?>>
                                <label class="form-check-label" for="<?= $fieldId ?>">
                                    <code class="perm <?= PermissionCatalog::isView($permission) ? 'perm-view' : ($permission === 'ALL' ? 'perm-all' : '') ?>"><?= $e($permission) ?></code>
                                    <span class="small text-body-secondary d-block"><?= $e(PermissionCatalog::describe($permission)) ?></span>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$isProtected): ?>
            <?php if ($custom !== []): ?>
                <div class="mb-3">
                    <div class="small fw-semibold text-body-secondary mb-1">Not listed above</div>
                    <?php foreach ($custom as $permission): ?>
                        <?php $fieldId = 'perm-' . md5($permission); ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="<?= $fieldId ?>" name="permissions[]" value="<?= $e($permission) ?>" checked>
                            <label class="form-check-label" for="<?= $fieldId ?>"><code class="perm"><?= $e($permission) ?></code></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="mb-2">
                <label class="form-label" for="extra">Additional permissions</label>
                <textarea class="form-control font-monospace" id="extra" name="extra" rows="2" placeholder="/reports, report_export"><?= $e($form['extra']) ?></textarea>
                <div class="form-text">
                    Separate with commas or spaces. A permission exists as soon as a role mentions it, and then also appears in the role <code>all</code>.
                    Views start with a slash. <code>ALL</code> grants everything.
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$isNew): ?>
            <div class="small text-body-secondary mt-2">
                Accounts with this role:
                <?php if ($members === []): ?>none<?php endif; ?>
                <?php foreach ($members as $member): ?>
                    <?php if ($can('/admin/account')): ?><a class="tag text-decoration-none" href="<?= $e($url('/admin/account?id=' . rawurlencode($member))) ?>"><?= $e($member) ?></a><?php else: ?><span class="tag"><?= $e($member) ?></span><?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
            <div>
                <?php if (!$isNew && !$isProtected && $can('role_delete')): ?>
                    <button class="btn btn-outline-danger" type="submit" form="deleteRole"><i class="bi bi-trash3"></i> Delete role</button>
                <?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-link text-body-secondary" href="<?= $e($url('/admin/roles')) ?>"><?= $isProtected ? 'Back' : 'Cancel' ?></a>
                <?php if (!$isProtected): ?>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i> Save role</button>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if (!$isNew && !$isProtected && $can('role_delete')): ?>
        <form id="deleteRole" method="post" action="<?= $e($url('/admin/role/delete')) ?>" data-confirm="Delete the role “<?= $e($form['id']) ?>”? It is removed from <?= count($members) ?> account(s).">
            <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">
            <input type="hidden" name="id" value="<?= $e($form['id']) ?>">
        </form>
    <?php endif; ?>
</main>
