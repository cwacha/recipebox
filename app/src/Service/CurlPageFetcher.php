<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Downloads the recipe page with the safety rules of RemoteFetcher (see there), so the application does not
 * depend on whether the chosen model can open URLs itself.
 */
final class CurlPageFetcher implements PageFetcher
{
    public function __construct(
        private readonly int $maxBytes = 4 * 1024 * 1024,
        private readonly int $timeout = 20,
        private readonly int $maxRedirects = 3,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    public function fetch(string $url): string
    {
        try {
            return (new RemoteFetcher(
                'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
                'RecipeBox/1.0 (recipe import)',
                $this->maxBytes,
                $this->timeout,
                $this->maxRedirects,
                $this->allowPrivateHosts,
            ))->fetch($url);
        } catch (RemoteFetchException $e) {
            throw new RecipeImportException('the page could not be downloaded: ' . $e->getMessage() . '.', 0, $e);
        }
    }
}
