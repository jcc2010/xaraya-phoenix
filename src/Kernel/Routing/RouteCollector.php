<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use Closure;

final class RouteCollector
{
    /** @var list<Route> */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function get(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['GET'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function post(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['POST'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function put(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['PUT'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function patch(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['PATCH'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function delete(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['DELETE'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function any(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler, $name);
    }

    /**
     * @param list<string> $middleware
     * @param callable(self): void $define
     */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        $previous = [$this->prefix, $this->groupMiddleware];
        $this->prefix .= rtrim($prefix, '/');
        $this->groupMiddleware = [...$this->groupMiddleware, ...$middleware];
        try {
            $define($this);
        } finally {
            [$this->prefix, $this->groupMiddleware] = $previous;
        }
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param list<string> $methods
     * @param string|array{0: string, 1: string}|Closure $handler
     */
    private function add(array $methods, string $path, string|array|Closure $handler, ?string $name): Route
    {
        $route = new Route($methods, $this->prefix . $path, $handler, $name);
        if ($this->groupMiddleware !== []) {
            $route->middleware(...$this->groupMiddleware);
        }
        $this->routes[] = $route;

        return $route;
    }
}
