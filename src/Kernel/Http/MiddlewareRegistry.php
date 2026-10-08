<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Closure;
use LogicException;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionClass;
use Xaraya\Kernel\Container\Container;

final class MiddlewareRegistry
{
    /** @var array<string, string|Closure> */
    private array $aliases = [];

    /** @var array<string, true> */
    private array $markers = [];

    public function __construct(private readonly Container $container) {}

    /**
     * A Closure factory receives (Container, list<string> $args). A class-string factory is built by the
     * container; spec arguments are passed to a constructor parameter named `$args`, and a class without
     * one rejects arguments.
     */
    public function register(string $alias, string|Closure $factory): void
    {
        $this->aliases[$alias] = $factory;
    }

    /**
     * Registers an alias that only marks a route (for example `csrf:off`); it builds no middleware and is
     * skipped when the route stack is assembled. Global middleware reads it with Route::hasMiddleware().
     */
    public function registerMarker(string $alias): void
    {
        $this->markers[$alias] = true;
    }

    public function isMarker(string $spec): bool
    {
        return isset($this->markers[explode(':', $spec, 2)[0]]);
    }

    public function resolve(string $spec): MiddlewareInterface
    {
        [$alias, $argString] = array_pad(explode(':', $spec, 2), 2, '');
        $factory = $this->aliases[$alias] ?? throw new LogicException("Unknown middleware '{$alias}'");
        $args = $argString === '' ? [] : explode(',', $argString);
        $middleware = $factory instanceof Closure ? $factory($this->container, $args) : $this->build($alias, $factory, $args);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new LogicException("Middleware '{$alias}' did not produce a MiddlewareInterface");
        }

        return $middleware;
    }

    /**
     * @param list<string> $spec
     * @return list<MiddlewareInterface>
     */
    public function stack(array $spec): array
    {
        return array_values(array_map($this->resolve(...), array_filter($spec, fn(string $s): bool => !$this->isMarker($s))));
    }

    /** @param list<string> $args */
    private function build(string $alias, string $class, array $args): object
    {
        if (!class_exists($class)) {
            return $this->container->make($class);
        }
        $acceptsArgs = false;
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $acceptsArgs = $acceptsArgs || $parameter->getName() === 'args';
        }
        if ($acceptsArgs) {
            return $this->container->make($class, ['args' => $args]);
        }
        if ($args !== []) {
            throw new LogicException("Middleware '{$alias}' does not accept arguments");
        }

        return $this->container->make($class);
    }
}
