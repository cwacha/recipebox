<?php

declare(strict_types=1);

namespace Tests;

use App\Repository\RecipeRepositoryInterface;
use App\Repository\SqliteRecipeRepository;

final class SqliteRecipeRepositoryTest extends RecipeRepositoryContract
{
    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }
        parent::setUp();
    }

    protected function createRepository(string $dir): RecipeRepositoryInterface
    {
        return new SqliteRecipeRepository($dir . '/recipes.sqlite');
    }
}
