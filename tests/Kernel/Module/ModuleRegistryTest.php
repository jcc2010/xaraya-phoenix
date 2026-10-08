<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Module;

use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\DbTestCase;

final class ModuleRegistryTest extends DbTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/modules';

    private function registry(array $paths = [self::FIXTURES]): ModuleRegistry
    {
        return new ModuleRegistry($this->db, new Migrator($this->db), $paths, dirname(__DIR__, 3) . '/src/Kernel/migrations');
    }

    public function testDiscoverAndEmptyEnabledBeforeInstall(): void
    {
        $r = $this->registry();
        self::assertSame(['alpha', 'beta'], array_keys($r->discover()));
        self::assertSame([], $r->enabled());
        self::assertFalse($r->isEnabled('alpha'));
    }

    public function testEnableRunsMigrationsAndRecordsModule(): void
    {
        $r = $this->registry();
        $r->enable('alpha');
        self::assertTrue($this->db->hasTable('modules'));
        self::assertTrue($this->db->hasTable('alpha_items'));
        self::assertTrue($r->isEnabled('alpha'));
        self::assertSame('1.2.0', $this->db->fetchValue('SELECT version FROM {modules} WHERE name = ?', ['alpha']));
    }

    public function testDependenciesAreEnforcedAndOrdered(): void
    {
        $r = $this->registry();
        try {
            $r->enable('beta');
            self::fail('beta should require alpha');
        } catch (ModuleException $e) {
            self::assertStringContainsString("requires 'alpha'", $e->getMessage());
        }
        $r->enable('alpha');
        $r->enable('beta');
        self::assertSame(['alpha', 'beta'], array_keys($r->enabled()));

        try {
            $r->disable('alpha');
            self::fail('alpha is required by beta');
        } catch (ModuleException $e) {
            self::assertStringContainsString("'beta' requires it", $e->getMessage());
        }
        $r->disable('beta');
        $r->disable('alpha');
        self::assertSame([], $r->enabled());
    }

    public function testMigrationPaths(): void
    {
        $r = $this->registry();
        $kernel = dirname(__DIR__, 3) . '/src/Kernel/migrations';
        self::assertSame(['kernel' => $kernel], $r->migrationPaths());
        self::assertSame(['kernel' => $kernel, 'alpha' => realpath(self::FIXTURES . '/alpha') . '/migrations'], $r->migrationPaths(all: true));
    }

    public function testUnknownModuleThrows(): void
    {
        $this->expectException(ModuleException::class);
        $this->registry()->enable('zeta');
    }

    public function testDuplicateModuleNamesThrow(): void
    {
        $this->expectException(ModuleException::class);
        $this->registry([self::FIXTURES, self::FIXTURES . '/../modules'])->discover();
    }

    public function testAutoloaderLoadsModuleClasses(): void
    {
        $this->registry()->registerAutoloader();
        self::assertSame('alpha', (new \Xaraya\Module\Alpha\Thing())->hello());
    }
}
