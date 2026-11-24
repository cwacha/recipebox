<?php

declare(strict_types=1);

$state = dirname(__DIR__) . '/var/state';

return [
    // Persistence backend: "json" (single file) or "sqlite" (database, needs the pdo_sqlite extension).
    // Use "php bin/migrate json sqlite" to copy existing recipes when switching.
    'storage' => getenv('RECIPES_STORAGE') ?: 'json',

    'json_file' => getenv('RECIPES_STATE_FILE') ?: $state . '/recipes.json',
    // JSON import downloads pictures from other servers into public/images. For safety, addresses inside
    // private networks (localhost, 192.168.x.x, ...) are refused; set to true to import from your own network.
    'import_allow_private_hosts' => filter_var(getenv('RECIPES_IMPORT_ALLOW_PRIVATE_HOSTS'), FILTER_VALIDATE_BOOLEAN),

    'sqlite_file' => getenv('RECIPES_SQLITE_FILE') ?: $state . '/recipes.sqlite',
];
