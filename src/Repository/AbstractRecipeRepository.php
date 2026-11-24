<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Recipe;

/**
 * Storage independent behaviour (search, ranking, categories, id generation).
 * Concrete repositories only have to implement how recipes are read and written.
 */
abstract class AbstractRecipeRepository implements RecipeRepositoryInterface
{
    public function find(string $id): ?Recipe
    {
        foreach ($this->all() as $recipe) {
            if ($recipe->id === $id) {
                return $recipe;
            }
        }

        return null;
    }

    public function save(Recipe $recipe): Recipe
    {
        return $this->saveMany([$recipe])[0];
    }

    public function search(string $query = '', ?string $category = null): array
    {
        $recipes = $this->all();

        if ($category !== null && $category !== '') {
            $recipes = array_values(array_filter(
                $recipes,
                static fn (Recipe $r): bool => mb_strtolower($r->category) === mb_strtolower($category),
            ));
        }

        $terms = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($terms === []) {
            return $recipes;
        }

        $scored = [];
        foreach ($recipes as $recipe) {
            $score = $this->score($recipe, $terms);
            if ($score > 0) {
                $scored[] = [$score, $recipe];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);

        return array_map(static fn (array $row): Recipe => $row[1], $scored);
    }

    public function categories(): array
    {
        $categories = [];
        foreach ($this->all() as $recipe) {
            if ($recipe->category !== '') {
                $categories[mb_strtolower($recipe->category)] ??= $recipe->category;
            }
        }
        natcasesort($categories);

        return array_values($categories);
    }

    public function nextId(): string
    {
        do {
            $id = bin2hex(random_bytes(6));
        } while ($this->find($id) !== null);

        return $id;
    }

    /**
     * @param list<string> $terms lower case search words
     * @return int 0 when any term is not found anywhere
     */
    protected function score(Recipe $recipe, array $terms): int
    {
        $fields = [
            10 => mb_strtolower($recipe->title),
            5 => mb_strtolower($recipe->category),
            4 => mb_strtolower(implode(' ', $recipe->tags)),
            3 => mb_strtolower(implode(' ', array_map(static fn ($i): string => $i->name, $recipe->ingredients))),
            2 => mb_strtolower($recipe->description),
            1 => mb_strtolower(implode(' ', $recipe->steps)),
        ];

        $total = 0;
        foreach ($terms as $term) {
            $best = 0;
            foreach ($fields as $weight => $haystack) {
                if (str_contains($haystack, $term)) {
                    $best = max($best, $weight + (str_starts_with($haystack, $term) ? 1 : 0));
                }
            }
            if ($best === 0) {
                return 0;
            }
            $total += $best;
        }

        return $total;
    }
}
