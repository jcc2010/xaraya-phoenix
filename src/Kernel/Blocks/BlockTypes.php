<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;
use LogicException;
use Xaraya\Kernel\Container\Container;

final class BlockTypes
{
    /** @param array<string, string> $types type => class implementing Block */
    public function __construct(private readonly Container $container, private array $types = []) {}

    public function register(string $type, string $class): void
    {
        $this->types[$type] = $class;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->types;
    }

    public function get(string $type): Block
    {
        $class = $this->types[$type] ?? throw new InvalidArgumentException("Unknown block type '{$type}'");
        $block = $this->container->get($class);
        if (!$block instanceof Block) {
            throw new LogicException("Block type '{$type}': {$class} must implement Block");
        }

        return $block;
    }
}
