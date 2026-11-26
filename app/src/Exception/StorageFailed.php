<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/** Stored data could not be read or written. */
final class StorageFailed extends RuntimeException
{
}
