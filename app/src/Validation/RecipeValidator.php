<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

/**
 * Validates and normalises recipe input (from the editor form or from uploaded JSON).
 *
 * Lenient on input shape: ingredients may be plain strings or {quantity, name} objects,
 * tags may be an array or a comma separated string, steps may be strings or {text} objects.
 */
final class RecipeValidator
{
    private const MAX_TITLE = 150;
    private const MAX_TIME = 10080; // one week in minutes

    /**
     * @param  array<mixed> $input
     * @return array{title: string, description: string, category: string, image: string, cooking_time: int, ingredients: list<array{quantity: string, name: string}>, steps: list<string>, tags: list<string>}
     * @throws ValidationException
     */
    public function validate(array $input, string $prefix = ''): array
    {
        $errors = [];

        $title = $this->text($input['title'] ?? '');
        if ($title === '') {
            $errors['title'] = 'A title is required.';
        } elseif (mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'The title is too long (max ' . self::MAX_TITLE . ' characters).';
        }

        $timeRaw = $input['cooking_time'] ?? $input['cookingTime'] ?? null;
        $time = filter_var($timeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_TIME]]);
        if ($time === false) {
            $errors['cooking_time'] = 'Cooking time must be a whole number of minutes (1 or more).';
        }

        $image = $this->text($input['image'] ?? '');
        if ($image !== '' && !$this->isAllowedImage($image)) {
            $errors['image'] = 'Image must be an http(s) URL or a path to an uploaded image.';
        }

        $ingredients = [];
        $rawIngredients = $input['ingredients'] ?? [];
        if (!is_array($rawIngredients)) {
            $errors['ingredients'] = 'Ingredients must be a list.';
        } else {
            foreach ($rawIngredients as $raw) {
                $ingredient = $this->ingredient($raw);
                if ($ingredient !== null) {
                    $ingredients[] = $ingredient;
                }
            }
            if ($ingredients === []) {
                $errors['ingredients'] = 'Add at least one ingredient.';
            }
        }

        $steps = [];
        $rawSteps = $input['steps'] ?? [];
        if (!is_array($rawSteps)) {
            $errors['steps'] = 'Steps must be a list.';
        } else {
            foreach ($rawSteps as $raw) {
                $text = $this->text(is_array($raw) ? ($raw['text'] ?? '') : $raw);
                if ($text !== '') {
                    $steps[] = $text;
                }
            }
            if ($steps === []) {
                $errors['steps'] = 'Add at least one preparation step.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($prefix === ''
                ? $errors
                : array_combine(array_map(static fn (string $k): string => $prefix . $k, array_keys($errors)), $errors));
        }

        return [
            'title' => $title,
            'description' => $this->text($input['description'] ?? ''),
            'category' => $this->text($input['category'] ?? ''),
            'image' => $image,
            'cooking_time' => $time,
            'ingredients' => $ingredients,
            'steps' => $steps,
            'tags' => $this->tags($input['tags'] ?? []),
        ];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return array{quantity: string, name: string}|null */
    private function ingredient(mixed $raw): ?array
    {
        if (is_array($raw)) {
            $name = $this->text($raw['name'] ?? $raw['item'] ?? '');
            $quantity = $this->text($raw['quantity'] ?? $raw['amount'] ?? '');
        } else {
            $name = $this->text($raw);
            $quantity = '';
        }

        return $name === '' ? null : ['quantity' => $quantity, 'name' => $name];
    }

    /** @return list<string> */
    private function tags(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $tags = [];
        foreach ($raw as $tag) {
            $tag = mb_strtolower($this->text($tag));
            if ($tag !== '' && !in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return array_slice($tags, 0, 12);
    }

    private function isAllowedImage(string $image): bool
    {
        if (preg_match('#^https?://[^\s]+$#i', $image) === 1) {
            return true;
        }

        // relative path inside the public directory, no traversal, no scheme
        return preg_match('#^(?!/)(?!.*\.\.)[\w\-./]+\.(?:jpe?g|png|webp|gif|avif)$#i', $image) === 1;
    }
}
