<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

abstract class ItemEvent extends Event
{
    /** @param array<string, mixed> $item */
    public function __construct(
        public readonly string $module,
        public readonly string $itemtype,
        public readonly string $id,
        public readonly array $item = [],
    ) {}
}
