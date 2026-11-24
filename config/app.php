<?php

declare(strict_types=1);

$state = dirname(__DIR__) . '/var/state';

return [
    // Persistence backend: "json" (single file) or "sqlite" (database, needs the pdo_sqlite extension).
    // Use "php bin/migrate json sqlite" to copy existing recipes when switching.
    'storage' => getenv('RECIPES_STORAGE') ?: 'json',

    'json_file' => getenv('RECIPES_STATE_FILE') ?: $state . '/recipes.json',
    'sqlite_file' => getenv('RECIPES_SQLITE_FILE') ?: $state . '/recipes.sqlite',
];
