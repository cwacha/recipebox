<?php
/**
 * @var \Closure $e
 * @var \Closure $url
 * @var string   $content
 * @var string   $title
 * @var string   $active
 * @var string   $description
 * @var list<string> $scripts
 * @var string   $basePath
 * @var string   $appTitle    the configured site title
 * @var bool      $authEnabled
 * @var ?array    $user       the signed in account (not a guest)
 * @var bool      $isGuest
 * @var \Closure  $can        can('/admin') tells whether the current account may use that view or action
 * @var \Closure  $csrf
 * @var \Closure  $flash      pending messages for this visitor (can only be read once)
 * @var \Closure  $asset      asset('/assets/js/app.js') adds a version so browsers pick up changed files
 */
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?></title>
    <meta name="description" content="<?= $e($description) ?>">
    <?php if ($user !== null): ?>
        <meta name="csrf-token" content="<?= $e($csrf()) ?>">
    <?php endif; ?>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E🍳%3C/text%3E%3C/svg%3E">
    <link href="<?= $e($url('/assets/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= $e($url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= $e($url('/assets/vendor/fonts/fonts.css')) ?>" rel="stylesheet">
    <link href="<?= $e($url($asset('/assets/css/app.css'))) ?>" rel="stylesheet">
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (t) document.documentElement.setAttribute('data-bs-theme', t);
        })();
        window.APP = <?= json_encode(['base' => $basePath, 'flash' => $flash()], JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    </script>
</head>
<body>

    <nav class="navbar navbar-expand-md app-navbar sticky-top">
        <div class="container">
            <a class="navbar-brand brand" href="<?= $e($url('/')) ?>"><i class="bi bi-egg-fried"></i> <?= $e($appTitle) ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="nav">
                <div class="ms-auto d-flex flex-wrap gap-2 py-2 py-md-0 align-items-center">
                    <?php if ($can('/recipes/new')): ?>
                        <a class="btn btn-primary<?= $active === 'new' ? ' active' : '' ?>" href="<?= $e($url('/recipes/new')) ?>"><i class="bi bi-plus-lg"></i> New recipe</a>
                    <?php endif; ?>
                    <?php if ($can('/import')): ?>
                        <a class="btn btn-outline-secondary<?= $active === 'import' ? ' active' : '' ?>" href="<?= $e($url('/import')) ?>"><i class="bi bi-upload"></i> Import</a>
                    <?php endif; ?>
                    <?php if ($can('recipe_export_all')): ?>
                        <a class="btn btn-outline-secondary" href="<?= $e($url('/api/recipes/export')) ?>" title="All recipes with pictures as ZIP"><i class="bi bi-file-zip"></i> Export all</a>
                    <?php endif; ?>
                    <?php if ($can('/admin')): ?>
                        <a class="btn btn-outline-secondary<?= $active === 'admin' ? ' active' : '' ?>" href="<?= $e($url('/admin')) ?>"><i class="bi bi-shield-lock"></i> Admin</a>
                    <?php endif; ?>
                    <button class="btn btn-outline-secondary" id="btnTheme" type="button" title="Toggle dark mode" aria-label="Toggle dark mode"><i class="bi bi-moon-stars"></i></button>
                    <?php if ($user !== null): ?>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle"></i> <?= $e($user['fullname'] !== '' ? $user['fullname'] : $user['id']) ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><span class="dropdown-item-text small text-body-secondary">Signed in as <strong><?= $e($user['id']) ?></strong></span></li>
                                <?php if ($can('/account/password')): ?>
                                    <li><a class="dropdown-item" href="<?= $e($url('/account/password')) ?>"><i class="bi bi-key"></i> Change password</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="post" action="<?= $e($url('/logout')) ?>">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrf()) ?>">
                                        <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right"></i> Sign out</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    <?php elseif ($authEnabled && $active !== 'login'): ?>
                        <a class="btn btn-outline-primary" href="<?= $e($url('/login')) ?>"><i class="bi bi-box-arrow-in-right"></i> Sign in</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <?= $content ?>

    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>

    <script src="<?= $e($url('/assets/vendor/jquery/jquery.min.js')) ?>"></script>
    <script src="<?= $e($url('/assets/vendor/bootstrap/js/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= $e($url($asset('/assets/js/app.js'))) ?>"></script>
    <?php foreach ($scripts as $script): ?>
        <script src="<?= $e($url($asset('/assets/js/' . $script))) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
