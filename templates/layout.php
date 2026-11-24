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
 */
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?></title>
    <meta name="description" content="<?= $e($description) ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E🍳%3C/text%3E%3C/svg%3E">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="<?= $e($url('/assets/css/app.css')) ?>" rel="stylesheet">
    <script>
        (function () {
            var t = localStorage.getItem('theme');
            if (t) document.documentElement.setAttribute('data-bs-theme', t);
        })();
        window.APP = <?= json_encode(['base' => $basePath], JSON_THROW_ON_ERROR) ?>;
    </script>
</head>
<body>

<nav class="navbar navbar-expand-md app-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand brand" href="<?= $e($url('/')) ?>"><i class="bi bi-egg-fried"></i> Recipe Box</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <div class="ms-auto d-flex flex-wrap gap-2 py-2 py-md-0">
                <a class="btn btn-primary<?= $active === 'new' ? ' active' : '' ?>" href="<?= $e($url('/recipes/new')) ?>"><i class="bi bi-plus-lg"></i> New recipe</a>
                <a class="btn btn-outline-secondary<?= $active === 'import' ? ' active' : '' ?>" href="<?= $e($url('/import')) ?>"><i class="bi bi-upload"></i> Import JSON</a>
                <a class="btn btn-outline-secondary" href="<?= $e($url('/api/recipes/export')) ?>"><i class="bi bi-download"></i> Export all</a>
                <button class="btn btn-outline-secondary" id="btnTheme" type="button" title="Toggle dark mode" aria-label="Toggle dark mode"><i class="bi bi-moon-stars"></i></button>
            </div>
        </div>
    </div>
</nav>

<?= $content ?>

<footer class="py-4 text-center text-body-secondary small no-print">
    Recipe Box · PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?> · Bootstrap · jQuery
</footer>

<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toasts"></div>

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $e($url('/assets/js/app.js')) ?>"></script>
<?php foreach ($scripts as $script): ?>
<script src="<?= $e($url('/assets/js/' . $script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
