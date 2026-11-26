<?php

declare(strict_types=1);

namespace Tests;

use App\Exception\ValidationException;
use App\Service\LlmClient;
use App\Service\LlmRecipeExtractor;
use App\Service\PageFetcher;
use App\Service\RecipeImportException;
use App\Validation\RecipeValidator;
use PHPUnit\Framework\TestCase;

final class LlmRecipeExtractorTest extends TestCase
{
    private const HTML = '<html><body><h1>Tomato Soup</h1><p>Boil water.</p></body></html>';

    public function testReadsARecipeFromFencedJsonAndSendsThePage(): void
    {
        $client = new FakeLlmClient("Here is the recipe:\n```json\n" . json_encode($this->recipe()) . "\n```");
        $fetcher = new FakePageFetcher(self::HTML);
        $extractor = new LlmRecipeExtractor($client, $fetcher, new RecipeValidator());

        $html = $extractor->fetch('https://example.com/tomato-soup');
        $recipe = $extractor->extract($html, 'https://example.com/tomato-soup');

        self::assertSame('Tomato Soup', $recipe['title']);
        self::assertSame(25, $recipe['cooking_time']);
        self::assertSame([['quantity' => '1 l', 'name' => 'water']], $recipe['ingredients']);
        self::assertSame(['Boil.', 'Season.'], $recipe['steps']);

        self::assertSame(['https://example.com/tomato-soup'], $fetcher->urls, 'the page is downloaded by the application');
        self::assertStringContainsString(self::HTML, $client->prompts[0], 'the page HTML reaches the prompt');
        self::assertStringContainsString('https://example.com/tomato-soup', $client->prompts[0]);
        self::assertStringNotContainsString('{html}', $client->prompts[0]);
        self::assertStringNotContainsString('{url}', $client->prompts[0]);
    }

    public function testUnwrapsASingleRecipeList(): void
    {
        $client = new FakeLlmClient(json_encode(['recipes' => [$this->recipe()]]));
        $extractor = new LlmRecipeExtractor($client, new FakePageFetcher(self::HTML), new RecipeValidator());

        self::assertSame('Tomato Soup', $extractor->extract(self::HTML, 'https://example.com/soup')['title']);
    }

    public function testACustomPromptMayPlaceHtmlAndUrlItself(): void
    {
        $client = new FakeLlmClient(json_encode($this->recipe()));
        $extractor = new LlmRecipeExtractor(
            $client,
            new FakePageFetcher(self::HTML),
            new RecipeValidator(),
            'Extract the recipe from {url} and answer with JSON. Page: {html}',
        );

        $extractor->extract(self::HTML, 'https://example.com/cake');

        self::assertSame('Extract the recipe from https://example.com/cake and answer with JSON. Page: ' . self::HTML, $client->prompts[0]);
    }

    public function testAPromptWithoutAHtmlPlaceholderGetsThePageAppended(): void
    {
        $client = new FakeLlmClient(json_encode($this->recipe()));
        $extractor = new LlmRecipeExtractor($client, new FakePageFetcher(self::HTML), new RecipeValidator(), 'Extract a recipe.');

        $extractor->extract(self::HTML, 'https://example.com/cake');

        self::assertSame("Extract a recipe.\n\nThe web page HTML follows:\n" . self::HTML, $client->prompts[0]);
    }

    public function testAPageThatCannotBeDownloadedIsReportedAndTheModelIsNotCalled(): void
    {
        $client = new FakeLlmClient(json_encode($this->recipe()));
        $fetcher = new FakePageFetcher(self::HTML);
        $fetcher->error = new RecipeImportException('the page could not be downloaded: the server answered with HTTP 404.');
        $extractor = new LlmRecipeExtractor($client, $fetcher, new RecipeValidator());

        try {
            $extractor->fetch('https://example.com/missing');
            self::fail('the failure should be reported');
        } catch (RecipeImportException $e) {
            self::assertStringContainsString('HTTP 404', $e->getMessage());
        }

        self::assertSame([], $client->prompts, 'the model is not asked without a page');
    }

    public function testFetchOnlyDownloadsThePage(): void
    {
        $client = new FakeLlmClient(json_encode($this->recipe()));
        $fetcher = new FakePageFetcher(self::HTML);
        $extractor = new LlmRecipeExtractor($client, $fetcher, new RecipeValidator());

        self::assertSame(self::HTML, $extractor->fetch('https://example.com/soup'));
        self::assertSame(['https://example.com/soup'], $fetcher->urls);
        self::assertSame([], $client->prompts, 'downloading does not ask the model');
    }

    public function testTheCancellationCheckIsPassedToTheClient(): void
    {
        $client = new FakeLlmClient(json_encode($this->recipe()));
        $extractor = new LlmRecipeExtractor($client, new FakePageFetcher(self::HTML), new RecipeValidator());
        $cancelled = static fn (): bool => true;

        $extractor->extract(self::HTML, 'https://example.com/soup', $cancelled);

        self::assertSame($cancelled, $client->cancellations[0], 'the client can ask whether the import was cancelled');
    }

    public function testANonRecipeAnswerIsReported(): void
    {
        $extractor = new LlmRecipeExtractor(new FakeLlmClient('{"error":"not a recipe"}'), new FakePageFetcher(self::HTML), new RecipeValidator());

        $this->expectException(RecipeImportException::class);
        $this->expectExceptionMessage('not a recipe');

        $extractor->extract(self::HTML, 'https://example.com/not-a-recipe');
    }

    public function testAnAnswerWithoutJsonIsReported(): void
    {
        $extractor = new LlmRecipeExtractor(new FakeLlmClient('I could not read that page.'), new FakePageFetcher(self::HTML), new RecipeValidator());

        $this->expectException(RecipeImportException::class);
        $this->expectExceptionMessage('valid JSON');

        $extractor->extract(self::HTML, 'https://example.com/broken');
    }

    public function testAnIncompleteRecipeFailsValidation(): void
    {
        $extractor = new LlmRecipeExtractor(new FakeLlmClient('{"title":"Only a title"}'), new FakePageFetcher(self::HTML), new RecipeValidator());

        $this->expectException(ValidationException::class);

        $extractor->extract(self::HTML, 'https://example.com/incomplete');
    }

    /** @return array<string, mixed> */
    private function recipe(): array
    {
        return [
            'title' => 'Tomato Soup',
            'description' => 'A simple soup.',
            'category' => 'Soup',
            'image' => '',
            'cooking_time' => 25,
            'ingredients' => [['quantity' => '1 l', 'name' => 'water']],
            'steps' => ['Boil.', 'Season.'],
            'tags' => ['vegetarian'],
        ];
    }
}

final class FakeLlmClient implements LlmClient
{
    /** @var list<string> */
    public array $prompts = [];
    /** @var list<callable|null> */
    public array $cancellations = [];

    public function __construct(private readonly string $answer)
    {
    }

    public function complete(string $prompt, ?callable $cancelled = null): string
    {
        $this->prompts[] = $prompt;
        $this->cancellations[] = $cancelled;

        return $this->answer;
    }
}

final class FakePageFetcher implements PageFetcher
{
    /** @var list<string> */
    public array $urls = [];
    public ?RecipeImportException $error = null;

    public function __construct(private readonly string $html)
    {
    }

    public function fetch(string $url): string
    {
        $this->urls[] = $url;

        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->html;
    }
}
