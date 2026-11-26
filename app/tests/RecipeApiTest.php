<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Http\Request;
use App\Repository\JsonFileRecipeRepository;
use PHPUnit\Framework\TestCase;

final class RecipeApiTest extends TestCase
{
    private string $dir;
    private Application $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-test-' . bin2hex(random_bytes(4));
        $this->app = new Application(
            new JsonFileRecipeRepository($this->dir . '/recipes.json'),
            config: ['auth_enabled' => false],
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testCrudAndSearchLifecycle(): void
    {
        $pasta = $this->call('POST', '/api/recipes', $this->recipe('Tomato Pasta', ['400 g spaghetti', '1 tin tomatoes'], ['italian']));
        self::assertSame(201, $pasta['status']);
        $id = $pasta['json']['id'];

        $this->call('POST', '/api/recipes', $this->recipe('Pancakes', ['flour', 'milk'], ['breakfast'], 'Breakfast'));

        self::assertCount(2, $this->call('GET', '/api/recipes')['json']['recipes']);

        $byIngredient = $this->call('GET', '/api/recipes', query: ['q' => 'SPAGHETTI'])['json']['recipes'];
        self::assertSame([$id], array_column($byIngredient, 'id'));

        self::assertSame([], $this->call('GET', '/api/recipes', query: ['q' => 'pasta milk'])['json']['recipes']);
        self::assertSame(['Breakfast'], $this->call('GET', '/api/recipes', query: ['category' => 'breakfast'])['json']['categories']);

        $updated = $this->call('PUT', "/api/recipes/$id", $this->recipe('Tomato Penne', ['penne'], []));
        self::assertSame('Tomato Penne', $updated['json']['title']);
        self::assertSame($pasta['json']['created_at'], $updated['json']['created_at']);

        self::assertSame(204, $this->call('DELETE', "/api/recipes/$id")['status']);
        self::assertSame(404, $this->call('GET', "/api/recipes/$id")['status']);
        self::assertSame(404, $this->call('DELETE', "/api/recipes/$id")['status']);
    }

    public function testImportIsAllOrNothing(): void
    {
        $bad = $this->call('POST', '/api/recipes/import', ['recipes' => [$this->recipe('Good', ['a'], []), ['title' => 'Bad']]]);
        self::assertSame(422, $bad['status']);
        self::assertArrayHasKey('recipe 2: cooking_time', $bad['json']['errors']);
        self::assertCount(0, $this->call('GET', '/api/recipes')['json']['recipes']);

        $ok = $this->call('POST', '/api/recipes/import', [$this->recipe('One', ['a'], []), $this->recipe('Two', ['b'], [])]);
        self::assertSame(2, $ok['json']['imported']);
        self::assertCount(2, $this->call('GET', '/api/recipes')['json']['recipes']);

        $single = $this->call('POST', '/api/recipes/import', $this->recipe('Single', ['c'], []));
        self::assertSame(1, $single['json']['imported']);
    }

    public function testValidationAndErrors(): void
    {
        $res = $this->call('POST', '/api/recipes', ['title' => '']);
        self::assertSame(422, $res['status']);
        self::assertArrayHasKey('title', $res['json']['errors']);

        self::assertSame(404, $this->call('GET', '/api/nope')['status']);
        self::assertSame(405, $this->call('PATCH', '/api/recipes')['status']);
        self::assertSame(400, $this->call('POST', '/api/recipes', raw: '{broken')['status']);
    }

    /**
     * @param  array<string, mixed>|null $body
     * @param  array<string, string>     $query
     * @return array{status: int, json: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null, array $query = [], ?string $raw = null): array
    {
        $response = $this->app->handle(new Request($method, $path, '', $query, $raw ?? ($body === null ? '' : json_encode($body))));

        return ['status' => $response->status(), 'json' => json_decode($response->body(), true) ?? []];
    }

    /**
     * @param  list<string> $ingredients
     * @param  list<string> $tags
     * @return array<string, mixed>
     */
    private function recipe(string $title, array $ingredients, array $tags, string $category = ''): array
    {
        return [
            'title' => $title,
            'category' => $category,
            'cooking_time' => 20,
            'ingredients' => $ingredients,
            'steps' => ['Cook it.'],
            'tags' => $tags,
        ];
    }
}
