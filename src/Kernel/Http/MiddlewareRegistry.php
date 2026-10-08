<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Closure;
use LogicException;
use Psr\Http\Server\MiddlewareInterface;
use Xaraya\Kernel\Container\Container;

final class MiddlewareRegistry
{
    /** @var array<string, string|Closure> */
    private array $aliases = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $alias, string|Closure $factory): void
    {
        $this->aliases[$alias] = $factory;
    }

    public function resolve(string $spec): MiddlewareInterface
    {
        [$alias, $argString] = array_pad(explode(':', $spec, 2), 2, '');
        $factory = $this->aliases[$alias] ?? throw new LogicException("Unknown middleware '{$alias}'");
        $args = $argString === '' ? [] : explode(',', $argString);
        $middleware = $factory instanceof Closure ? $factory($this->container, $args) : $this->container->make($factory);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new LogicException("Middleware '{$alias}' did not produce a MiddlewareInterface");
        }

        return $middleware;
    }
}
