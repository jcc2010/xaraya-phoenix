<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Config;

use RuntimeException;

final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}

    public static function load(string $file, ?string $cacheFile = null): self
    {
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = require $cacheFile;
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return new self($cached);
            }
        }
        $items = is_file($file) ? require $file : [];
        if (!is_array($items)) {
            throw new RuntimeException("Config file {$file} must return an array");
        }
        /** @var array<string, mixed> $items */
        $config = new self($items);
        if ($cacheFile !== null && $config->get('app.debug', false) !== true) {
            $config->writeCache($cacheFile);
        }

        return $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }
        $node = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function set(string $key, mixed $value): void
    {
        $node = &$this->items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function writeCache(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, '<?php return ' . var_export($this->items, true) . ";\n");
        rename($tmp, $file);
    }
}
