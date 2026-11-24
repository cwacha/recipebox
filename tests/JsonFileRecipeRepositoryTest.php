<?php

declare(strict_types=1);

namespace Tests;

use App\Repository\JsonFileRecipeRepository;
use App\Repository\RecipeRepositoryInterface;

final class JsonFileRecipeRepositoryTest extends RecipeRepositoryContract
{
    protected function createRepository(string $dir): RecipeRepositoryInterface
    {
        return new JsonFileRecipeRepository($dir . '/recipes.json');
    }
}
