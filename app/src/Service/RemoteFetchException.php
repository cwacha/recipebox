<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Failure inside RemoteFetcher; the image and page fetchers translate it into their own exception types. */
final class RemoteFetchException extends RuntimeException
{
}
