<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $can
 * @var string   $section overview|accounts|roles|config
 */
$tabs = [
    'overview' => ['/admin', '/admin', 'Overview', 'bi-speedometer2'],
    'accounts' => ['/admin/accounts', '/admin/accounts', 'Accounts', 'bi-people'],
    'roles' => ['/admin/roles', '/admin/roles', 'Roles & permissions', 'bi-diagram-3'],
    'config' => ['/admin/config', '/admin/config', 'Configuration', 'bi-sliders'],
];
?>
<ul class="nav nav-tabs admin-tabs mb-4 no-print">
    <?php foreach ($tabs as $key => [$permission, $path, $label, $icon]): ?>
        <?php if ($can($permission)): ?>
            <li class="nav-item">
                <a class="nav-link<?= $section === $key ? ' active' : '' ?>" href="<?= $e($url($path)) ?>"><i class="bi <?= $icon ?>"></i> <?= $e($label) ?></a>
            </li>
        <?php endif; ?>
    <?php endforeach; ?>
</ul>
