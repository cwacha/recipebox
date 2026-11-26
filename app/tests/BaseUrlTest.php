<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Http\Request;
use App\Model\Recipe;
use App\Repository\JsonFileRecipeRepository;
use PHPUnit\Framework\TestCase;

/** The application served under a URL prefix such as http://localhost/~user/recipebox/public/. */
final class BaseUrlTest extends TestCase
{
    private const PREFIX = '/~user/recipebox/public';

    private string $dir;
    private JsonFileRecipeRepository $repo;
    private string $slug;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-baseurl-test-' . bin2hex(random_bytes(4));
        $this->repo = new JsonFileRecipeRepository($this->dir . '/recipes.json');
        $this->slug = $this->repo->save(Recipe::create($this->repo->nextId(), [
            'title' => 'Test Soup',
            'cooking_time' => 5,
            'ingredients' => [['quantity' => '', 'name' => 'water']],
            'steps' => ['Boil.'],
        ]))->slug;
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->remove($path . '/' . $item);
                }
            }
            rmdir($path);
        }
    }

    /** @param array<string, mixed> $config */
    private function app(array $config): Application
    {
        return new Application($this->repo, config: $config + [
            'auth_enabled' => false,
        ]);
    }

    public function testAllLinksAndAssetsUseTheConfiguredPrefix(): void
    {
        $body = $this->app(['base_url' => self::PREFIX])->handle(new Request('GET', '/', ''))->body();

        self::assertStringContainsString('href="' . self::PREFIX . '/assets/css/app.css', $body);
        self::assertStringContainsString('href="' . self::PREFIX . '/' . '"', $body);
        self::assertStringContainsString('href="' . self::PREFIX . '/recipes/' . $this->slug . '"', $body);
        self::assertStringContainsString('href="' . self::PREFIX . '/recipes/new"', $body);
        self::assertStringContainsString('"base":"' . str_replace('/', '\\/', self::PREFIX) . '"', $body);
        self::assertStringNotContainsString('href="/assets/', $body);
        self::assertStringNotContainsString('href="/recipes/', $body);
    }

    public function testRedirectsAndFormTargetsUseTheConfiguredPrefix(): void
    {
        $app = $this->app(['base_url' => self::PREFIX]);

        // with authentication switched off, /login sends you back to the start page
        $login = $app->handle(new Request('GET', '/login', ''));
        self::assertSame(302, $login->status());
        self::assertSame(self::PREFIX . '/', $login->headers()['Location']);

        // a link with the internal id redirects to the readable URL
        $legacy = $app->handle(new Request('GET', '/recipes/' . $this->repo->all()[0]->id, ''));
        self::assertSame(301, $legacy->status());
        self::assertSame(self::PREFIX . '/recipes/' . $this->slug, $legacy->headers()['Location']);

        $form = $app->handle(new Request('GET', '/recipes/' . $this->slug . '/edit', ''))->body();
        self::assertStringContainsString('data-cancel="' . self::PREFIX . '/recipes/' . $this->slug . '"', $form);
        self::assertStringContainsString('href="' . self::PREFIX . '/recipes/' . $this->slug . '"', $form);
    }

    public function testThePrefixIsDetectedWithoutConfiguration(): void
    {
        $server = $_SERVER;
        $_SERVER['SCRIPT_NAME'] = self::PREFIX . '/index.php';
        $_SERVER['REQUEST_URI'] = self::PREFIX . '/recipes/' . $this->slug;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $request = Request::fromGlobals();
            $body = $this->app([])->handle($request)->body();
        } finally {
            $_SERVER = $server;
        }

        self::assertSame(self::PREFIX, $request->basePath);
        self::assertSame('/recipes/' . $this->slug, $request->path);
        self::assertStringContainsString(self::PREFIX . '/assets/css/app.css', $body);
    }
}
