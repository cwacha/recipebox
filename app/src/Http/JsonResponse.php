<?php

declare(strict_types=1);

namespace App\Http;

class JsonResponse extends Response
{
    /** @param array<string, string> $headers */
    public function __construct(mixed $data, int $status = 200, array $headers = [])
    {
        parent::__construct(
            $status === 204 ? '' : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store', ...$headers],
        );
    }
}
