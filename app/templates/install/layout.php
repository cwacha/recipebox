<?php
/**
 * Minimal page frame for the first-run setup. The regular layout assumes a configured application
 * with accounts and navigation, which does not exist yet.
 *
 * @var \Closure $e
 * @var \Closure $url
 * @var \Closure $asset
 * @var string   $title
 * @var string   $content
 * @var string   $step check|config
 */
?>
<!doctype html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E🍳%3C/text%3E%3C/svg%3E">
    <link href="<?= $e($url('/assets/vendor/bootstrap/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= $e($url('/assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= $e($url('/assets/vendor/fonts/fonts.css')) ?>" rel="stylesheet">
    <link href="<?= $e($url($asset('/assets/css/app.css'))) ?>" rel="stylesheet">
</head>
<body>

    <header class="border-bottom bg-body-tertiary">
        <div class="container py-3 d-flex flex-wrap align-items-center gap-3">
            <span class="brand fs-4"><i class="bi bi-egg-fried"></i> Recipe Box</span>
            <span class="badge text-bg-warning">Setup</span>
            <span class="ms-auto text-body-secondary small">Step <?= $step === 'config' ? '2' : '1' ?> of 2</span>
        </div>
    </header>

    <main class="container py-4 py-md-5">
        <?= $content ?>
    </main>

    <footer class="container pb-4 text-body-secondary small">
        Recipe Box setup. Nothing is written until you confirm the configuration.
    </footer>

</body>
</html>
