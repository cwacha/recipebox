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

    public function testUpgradesDatabaseCreatedBeforeSlugsExisted(): void
    {
        mkdir($this->dir, 0775, true);
        $pdo = new \PDO('sqlite:' . $this->dir . '/recipes.sqlite');
        $pdo->exec('CREATE TABLE recipes (id TEXT PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT \'\',
            category TEXT NOT NULL DEFAULT \'\', image TEXT, cooking_time INTEGER NOT NULL, ingredients TEXT NOT NULL,
            steps TEXT NOT NULL, tags TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO recipes (id, title, cooking_time, ingredients, steps, tags, created_at, updated_at)
            VALUES (?, ?, 5, \'[{"quantity":"","name":"x"}]\', \'["y"]\', \'[]\', \'2026-01-01T10:00:00+00:00\', \'2026-01-01T10:00:00+00:00\')');
        $insert->execute(['a', 'Pasta']);
        $insert->execute(['b', 'Pasta']);
        unset($pdo, $insert);

        $repo = $this->createRepository($this->dir);

        self::assertSame('pasta', $repo->find('a')?->slug);
        self::assertSame('pasta-2', $repo->find('b')?->slug);
        self::assertSame('2026-01-01T10:00:00+00:00', $repo->find('a')?->updatedAt);
        self::assertSame('b', $this->createRepository($this->dir)->findBySlug('pasta-2')?->id);
    }
}
