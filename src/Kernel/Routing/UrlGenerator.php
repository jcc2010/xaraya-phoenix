<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use InvalidArgumentException;
use LogicException;

final class UrlGenerator
{
    /** @var array<string, Route> */
    private array $named = [];

    /** @param list<Route> $routes */
    public function __construct(array $routes, private readonly string $baseUrl)
    {
        foreach ($routes as $route) {
            if ($route->name === null) {
                continue;
            }
            if (isset($this->named[$route->name])) {
                throw new LogicException("Duplicate route name '{$route->name}'");
            }
            $this->named[$route->name] = $route;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /** @param array<string, string|int> $params */
    public function generate(string $name, array $params = [], bool $absolute = false): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Unknown route '{$name}'");
        $used = [];
        $path = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/',
            function (array $m) use ($params, $name, &$used): string {
                $key = $m[1];
                if (!array_key_exists($key, $params)) {
                    throw new InvalidArgumentException("Route '{$name}' needs parameter '{$key}'");
                }
                $used[$key] = true;

                return rawurlencode((string) $params[$key]);
            },
            $route->path,
        ) ?? $route->path;
        $query = array_diff_key($params, $used);
        $url = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return $absolute ? rtrim($this->baseUrl, '/') . $url : $url;
    }
}
