<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Http\Request;
use App\Repository\JsonFileRecipeRepository;
use App\Service\ImageStorage;
use App\Service\ImageFetchException;
use App\Service\ImageFetcher;
use PHPUnit\Framework\TestCase;

final class ImageImportTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $root;
    private Application $app;
    private FakeFetcher $fetcher;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recipes-import-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/public/images', 0775, true);
        mkdir($this->root . '/defaults/images', 0775, true);
        foreach (['carbonara.jpg', 'greek-salad.jpg'] as $default) {
            file_put_contents($this->root . '/public/images/' . $default, 'default image');
            file_put_contents($this->root . '/defaults/images/' . $default, 'default image');
        }
        file_put_contents($this->root . '/public/images/mine.jpg', 'placed by hand');

        $this->fetcher = new FakeFetcher();
        $this->app = new Application(
            new JsonFileRecipeRepository($this->root . '/state/recipes.json'),
            fetcher: $this->fetcher,
            images: new ImageStorage($this->root . '/public', $this->root . '/defaults'),
            config: [
                'auth_enabled' => false,
                'auth_state_dir' => $this->root . '/state-auth',
                'auth_defaults_dir' => dirname(__DIR__) . '/defaults',
            ],
        );
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testRemoteImagesAreDownloadedAndNamedAfterTheRecipeSlug(): void
    {
        $png = base64_decode(self::PNG);
        $this->fetcher->responses['https://cdn.example.com/pasta.png'] = $png;

        $res = $this->import([
            $this->recipe('Spaghetti Pasta', 'https://cdn.example.com/pasta.png'),
            $this->recipe('Pasta again', 'https://cdn.example.com/pasta.png'),
        ]);

        self::assertSame(201, $res['status']);
        self::assertSame([], $res['json']['warnings']);
        self::assertSame(['spaghetti-pasta', 'pasta-again'], array_column($res['json']['recipes'], 'slug'));
        self::assertSame(['images/spaghetti-pasta.png', 'images/pasta-again.png'], array_column($res['json']['recipes'], 'image'));
        self::assertSame($png, file_get_contents($this->root . '/public/images/spaghetti-pasta.png'));
        self::assertSame($png, file_get_contents($this->root . '/public/images/pasta-again.png'));
        self::assertSame(['https://cdn.example.com/pasta.png'], $this->fetcher->requested, 'the same URL is only downloaded once');

        $stored = $this->call('GET', '/api/recipes/' . $res['json']['recipes'][0]['id'])['json'];
        self::assertSame('images/spaghetti-pasta.png', $stored['image'], 'the picture is saved with the recipe');
        self::assertSame($res['json']['recipes'][0]['created_at'], $stored['updated_at'], 'attaching the picture is not an edit');
    }

    public function testExistingFilesAreNeverOverwritten(): void
    {
        file_put_contents($this->root . '/public/images/pasta.png', 'someone elses file');
        $this->fetcher->responses['https://cdn.example.com/a.png'] = base64_decode(self::PNG);

        $res = $this->import([$this->recipe('Pasta', 'https://cdn.example.com/a.png')]);

        self::assertSame('images/pasta-2.png', $res['json']['recipes'][0]['image']);
        self::assertSame('someone elses file', file_get_contents($this->root . '/public/images/pasta.png'));
    }

    public function testLocalImagesAreKeptWithoutDownloading(): void
    {
        $res = $this->import([$this->recipe('Carbonara', 'images/carbonara.jpg')]);

        self::assertSame('images/carbonara.jpg', $res['json']['recipes'][0]['image']);
        self::assertSame([], $res['json']['warnings']);
        self::assertSame([], $this->fetcher->requested);
    }

    public function testProblemsWithPicturesOnlyProduceWarnings(): void
    {
        $this->fetcher->responses['https://down.example.com/a.jpg'] = new ImageFetchException('the server answered with HTTP 404');
        $this->fetcher->responses['https://web.example.com/page'] = '<html>not an image</html>';

        $res = $this->import([
            $this->recipe('One', 'https://down.example.com/a.jpg'),
            $this->recipe('Two', 'https://web.example.com/page'),
            $this->recipe('Three', 'images/missing.jpg'),
            $this->recipe('Four', ''),
        ]);

        self::assertSame(201, $res['status']);
        self::assertSame(4, $res['json']['imported']);
        self::assertSame([null, null, null, null], array_column($res['json']['recipes'], 'image'));
        self::assertCount(3, $res['json']['warnings']);
        self::assertStringContainsString('recipe 1: image "https://down.example.com/a.jpg" could not be downloaded: the server answered with HTTP 404', $res['json']['warnings'][0]);
        self::assertStringContainsString('recipe 2:', $res['json']['warnings'][1]);
        self::assertStringContainsString('not a JPEG, PNG, WebP or GIF image', $res['json']['warnings'][1]);
        self::assertStringContainsString('recipe 3: image "images/missing.jpg" does not exist', $res['json']['warnings'][2]);
        self::assertSame([], glob($this->root . '/public/images/*.html') ?: []);
    }

    public function testNothingIsDownloadedWhenTheImportIsInvalid(): void
    {
        $bad = $this->recipe('Bad', 'https://cdn.example.com/a.png');
        unset($bad['steps']);

        $res = $this->import([$this->recipe('Good', 'https://cdn.example.com/b.png'), $bad]);

        self::assertSame(422, $res['status']);
        self::assertSame([], $this->fetcher->requested);
    }

    public function testDownloadedImageIsRemovedWithItsRecipe(): void
    {
        $this->fetcher->responses['https://cdn.example.com/a.png'] = base64_decode(self::PNG);
        $recipes = $this->import([
            $this->recipe('A', 'https://cdn.example.com/a.png'),
            $this->recipe('B', 'https://cdn.example.com/a.png'),
        ])['json']['recipes'];

        $this->call('DELETE', '/api/recipes/' . $recipes[0]['id']);
        self::assertFileDoesNotExist($this->root . '/public/images/a.png');
        self::assertFileExists($this->root . '/public/images/b.png', 'the other recipe keeps its own copy');
        $this->call('DELETE', '/api/recipes/' . $recipes[1]['id']);
        self::assertFileDoesNotExist($this->root . '/public/images/b.png');
    }

    public function testDefaultAndHandPlacedImagesAreNeverDeleted(): void
    {
        $recipes = $this->import([
            $this->recipe('Spaghetti Carbonara', 'images/carbonara.jpg'),
            $this->recipe('Greek Salad', 'images/greek-salad.jpg'),
            $this->recipe('My Own Recipe', 'images/mine.jpg'),
        ])['json']['recipes'];

        foreach ($recipes as $recipe) {
            self::assertSame(204, $this->call('DELETE', '/api/recipes/' . $recipe['id'])['status']);
        }

        self::assertFileExists($this->root . '/public/images/carbonara.jpg', 'different name than the slug');
        self::assertFileExists($this->root . '/public/images/greek-salad.jpg', 'named like the slug, but a default image');
        self::assertFileExists($this->root . '/public/images/mine.jpg', 'a file you placed yourself under another name');
    }

    public function testImageIsKeptWhileAnotherRecipeStillUsesIt(): void
    {
        $this->fetcher->responses['https://cdn.example.com/a.png'] = base64_decode(self::PNG);
        $first = $this->import([$this->recipe('Soup', 'https://cdn.example.com/a.png')])['json']['recipes'][0];
        $second = $this->import([$this->recipe('Other', 'images/soup.png')])['json']['recipes'][0];

        $this->call('DELETE', '/api/recipes/' . $first['id']);
        self::assertFileExists($this->root . '/public/images/soup.png');

        $this->call('DELETE', '/api/recipes/' . $second['id']);
        self::assertFileExists($this->root . '/public/images/soup.png', 'not named after its slug, so it is not touched');
    }

    public function testPictureLinkEnteredInTheEditorIsDownloadedForNewRecipes(): void
    {
        $png = base64_decode(self::PNG);
        $this->fetcher->responses['https://cdn.example.com/soup.png'] = $png;

        $res = $this->call('POST', '/api/recipes', $this->recipe('Tomato Soup', 'https://cdn.example.com/soup.png'));

        self::assertSame(201, $res['status']);
        self::assertSame('images/tomato-soup.png', $res['json']['image']);
        self::assertArrayNotHasKey('warnings', $res['json']);
        self::assertSame($png, file_get_contents($this->root . '/public/images/tomato-soup.png'));
        self::assertSame('images/tomato-soup.png', $this->call('GET', '/api/recipes/' . $res['json']['id'])['json']['image']);

        $second = $this->call('POST', '/api/recipes', $this->recipe('Tomato Soup', 'https://cdn.example.com/soup.png'))['json'];
        self::assertSame('images/tomato-soup-2.png', $second['image']);
        self::assertCount(1, $this->fetcher->requested, 'downloaded once');
    }

    public function testPictureLinkEnteredInTheEditorIsDownloadedWhenEditing(): void
    {
        $this->fetcher->responses['https://cdn.example.com/first.png'] = base64_decode(self::PNG);
        $this->fetcher->responses['https://cdn.example.com/second.png'] = base64_decode(self::PNG) . "\0";

        $created = $this->call('POST', '/api/recipes', $this->recipe('Soup', ''))['json'];
        self::assertNull($created['image']);

        $first = $this->call('PUT', '/api/recipes/' . $created['id'], $this->recipe('Soup', 'https://cdn.example.com/first.png'));
        self::assertSame(200, $first['status']);
        self::assertSame('images/soup.png', $first['json']['image'], 'named after the slug of the recipe');
        self::assertFileExists($this->root . '/public/images/soup.png');

        $second = $this->call('PUT', '/api/recipes/' . $created['id'], $this->recipe('Soup', 'https://cdn.example.com/second.png'));
        self::assertSame('images/soup-2.png', $second['json']['image']);
        self::assertFileDoesNotExist($this->root . '/public/images/soup.png', 'the replaced picture is removed');
        self::assertFileExists($this->root . '/public/images/soup-2.png');
        self::assertSame('soup', $second['json']['slug'], 'the URL of the recipe does not change');

        $local = $this->call('PUT', '/api/recipes/' . $created['id'], $this->recipe('Soup', 'images/soup-2.png'));
        self::assertSame('images/soup-2.png', $local['json']['image']);
        self::assertCount(2, $this->fetcher->requested, 'an unchanged local picture is not downloaded again');
    }

    public function testFailedDownloadRejectsTheFormAndChangesNothing(): void
    {
        $this->fetcher->responses['https://down.example.com/a.png'] = new ImageFetchException('the server answered with HTTP 404');
        $this->fetcher->responses['https://web.example.com/page'] = '<html>no image</html>';

        $new = $this->call('POST', '/api/recipes', $this->recipe('Soup', 'https://down.example.com/a.png'));
        self::assertSame(422, $new['status']);
        self::assertSame('The picture could not be downloaded: the server answered with HTTP 404.', $new['json']['errors']['image']);
        self::assertSame([], $this->call('GET', '/api/recipes')['json']['recipes'], 'nothing was saved');

        $html = $this->call('POST', '/api/recipes', $this->recipe('Soup', 'https://web.example.com/page'));
        self::assertSame(422, $html['status']);
        self::assertStringContainsString('not a JPEG, PNG, WebP or GIF image', $html['json']['errors']['image']);

        $existing = $this->call('POST', '/api/recipes', $this->recipe('Soup', 'images/carbonara.jpg'))['json'];
        $edit = $this->call('PUT', '/api/recipes/' . $existing['id'], $this->recipe('Renamed', 'https://down.example.com/a.png'));
        self::assertSame(422, $edit['status']);
        $unchanged = $this->call('GET', '/api/recipes/' . $existing['id'])['json'];
        self::assertSame('Soup', $unchanged['title']);
        self::assertSame('images/carbonara.jpg', $unchanged['image']);
        self::assertSame([], glob($this->root . '/public/images/soup*') ?: []);
    }

    public function testAnUnchangedLinkDoesNotBlockEditingARecipe(): void
    {
        $id = $this->call('POST', '/api/recipes', $this->recipe('Old', 'images/carbonara.jpg'))['json']['id'];
        $this->call('PUT', '/api/recipes/' . $id, $this->recipe('Old', 'images/carbonara.jpg'));

        // a recipe created before pictures were downloaded still holds a link; the site behind it is gone
        $path = $this->root . '/state/recipes.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['recipes'][0]['image'] = 'https://gone.example.com/old.jpg';
        file_put_contents($path, json_encode($data));
        $this->fetcher->requested = [];

        $res = $this->call('PUT', '/api/recipes/' . $id, [...$this->recipe('Old but renamed', 'https://gone.example.com/old.jpg')]);

        self::assertSame(200, $res['status']);
        self::assertSame('Old but renamed', $res['json']['title']);
        self::assertSame('https://gone.example.com/old.jpg', $res['json']['image']);
        self::assertSame([], $this->fetcher->requested);
    }

    /**
     * @param  list<array<string, mixed>> $recipes
     * @return array{status: int, json: array<string, mixed>}
     */
    private function import(array $recipes): array
    {
        return $this->call('POST', '/api/recipes/import', ['recipes' => $recipes]);
    }

    /**
     * @param  array<string, mixed>|null $body
     * @return array{status: int, json: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $body = null): array
    {
        $response = $this->app->handle(new Request($method, $path, '', [], $body === null ? '' : json_encode($body)));

        return ['status' => $response->status(), 'json' => json_decode($response->body(), true) ?? []];
    }

    /** @return array<string, mixed> */
    private function recipe(string $title, string $image): array
    {
        return [
            'title' => $title,
            'image' => $image,
            'cooking_time' => 10,
            'ingredients' => ['salt'],
            'steps' => ['Cook.'],
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

final class FakeFetcher implements ImageFetcher
{
    /** @var array<string, string|ImageFetchException> */
    public array $responses = [];
    /** @var list<string> */
    public array $requested = [];

    public function fetch(string $url): string
    {
        $this->requested[] = $url;
        $response = $this->responses[$url] ?? new ImageFetchException('not found');

        return $response instanceof ImageFetchException ? throw $response : $response;
    }
}
