<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;

/**
 * Sends one prompt to an OpenAI-compatible chat completions endpoint and returns the answer text.
 * The API key stays on the server. Redirects are not followed so the key cannot leak to another host.
 */
final class CurlLlmClient implements LlmClient
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey = '',
        private readonly string $model = 'gpt-4o-mini',
        private readonly int $timeout = 60,
    ) {
    }

    public function complete(string $prompt, ?callable $cancelled = null): string
    {
        if (!extension_loaded('curl')) {
            throw new RecipeImportException('the curl PHP extension is not installed');
        }

        $parts = parse_url($this->endpoint);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new RecipeImportException('the configured API endpoint is not an http(s) URL');
        }

        try {
            $body = json_encode([
                'model' => $this->model,
                'temperature' => 0,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RecipeImportException('the prompt could not be encoded: ' . $e->getMessage());
        }

        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        // A model that has to read a web page can answer slowly. PHP's default 30 s limit would abort the script
        // while it waits for curl; the request's own timeout below is what ends the call.
        if (function_exists('set_time_limit')) {
            @set_time_limit(max(1, $this->timeout) + 15);
        }

        $ch = curl_init();
        $cancelled ??= static fn (): bool => false;
        $lastCheck = 0.0;
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(1, $this->timeout),
            CURLOPT_USERAGENT => 'RecipeBox/1.0 (recipe import)',
            // Ask regularly whether the caller gave up (e.g. the browser tab was closed) and stop the request then,
            // so a cancelled import does not keep a model busy.
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => static function () use ($cancelled, &$lastCheck): int {
                $now = microtime(true);
                if ($now - $lastCheck < 0.5) {
                    return 0;
                }
                $lastCheck = $now;

                return $cancelled() ? 1 : 0;
            },
        ]);

        $answer = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        unset($ch);

        if ($answer === false) {
            throw new RecipeImportException($errno === CURLE_ABORTED_BY_CALLBACK
                ? 'the request was cancelled'
                : ($error !== '' ? $error : 'the request failed'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RecipeImportException('the API answered with HTTP ' . $status . $this->detail($answer));
        }

        return $this->text($answer);
    }

    private function text(string $answer): string
    {
        try {
            $decoded = json_decode($answer, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RecipeImportException('the API did not answer with JSON');
        }
        if (!is_array($decoded)) {
            throw new RecipeImportException('the API did not answer with a JSON object');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? $decoded['output_text'] ?? null;
        $content = $this->flatten($content);

        if ($content === '' && isset($decoded['output']) && is_array($decoded['output'])) {
            // OpenAI Responses API: output[].content[].text
            $parts = [];
            foreach ($decoded['output'] as $output) {
                if (is_array($output) && isset($output['content']) && is_array($output['content'])) {
                    foreach ($output['content'] as $part) {
                        $parts[] = $this->flatten($part['text'] ?? null);
                    }
                }
            }
            $content = trim(implode("\n", array_filter($parts)));
        }

        if ($content === '') {
            throw new RecipeImportException('the API response did not contain any text');
        }

        return $content;
    }

    /** Chat completion content may be a string or a list of content parts. */
    private function flatten(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (!is_array($content)) {
            return '';
        }

        $parts = [];
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = $part;
            } elseif (is_array($part) && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /** A short, safe description of the error field of an API response. */
    private function detail(string $answer): string
    {
        $decoded = json_decode($answer, true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? '') : '';
        $message = is_scalar($message) ? trim((string) $message) : '';
        if ($message === '') {
            return '';
        }

        $message = mb_substr($message, 0, 300);

        return ': ' . $message;
    }
}
