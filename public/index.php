<?php

declare(strict_types=1);

use App\Application;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(500);
    exit('Dependencies missing. Run "composer install" first.');
}

require $root . '/vendor/autoload.php';

(new Application($root, debug: (bool) getenv('APP_DEBUG')))->run();
