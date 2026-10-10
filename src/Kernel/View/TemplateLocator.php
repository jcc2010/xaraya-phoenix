<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Xaraya\Kernel\Module\ModuleRegistry;

/**
 * Resolves template names to files. "module::path" is a module template: the active theme chain's
 * templates/modules/<module>/ first, then the module's own templates/. A plain "path" is a theme-level
 * template (layout, block, home, error/404) looked up in each theme's templates/. In one directory holding
 * both a .twig and a .php file, preferred() wins.
 */
final class TemplateLocator
{
    public const EXTENSIONS = ['php', 'twig'];

    private const NAME = '/^(?:([a-z][a-z0-9_-]*)::)?([A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*)$/D';

    /** @var array<string, ?string> */
    private array $found = [];

    private readonly bool $twigInstalled;

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ModuleRegistry $modules,
        private readonly ?string $engine = null,
        ?bool $twigInstalled = null,
    ) {
        if ($engine !== null && !in_array($engine, self::EXTENSIONS, true)) {
            throw new ViewException("view.engine must be 'php' or 'twig', not '{$engine}'");
        }
        $this->twigInstalled = $twigInstalled ?? class_exists(\Twig\Environment::class);
    }

    public function preferred(): string
    {
        $preferred = $this->engine ?? $this->themes->active()->engine;

        return $preferred === 'twig' && !$this->twigInstalled ? 'php' : $preferred;
    }

    /** @return array{0: ?string, 1: string} the module (null for theme-level templates) and the relative path */
    public static function parse(string $name): array
    {
        if (preg_match(self::NAME, $name, $m) !== 1) {
            throw new ViewException("Invalid template name '{$name}'");
        }

        return [$m[1] === '' ? null : $m[1], $m[2]];
    }

    /** @return list<string> the directories searched for $name, in lookup order */
    public function directories(string $name): array
    {
        [$module] = self::parse($name);
        $dirs = [];
        foreach ($this->themes->chain() as $theme) {
            $dirs[] = $theme->path . '/templates' . ($module === null ? '' : '/modules/' . $module);
        }
        if ($module !== null) {
            $manifest = $this->modules->discover()[$module] ?? null;
            if ($manifest !== null) {
                $dirs[] = $manifest->path . '/templates';
            }
        }

        return $dirs;
    }

    /** The first file for $name: of $extension only, or of either engine with preferred() breaking ties. */
    public function find(string $name, ?string $extension = null): ?string
    {
        $key = $name . '|' . ($extension ?? '');
        if (array_key_exists($key, $this->found)) {
            return $this->found[$key];
        }
        if ($extension !== null && !in_array($extension, self::EXTENSIONS, true)) {
            throw new ViewException("Unknown template extension '{$extension}'");
        }
        $path = self::parse($name)[1];
        $order = $extension !== null ? [$extension] : array_values(array_unique([$this->preferred(), ...self::EXTENSIONS]));
        foreach ($this->directories($name) as $dir) {
            foreach ($order as $ext) {
                $file = $dir . '/' . $path . '.' . $ext;
                if (is_file($file)) {
                    return $this->found[$key] = $file;
                }
            }
        }

        return $this->found[$key] = null;
    }
}
