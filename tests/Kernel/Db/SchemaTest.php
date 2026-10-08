<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use PDOException;
use Xaraya\Kernel\Db\Dialect\Sqlite;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Tests\Support\DbTestCase;

final class SchemaTest extends DbTestCase
{
    private function createAllTypes(): void
    {
        $this->db->schema()->create('things', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('slug', 64)->unique();
            $t->text('body')->nullable();
            $t->int('views')->default(0);
            $t->bigint('bytes')->default(0);
            $t->bool('published')->default(false);
            $t->datetime('created');
            $t->json('extra')->nullable();
            $t->index('created');
        });
    }

    public function testCreateInsertAndReadAllTypes(): void
    {
        $this->createAllTypes();
        self::assertTrue($this->db->schema()->has('things'));

        $this->db->insert('things', [
            'id' => '01m3t19wn8reaww81zg1m47tjq',
            'slug' => 'hello',
            'created' => new \DateTimeImmutable('2026-10-08 15:07:16', new \DateTimeZone('UTC')),
            'extra' => ['kind' => 'note'],
        ]);
        $row = $this->db->fetchOne('SELECT * FROM {things} WHERE id = ?', ['01m3t19wn8reaww81zg1m47tjq']);

        self::assertNotNull($row);
        self::assertSame('hello', $row['slug']);
        self::assertNull($row['body']);
        self::assertSame(0, (int) $row['views']);
        self::assertFalse((bool) $row['published']);
        self::assertSame('2026-10-08 15:07:16', $row['created']);
        self::assertEquals(['kind' => 'note'], json_decode((string) $row['extra'], true));
    }

    public function testUniqueConstraintIsEnforced(): void
    {
        $this->createAllTypes();
        $row = ['slug' => 'dup', 'created' => '2026-10-08 00:00:00'];
        $this->db->insert('things', ['id' => '01m3t19wn8reaww81zg1m47tja'] + $row);
        $this->expectException(PDOException::class);
        $this->db->insert('things', ['id' => '01m3t19wn8reaww81zg1m47tjb'] + $row);
    }

    public function testAlterAddsColumnsAndIndexes(): void
    {
        $this->createAllTypes();
        $this->db->schema()->table('things', function (Blueprint $t): void {
            $t->string('title')->nullable();
            $t->index('title');
        });
        $this->db->insert('things', ['id' => '01m3t19wn8reaww81zg1m47tjq', 'slug' => 's', 'created' => '2026-10-08 00:00:00', 'title' => 'T']);
        self::assertSame('T', $this->db->fetchValue('SELECT title FROM {things}'));
    }

    public function testRenameAndDrop(): void
    {
        $this->createAllTypes();
        $this->db->schema()->rename('things', 'items');
        self::assertFalse($this->db->schema()->has('things'));
        self::assertTrue($this->db->schema()->has('items'));
        $this->db->schema()->drop('items');
        self::assertFalse($this->db->schema()->has('items'));
    }

    public function testIncrementsAndForeignKeyCascade(): void
    {
        $s = $this->db->schema();
        $s->create('parents', function (Blueprint $t): void {
            $t->increments();
            $t->string('name');
        });
        $s->create('children', function (Blueprint $t): void {
            $t->increments();
            $t->int('parent_id');
            $t->foreign('parent_id', 'parents');
        });
        $this->db->insert('parents', ['name' => 'p']);
        $pid = $this->db->fetchValue('SELECT id FROM {parents}');
        $this->db->insert('children', ['parent_id' => $pid]);
        $this->db->delete('parents', ['id' => $pid]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM {children}'));
    }

    public function testCompositePrimaryKey(): void
    {
        $this->db->schema()->create('pairs', function (Blueprint $t): void {
            $t->string('a', 32);
            $t->string('b', 32);
            $t->primary('a', 'b');
        });
        $this->db->insert('pairs', ['a' => 'x', 'b' => 'y']);
        $this->expectException(PDOException::class);
        $this->db->insert('pairs', ['a' => 'x', 'b' => 'y']);
    }

    public function testForeignKeysOnlyInCreate(): void
    {
        $this->createAllTypes();
        $this->expectException(\LogicException::class);
        $this->db->schema()->table('things', function (Blueprint $t): void {
            $t->foreign('slug', 'other');
        });
    }

    public function testLongIndexNamesAreShortened(): void
    {
        $b = new Blueprint('a_really_long_table_name_for_testing_index_names');
        $b->string('an_equally_long_column_name_here');
        $b->index('an_equally_long_column_name_here');
        $sql = (new Sqlite())->createTable($b, 'xar_');
        self::assertCount(2, $sql);
        self::assertMatchesRegularExpression('/INDEX "([^"]{1,60})" ON/', $sql[1]);
    }
}
