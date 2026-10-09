<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Sync;

final class SyncReport
{
    public int $pages = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $skipped = 0;
    public int $deleted = 0;
    public bool $notModified = false;
    public bool $alreadyRunning = false;
    public bool $valveTripped = false;

    public function __construct(public readonly string $handle, public readonly bool $full) {}

    public function ok(): bool
    {
        return !$this->valveTripped;
    }

    public function summary(): string
    {
        if ($this->alreadyRunning) {
            return "{$this->handle}: already running, skipped";
        }
        if ($this->notModified) {
            return "{$this->handle}: not modified";
        }
        $line = sprintf(
            '%s: %d page(s), %d created, %d updated, %d unchanged, %d skipped, %d deleted',
            $this->handle,
            $this->pages,
            $this->created,
            $this->updated,
            $this->unchanged,
            $this->skipped,
            $this->deleted,
        );

        return $this->valveTripped
            ? $line . ' - SAFETY VALVE: the full walk saw under half of the live items, so nothing was deleted'
            : $line;
    }
}
