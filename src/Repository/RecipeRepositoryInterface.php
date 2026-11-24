<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Recipe;

interface RecipeRepositoryInterface
{
    /** @return list<Recipe> newest first */
    public function all(): array;

    public function find(string $id): ?Recipe;

    /**
     * Full text search over title, category, tags, ingredients, description and steps.
     * All words of the query must match. Results are ordered by relevance (or newest first without query).
     *
     * @return list<Recipe>
     */
    public function search(string $query = '', ?string $category = null): array;

    /** @return list<string> */
    public function categories(): array;

    /** Creates or updates (by id) a recipe. */
    public function save(Recipe $recipe): Recipe;

    /**
     * Saves several recipes in one atomic operation.
     *
     * @param  list<Recipe> $recipes
     * @return list<Recipe>
     */
    public function saveMany(array $recipes): array;

    public function delete(string $id): bool;

    public function nextId(): string;
}
