<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

abstract class Event
{
    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
