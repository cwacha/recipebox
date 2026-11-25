<?php

declare(strict_types=1);

namespace App\Service;

/** Downloads a web page so that its HTML can be handed to the LLM. */
interface PageFetcher
{
    /**
     * @return string the HTML of the page
     *
     * @throws RecipeImportException when the page cannot be downloaded
     */
    public function fetch(string $url): string;
}
