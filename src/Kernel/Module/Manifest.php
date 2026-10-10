<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Module;

use JsonException;
use Xaraya\Kernel\Blocks\BlockInstance;
use Xaraya\Kernel\Hooks\DisplayHook;
use Xaraya\Kernel\Hooks\HookBindings;

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

    /** @return array<string, string> display hook name => class implementing DisplayHook */
    public function displayHooks(): array
    {
        $out = [];
        foreach ((array) ($this->data['displayHooks'] ?? []) as $hook => $class) {
            if (!is_string($hook) || !in_array($hook, DisplayHook::HOOKS, true) || !is_string($class) || $class === '') {
                throw new ModuleException("{$this->name}: displayHooks must map " . implode(', ', DisplayHook::HOOKS) . ' to class names');
            }
            $out[$hook] = $class;
        }

        return $out;
    }

    /** @return list<array{subject: string, itemtype: string}> bindings seeded when this (observer) module is enabled */
    public function hookDefaults(): array
    {
        $out = [];
        foreach ((array) ($this->data['hookDefaults'] ?? []) as $i => $default) {
            $subject = is_array($default) ? ($default['subject'] ?? null) : null;
            $itemtype = is_array($default) ? ($default['itemtype'] ?? '*') : null;
            if (!is_string($subject) || !HookBindings::isValidPart($subject) || !is_string($itemtype) || !HookBindings::isValidPart($itemtype, true)) {
                throw new ModuleException("{$this->name}: hookDefaults[{$i}] needs a string \"subject\" and an optional string \"itemtype\"");
            }
            $out[] = ['subject' => $subject, 'itemtype' => $itemtype];
        }

        return $out;
    }

    /** @return array<string, string> block type ("<module>.<name>") => class implementing Block */
    public function blocks(): array
    {
        $out = [];
        foreach ((array) ($this->data['blocks'] ?? []) as $type => $class) {
            if (
                !is_string($type)
                || !str_starts_with($type, $this->name . '.')
                || preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/D', $type) !== 1
                || !is_string($class)
                || $class === ''
            ) {
                throw new ModuleException("{$this->name}: blocks must map \"{$this->name}.<name>\" types to class names");
            }
            $out[$type] = $class;
        }

        return $out;
    }

    /**
     * Block instances created the first time this module is enabled.
     *
     * @return list<array{type: string, region: string, title: ?string, config: array<string, mixed>, visibility: array{routes?: list<string>, roles?: list<string>}, sort: int}>
     */
    public function blockDefaults(): array
    {
        $out = [];
        $declared = $this->blocks();
        foreach ((array) ($this->data['blockDefaults'] ?? []) as $i => $default) {
            if (!is_array($default) || !is_string($default['type'] ?? null) || !is_string($default['region'] ?? null)) {
                throw new ModuleException("{$this->name}: blockDefaults[{$i}] needs string \"type\" and \"region\"");
            }
            $title = $default['title'] ?? null;
            $config = $default['config'] ?? [];
            $visibility = $default['visibility'] ?? [];
            if (($title !== null && !is_string($title)) || !is_array($config) || !is_array($visibility)) {
                throw new ModuleException("{$this->name}: blockDefaults[{$i}] has an invalid title, config or visibility");
            }
            if (!BlockInstance::isValidType($default['type']) || !BlockInstance::isValidRegion($default['region'])) {
                throw new ModuleException("{$this->name}: blockDefaults[{$i}] has an invalid type or region");
            }
            // A type in this module's namespace must be one this module declares; other types (built-ins, other modules) are checked at render.
            if (str_starts_with($default['type'], $this->name . '.') && !isset($declared[$default['type']])) {
                throw new ModuleException("{$this->name}: blockDefaults[{$i}] uses undeclared block type '{$default['type']}'");
            }
            try {
                $visibility = BlockInstance::checkedVisibility($visibility);
            } catch (\InvalidArgumentException $e) {
                throw new ModuleException("{$this->name}: blockDefaults[{$i}]: {$e->getMessage()}", 0, $e);
            }
            /** @var array<string, mixed> $config */
            $out[] = [
                'type' => $default['type'],
                'region' => $default['region'],
                'title' => $title,
                'config' => $config,
                'visibility' => $visibility,
                'sort' => (int) ($default['sort'] ?? 0),
            ];
        }

        return $out;
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
