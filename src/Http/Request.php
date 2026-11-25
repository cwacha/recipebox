<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\HttpException;
use JsonException;

final class Request
{
    /** @var array<string, mixed> values added while the request is processed (session, identity, ...) */
    private array $attributes = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $files
     * @param array<string, mixed>  $post    parsed form fields
     * @param array<string, string> $cookies
     * @param array<string, string> $headers header names in lower case
     * @param array<string, mixed>  $server  REMOTE_ADDR, HTTPS, PHP_AUTH_USER, ...
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $basePath,
        public readonly array $query = [],
        public readonly string $rawBody = '',
        public readonly array $files = [],
        public readonly array $post = [],
        public readonly array $cookies = [],
        public readonly array $headers = [],
        public readonly array $server = [],
        ?Session $session = null,
    ) {
        if ($session !== null) {
            $this->attributes['session'] = $session;
        }
    }

    /**
     * @param string|null $baseUrl the URL prefix the application is served under ('' = document root),
     *                             null to derive it from the front controller location (SCRIPT_NAME)
     */
    public static function fromGlobals(?string $baseUrl = null): self
    {
        $uriPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

        if ($baseUrl !== null) {
            // explicit setting: it is authoritative, also for links when a proxy already stripped the prefix
            $base = self::normalizeBaseUrl($baseUrl);
        } else {
            // derive it from the location of the front controller
            // ("" at a document root, "/~user/recipes/public" inside a user directory, ...)
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
            $base = rtrim(dirname($script), '/');
            if ($base === '.' || $base === '/') {
                $base = '';
            }
            if ($base !== '' && $uriPath !== $base && !str_starts_with($uriPath, $base . '/')) {
                $base = '';
            }
        }

        $path = $base !== '' && ($uriPath === $base || str_starts_with($uriPath, $base . '/'))
            ? '/' . trim(substr($uriPath, strlen($base)), '/')
            : $uriPath;

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            path: $path,
            basePath: $base,
            query: $_GET,
            rawBody: (string) file_get_contents('php://input'),
            files: $_FILES,
            post: $_POST,
            cookies: array_filter($_COOKIE, is_string(...)),
            headers: self::readHeaders(),
            server: $_SERVER,
        );
    }

    /** @return array<string, string> */
    private static function readHeaders(): array
    {
        $headers = [];
        foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }

        return $headers;
    }

    /** '' means the document root, otherwise a prefix such as "/~user/recipebox/public". */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '/')) {
            $path = (string) strtok($url, '?#'); // a path (possibly protocol relative: //host is not supported)
        } else {
            $parts = parse_url($url);
            $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        }
        $path = '/' . trim((string) preg_replace('#/+#', '/', $path), '/');
        if (str_contains($path, '..') || str_contains($path, '\\')) {
            return '';
        }

        return $path === '/' ? '' : rtrim($path, '/');
    }

    /** The same request as seen under another URL prefix (e.g. from the configured base URL). */
    public function withBasePath(string $basePath): self
    {
        $copy = new self(
            $this->method, $this->path, $basePath, $this->query, $this->rawBody, $this->files,
            $this->post, $this->cookies, $this->headers, $this->server,
            $this->attributes['session'] ?? null,
        );
        $copy->attributes = $this->attributes;

        return $copy;
    }

    public function with(string $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->attributes[$key] = $value;

        return $copy;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function session(): Session
    {
        return $this->attributes['session'] ?? throw new \LogicException('The request has no session yet.');
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));

        return ($https !== '' && $https !== 'off') || (int) ($this->server['SERVER_PORT'] ?? 0) === 443;
    }

    /** Requests that change something and therefore need a CSRF token when they rely on cookies. */
    public function isUnsafe(): bool
    {
        return !in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    /**
     * HTTP Basic credentials: [user, password], null if the request has none, false if the header is malformed.
     *
     * @return array{string, string}|null|false
     */
    public function basicAuth(): array|null|false
    {
        $header = $this->header('authorization')
            ?? ($this->server['HTTP_AUTHORIZATION'] ?? $this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null);

        if ($header === null) {
            if (isset($this->server['PHP_AUTH_USER'])) {
                return [(string) $this->server['PHP_AUTH_USER'], (string) ($this->server['PHP_AUTH_PW'] ?? '')];
            }

            return null;
        }

        if (!is_string($header) || preg_match('/^basic(\s|$)/i', $header) !== 1) {
            return null; // another scheme, not ours
        }
        if (preg_match('/^basic\s+([A-Za-z0-9+\/]+={0,2})$/i', $header, $m) !== 1) {
            return false;
        }

        $decoded = base64_decode($m[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return false;
        }
        [$user, $password] = explode(':', $decoded, 2);

        return [$user, $password];
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
