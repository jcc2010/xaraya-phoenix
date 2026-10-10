<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Module;

use Closure;
use DateTimeImmutable;
use WeakReference;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Migrations\Migrator;

final class ModuleRegistry
{
    private ?Closure $autoloader = null;

    /** @var array<string, Manifest>|null */
    private ?array $discovered = null;

    /** @var array<string, Manifest>|null */
    private ?array $enabledCache = null;

    /** @var list<Closure(Manifest, bool): void> */
    private array $enableListeners = [];

    /** @param list<string> $paths directories that contain module folders */
    public function __construct(
        private readonly Connection $db,
        private readonly Migrator $migrator,
        private readonly array $paths,
        private readonly string $kernelMigrations,
    ) {}

    /** @return array<string, Manifest> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }
        $found = [];
        foreach ($this->paths as $base) {
            foreach (glob(rtrim($base, '/') . '/*/module.json') ?: [] as $file) {
                $manifest = Manifest::fromDirectory(dirname($file));
                if (isset($found[$manifest->name])) {
                    throw new ModuleException("Module '{$manifest->name}' found twice: {$found[$manifest->name]->path} and {$manifest->path}");
                }
                $found[$manifest->name] = $manifest;
            }
        }
        ksort($found);

        return $this->discovered = $found;
    }

    public function get(string $name): Manifest
    {
        return $this->discover()[$name] ?? throw new ModuleException("Unknown module '{$name}'");
    }

    /** @return array<string, Manifest> memoised until enable(), disable() or refresh() */
    public function enabled(): array
    {
        if ($this->enabledCache !== null) {
            return $this->enabledCache;
        }
        if (!$this->db->hasTable('modules')) {
            return $this->enabledCache = [];
        }
        $discovered = $this->discover();
        $enabled = [];
        foreach ($this->db->select('modules')->where('enabled', '=', true)->orderBy('name')->all() as $row) {
            $name = (string) $row['name'];
            if (isset($discovered[$name])) {
                $enabled[$name] = $discovered[$name];
            }
        }

        return $this->enabledCache = $this->sortByDependencies($enabled);
    }

    /** Forgets the memoised enabled() list, after xar_modules was changed by other code. */
    public function refresh(): void
    {
        $this->enabledCache = null;
    }

    /**
     * Registers a callback that runs after enable() has migrated and recorded a module. $firstInstall is true
     * when xar_modules had no row for the module before, so seeds that must run once can check it.
     *
     * @param Closure(Manifest, bool): void $listener
     */
    public function onEnable(Closure $listener): void
    {
        $this->enableListeners[] = $listener;
    }

    public function isEnabled(string $name): bool
    {
        return isset($this->enabled()[$name]);
    }

    public function enable(string $name): void
    {
        $manifest = $this->get($name);
        foreach (array_keys($manifest->requiredModules()) as $dependency) {
            if (!$this->isEnabled($dependency)) {
                throw new ModuleException("Module '{$name}' requires '{$dependency}'; enable it first");
            }
        }
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
        $this->migrator->migrate(['kernel' => $this->kernelMigrations]);
        if ($manifest->migrationsPath() !== null) {
            $this->migrator->migrate([$name => $manifest->migrationsPath()]);
        }
        $firstInstall = $this->db->select('modules')->where('name', '=', $name)->first() === null;
        $this->db->upsert('modules', [
            'name' => $name,
            'version' => $manifest->version,
            'enabled' => true,
            'installed_at' => new DateTimeImmutable(),
        ], ['name']);
        $this->enabledCache = null;
        foreach ($this->enableListeners as $listener) {
            $listener($manifest, $firstInstall);
        }
    }

    public function disable(string $name): void
    {
        $this->get($name);
        foreach ($this->enabled() as $other) {
            if ($other->name !== $name && array_key_exists($name, $other->requiredModules())) {
                throw new ModuleException("Cannot disable '{$name}': '{$other->name}' requires it");
            }
        }
        if ($this->db->hasTable('modules')) {
            $this->db->update('modules', ['enabled' => false], ['name' => $name]);
        }
        $this->enabledCache = null;
    }

    /** @return array<string, string> */
    public function migrationPaths(bool $all = false): array
    {
        $paths = ['kernel' => $this->kernelMigrations];
        foreach ($all ? $this->discover() : $this->enabled() as $manifest) {
            if ($manifest->migrationsPath() !== null) {
                $paths[$manifest->name] = $manifest->migrationsPath();
            }
        }

        return $paths;
    }

    public function registerAutoloader(): void
    {
        if ($this->autoloader !== null) {
            return;
        }
        // Hold the registry weakly: a global autoloader that captured $this would keep this registry
        // (and its database connection) alive for the rest of the process.
        $self = WeakReference::create($this);
        $autoloader = static function (string $class) use ($self): void {
            $registry = $self->get();
            if ($registry === null) {
                return;
            }
            foreach ($registry->discover() as $manifest) {
                $ns = $manifest->namespace();
                if (!str_starts_with($class, $ns)) {
                    continue;
                }
                $file = $manifest->path . '/src/' . str_replace('\\', '/', substr($class, strlen($ns))) . '.php';
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        };
        spl_autoload_register($autoloader);
        $this->autoloader = $autoloader;
    }

    public function __destruct()
    {
        if ($this->autoloader !== null) {
            spl_autoload_unregister($this->autoloader);
        }
    }

    /**
     * @param array<string, Manifest> $modules
     * @return array<string, Manifest>
     */
    private function sortByDependencies(array $modules): array
    {
        $sorted = [];
        $visit = function (string $name, array $stack) use (&$visit, &$sorted, $modules): void {
            if (isset($sorted[$name]) || !isset($modules[$name])) {
                return;
            }
            if (in_array($name, $stack, true)) {
                throw new ModuleException('Circular module dependency: ' . implode(' -> ', [...$stack, $name]));
            }
            foreach (array_keys($modules[$name]->requiredModules()) as $dependency) {
                $visit($dependency, [...$stack, $name]);
            }
            $sorted[$name] = $modules[$name];
        };
        foreach (array_keys($modules) as $name) {
            $visit($name, []);
        }

        return $sorted;
    }
}
