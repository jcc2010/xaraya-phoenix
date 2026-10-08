<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Container;

use ReflectionClass;
use ReflectionNamedType;

final class Container
{
    /** @var array<string, array{factory: callable, shared: bool}> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function __construct()
    {
        $this->instances[self::class] = $this;
    }

    public function set(string $id, callable $factory, bool $shared = true): void
    {
        unset($this->instances[$id]);
        $this->factories[$id] = ['factory' => $factory, 'shared' => $shared];
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', array_keys($this->resolving)) . " -> {$id}");
        }
        $this->resolving[$id] = true;
        try {
            if (isset($this->factories[$id])) {
                $value = ($this->factories[$id]['factory'])($this);
                if ($this->factories[$id]['shared']) {
                    $this->instances[$id] = $value;
                }

                return $value;
            }

            return $this->instances[$id] = $this->make($id);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * @template T of object
     * @param class-string<T>|string $class
     * @param array<string, mixed> $params
     * @return ($class is class-string<T> ? T : object)
     */
    public function make(string $class, array $params = []): object
    {
        if (!class_exists($class)) {
            throw new ContainerException("Cannot resolve '{$class}': no binding and no such class");
        }
        $ref = new ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new ContainerException("Cannot instantiate {$class} without a binding");
        }
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }
        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();
            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
                continue;
            }
            $type = $p->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
                $args[] = $this->get($type->getName());
                continue;
            }
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }
            if ($p->allowsNull()) {
                $args[] = null;
                continue;
            }
            throw new ContainerException("Cannot resolve parameter \${$name} of {$class}");
        }

        return $ref->newInstanceArgs($args);
    }
}
