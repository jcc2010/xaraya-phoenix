<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cache;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A small file cache. Each entry is one file under $dir, named by the sha1 of its key, so any string is a valid
 * key. Values are scalars, null or arrays of them; objects are refused because they cannot be read back safely.
 * Entries never expire: put a version (such as an updated_at) in the key. `xar cache:clear` empties $dir.
 */
final class Cache
{
    public function __construct(private readonly string $dir) {}

    public function get(string $key, mixed $default = null): mixed
    {
        [$hit, $value] = $this->read($key);

        return $hit ? $value : $default;
    }

    public function has(string $key): bool
    {
        return $this->read($key)[0];
    }

    public function set(string $key, mixed $value): void
    {
        self::assertStorable($value);
        $file = $this->file($key);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create cache directory '{$dir}'");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        $content = serialize(['v' => $value]);
        if (@file_put_contents($tmp, $content) !== strlen($content) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write cache entry '{$key}'");
        }
    }

    /**
     * @template T
     * @param callable(): T $make
     * @return T
     */
    public function remember(string $key, callable $make): mixed
    {
        [$hit, $value] = $this->read($key);
        if ($hit) {
            /** @var T $value */
            return $value;
        }
        $value = $make();
        $this->set($key, $value);

        return $value;
    }

    public function forget(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Removes every entry; returns the number of files deleted. */
    public function clear(): int
    {
        return self::removeTree($this->dir);
    }

    /** Deletes $path (a file, a symlink or a directory tree, never following links); returns the files deleted. */
    public static function removeTree(string $path): int
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path) ? 1 : 0;
        }
        if (!is_dir($path)) {
            return 0;
        }
        $removed = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $removed++;
            }
        }
        @rmdir($path);

        return $removed;
    }

    /** @return array{0: bool, 1: mixed} */
    private function read(string $key): array
    {
        $file = $this->file($key);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false) {
            return [false, null];
        }
        $entry = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($entry) && array_key_exists('v', $entry) ? [true, $entry['v']] : [false, null];
    }

    private function file(string $key): string
    {
        $hash = sha1($key);

        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    private static function assertStorable(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertStorable($item);
            }

            return;
        }
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Cache values must be scalars, null or arrays of them');
        }
    }
}
