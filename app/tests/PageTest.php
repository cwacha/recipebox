<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Http\Request;
use App\Repository\JsonFileRecipeRepository;
use PHPUnit\Framework\TestCase;

final class PageTest extends TestCase
{
    private string $dir;
    private Application $app;
    private string $id;
    private string $slug;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-page-test-' . bin2hex(random_bytes(4));
        $this->app = new Application(
            dirname(__DIR__),
            new JsonFileRecipeRepository($this->dir . '/recipes.json'),
            config: ['auth' => ['enabled' => false, 'state_dir' => $this->dir . '/auth']],
        );

        $created = $this->app->handle(new Request('POST', '/api/recipes', '', [], json_encode([
            'title' => 'Soup <b>of the day</b>',
            'category' => 'Soup',
            'cooking_time' => 90,
            'ingredients' => [['quantity' => '1 l', 'name' => 'water']],
            'steps' => ['Boil the water.', 'Add salt.'],
            'tags' => ['quick'],
        ])));
        $json = json_decode($created->body(), true);
        $this->id = $json['id'];
        $this->slug = $json['slug'];
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testEachPageHasItsOwnUrl(): void
    {
        $home = $this->get('/');
        self::assertSame(200, $home->status());
        self::assertSame('soup-b-of-the-day-b', $this->slug);
        self::assertStringContainsString('href="/recipes/soup-b-of-the-day-b"', $home->body());

        $show = $this->get('/recipes/' . $this->slug);
        self::assertSame(200, $show->status());
        self::assertStringContainsString('<title>Soup &lt;b&gt;of the day&lt;/b&gt; – Recipe Box</title>', $show->body());
        self::assertStringContainsString('1 h 30 min', $show->body());
        self::assertStringContainsString('Boil the water.', $show->body());
        self::assertStringNotContainsString('<b>of the day</b>', $show->body());

        $edit = $this->get('/recipes/' . $this->slug . '/edit');
        self::assertSame(200, $edit->status());
        self::assertStringContainsString('data-id="' . $this->id . '"', $edit->body());
        self::assertStringContainsString('value="1 l"', $edit->body());

        self::assertStringContainsString('data-id=""', $this->get('/recipes/new')->body());
        self::assertSame(200, $this->get('/import')->status());
    }

    public function testOldIdUrlsRedirectToTheReadableUrl(): void
    {
        $show = $this->get('/recipes/' . $this->id);
        self::assertSame(301, $show->status());
        self::assertSame('/recipes/' . $this->slug, $show->headers()['Location']);

        $edit = $this->get('/recipes/' . $this->id . '/edit');
        self::assertSame(301, $edit->status());
        self::assertSame('/recipes/' . $this->slug . '/edit', $edit->headers()['Location']);
    }

    public function testSearchIsBookmarkable(): void
    {
        $hit = $this->get('/', ['q' => 'water']);
        self::assertStringContainsString('1 result for “water”', $hit->body());

        $miss = $this->get('/', ['q' => 'nothingmatches']);
        self::assertStringContainsString('No recipes found', $miss->body());

        $category = $this->get('/', ['category' => 'soup']);
        self::assertStringContainsString('in soup', $category->body());
    }

    public function testUnknownRecipeIsAnHtml404(): void
    {
        foreach (['/recipes/unknown', '/recipes/unknown/edit', '/nowhere'] as $path) {
            $res = $this->get($path);
            self::assertSame(404, $res->status(), $path);
            self::assertStringContainsString('Back to all recipes', $res->body());
        }
    }

    public function testRecipeNewIsNotMistakenForAnId(): void
    {
        self::assertStringContainsString('New recipe', $this->get('/recipes/new')->body());
    }

    public function testThirdPartyAssetsAreServedLocally(): void
    {
        $body = $this->get('/')->body();
        $linked = [
            '/assets/vendor/bootstrap/css/bootstrap.min.css',
            '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js',
            '/assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
            '/assets/vendor/fonts/fonts.css',
            '/assets/vendor/jquery/jquery.min.js',
        ];
        $files = [
            '/assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
            '/assets/vendor/fonts/files/fraunces-latin.woff2',
            '/assets/vendor/fonts/files/inter-latin.woff2',
        ];

        foreach ($linked as $asset) {
            self::assertStringContainsString($asset, $body, $asset . ' is linked');
        }
        foreach ([...$linked, ...$files] as $asset) {
            self::assertFileExists(dirname(__DIR__) . '/public' . $asset);
        }
        self::assertStringNotContainsString('cdn.jsdelivr.net', $body);
        self::assertStringNotContainsString('fonts.googleapis.com', $body);
    }

    /** @param array<string, string> $query */
    private function get(string $path, array $query = []): \App\Http\Response
    {
        return $this->app->handle(new Request('GET', $path, '', $query));
    }
}
