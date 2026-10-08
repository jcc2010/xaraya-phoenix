<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Module;

use JsonException;

final class Manifest
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $path,
        private readonly array $data,
    ) {}

    public static function fromDirectory(string $dir): self
    {
        $file = $dir . '/module.json';
        if (!is_file($file)) {
            throw new ModuleException("No module.json in {$dir}");
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ModuleException("Invalid JSON in {$file}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new ModuleException("{$file} must contain a JSON object");
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
            throw new ModuleException("{$file}: \"name\" must be a lower-case identifier");
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new ModuleException("{$file}: \"version\" is required");
        }

        /** @var array<string, mixed> $data */
        return new self($name, $version, realpath($dir) ?: $dir, $data);
    }

    public function namespace(): string
    {
        $ns = $this->data['namespace'] ?? null;
        if (is_string($ns) && $ns !== '') {
            return rtrim($ns, '\\') . '\\';
        }

        return 'Xaraya\\Module\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $this->name))) . '\\';
    }

    public function provider(): ?string
    {
        return $this->string('provider');
    }

    public function routes(): ?string
    {
        return $this->string('routes');
    }

    public function migrationsPath(): ?string
    {
        $dir = $this->string('migrations');

        return $dir === null ? null : $this->path . '/' . trim($dir, '/');
    }

    /** @return list<array{event: string, listener: string, priority: int}> */
    public function subscribers(): array
    {
        $out = [];
        foreach ((array) ($this->data['subscribers'] ?? []) as $i => $s) {
            if (!is_array($s) || !is_string($s['event'] ?? null) || !is_string($s['listener'] ?? null)) {
                throw new ModuleException("{$this->name}: subscribers[{$i}] needs string \"event\" and \"listener\"");
            }
            $out[] = ['event' => $s['event'], 'listener' => $s['listener'], 'priority' => (int) ($s['priority'] ?? 0)];
        }

        return $out;
    }

    /** @return list<string> */
    public function commands(): array
    {
        return array_values(array_filter((array) ($this->data['commands'] ?? []), 'is_string'));
    }

    /** @return array<string, string> */
    public function requiredModules(): array
    {
        $requires = $this->data['requires'] ?? [];
        $modules = is_array($requires) ? ($requires['modules'] ?? []) : [];
        $out = [];
        foreach ((array) $modules as $name => $constraint) {
            $out[(string) $name] = is_string($constraint) ? $constraint : '*';
        }

        return $out;
    }

    public function isContent(): bool
    {
        return ($this->data['content'] ?? false) === true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    private function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
