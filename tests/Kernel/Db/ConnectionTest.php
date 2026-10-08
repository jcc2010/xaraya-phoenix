<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use InvalidArgumentException;
use Xaraya\Kernel\Db\ConnectionFactory;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Tests\Support\DbTestCase;

final class ConnectionTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->schema()->create('kv', function (Blueprint $t): void {
            $t->string('k', 64)->primary();
            $t->text('v')->nullable();
            $t->int('n')->default(0);
        });
    }

    public function testFetchHelpers(): void
    {
        $this->db->insert('kv', ['k' => 'a', 'v' => 'one', 'n' => 1]);
        $this->db->insert('kv', ['k' => 'b', 'v' => 'two', 'n' => 2]);

        self::assertCount(2, $this->db->fetchAll('SELECT * FROM {kv} ORDER BY k'));
        self::assertSame('two', $this->db->fetchOne('SELECT v FROM {kv} WHERE k = ?', ['b'])['v'] ?? null);
        self::assertNull($this->db->fetchOne('SELECT v FROM {kv} WHERE k = ?', ['zzz']));
        self::assertSame(3, (int) $this->db->fetchValue('SELECT SUM(n) FROM {kv}'));
        self::assertNull($this->db->fetchValue('SELECT v FROM {kv} WHERE k = ?', ['zzz']));
    }

    public function testUpdateDeleteAndGuard(): void
    {
        $this->db->insert('kv', ['k' => 'a', 'n' => 1]);
        self::assertSame(1, $this->db->update('kv', ['n' => 5], ['k' => 'a']));
        self::assertSame(5, (int) $this->db->fetchValue('SELECT n FROM {kv} WHERE k = ?', ['a']));
        self::assertSame(1, $this->db->delete('kv', ['k' => 'a']));

        $this->expectException(InvalidArgumentException::class);
        $this->db->update('kv', ['n' => 1], []);
    }

    public function testWhereNullMatchesIsNull(): void
    {
        $this->db->insert('kv', ['k' => 'a', 'v' => null]);
        self::assertSame(1, $this->db->update('kv', ['n' => 9], ['v' => null]));
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $this->db->upsert('kv', ['k' => 'a', 'v' => 'first', 'n' => 1], ['k']);
        $this->db->upsert('kv', ['k' => 'a', 'v' => 'second', 'n' => 2], ['k']);
        self::assertSame('second', $this->db->fetchValue('SELECT v FROM {kv} WHERE k = ?', ['a']));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM {kv}'));
    }

    public function testTransactionRollsBack(): void
    {
        try {
            $this->db->transaction(function ($db): void {
                $db->insert('kv', ['k' => 'a']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM {kv}'));
        self::assertSame('ok', $this->db->transaction(fn() => 'ok'));
    }

    public function testTablesAreFilteredByPrefix(): void
    {
        self::assertSame(['kv'], $this->db->tables());
        self::assertTrue($this->db->hasTable('kv'));
        self::assertFalse($this->db->hasTable('nope'));
    }

    public function testRejectsUnsafeIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->insert('kv', ['k; DROP TABLE x' => 'a']);
    }

    public function testRejectsIdentifierWithTrailingNewline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->ident("kv\n");
    }

    public function testNormalize(): void
    {
        $c = $this->db::class;
        self::assertSame(1, $c::normalize(true));
        self::assertSame('{"a":"é/b"}', $c::normalize(['a' => 'é/b']));
        self::assertSame('2026-10-08 13:00:00', $c::normalize(new \DateTimeImmutable('2026-10-08 15:00:00', new \DateTimeZone('+02:00'))));
        self::assertSame('x', $c::normalize('x'));
    }

    public function testFactoryRejectsUnknownDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConnectionFactory::make(['dsn' => 'oracle:whatever']);
    }
}
