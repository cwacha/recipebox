<?php

declare(strict_types=1);

namespace Tests;

use App\Model\Recipe;
use App\Repository\RecipeRepositoryInterface;
use PHPUnit\Framework\TestCase;

/** Behaviour every storage backend has to provide. Concrete subclasses supply the repository. */
abstract class RecipeRepositoryContract extends TestCase
{
    protected string $dir;

    abstract protected function createRepository(string $dir): RecipeRepositoryInterface;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-repo-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testStartsEmpty(): void
    {
        $repo = $this->createRepository($this->dir);

        self::assertSame([], $repo->all());
        self::assertNull($repo->find('nope'));
        self::assertFalse($repo->delete('nope'));
    }

    public function testRoundTripKeepsAllFields(): void
    {
        $repo = $this->createRepository($this->dir);
        $saved = $repo->save($this->recipe('r1', 'Käse-Spätzle "special"', '2026-01-01T10:00:00+00:00'));

        // a new instance proves the data really was persisted
        $loaded = $this->createRepository($this->dir)->find('r1');

        self::assertEquals($saved, $loaded);
        self::assertSame('kaese-spaetzle-special', $loaded->slug);
        self::assertSame('r1', $this->createRepository($this->dir)->findBySlug('kaese-spaetzle-special')?->id);
        self::assertSame(4, $loaded->jsonSerialize()['servings']);
    }

    public function testSaveUpdatesExistingRecipe(): void
    {
        $repo = $this->createRepository($this->dir);
        $original = $repo->save($this->recipe('r1', 'Soup', '2026-01-01T10:00:00+00:00'));
        $repo->save($original->withData([
            'title' => 'Better soup',
            'cooking_time' => 45,
            'ingredients' => [['quantity' => '', 'name' => 'x']],
            'steps' => ['y'],
        ]));

        $all = $repo->all();
        self::assertCount(1, $all);
        self::assertSame('Better soup', $all[0]->title);
        self::assertSame($original->createdAt, $all[0]->createdAt);
        self::assertSame('soup', $all[0]->slug, 'the URL stays stable when the title changes');
        self::assertNotNull($repo->findBySlug('soup'));
    }

    public function testSlugsAreUniqueAndAvoidReservedRoutes(): void
    {
        $repo = $this->createRepository($this->dir);
        $saved = $repo->saveMany([
            $this->recipe('a', 'Pasta', '2026-01-01T10:00:00+00:00'),
            $this->recipe('b', 'Pasta!', '2026-01-02T10:00:00+00:00'),
            $this->recipe('c', 'New', '2026-01-03T10:00:00+00:00'),
        ]);
        $more = $repo->save($this->recipe('d', 'pasta', '2026-01-04T10:00:00+00:00'));

        self::assertSame(['pasta', 'pasta-2', 'new-recipe'], array_map(static fn (Recipe $r): string => $r->slug, $saved));
        self::assertSame('pasta-3', $more->slug);

        $repo->delete('b');
        self::assertSame('pasta-2', $repo->save($this->recipe('e', 'Pasta', '2026-01-05T10:00:00+00:00'))->slug, 'freed slugs can be reused');
        self::assertNull($repo->findBySlug('unknown'));
    }

    public function testListsNewestFirstAndDeletes(): void
    {
        $repo = $this->createRepository($this->dir);
        $repo->saveMany([
            $this->recipe('a', 'Old', '2026-01-01T10:00:00+00:00'),
            $this->recipe('b', 'New', '2026-03-01T10:00:00+00:00'),
            $this->recipe('c', 'Middle', '2026-02-01T10:00:00+00:00'),
        ]);

        self::assertSame(['b', 'c', 'a'], array_map(static fn (Recipe $r): string => $r->id, $repo->all()));
        self::assertTrue($repo->delete('c'));
        self::assertNull($repo->find('c'));
        self::assertCount(2, $repo->all());
    }

    public function testSearchRankingAndCategories(): void
    {
        $repo = $this->createRepository($this->dir);
        $repo->saveMany([
            $this->recipe('a', 'Tomato soup', '2026-01-01T10:00:00+00:00', category: 'Soup', ingredients: ['tomatoes']),
            $this->recipe('b', 'Salad', '2026-01-02T10:00:00+00:00', category: 'Salad', ingredients: ['tomatoes', 'feta']),
            $this->recipe('c', 'Pancakes', '2026-01-03T10:00:00+00:00', category: 'soup', ingredients: ['flour']),
        ]);

        self::assertSame(['a', 'b'], $this->ids($repo->search('tomato')), 'title match ranks above ingredient match');
        self::assertSame(['b'], $this->ids($repo->search('TOMATO feta')));
        self::assertSame([], $this->ids($repo->search('tomato flour')));
        self::assertSame(['c', 'a'], $this->ids($repo->search('', 'SOUP')), 'category filter is case-insensitive, newest first');
        self::assertSame(['Salad', 'soup'], $repo->categories(), 'spelling variants are merged, newest recipe wins');
    }

    public function testGeneratesUniqueIds(): void
    {
        $repo = $this->createRepository($this->dir);
        $id = $repo->nextId();
        self::assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $id);
        $repo->save($this->recipe($id, 'x', '2026-01-01T10:00:00+00:00'));
        self::assertNotSame($id, $repo->nextId());
    }

    /**
     * @param  list<string> $ingredients
     */
    protected function recipe(string $id, string $title, string $updated, string $category = 'Dinner', array $ingredients = ['salt']): Recipe
    {
        return Recipe::fromArray([
            'id' => $id,
            'title' => $title,
            'description' => 'Tastes “great” & simple',
            'category' => $category,
            'image' => 'images/' . $id . '.jpg',
            'cooking_time' => 30,
            'ingredients' => array_map(static fn (string $n): array => ['quantity' => '1 tsp', 'name' => $n], $ingredients),
            'steps' => ['First', 'Second'],
            'tags' => ['quick', 'vegan'],
            'created_at' => '2026-01-01T09:00:00+00:00',
            'updated_at' => $updated,
        ]);
    }

    /**
     * @param  list<Recipe> $recipes
     * @return list<string>
     */
    private function ids(array $recipes): array
    {
        return array_map(static fn (Recipe $r): string => $r->id, $recipes);
    }
}
