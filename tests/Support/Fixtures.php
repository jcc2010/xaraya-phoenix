<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

/** Writes small fixture themes and modules (a manifest plus files) into a temp directory. */
final class Fixtures
{
    /**
     * @param array<string, mixed> $json merged over {"name", "version": "1.0.0"}
     * @param array<string, string> $files relative path => content
     */
    public static function theme(string $base, string $name, array $json = [], array $files = []): string
    {
        return self::write($base . '/' . $name, 'theme.json', ['name' => $name, 'version' => '1.0.0', ...$json], $files);
    }

    /**
     * @param array<string, mixed> $json merged over {"name", "version": "1.0.0"}
     * @param array<string, string> $files relative path => content
     */
    public static function module(string $base, string $name, array $json = [], array $files = []): string
    {
        return self::write($base . '/' . $name, 'module.json', ['name' => $name, 'version' => '1.0.0', ...$json], $files);
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $files
     */
    private static function write(string $dir, string $manifest, array $json, array $files): string
    {
        $all = [$manifest => json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ...$files];
        foreach ($all as $path => $content) {
            $file = $dir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, $content);
        }

        return $dir;
    }
}
