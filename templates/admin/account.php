<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $can
 * @var \Closure $csrf
 * @var array<string, mixed> $account
 * @var bool $isSelf
 */
$id = $account['id'];
?>
<main class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= $e($url('/admin')) ?>">Administration</a></li>
            <?php if ($can('/admin/accounts')): ?><li class="breadcrumb-item"><a href="<?= $e($url('/admin/accounts')) ?>">Accounts</a></li><?php endif; ?>
            <li class="breadcrumb-item active" aria-current="page"><?= $e($id) ?></li>
        </ol>
    </nav>

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
            <h1 class="recipe-title h2 mb-1"><i class="bi bi-person-circle"></i> <?= $e($account['fullname'] !== '' ? $account['fullname'] : $id) ?></h1>
            <?php if ($isSelf): ?><span class="badge text-bg-primary">this is you</span><?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php if ($can('/admin/account/edit')): ?>
                <a class="btn btn-primary" href="<?= $e($url('/admin/account/edit?id=' . rawurlencode($id))) ?>"><i class="bi bi-pencil"></i> Edit</a>
            <?php endif; ?>
            <?php if ($can('/admin/account/password')): ?>
                <a class="btn btn-outline-secondary" href="<?= $e($url('/admin/account/password?id=' . rawurlencode($id))) ?>"><i class="bi bi-key"></i> Password</a>
            <?php endif; ?>
            <?php if ($can('account_delete') && !$isSelf): ?>
                <form method="post" action="<?= $e($url('/admin/account/delete')) ?>" data-confirm="Delete the account “<?= $e($id) ?>”? This cannot be undone.">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">
                    <input type="hidden" name="id" value="<?= $e($id) ?>">
                    <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash3"></i> Delete</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <h2 class="section-label h6">Details</h2>
            <dl class="row mb-0">
                <dt class="col-sm-4">Account ID</dt><dd class="col-sm-8"><code><?= $e($id) ?></code></dd>
                <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= $account['fullname'] !== '' ? $e($account['fullname']) : '<span class="text-body-secondary">–</span>' ?></dd>
                <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= $account['email'] !== '' ? '<a href="mailto:' . $e($account['email']) . '">' . $e($account['email']) . '</a>' : '<span class="text-body-secondary">–</span>' ?></dd>
                <dt class="col-sm-4">Sign-in</dt>
                <dd class="col-sm-8">
                    <?php if ($account['can_sign_in']): ?>
                        <span class="badge text-bg-success">enabled</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">disabled</span> <span class="small text-body-secondary">no password is set</span>
                    <?php endif; ?>
                </dd>
                <dt class="col-sm-4">Roles</dt>
                <dd class="col-sm-8">
                    <?php foreach ($account['roles'] as $role): ?>
                        <?php if ($can('/admin/role/edit')): ?>
                            <a class="tag text-decoration-none" href="<?= $e($url('/admin/role/edit?id=' . rawurlencode($role))) ?>"><?= $e($role) ?></a>
                        <?php else: ?>
                            <span class="tag"><?= $e($role) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($account['roles'] === []): ?><span class="text-body-secondary">no role: can only read recipes</span><?php endif; ?>
                </dd>
            </dl>
        </div>
        <div class="col-lg-7">
            <h2 class="section-label h6">What this account may do <small class="text-body-secondary fw-normal">(combined from all roles)</small></h2>
            <?php if ($account['permissions'] === []): ?>
                <p class="text-body-secondary">Nothing beyond reading recipes (<code>show</code>), which every account may always do.</p>
            <?php else: ?>
                <?= $this->render('admin/_permissions', ['permissions' => $account['permissions']]) ?>
            <?php endif; ?>
        </div>
    </div>
</main>
