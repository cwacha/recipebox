<?php

declare(strict_types=1);

namespace App\Exception;

class ValidationException extends HttpException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(422, 'Validation failed');
    }
}
