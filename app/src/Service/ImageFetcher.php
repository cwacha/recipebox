<?php

declare(strict_types=1);

namespace App\Service;

interface ImageFetcher
{
    /**
     * Downloads a remote file and returns its raw bytes.
     *
     * @throws ImageFetchException
     */
    public function fetch(string $url): string;
}
