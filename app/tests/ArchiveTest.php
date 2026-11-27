<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Http\Request;
use App\Repository\JsonFileRecipeRepository;
use App\Service\ImageStorage;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ArchiveTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $root;
    private Application $app;
    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        $this->root = $this->newRoot();
        $this->app = $this->application($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->remove($root);
        }
    }

    public function testExportAllContainsRecipesAndPictures(): void
    {
        $this->createRecipe('Carbonara', 'images/carbonara.png');
        $this->createRecipe('Carbonara again', 'images/carbonara.png');

        $response = $this->app->handle(new Request('GET', '/api/recipes/export', ''));

        self::assertSame(200, $response->status());
        self::assertSame('application/zip', $response->headers()['Content-Type']);
        self::assertSame('attachment; filename="recipes.zip"', $response->headers()['Content-Disposition']);

        $zip = $this->openZip($response->body());
        $json = json_decode((string) $zip->getFromName('recipes.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(2, $json['recipes']);
        self::assertSame(['images/carbonara.png', 'images/carbonara.png'], array_column($json['recipes'], 'image'));
        self::assertSame(base64_decode(self::PNG), $zip->getFromName('images/carbonara.png'));
        self::assertSame(2, $zip->numFiles, 'the picture shared by both recipes is stored once');
        $zip->close();
    }

    public function testExportedArchiveCanBeImportedIntoAnotherInstallation(): void
    {
        $this->createRecipe('Carbonara', 'images/carbonara.png');
        $body = $this->app->handle(new Request('GET', '/api/recipes/export', ''))->body();
        $archive = $this->root . '/upload.zip';
        file_put_contents($archive, $body);

        $other = $this->newRoot();
        $imported = $this->importArchive($this->application($other), $archive);

        self::assertSame(201, $imported['status']);
        self::assertSame(1, $imported['json']['imported']);
        self::assertSame([], $imported['json']['warnings']);
        self::assertSame('Carbonara', $imported['json']['recipes'][0]['title']);

        $image = $imported['json']['recipes'][0]['image'];
        self::assertMatchesRegularExpression('#^uploads/[a-f0-9]+\.png$#', $image);
        self::assertSame(base64_decode(self::PNG), file_get_contents($other . '/public/' . $image));
    }

    public function testReplaceOptionClearsExistingRecipesAndPictures(): void
    {
        $this->createRecipe('Carbonara', 'images/carbonara.png');
        $body = $this->app->handle(new Request('GET', '/api/recipes/export', ''))->body();
        $archive = $this->root . '/replace.zip';
        file_put_contents($archive, $body);

        $this->createRecipe('Extra', '');
        self::assertCount(2, $this->recipes($this->app)['recipes']);

        $imported = $this->importArchive($this->app, $archive, ['replace' => '1']);

        self::assertSame(201, $imported['status']);
        self::assertSame(1, $imported['json']['imported']);
        self::assertSame([], $imported['json']['warnings']);

        $recipes = $this->recipes($this->app)['recipes'];
        self::assertCount(1, $recipes);
        self::assertSame('Carbonara', $recipes[0]['title']);
        self::assertFileDoesNotExist($this->root . '/public/images/carbonara.png', 'the picture of the replaced recipe is removed');
        self::assertMatchesRegularExpression('#^uploads/[a-f0-9]+\.png$#', $recipes[0]['image']);
        self::assertFileExists($this->root . '/public/' . $recipes[0]['image']);
    }

    public function testReplaceOptionDoesNotClearWhenTheArchiveIsInvalid(): void
    {
        $this->createRecipe('Keep me', 'images/carbonara.png');
        $archive = $this->zip([
            'recipes.json' => json_encode(['recipes' => [['title' => '', 'cooking_time' => 0]]]),
        ]);

        $imported = $this->importArchive($this->app, $archive, ['replace' => '1']);

        self::assertSame(422, $imported['status']);
        $recipes = $this->recipes($this->app)['recipes'];
        self::assertCount(1, $recipes);
        self::assertSame('Keep me', $recipes[0]['title']);
        self::assertFileExists($this->root . '/public/images/carbonara.png', 'nothing is deleted before the import is valid');
    }

    public function testWithoutReplaceOptionExistingRecipesStay(): void
    {
        $this->createRecipe('Existing', '');
        $archive = $this->zip([
            'recipes.json' => json_encode(['recipes' => [[
                'title' => 'Imported',
                'cooking_time' => 5,
                'ingredients' => ['salt'],
                'steps' => ['Cook.'],
            ]]]),
        ]);

        $imported = $this->importArchive($this->app, $archive);

        self::assertSame(201, $imported['status']);
        $recipes = $this->recipes($this->app)['recipes'];
        self::assertCount(2, $recipes);
        $titles = array_column($recipes, 'title');
        sort($titles);
        self::assertSame(['Existing', 'Imported'], $titles);
    }

    public function testArchiveWithoutRecipesJsonIsRejected(): void
    {
        $archive = $this->root . '/empty.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('images/carbonara.png', base64_decode(self::PNG));
        $zip->close();

        $imported = $this->importArchive($this->app, $archive);

        self::assertSame(400, $imported['status']);
        self::assertStringContainsString('recipes.json', $imported['json']['error']);
    }

    public function testArchiveWithBrokenJsonIsRejected(): void
    {
        $archive = $this->zip([
            'recipes.json' => '{"recipes": [',
        ]);

        $imported = $this->importArchive($this->app, $archive);

        self::assertSame(400, $imported['status']);
        self::assertStringContainsString('not valid JSON', $imported['json']['error']);
    }

    public function testArchiveEntriesCannotEscapeTheUploadFolder(): void
    {
        $archive = $this->zip([
            'recipes.json' => json_encode(['recipes' => [[
                'title' => 'Safe',
                'cooking_time' => 5,
                'ingredients' => ['salt'],
                'steps' => ['Cook.'],
                'image' => 'images/safe.png',
            ]]]),
            'images/safe.png' => base64_decode(self::PNG),
            '../../evil.php' => '<?php echo "escaped";',
        ]);

        $imported = $this->importArchive($this->app, $archive);

        self::assertSame(201, $imported['status']);
        self::assertSame(1, $imported['json']['imported']);
        self::assertMatchesRegularExpression('#^uploads/[a-f0-9]+\.png$#', $imported['json']['recipes'][0]['image']);
        self::assertFileDoesNotExist($this->root . '/evil.php');
        self::assertFileDoesNotExist(dirname($this->root) . '/evil.php');
    }

    public function testAnImageThatIsNotReallyAnImageOnlyProducesAWarning(): void
    {
        $archive = $this->zip([
            'recipes.json' => json_encode(['recipes' => [[
                'title' => 'Broken picture',
                'cooking_time' => 5,
                'ingredients' => ['salt'],
                'steps' => ['Cook.'],
                'image' => 'images/broken.png',
            ]]]),
            'images/broken.png' => 'this is not an image',
        ]);

        $imported = $this->importArchive($this->app, $archive);

        self::assertSame(201, $imported['status']);
        self::assertSame(1, $imported['json']['imported']);
        self::assertNull($imported['json']['recipes'][0]['image']);
        self::assertCount(1, $imported['json']['warnings']);
        self::assertStringContainsString('could not be used', $imported['json']['warnings'][0]);
    }

    private function createRecipe(string $title, string $image): void
    {
        $response = $this->app->handle(new Request('POST', '/api/recipes', '', [], json_encode([
            'title' => $title,
            'image' => $image,
            'cooking_time' => 10,
            'ingredients' => ['salt'],
            'steps' => ['Cook.'],
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(201, $response->status(), $response->body());
    }

    /**
     * @param array<string, string> $post
     * @return array{status: int, json: array<string, mixed>}
     */
    private function importArchive(Application $app, string $file, array $post = []): array
    {
        $response = $app->handle(new Request('POST', '/api/recipes/import/archive', '', files: [
            'archive' => [
                'error' => UPLOAD_ERR_OK,
                'tmp_name' => $file,
                'size' => filesize($file),
                'name' => basename($file),
            ],
        ], post: $post));

        return ['status' => $response->status(), 'json' => json_decode($response->body(), true) ?? []];
    }

    /** @return array<string, mixed> */
    private function recipes(Application $app): array
    {
        return json_decode($app->handle(new Request('GET', '/api/recipes', ''))->body(), true) ?? [];
    }

    private function application(string $root): Application
    {
        return new Application(
            new JsonFileRecipeRepository($root . '/state/recipes.json'),
            images: new ImageStorage($root . '/public', $root . '/defaults'),
            config: ['auth_enabled' => false],
        );
    }

    private function newRoot(): string
    {
        $root = sys_get_temp_dir() . '/recipes-archive-test-' . bin2hex(random_bytes(4));
        mkdir($root . '/public/images', 0775, true);
        mkdir($root . '/public/uploads', 0775, true);
        file_put_contents($root . '/public/images/carbonara.png', base64_decode(self::PNG));
        $this->roots[] = $root;

        return $root;
    }

    /** @param array<string, string> $entries */
    private function zip(array $entries): string
    {
        $archive = $this->root . '/archive-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return $archive;
    }

    private function openZip(string $bytes): ZipArchive
    {
        $path = $this->root . '/export-' . bin2hex(random_bytes(4)) . '.zip';
        file_put_contents($path, $bytes);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));

        return $zip;
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
