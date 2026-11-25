<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Raised when a recipe cannot be fetched from a URL or the LLM answers with something unusable. */
final class RecipeImportException extends RuntimeException
{
}
