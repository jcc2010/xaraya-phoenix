<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use Xaraya\Module\Blog\Post\PostRecord;

interface SourceAdapter
{
    /**
     * @param array<string, mixed> $item one JSON Feed item
     * @throws InvalidArgumentException when the item can't be stored
     */
    public function toRecord(array $item, DateTimeImmutable $now): PostRecord;

    /**
     * @param array<array-key, mixed> $feed a page envelope
     * @return array<string, mixed> blog column => value; always has 'pinned_item_id' and 'extra'
     */
    public function feedMeta(array $feed): array;
}
