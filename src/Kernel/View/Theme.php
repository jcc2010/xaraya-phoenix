<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use JsonException;

final class Theme
{
    public const ENGINES = ['php', 'twig'];

    private const NAME = '/^[a-z][a-z0-9_-]*$/D';

    /**
     * @param list<string> $regions
     * @param array<string, array<string, mixed>> $settings setting name => schema (type, label, default)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $path,
        public readonly ?string $parent,
        public readonly string $engine,
        public readonly array $regions,
        public readonly array $settings,
    ) {}

    public static function fromDirectory(string $dir): self
    {
        $file = $dir . '/theme.json';
        if (!is_file($file)) {
            throw new ViewException("No theme.json in {$dir}");
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ViewException("Invalid JSON in {$file}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new ViewException("{$file} must contain a JSON object");
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME, $name) !== 1) {
            throw new ViewException("{$file}: \"name\" must be a lower-case identifier");
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new ViewException("{$file}: \"version\" is required");
        }
        $parent = $data['parent'] ?? null;
        if ($parent !== null) {
            if (!is_string($parent) || preg_match(self::NAME, $parent) !== 1 || $parent === $name) {
                throw new ViewException("{$file}: \"parent\" must be another theme's name");
            }
        }
        $engine = $data['engine'] ?? 'php';
        if (!is_string($engine) || !in_array($engine, self::ENGINES, true)) {
            throw new ViewException("{$file}: \"engine\" must be \"php\" or \"twig\"");
        }
        $regions = [];
        foreach ((array) ($data['regions'] ?? []) as $region) {
            if (!is_string($region) || preg_match(self::NAME, $region) !== 1) {
                throw new ViewException("{$file}: every region must be a lower-case identifier");
            }
            $regions[] = $region;
        }
        $settings = [];
        foreach ((array) ($data['settings'] ?? []) as $key => $schema) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1 || !is_array($schema)) {
                throw new ViewException("{$file}: \"settings\" must map lower-case names to objects");
            }
            /** @var array<string, mixed> $schema */
            $settings[$key] = $schema;
        }

        return new self($name, $version, realpath($dir) ?: $dir, $parent, $engine, $regions, $settings);
    }

    /** @return array<string, mixed> */
    public function settingDefaults(): array
    {
        $defaults = [];
        foreach ($this->settings as $key => $schema) {
            $defaults[$key] = $schema['default'] ?? null;
        }

        return $defaults;
    }
}
