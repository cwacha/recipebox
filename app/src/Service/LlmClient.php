<?php

declare(strict_types=1);

namespace App\Service;

/** A chat-completions style LLM endpoint; returns the text of the model's answer. */
interface LlmClient
{
    /**
     * @param callable(): bool|null $cancelled asked while the request is running; true aborts it
     *
     * @throws RecipeImportException when the request fails, is cancelled or the answer contains no text
     */
    public function complete(string $prompt, ?callable $cancelled = null): string;
}
