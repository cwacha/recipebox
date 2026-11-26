<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ValidationException;

/** Turns a web page into validated recipe data without saving anything. */
interface RecipeExtractor
{
    /**
     * Downloads the page, so its HTML can be handed to the model in a later request.
     *
     * @throws RecipeImportException when the page cannot be downloaded
     */
    public function fetch(string $url): string;

    /**
     * @param callable(): bool|null $cancelled asked while the model is working; true stops the request
     *
     * @return array{title: string, description: string, category: string, image: string, cooking_time: int, ingredients: list<array{quantity: string, name: string}>, steps: list<string>, tags: list<string>}
     *
     * @throws RecipeImportException when the service cannot be reached, the request is cancelled or it does not
     *                               answer with a recipe
     * @throws ValidationException   when the answer is not a complete recipe
     */
    public function extract(string $html, string $url, ?callable $cancelled = null): array;
}
