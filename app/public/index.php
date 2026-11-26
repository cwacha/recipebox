<?php

declare(strict_types=1);

use App\Application;

$basedir = dirname(__DIR__);
$autoload = $basedir . '/vendor/autoload.php';

if (!is_file($autoload)) {
    // Composer has not run yet. The setup check itself only needs the constants and the requirements
    // class, so show the same first-run page instead of a bare error message.
    http_response_code(500);
    require $basedir . '/src/constants.php';
    require $basedir . '/src/Install/Requirements.php';

    $report = (new \App\Install\Requirements())->run();
    $e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $basePath = rtrim(dirname($script), '/');
    if ($basePath === '.' || $basePath === '/') {
        $basePath = '';
    }
    $url = static fn (string $path = '/'): string => $basePath . $path;
    $asset = static fn (string $path): string => $path;
    $title = 'Setup – Recipe Box';
    $step = 'check';
    $content = (static function () use ($report, $e, $url): string {
        ob_start();
        require $basedir . '/templates/install/check.php';

        return (string) ob_get_clean();
    })();
    require $basedir . '/templates/install/layout.php';
    exit;
}

require $autoload;

(new Application(debug: (bool) getenv('APP_DEBUG')))->run();
