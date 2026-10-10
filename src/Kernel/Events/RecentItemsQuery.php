<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

/** Asks modules for their newest items. A listener checks wants() and add()s what it has. */
final class RecentItemsQuery extends Event
{
    /** @var list<RecentItem> */
    private array $items = [];

    public function __construct(
        public readonly int $limit = 5,
        public readonly ?string $module = null,
        public readonly ?string $itemtype = null,
    ) {}

    public function wants(string $module, string $itemtype): bool
    {
        return ($this->module === null || $this->module === $module) && ($this->itemtype === null || $this->itemtype === $itemtype);
    }

    public function add(RecentItem $item): void
    {
        $this->items[] = $item;
    }

    /** @return list<RecentItem> newest first, undated last, at most $limit */
    public function items(): array
    {
        $items = $this->items;
        usort($items, static fn(RecentItem $a, RecentItem $b): int => ($b->date?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->date?->getTimestamp() ?? PHP_INT_MIN));

        return array_slice($items, 0, max(0, $this->limit));
    }
}
