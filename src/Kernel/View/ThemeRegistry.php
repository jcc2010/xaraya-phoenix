<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

final class ThemeRegistry
{
    /** @var array<string, Theme>|null */
    private ?array $discovered = null;

    /** @param list<string> $paths directories that contain theme folders */
    public function __construct(private readonly array $paths, private readonly string $active) {}

    /** @return array<string, Theme> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }
        $found = [];
        foreach ($this->paths as $base) {
            foreach (glob(rtrim($base, '/') . '/*/theme.json') ?: [] as $file) {
                $theme = Theme::fromDirectory(dirname($file));
                if (isset($found[$theme->name])) {
                    throw new ViewException("Theme '{$theme->name}' found twice: {$found[$theme->name]->path} and {$theme->path}");
                }
                $found[$theme->name] = $theme;
            }
        }
        ksort($found);

        return $this->discovered = $found;
    }

    public function get(string $name): Theme
    {
        return $this->discover()[$name] ?? throw new ViewException("Unknown theme '{$name}'");
    }

    public function active(): Theme
    {
        return $this->get($this->active);
    }

    /** @return list<Theme> the active theme first, then its parent, grandparent and so on */
    public function chain(): array
    {
        $chain = [];
        $theme = $this->active();
        while (true) {
            if (isset($chain[$theme->name])) {
                throw new ViewException('Circular theme parents: ' . implode(' -> ', [...array_keys($chain), $theme->name]));
            }
            $chain[$theme->name] = $theme;
            if ($theme->parent === null) {
                return array_values($chain);
            }
            $theme = $this->get($theme->parent);
        }
    }

    /** @return list<string> the active theme's regions; a theme that declares none inherits its parent's */
    public function regions(): array
    {
        foreach ($this->chain() as $theme) {
            if ($theme->regions !== []) {
                return $theme->regions;
            }
        }

        return [];
    }

    /** @return array<string, mixed> setting defaults, a child's overriding its parent's */
    public function settingDefaults(): array
    {
        $defaults = [];
        foreach (array_reverse($this->chain()) as $theme) {
            $defaults = [...$defaults, ...$theme->settingDefaults()];
        }

        return $defaults;
    }
}
