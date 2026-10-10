<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Assets;

use FilesystemIterator;
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
            if (!is_dir($source) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                continue;
            }
            // A link planted at {target}/{kind} could point outside the target: replace it with a real directory.
            if (is_link($this->target . '/' . $kind)) {
                @unlink($this->target . '/' . $kind);
            }
            $target = $this->target . '/' . $kind . '/' . $name;
            Cache::removeTree($target);
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException("Cannot create directory '{$parent}'");
            }
            $method = !$copy && @symlink($source, $target) ? 'symlink' : self::copyTree($source, $target);
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
