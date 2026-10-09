<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Sync;

use RuntimeException;

final class SyncLock
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(private readonly string $directory) {}

    public function acquire(string $name): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Cannot create lock directory {$this->directory}");
        }
        $handle = @fopen($this->directory . '/blog-' . $name . '.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file for '{$name}'");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handles[$name] = $handle;

        return true;
    }

    public function release(string $name): void
    {
        if (!isset($this->handles[$name])) {
            return;
        }
        flock($this->handles[$name], LOCK_UN);
        fclose($this->handles[$name]);
        unset($this->handles[$name]);
    }
}
