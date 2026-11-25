<?php
/**
 * Permissions of a role split into views (pages) and actions.
 *
 * @var \Closure $e
 * @var list<string> $permissions
 */
use App\Auth\PermissionCatalog;

$views = array_values(array_filter($permissions, PermissionCatalog::isView(...)));
$actions = array_values(array_filter($permissions, static fn (string $p): bool => !PermissionCatalog::isView($p)));
?>
<?php if ($views !== []): ?>
    <div class="mb-1"><span class="perm-label">Views</span>
        <?php foreach ($views as $p): ?><code class="perm perm-view" title="<?= $e(PermissionCatalog::describe($p)) ?>"><?= $e($p) ?></code><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($actions !== []): ?>
    <div><span class="perm-label">Actions</span>
        <?php foreach ($actions as $p): ?><code class="perm<?= $p === 'ALL' ? ' perm-all' : '' ?>" title="<?= $e(PermissionCatalog::describe($p)) ?>"><?= $e($p) ?></code><?php endforeach; ?>
    </div>
<?php endif; ?>
