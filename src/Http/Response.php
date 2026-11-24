<?php

declare(strict_types=1);

namespace App\Http;

class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        protected string $body = '',
        protected int $status = 200,
        protected array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        echo $this->body;
    }
}
