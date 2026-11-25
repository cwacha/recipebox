<?php

declare(strict_types=1);

namespace App\Auth\Exception;

use RuntimeException;

/** Account, role or token data could not be read or written. */
final class StorageFailed extends RuntimeException
{
}
