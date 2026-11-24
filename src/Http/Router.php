<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\HttpException;
use App\Exception\NotFoundException;
use Closure;

final class Router
{
    /** @var list<array{string, string, Closure, ?string}> */
    private array $routes = [];

    /**
     * Registers a route; "{name}" segments are passed to the handler as string arguments.
     * $permission is what the signed-in account needs to call it; null marks a public route.
     */
    public function add(string $method, string $pattern, callable $handler, ?string $permission): self
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = [strtoupper($method), $regex, $handler(...), $permission];

        return $this;
    }

    public function match(Request $request): Route
    {
        $pathMatched = false;

        foreach ($this->routes as [$method, $regex, $handler, $permission]) {
            if (preg_match($regex, $request->path, $m) !== 1) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $request->method) {
                continue;
            }

            return new Route($handler, array_filter($m, is_string(...), ARRAY_FILTER_USE_KEY), $permission);
        }

        throw $pathMatched ? new HttpException(405, 'Method not allowed') : new NotFoundException('Route not found');
    }
}
