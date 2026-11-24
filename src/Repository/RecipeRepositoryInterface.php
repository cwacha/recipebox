<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Recipe;

interface RecipeRepositoryInterface
{
    /** @return list<Recipe> newest first */
    public function all(): array;

    public function find(string $id): ?Recipe;

    /** Looks a recipe up by its readable URL name. */
    public function findBySlug(string $slug): ?Recipe;

    /**
     * Full text search over title, category, tags, ingredients, description and steps.
     * All words of the query must match. Results are ordered by relevance (or newest first without query).
     *
     * @return list<Recipe>
     */
    public function search(string $query = '', ?string $category = null): array;

    /** @return list<string> */
    public function categories(): array;

    /**
     * Creates or updates (by id) a recipe. Returns the stored recipe, which has a unique slug
     * assigned if it did not have one.
     */
    public function save(Recipe $recipe): Recipe;

    /**
     * Saves several recipes in one atomic operation. Recipes without a slug get a unique one
     * derived from their title; the stored recipes are returned.
     *
     * @param  list<Recipe> $recipes
     * @return list<Recipe>
     */
    public function saveMany(array $recipes): array;

    public function delete(string $id): bool;

    public function nextId(): string;
}
