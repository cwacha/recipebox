<?php

declare(strict_types=1);

namespace App\Http;

class RedirectResponse extends Response
{
    public function __construct(string $location, int $status = 301)
    {
        parent::__construct('', $status, ['Location' => $location]);
    }
}
