<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Recipe;
use App\Repository\RecipeRepositoryInterface;
use RuntimeException;

/**
 * Initialises a fresh installation: the default recipes are saved into the (new) repository and the
 * default images are copied to the public images folder. Existing images are never overwritten.
 * The caller decides when storage counts as fresh.
 */
final class Seeder
{
    public function __construct(
        private readonly string $defaultsDir = BASEDIR . '/defaults/seed',
        private readonly string $publicDir = BASEDIR . '/public',
    ) {
    }

    /** @return int number of recipes seeded */
    public function seed(RecipeRepositoryInterface $repository): int
    {
        $source = $this->defaultsDir . '/recipes.json';
        if (!is_file($source)) {
            return 0;
        }

        $data = json_decode((string) file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
        $recipes = array_map(Recipe::fromArray(...), $data['recipes'] ?? []);

        $this->copyImages();
        $repository->saveMany($recipes);

        return count($recipes);
    }

    private function copyImages(): void
    {
        $target = $this->publicDir . '/images';
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException("Cannot create image directory: $target");
        }

        foreach (glob($this->defaultsDir . '/images/*') ?: [] as $image) {
            $destination = $target . '/' . basename($image);
            if (is_file($image) && !file_exists($destination)) {
                copy($image, $destination);
            }
        }
    }
}
