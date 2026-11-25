<?php

declare(strict_types=1);

namespace App\Http;

class Response
{
    /** @var list<Cookie> */
    protected array $cookies = [];

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

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function addCookie(Cookie $cookie): static
    {
        $this->cookies[] = $cookie;

        return $this;
    }

    /** @return list<Cookie> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function send(): void
    {
        $this->sendHeaders();
        echo $this->body;
    }

    /** Sends the status line, the headers and the cookies; streaming responses use this before writing. */
    protected function sendHeaders(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header("$name: $value");
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie->name, $cookie->value, [
                'expires' => $cookie->expires,
                'path' => $cookie->path,
                'secure' => $cookie->secure,
                'httponly' => $cookie->httpOnly,
                'samesite' => $cookie->sameSite,
            ]);
        }
    }
}
