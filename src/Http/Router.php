<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\HttpException;
use App\Exception\NotFoundException;
use Closure;

final class Router
{
    /** @var list<array{string, string, Closure}> */
    private array $routes = [];

    /** Registers a route; "{name}" segments are passed to the handler as string arguments. */
    public function add(string $method, string $pattern, callable $handler): self
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler(...)];

        return $this;
    }

    public function dispatch(Request $request): Response
    {
        $pathMatched = false;

        foreach ($this->routes as [$method, $regex, $handler]) {
            if (preg_match($regex, $request->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $request->method) {
                continue;
            }

            $params = array_filter($m, is_string(...), ARRAY_FILTER_USE_KEY);

            return $handler($request, ...$params);
        }

        throw $pathMatched ? new HttpException(405, 'Method not allowed') : new NotFoundException('Route not found');
    }
}
