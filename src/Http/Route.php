<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

final readonly class Route
{
    /** @param array<string, string> $params */
    public function __construct(
        private Closure $handler,
        private array $params,
        public ?string $permission,
    ) {
    }

    public function run(Request $request): Response
    {
        return ($this->handler)($request, ...$this->params);
    }
}
