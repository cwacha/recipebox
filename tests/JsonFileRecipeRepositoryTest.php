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

    public function testRecipesWithoutSlugGetOneOnFirstLoad(): void
    {
        mkdir($this->dir, 0775, true);
        $legacy = static fn (string $id, string $title): array => [
            'id' => $id, 'title' => $title, 'cooking_time' => 5,
            'ingredients' => [['quantity' => '', 'name' => 'x']], 'steps' => ['y'],
            'created_at' => '2026-01-01T10:00:00+00:00', 'updated_at' => '2026-01-01T10:00:00+00:00',
        ];
        file_put_contents($this->dir . '/recipes.json', json_encode(['recipes' => [$legacy('a', 'Pasta'), $legacy('b', 'Pasta')]]));

        $repo = $this->createRepository($this->dir);

        self::assertSame('pasta', $repo->find('a')?->slug);
        self::assertSame('pasta-2', $repo->find('b')?->slug);
        self::assertSame('2026-01-01T10:00:00+00:00', $repo->find('a')?->updatedAt, 'migration does not touch timestamps');
        self::assertStringContainsString('"slug": "pasta-2"', (string) file_get_contents($this->dir . '/recipes.json'));
    }
}
