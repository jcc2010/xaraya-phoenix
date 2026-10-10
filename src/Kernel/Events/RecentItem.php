<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

use DateTimeImmutable;

final class RecentItem
{
    public function __construct(
        public readonly string $module,
        public readonly string $title,
        public readonly string $url,
        public readonly ?DateTimeImmutable $date = null,
    ) {}
}
