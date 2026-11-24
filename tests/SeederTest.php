<?php

declare(strict_types=1);

namespace Tests;

use App\Repository\JsonFileRecipeRepository;
use App\Service\Seeder;
use PHPUnit\Framework\TestCase;

final class SeederTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/recipes-seed-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/defaults/images', 0775, true);
        file_put_contents($this->root . '/defaults/recipes.json', json_encode(['recipes' => [[
            'id' => 'seed1',
            'title' => 'Seeded',
            'cooking_time' => 10,
            'ingredients' => [['quantity' => '1', 'name' => 'x']],
            'steps' => ['y'],
            'image' => 'images/a.jpg',
            'created_at' => '2026-01-01T10:00:00+00:00',
            'updated_at' => '2026-01-01T10:00:00+00:00',
        ]]]));
        file_put_contents($this->root . '/defaults/images/a.jpg', 'default-a');
        file_put_contents($this->root . '/defaults/images/b.jpg', 'default-b');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testSeedsRecipesAndImages(): void
    {
        $repo = new JsonFileRecipeRepository($this->root . '/var/state/recipes.json');

        self::assertSame(1, $this->seeder()->seed($repo));
        self::assertSame('Seeded', $repo->find('seed1')?->title);
        self::assertSame('default-a', file_get_contents($this->root . '/public/images/a.jpg'));
        self::assertSame('default-b', file_get_contents($this->root . '/public/images/b.jpg'));
        self::assertFileDoesNotExist($this->root . '/public/images/seed');
    }

    public function testKeepsExistingImagesWhenSeeding(): void
    {
        mkdir($this->root . '/public/images', 0775, true);
        file_put_contents($this->root . '/public/images/a.jpg', 'customised');

        $this->seeder()->seed(new JsonFileRecipeRepository($this->root . '/var/state/recipes.json'));

        self::assertSame('customised', file_get_contents($this->root . '/public/images/a.jpg'));
        self::assertSame('default-b', file_get_contents($this->root . '/public/images/b.jpg'));
    }

    public function testDoesNothingWithoutDefaults(): void
    {
        unlink($this->root . '/defaults/recipes.json');
        $repo = new JsonFileRecipeRepository($this->root . '/var/state/recipes.json');

        self::assertSame(0, $this->seeder()->seed($repo));
        self::assertSame([], $repo->all());
    }

    private function seeder(): Seeder
    {
        return new Seeder($this->root . '/defaults', $this->root . '/public');
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
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
