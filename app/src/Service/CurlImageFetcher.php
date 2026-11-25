<?php

declare(strict_types=1);

namespace App\Service;

/** Downloads images from the internet with the safety rules of RemoteFetcher (see there). */
final class CurlImageFetcher implements ImageFetcher
{
    public function __construct(
        private readonly int $maxBytes = 8 * 1024 * 1024,
        private readonly int $timeout = 15,
        private readonly int $maxRedirects = 3,
        private readonly bool $allowPrivateHosts = false,
    ) {
    }

    public function fetch(string $url): string
    {
        try {
            return (new RemoteFetcher(
                'image/*',
                'RecipeBox/1.0 (image import)',
                $this->maxBytes,
                $this->timeout,
                $this->maxRedirects,
                $this->allowPrivateHosts,
            ))->fetch($url);
        } catch (RemoteFetchException $e) {
            throw new ImageFetchException($e->getMessage(), 0, $e);
        }
    }

    /** Whether an IP address belongs to the public internet; kept here for callers that only use image imports. */
    public static function isPublicAddress(string $ip): bool
    {
        return RemoteFetcher::isPublicAddress($ip);
    }
}
