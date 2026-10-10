<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Db\Migrations\Migrator;

abstract class AppTestCase extends TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/xar-app-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
        if (!$this->usesSqlite()) {
            DbTestCase::dropAll(DbTestCase::connect());
        }
    }

    protected function tearDown(): void
    {
        // PHPUnit keeps TestCase objects alive for the whole run; drop subclass state so Apps
        // (and their PDO connections) can be collected instead of exhausting max_connections.
        foreach ((new \ReflectionObject($this))->getProperties() as $property) {
            $declaring = $property->getDeclaringClass();
            if ($property->isStatic() || !$declaring->isSubclassOf(self::class) || !$property->isInitialized($this)) {
                continue;
            }
            $name = $property->getName();
            // Bind to the declaring class so private properties can be unset too.
            \Closure::bind(static function (object $test) use ($name): void {
                unset($test->{$name});
            }, null, $declaring->getName())($this);
        }
        gc_collect_cycles();
        if (!$this->usesSqlite()) {
            DbTestCase::dropAll(DbTestCase::connect());
        }
        self::rmdir($this->tmp);
    }

    protected function migrateKernel(App $app): void
    {
        $app->container()->get(Migrator::class)->migrate(['kernel' => dirname(__DIR__, 2) . '/src/Kernel/migrations']);
    }

    /**
     * @param list<string> $modulePaths
     * @param array<string, mixed> $overrides
     */
    protected function boot(array $modulePaths = [], array $overrides = []): App
    {
        return App::boot(dirname(__DIR__, 2), $this->overrides($modulePaths, $overrides));
    }

    /**
     * @param list<string> $modulePaths
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function overrides(array $modulePaths = [], array $overrides = []): array
    {
        $db = DbTestCase::dbConfig();
        if ($this->usesSqlite()) {
            $db['dsn'] = 'sqlite:' . $this->tmp . '/test.sqlite';
        }

        return [
            'app.debug' => true,
            'app.url' => 'http://xar.test',
            'app.cache' => $this->tmp . '/cache',
            'db' => $db,
            'modules.paths' => $modulePaths,
            'log.path' => $this->tmp . '/logs',
            ...$overrides,
        ];
    }

    private function usesSqlite(): bool
    {
        return str_starts_with(DbTestCase::dbConfig()['dsn'], 'sqlite:');
    }

    public static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
