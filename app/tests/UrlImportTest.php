<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Session;
use App\Http\StreamedJsonResponse;
use App\Repository\JsonFileRecipeRepository;
use App\Service\RecipeExtractor;
use App\Service\RecipeImportException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class UrlImportTest extends TestCase
{
    private string $dir;
    private JsonFileRecipeRepository $recipes;
    private FakeRecipeExtractor $extractor;
    private Session $session;
    private Application $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-url-import-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->recipes = new JsonFileRecipeRepository($this->dir . '/recipes.json');
        $this->extractor = new FakeRecipeExtractor();
        $this->session = Session::memory();
        $this->app = $this->app($this->extractor);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function testThePreviewIsReturnedWithoutSavingAnything(): void
    {
        $this->extractor->recipe = $this->recipe();

        $res = $this->call('POST', '/api/recipes/import/url', ['url' => 'https://example.com/tomato-soup']);

        self::assertSame(200, $res['status']);
        self::assertSame('Tomato Soup', $res['json']['recipe']['title']);
        self::assertSame('https://example.com/tomato-soup', $res['json']['url']);
        self::assertSame(['https://example.com/tomato-soup'], $this->extractor->urls);
        self::assertSame([], $this->recipes->all(), 'the preview is not stored');
    }

    public function testAConfirmedPreviewIsImported(): void
    {
        $this->extractor->recipe = $this->recipe();
        $preview = $this->call('POST', '/api/recipes/import/url', ['url' => 'https://example.com/soup'])['json']['recipe'];

        $imported = $this->call('POST', '/api/recipes/import', $preview);

        self::assertSame(201, $imported['status']);
        self::assertSame('Tomato Soup', $imported['json']['recipes'][0]['title']);
        self::assertSame('tomato-soup', $imported['json']['recipes'][0]['slug']);
        self::assertCount(1, $this->recipes->all());
    }

    public function testInvalidUrlsAreRejectedBeforeTheServiceIsCalled(): void
    {
        foreach (['', 'not a url', 'file:///etc/passwd', 'ftp://example.com/recipe'] as $url) {
            $res = $this->call('POST', '/api/recipes/import/url', ['url' => $url]);
            self::assertSame(422, $res['status'], $url);
            self::assertArrayHasKey('url', $res['json']['errors'], $url);
        }

        self::assertSame([], $this->extractor->urls);
    }

    public function testAServiceFailureBecomesBadGateway(): void
    {
        $this->extractor->error = new RecipeImportException('the page does not contain a recipe');

        $res = $this->call('POST', '/api/recipes/import/url', ['url' => 'https://example.com/article']);

        self::assertSame(502, $res['status']);
        self::assertStringContainsString('the page does not contain a recipe', $res['json']['error']);
    }

    public function testThePageOnlyOffersUrlImportWhenItIsConfigured(): void
    {
        self::assertStringContainsString('urlImportForm', $this->get('/import')->body());

        $app = $this->app(null);
        self::assertStringNotContainsString('urlImportForm', $app->handle(new Request('GET', '/import', ''))->body());
        self::assertSame(503, $this->call('POST', '/api/recipes/import/url', ['url' => 'https://example.com/soup'], $app)['status']);
    }

    public function testThePageOffersUrlImportWhenAnEndpointIsConfigured(): void
    {
        $app = $this->app(null, ['endpoint' => 'https://api.example.com/v1/chat/completions']);

        self::assertStringContainsString('urlImportForm', $app->handle(new Request('GET', '/import', ''))->body());
    }

    public function testUnexpectedExtractorErrorsBecomeAnInternalServerError(): void
    {
        $this->extractor->error = new RuntimeException('boom');

        $res = $this->call('POST', '/api/recipes/import/url', ['url' => 'https://example.com/soup']);

        self::assertSame(500, $res['status']);
        self::assertSame([], $this->recipes->all());
    }

    public function testThePageIsDownloadedBeforeTheModelIsAsked(): void
    {
        $this->extractor->recipe = $this->recipe();
        $this->extractor->html = '<html>Tomato Soup</html>';

        $page = $this->call('POST', '/api/recipes/import/url/page', ['url' => 'https://example.com/soup'])['json'];

        self::assertSame('https://example.com/soup', $page['url']);
        self::assertSame(strlen('<html>Tomato Soup</html>'), $page['bytes']);
        self::assertSame(['https://example.com/soup'], $this->extractor->urls);
        self::assertSame([], $this->extractor->htmls, 'the model is not asked yet');

        $events = $this->extract($page['token']);

        $done = end($events);
        self::assertSame('done', $done['event']);
        self::assertSame('Tomato Soup', $done['recipe']['title']);
        self::assertSame('https://example.com/soup', $done['url']);
        self::assertSame(['<html>Tomato Soup</html>'], $this->extractor->htmls, 'the downloaded page reaches the model');
        self::assertSame([], $this->recipes->all(), 'the preview is not stored');
    }

    public function testAPageDownloadFailureIsReportedBeforeTheModelIsAsked(): void
    {
        $this->extractor->error = new RecipeImportException('the server answered with HTTP 404.');

        $res = $this->call('POST', '/api/recipes/import/url/page', ['url' => 'https://example.com/missing']);

        self::assertSame(502, $res['status']);
        self::assertStringContainsString('The page could not be downloaded', $res['json']['error']);
        self::assertSame([], $this->extractor->htmls);
    }

    public function testAModelFailureIsReportedAsAnErrorEvent(): void
    {
        $page = $this->call('POST', '/api/recipes/import/url/page', ['url' => 'https://example.com/soup'])['json'];
        $this->extractor->extractError = new RecipeImportException('the page does not contain a recipe');

        $events = $this->extract($page['token']);
        $last = end($events);

        self::assertSame('error', $last['event']);
        self::assertStringContainsString('the page does not contain a recipe', $last['message']);
    }

    public function testAnAnswerThatIsNoRecipeIsReportedWithItsErrors(): void
    {
        $page = $this->call('POST', '/api/recipes/import/url/page', ['url' => 'https://example.com/soup'])['json'];
        $this->extractor->invalid = true;

        $events = $this->extract($page['token']);
        $last = end($events);

        self::assertSame('error', $last['event']);
        self::assertArrayHasKey('title', $last['errors']);
    }

    public function testExtractingNeedsThePageOfTheSameBrowser(): void
    {
        $res = $this->call('POST', '/api/recipes/import/url/extract', ['token' => 'does-not-exist']);

        self::assertSame(409, $res['status']);
        self::assertSame([], $this->extractor->htmls);
    }

    public function testTheDownloadedPageIsOnlyUsedOnce(): void
    {
        $this->extractor->recipe = $this->recipe();
        $page = $this->call('POST', '/api/recipes/import/url/page', ['url' => 'https://example.com/soup'])['json'];

        $this->extract($page['token']);
        $second = $this->call('POST', '/api/recipes/import/url/extract', ['token' => $page['token']]);

        self::assertSame(409, $second['status']);
    }

    private function app(?RecipeExtractor $extractor, array $llm = ['endpoint' => '']): Application
    {
        $config = [
            'auth_enabled' => false,
        ];
        foreach ($llm as $key => $value) {
            $config['llm_' . $key] = $value;
        }

        return new Application(
            $this->recipes,
            config: $config,
            recipeExtractor: $extractor,
        );
    }

    private function get(string $path): \App\Http\Response
    {
        return $this->app->handle(new Request('GET', $path, ''));
    }

    /**
     * @param  array<string, mixed>|null $body
     * @return array{status: int, json: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null, ?Application $app = null): array
    {
        $response = ($app ?? $this->app)->handle($this->request($method, $path, $body));

        return ['status' => $response->status(), 'json' => json_decode($response->body(), true) ?? []];
    }

    /** @return list<array<string, mixed>> */
    private function extract(string $token): array
    {
        $response = $this->app->handle($this->request('POST', '/api/recipes/import/url/extract', ['token' => $token]));
        self::assertInstanceOf(StreamedJsonResponse::class, $response);

        return $response->events();
    }

    /** @param array<string, mixed>|null $body */
    private function request(string $method, string $path, ?array $body = null): Request
    {
        return new Request($method, $path, '', [], $body === null ? '' : (string) json_encode($body), session: $this->session);
    }

    /** @return array<string, mixed> */
    private function recipe(): array
    {
        return [
            'title' => 'Tomato Soup',
            'description' => 'A simple soup.',
            'category' => 'Soup',
            'image' => '',
            'cooking_time' => 20,
            'ingredients' => [['quantity' => '1 l', 'name' => 'water']],
            'steps' => ['Boil.', 'Season.'],
            'tags' => ['vegetarian'],
        ];
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->remove($path . '/' . $item);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}

final class FakeRecipeExtractor implements RecipeExtractor
{
    /** @var list<string> */
    public array $urls = [];
    /** @var list<string> */
    public array $htmls = [];
    /** @var array<string, mixed>|null */
    public ?array $recipe = null;
    public string $html = '<html>a recipe page</html>';
    public ?Throwable $error = null;
    public ?Throwable $extractError = null;
    public bool $invalid = false;

    public function fetch(string $url): string
    {
        $this->urls[] = $url;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->html;
    }

    public function extract(string $html, string $url, ?callable $cancelled = null): array
    {
        $this->htmls[] = $html;

        if ($this->extractError !== null) {
            throw $this->extractError;
        }
        if ($this->invalid) {
            throw new ValidationException(['title' => 'A title is required.']);
        }

        return $this->recipe ?? [];
    }
}
