<?php

declare(strict_types=1);

namespace App\Service;

use App\Validation\RecipeValidator;

/**
 * Downloads the page itself and asks an LLM to turn it into one recipe. The prompt may contain {html} and {url};
 * the HTML is inserted there, or appended to the prompt when {html} is missing.
 */
final class LlmRecipeExtractor implements RecipeExtractor
{
    public const DEFAULT_PROMPT = <<<'PROMPT'
You are a recipe extraction service. The HTML source of the web page at {url} is included below. Return its cooking recipe as one JSON object. Return JSON only, without markdown fences or commentary.

The object must have these keys:
- "title": the recipe name (string, required)
- "description": a short summary (string, may be empty)
- "category": for example "Soup", "Main course" or "Dessert" (string, may be empty)
- "image": the absolute URL of the main photo (string, empty if there is none)
- "cooking_time": the total preparation and cooking time in whole minutes (integer, required, at least 1)
- "ingredients": an array of objects with "quantity" and "name" (required)
- "steps": an array of instruction strings (required)
- "tags": an array of short lowercase strings such as "vegetarian" or "italian"

Ignore navigation, advertisements and other page furniture. Use only information from the page; do not invent ingredients or steps. If the page is not a recipe, return {"error":"not a recipe"}.

The page HTML follows:
{html}
PROMPT;

    public function __construct(
        private readonly LlmClient $client,
        private readonly PageFetcher $fetcher,
        private readonly RecipeValidator $validator,
        private readonly string $prompt = self::DEFAULT_PROMPT,
    ) {
    }

    public function extract(string $url, ?callable $onStep = null, ?callable $cancelled = null): array
    {
        $onStep ??= static function (string $step): void {
        };

        $prompt = trim($this->prompt) === '' ? self::DEFAULT_PROMPT : $this->prompt;

        $onStep('download');
        $html = $this->fetcher->fetch($url);

        // {url} is replaced first so that nothing inside the HTML can be taken for a placeholder
        $request = str_replace('{url}', $url, $prompt);
        $request = str_contains($request, '{html}')
            ? str_replace('{html}', $html, $request)
            : $request . "\n\nThe web page HTML follows:\n" . $html;

        $onStep('ask');
        $answer = $this->client->complete($request, $cancelled);

        $onStep('parse');

        return $this->validator->validate($this->decode($answer));
    }

    /** @return array<mixed> */
    private function decode(string $answer): array
    {
        $answer = trim($answer);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $answer, $match) === 1) {
            $answer = $match[1];
        }

        $decoded = json_decode($answer, true);
        if (!is_array($decoded)) {
            $candidate = $this->firstJsonValue($answer);
            $decoded = $candidate === null ? null : json_decode($candidate, true);
        }
        if (!is_array($decoded)) {
            throw new RecipeImportException('the model did not answer with valid JSON');
        }

        if (isset($decoded['recipe']) && is_array($decoded['recipe'])) {
            $decoded = $decoded['recipe'];
        } elseif (isset($decoded['recipes']) && is_array($decoded['recipes'])) {
            $decoded = $this->single($decoded['recipes']);
        } elseif (array_is_list($decoded)) {
            $decoded = $this->single($decoded);
        }

        if (isset($decoded['error']) && is_scalar($decoded['error'])) {
            $error = trim((string) $decoded['error']);
            throw new RecipeImportException($error === '' ? 'the page does not contain a recipe' : $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RecipeImportException('the model did not answer with a recipe object');
        }

        return $decoded;
    }

    /**
     * @param  array<mixed> $recipes
     * @return array<mixed>
     */
    private function single(array $recipes): array
    {
        $recipes = array_values($recipes);
        if (count($recipes) !== 1 || !is_array($recipes[0])) {
            throw new RecipeImportException('the model did not answer with exactly one recipe');
        }

        return $recipes[0];
    }

    /** Finds the first complete JSON object or array inside a text that may contain prose. */
    private function firstJsonValue(string $text): ?string
    {
        $start = null;
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
                continue;
            }

            if ($start === null) {
                if ($char === '{' || $char === '[') {
                    $start = $i;
                    $depth = 1;
                }
                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    json_decode($candidate, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $candidate;
                    }
                    $start = null;
                }
            }
        }

        return null;
    }
}
