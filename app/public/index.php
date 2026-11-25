<?php

declare(strict_types=1);

use App\Application;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dependencies missing. Run "composer install" first.');
}

require $autoload;

(new Application(debug: (bool) getenv('APP_DEBUG')))->run();
