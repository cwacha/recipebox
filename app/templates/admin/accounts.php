<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $can
 * @var array<string, array<string, mixed>> $accounts
 */
?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="recipe-title h2 mb-0"><i class="bi bi-shield-lock"></i> Administration</h1>
        <?php if ($can('/admin/account/edit')): ?>
            <a class="btn btn-primary" href="<?= $e($url('/admin/account/edit')) ?>"><i class="bi bi-person-plus"></i> New account</a>
        <?php endif; ?>
    </div>
    <?= $this->render('admin/_nav', ['section' => 'accounts']) ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle admin-table">
            <thead>
                <tr><th>Account</th><th>Name</th><th>Email</th><th>Roles</th><th>Sign-in</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $id => $account): ?>
                    <tr>
                        <td>
                            <?php if ($can('/admin/account')): ?>
                                <a class="fw-semibold" href="<?= $e($url('/admin/account?id=' . rawurlencode((string) $id))) ?>"><?= $e((string) $id) ?></a>
                            <?php else: ?>
                                <span class="fw-semibold"><?= $e((string) $id) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $e($account['fullname']) ?></td>
                        <td><?= $e($account['email']) ?></td>
                        <td>
                            <?php foreach ($account['roles'] as $role): ?><span class="tag"><?= $e($role) ?></span><?php endforeach; ?>
                            <?php if ($account['roles'] === []): ?><span class="text-body-secondary small">no role</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($account['can_sign_in']): ?>
                                <span class="badge text-bg-success">enabled</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary" title="No password is set, so this account can never sign in">disabled</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($can('/admin/account')): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('/admin/account?id=' . rawurlencode((string) $id))) ?>" title="Details"><i class="bi bi-eye"></i></a>
                            <?php endif; ?>
                            <?php if ($can('/admin/account/edit')): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('/admin/account/edit?id=' . rawurlencode((string) $id))) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if ($can('/admin/account/password')): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('/admin/account/password?id=' . rawurlencode((string) $id))) ?>" title="Password"><i class="bi bi-key"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
