<?php

declare(strict_types=1);

namespace App\Model;

use JsonSerializable;

final readonly class Ingredient implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $quantity = '',
    ) {
    }

    /** @param array{name: string, quantity?: string} $data */
    public static function fromArray(array $data): self
    {
        return new self(name: $data['name'], quantity: $data['quantity'] ?? '');
    }

    /** @return array{quantity: string, name: string} */
    public function jsonSerialize(): array
    {
        return ['quantity' => $this->quantity, 'name' => $this->name];
    }
}
