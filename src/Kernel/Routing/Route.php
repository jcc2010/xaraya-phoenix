<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use Closure;

final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    /**
     * @param list<string> $methods
     * @param string|array{0: string, 1: string}|Closure $handler
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly string|array|Closure $handler,
        public readonly ?string $name = null,
    ) {}

    public function middleware(string ...$aliases): self
    {
        array_push($this->middleware, ...array_values($aliases));

        return $this;
    }

    /** @return list<string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function hasMiddleware(string $alias): bool
    {
        foreach ($this->middleware as $m) {
            if ($m === $alias || str_starts_with($m, $alias . ':')) {
                return true;
            }
        }

        return false;
    }
}
