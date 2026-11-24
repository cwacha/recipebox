<?php

declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;

/**
 * A cooking recipe. All quantities are for a fixed number of servings (SERVINGS).
 */
final readonly class Recipe implements JsonSerializable
{
    public const SERVINGS = 4;

    /**
     * @param list<Ingredient> $ingredients
     * @param list<string>     $steps
     * @param list<string>     $tags
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $category,
        public ?string $image,
        public int $cookingTime,
        public array $ingredients,
        public array $steps,
        public array $tags,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** Builds a new recipe from already validated input (see RecipeValidator). */
    public static function create(string $id, array $data): self
    {
        $now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        return self::fromArray([...$data, 'id' => $id, 'created_at' => $now, 'updated_at' => $now]);
    }

    /** Returns a copy with new content, keeping id and creation date. */
    public function withData(array $data): self
    {
        return self::fromArray([
            ...$data,
            'id' => $this->id,
            'created_at' => $this->createdAt,
            'updated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ]);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            title: (string) $data['title'],
            description: (string) ($data['description'] ?? ''),
            category: (string) ($data['category'] ?? ''),
            image: ($data['image'] ?? '') !== '' ? (string) $data['image'] : null,
            cookingTime: (int) $data['cooking_time'],
            ingredients: array_map(
                static fn (array|Ingredient $i): Ingredient => $i instanceof Ingredient ? $i : Ingredient::fromArray($i),
                array_values($data['ingredients'] ?? []),
            ),
            steps: array_map('strval', array_values($data['steps'] ?? [])),
            tags: array_map('strval', array_values($data['tags'] ?? [])),
            createdAt: (string) ($data['created_at'] ?? ''),
            updatedAt: (string) ($data['updated_at'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'image' => $this->image,
            'servings' => self::SERVINGS,
            'cooking_time' => $this->cookingTime,
            'ingredients' => $this->ingredients,
            'steps' => $this->steps,
            'tags' => $this->tags,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
