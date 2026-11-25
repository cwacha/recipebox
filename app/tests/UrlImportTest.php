<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Exception\ValidationException;
use App\Http\Request;
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
    private Application $app;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/recipes-url-import-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->recipes = new JsonFileRecipeRepository($this->dir . '/recipes.json');
        $this->extractor = new FakeRecipeExtractor();
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

    public function testTheStreamedImportReportsEveryStep(): void
    {
        $this->extractor->recipe = $this->recipe();

        $response = $this->app->handle(new Request('POST', '/api/recipes/import/url', '', ['stream' => '1'], (string) json_encode(['url' => 'https://example.com/soup'])));

        self::assertInstanceOf(StreamedJsonResponse::class, $response);
        self::assertSame('application/x-ndjson; charset=utf-8', $response->headers()['Content-Type']);

        $events = $response->events();
        $steps = [];
        foreach ($events as $event) {
            if (($event['event'] ?? '') === 'step') {
                $steps[] = $event['step'];
            }
        }
        self::assertSame(['check', 'download', 'ask', 'parse'], $steps);

        $done = end($events);
        self::assertSame('done', $done['event']);
        self::assertSame('Tomato Soup', $done['recipe']['title']);
        self::assertSame('https://example.com/soup', $done['url']);
        self::assertSame([], $this->recipes->all(), 'the preview is not stored');
    }

    public function testAStreamedFailureIsReportedAsAnErrorEvent(): void
    {
        $this->extractor->error = new RecipeImportException('the page could not be downloaded: the server answered with HTTP 404.');

        $events = $this->app->handle(new Request('POST', '/api/recipes/import/url', '', ['stream' => '1'], (string) json_encode(['url' => 'https://example.com/soup'])))->events();

        $last = end($events);
        self::assertSame('error', $last['event']);
        self::assertStringContainsString('HTTP 404', $last['message']);
    }

    public function testAStreamedAnswerThatIsNoRecipeIsReportedWithItsErrors(): void
    {
        $this->extractor->invalid = true;

        $events = $this->app->handle(new Request('POST', '/api/recipes/import/url', '', ['stream' => '1'], (string) json_encode(['url' => 'https://example.com/soup'])))->events();

        $last = end($events);
        self::assertSame('error', $last['event']);
        self::assertArrayHasKey('title', $last['errors']);
    }

    private function app(?RecipeExtractor $extractor, array $llm = ['endpoint' => '']): Application
    {
        return new Application(
            dirname(__DIR__),
            $this->recipes,
            config: [
                'auth' => [
                    'enabled' => false,
                    'state_dir' => $this->dir . '/auth',
                    'defaults_dir' => dirname(__DIR__) . '/defaults',
                ],
                'llm' => $llm,
            ],
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
        $response = ($app ?? $this->app)->handle(new Request($method, $path, '', [], $body === null ? '' : json_encode($body)));

        return ['status' => $response->status(), 'json' => json_decode($response->body(), true) ?? []];
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
    /** @var array<string, mixed>|null */
    public ?array $recipe = null;
    public ?Throwable $error = null;
    public bool $invalid = false;

    public function extract(string $url, ?callable $onStep = null, ?callable $cancelled = null): array
    {
        $this->urls[] = $url;
        $onStep ??= static function (string $step): void {
        };

        $onStep('download');
        if ($this->error !== null) {
            throw $this->error;
        }
        $onStep('ask');
        $onStep('parse');

        if ($this->invalid) {
            throw new ValidationException(['title' => 'A title is required.']);
        }

        return $this->recipe ?? [];
    }
}
