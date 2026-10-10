<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Assets;

use Closure;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\ThemeRegistry;

/** Publishes modules/<name>/assets and themes/<name>/assets to {target}/module/<name> and {target}/theme/<name>. */
final class AssetPublisher
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ThemeRegistry $themes,
        private readonly string $target,
        private readonly ?Closure $linker = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /** @return list<array{kind: string, name: string, source: string, target: string, method: string}> */
    public function publish(bool $copy = false): array
    {
        $sources = [];
        foreach ($this->modules->discover() as $manifest) {
            $sources[] = ['module', $manifest->name, $manifest->path . '/assets'];
        }
        foreach ($this->themes->discover() as $theme) {
            $sources[] = ['theme', $theme->name, $theme->path . '/assets'];
        }
        $done = [];
        foreach ($sources as [$kind, $name, $source]) {
            if (!is_dir($source)) {
                continue;
            }
            // Same pattern as Manifest and Theme; anything else could escape the target directory.
            if (preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
                throw new RuntimeException("Invalid {$kind} name '{$name}'");
            }
            // {target}/{kind} must be a real directory. If it is a link (planted, or left by an older layout),
            // writing through it could land outside the target, so the link itself is removed and recreated.
            $kindDir = $this->target . '/' . $kind;
            if (is_link($kindDir)) {
                $this->logger?->warning("asset:publish removed the link '{$kindDir}' so it cannot redirect writes outside the target");
                if (!@unlink($kindDir) && !@rmdir($kindDir)) {
                    throw new RuntimeException("Cannot replace {$kindDir}");
                }
            }
            $target = $kindDir . '/' . $name;
            Cache::removeTree($target);
            if (is_link($target)) {
                @rmdir($target); // Windows directory junctions need rmdir
            }
            if (is_link($target) || file_exists($target)) {
                throw new RuntimeException("Cannot replace {$target}");
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException("Cannot create directory '{$parent}'");
            }
            $link = $this->linker ?? static fn(string $from, string $to): bool => @symlink($from, $to);
            $method = !$copy && $link($source, $target) ? 'symlink' : self::copyTree($source, $target);
            $done[] = ['kind' => $kind, 'name' => $name, 'source' => $source, 'target' => $target, 'method' => $method];
        }

        return $done;
    }

    private static function copyTree(string $from, string $to): string
    {
        if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
            throw new RuntimeException("Cannot create directory '{$to}'");
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $destination = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
                    throw new RuntimeException("Cannot create directory '{$destination}'");
                }
            } elseif (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException("Cannot copy '{$item->getPathname()}'");
            }
        }

        return 'copy';
    }
}
