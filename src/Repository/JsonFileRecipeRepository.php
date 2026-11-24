<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Recipe;
use RuntimeException;

/**
 * Persists recipes in a single JSON file. Writes are serialised with an exclusive
 * file lock and happen atomically (temp file + rename).
 */
class JsonFileRecipeRepository extends AbstractRecipeRepository
{
    public function __construct(private readonly string $file)
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data directory: $dir");
        }
    }

    public function all(): array
    {
        $recipes = $this->read();
        usort($recipes, static fn (Recipe $a, Recipe $b): int => [$b->updatedAt, $b->id] <=> [$a->updatedAt, $a->id]);

        return $recipes;
    }

    public function saveMany(array $recipes): array
    {
        $this->locked(function () use ($recipes): void {
            $byId = [];
            foreach ($this->read() as $existing) {
                $byId[$existing->id] = $existing;
            }
            foreach ($recipes as $recipe) {
                $byId[$recipe->id] = $recipe;
            }
            $this->write(array_values($byId));
        });

        return $recipes;
    }

    public function delete(string $id): bool
    {
        $deleted = false;
        $this->locked(function () use ($id, &$deleted): void {
            $current = $this->read();
            $remaining = array_values(array_filter($current, static fn (Recipe $r): bool => $r->id !== $id));
            $deleted = count($remaining) !== count($current);
            if ($deleted) {
                $this->write($remaining);
            }
        });

        return $deleted;
    }

    /** @return list<Recipe> */
    private function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $json = file_get_contents($this->file);
        if ($json === false || trim($json) === '') {
            return [];
        }

        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_map(Recipe::fromArray(...), $data['recipes'] ?? []);
    }

    /** @param list<Recipe> $recipes */
    private function write(array $recipes): void
    {
        $json = json_encode(
            ['recipes' => $recipes],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException('Could not write recipe data file.');
        }
    }

    private function locked(callable $operation): void
    {
        $lock = fopen($this->file . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock recipe data file.');
        }

        try {
            $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
