<?php

declare(strict_types=1);

namespace App\Auth\Exception;

use InvalidArgumentException;

final class ValidationFailed extends InvalidArgumentException
{
    /** @param array<string, string> $errors field => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
