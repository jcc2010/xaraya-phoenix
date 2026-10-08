<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use function FastRoute\cachedDispatcher;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector as FastCollector;

use function FastRoute\simpleDispatcher;

use Nyholm\Psr7\Response;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Exception\NotFound;

final class Router
{
    /** @var list<Route> */
    private array $routes;

    private Dispatcher $dispatcher;

    /** @param list<Route> $routes */
    public function __construct(array $routes, ?string $cacheFile = null)
    {
        $this->routes = $this->withPreflight($routes);
        $define = function (FastCollector $collector): void {
            foreach ($this->routes as $index => $route) {
                $collector->addRoute($route->methods, $route->path, $index);
            }
        };
        if ($cacheFile !== null && !is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0775, true);
        }
        $this->dispatcher = $cacheFile === null
            ? simpleDispatcher($define)
            : cachedDispatcher($define, ['cacheFile' => $cacheFile]);
    }

    public function match(string $method, string $path): RouteMatch
    {
        $result = $this->dispatcher->dispatch(strtoupper($method), $path);

        return match ($result[0]) {
            Dispatcher::FOUND => new RouteMatch($this->routes[$result[1]], $result[2]),
            Dispatcher::METHOD_NOT_ALLOWED => throw new MethodNotAllowed(array_values($result[1])),
            default => throw new NotFound(),
        };
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param list<Route> $routes
     * @return list<Route>
     */
    private function withPreflight(array $routes): array
    {
        $seen = [];
        foreach ($routes as $route) {
            if (in_array('OPTIONS', $route->methods, true)) {
                $seen[$route->path] = true;
            }
        }
        $extra = [];
        foreach ($routes as $route) {
            if ($route->hasMiddleware('cors') && !isset($seen[$route->path])) {
                $seen[$route->path] = true;
                $extra[] = (new Route(['OPTIONS'], $route->path, static fn() => new Response(204)))->middleware('cors');
            }
        }

        return [...$routes, ...$extra];
    }
}
