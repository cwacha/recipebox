<?php

declare(strict_types=1);

namespace App\Auth\Exception;

use RuntimeException;

/** A change was refused because afterwards nobody could administer accounts and roles anymore. */
final class LockoutPrevented extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This change was not saved: afterwards no account could manage accounts and roles anymore.');
    }
}
