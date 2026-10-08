<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Events\ItemCreated;

final class CountGreetings
{
    public int $count = 0;

    public function __invoke(ItemCreated $event): void
    {
        if ($event->module === 'hello') {
            $this->count++;
        }
    }
}
