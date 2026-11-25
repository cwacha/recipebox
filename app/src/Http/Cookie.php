<?php

declare(strict_types=1);

namespace App\Http;

final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $value,
        public int $expires = 0,
        public string $path = '/',
        public bool $secure = false,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
    }

    /** A cookie that makes the browser delete the existing one. */
    public function expired(): self
    {
        return new self($this->name, '', time() - 3600, $this->path, $this->secure, $this->httpOnly, $this->sameSite);
    }
}
