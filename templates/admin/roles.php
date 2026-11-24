<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $can
 * @var array<string, list<string>> $roles   role ID => permissions
 * @var array<string, list<string>> $members role ID => account IDs
 */
?>
<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="recipe-title h2 mb-0"><i class="bi bi-shield-lock"></i> Administration</h1>
        <?php if ($can('/admin/role/edit')): ?>
            <a class="btn btn-primary" href="<?= $e($url('/admin/role/edit')) ?>"><i class="bi bi-plus-circle"></i> New role</a>
        <?php endif; ?>
    </div>
    <?= $this->render('admin/_nav', ['section' => 'roles']) ?>

    <div class="vstack gap-3">
        <?php foreach ($roles as $roleId => $permissions): ?>
            <?php $roleId = (string) $roleId; ?>
            <section class="card shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <div>
                            <h2 class="h5 mb-0"><code><?= $e($roleId) ?></code>
                                <?php if ($roleId === 'all'): ?><span class="badge text-bg-secondary align-middle" title="Lists every permission that exists. Maintained automatically.">built in</span><?php endif; ?>
                            </h2>
                            <div class="small text-body-secondary">
                                <?= count($permissions) ?> permissions ·
                                <?php if ($members[$roleId] === []): ?>
                                    no accounts
                                <?php else: ?>
                                    accounts:
                                    <?php foreach ($members[$roleId] as $member): ?>
                                        <?php if ($can('/admin/account')): ?>
                                            <a href="<?= $e($url('/admin/account?id=' . rawurlencode($member))) ?>"><?= $e($member) ?></a>
                                        <?php else: ?><?= $e($member) ?><?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($can('/admin/role/edit')): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('/admin/role/edit?id=' . rawurlencode($roleId))) ?>"><i class="bi bi-<?= $roleId === 'all' ? 'eye' : 'pencil' ?>"></i> <?= $roleId === 'all' ? 'View' : 'Edit' ?></a>
                        <?php endif; ?>
                    </div>
                    <?= $this->render('admin/_permissions', ['permissions' => $permissions]) ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</main>
