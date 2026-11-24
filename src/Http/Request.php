<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\HttpException;
use JsonException;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $files
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        public readonly array $query = [],
        public readonly string $rawBody = '',
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

        // Directory the app is served from (e.g. "" or "/recipes"), derived from the front controller location
        $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($base !== '' && !str_starts_with($uriPath, $base . '/') && $uriPath !== $base) {
            $base = '';
        }

        $path = '/' . trim(substr($uriPath, strlen($base)), '/');

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            basePath: $base,
            query: $_GET,
            rawBody: (string) file_get_contents('php://input'),
            files: $_FILES,
        );
    }

    /** @return array<mixed> */
    public function json(): array
    {
        if (trim($this->rawBody) === '') {
            return [];
        }

        try {
            $data = json_decode($this->rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HttpException(400, 'Invalid JSON: ' . $e->getMessage());
        }

        if (!is_array($data)) {
            throw new HttpException(400, 'Expected a JSON object or array.');
        }

        return $data;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }
}
