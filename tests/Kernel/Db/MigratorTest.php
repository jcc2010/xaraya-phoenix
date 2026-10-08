<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use RuntimeException;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Tests\Support\DbTestCase;

final class MigratorTest extends DbTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/xar-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->writeMigration('2026_01_01_000001_create_alpha', 'alpha');
        $this->writeMigration('2026_01_01_000002_create_beta', 'beta');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function writeMigration(string $name, string $table): void
    {
        file_put_contents("{$this->dir}/{$name}.php", <<<PHP
            <?php
            declare(strict_types=1);
            use Xaraya\\Kernel\\Db\\Migrations\\Migration;
            use Xaraya\\Kernel\\Db\\Schema\\Blueprint;
            use Xaraya\\Kernel\\Db\\Schema\\Schema;
            return new class extends Migration {
                public function up(Schema \$schema): void
                {
                    \$schema->create('{$table}', function (Blueprint \$t): void { \$t->increments(); });
                }
                public function down(Schema \$schema): void
                {
                    \$schema->drop('{$table}');
                }
            };
            PHP);
    }

    public function testMigrateIsIdempotentAndBatched(): void
    {
        $m = new Migrator($this->db);
        self::assertSame(['demo/2026_01_01_000001_create_alpha', 'demo/2026_01_01_000002_create_beta'], $m->migrate(['demo' => $this->dir]));
        self::assertTrue($this->db->hasTable('alpha'));
        self::assertTrue($this->db->hasTable('beta'));
        self::assertSame([], $m->migrate(['demo' => $this->dir]));

        $this->writeMigration('2026_01_02_000001_create_gamma', 'gamma');
        self::assertSame(['demo/2026_01_02_000001_create_gamma'], $m->migrate(['demo' => $this->dir]));

        $status = $m->status(['demo' => $this->dir]);
        self::assertSame([1, 1, 2], array_column($status, 'batch'));
    }

    public function testRollbackLastBatchOnly(): void
    {
        $m = new Migrator($this->db);
        $m->migrate(['demo' => $this->dir]);
        $this->writeMigration('2026_01_02_000001_create_gamma', 'gamma');
        $m->migrate(['demo' => $this->dir]);

        self::assertSame(['demo/2026_01_02_000001_create_gamma'], $m->rollback(['demo' => $this->dir]));
        self::assertFalse($this->db->hasTable('gamma'));
        self::assertTrue($this->db->hasTable('alpha'));

        self::assertSame(
            ['demo/2026_01_01_000002_create_beta', 'demo/2026_01_01_000001_create_alpha'],
            $m->rollback(['demo' => $this->dir]),
        );
        self::assertFalse($this->db->hasTable('alpha'));
        self::assertSame([null, null, null], array_column($m->status(['demo' => $this->dir]), 'batch'));
    }

    public function testMissingDirectoryIsEmpty(): void
    {
        self::assertSame([], (new Migrator($this->db))->migrate(['none' => '/nonexistent/dir']));
    }

    public function testFileMustReturnMigration(): void
    {
        file_put_contents("{$this->dir}/2026_01_03_000001_bad.php", '<?php return 42;');
        $this->expectException(RuntimeException::class);
        (new Migrator($this->db))->migrate(['demo' => $this->dir]);
    }

    public function testKernelMigrationCreatesModulesTable(): void
    {
        $kernel = dirname(__DIR__, 3) . '/src/Kernel/migrations';
        (new Migrator($this->db))->migrate(['kernel' => $kernel]);
        $this->db->insert('modules', ['name' => 'blog', 'version' => '0.1.0', 'installed_at' => '2026-10-08 00:00:00']);
        self::assertTrue((bool) $this->db->fetchValue('SELECT enabled FROM {modules} WHERE name = ?', ['blog']));
    }
}
