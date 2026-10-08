<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Schema;

use InvalidArgumentException;
use LogicException;
use Xaraya\Kernel\Db\Connection;

final class Schema
{
    public function __construct(private readonly Connection $db) {}

    /** The connection, for data changes (backfills, seeds) inside migrations. */
    public function connection(): Connection
    {
        return $this->db;
    }

    /** @param callable(Blueprint): void $define */
    public function create(string $table, callable $define): void
    {
        self::check($table);
        $blueprint = new Blueprint($table);
        $define($blueprint);
        $this->run($this->db->dialect()->createTable($blueprint, $this->db->prefix()));
    }

    /** @param callable(Blueprint): void $define */
    public function table(string $table, callable $define): void
    {
        self::check($table);
        $blueprint = new Blueprint($table);
        $define($blueprint);
        if ($blueprint->foreignKeys !== []) {
            throw new LogicException('Foreign keys can only be declared in Schema::create()');
        }
        if ($blueprint->primary !== [] || array_filter($blueprint->columns, fn(Column $c): bool => $c->primary) !== []) {
            throw new LogicException('Primary keys can only be declared in Schema::create()');
        }
        $this->run($this->db->dialect()->alterTable($blueprint, $this->db->prefix()));
    }

    /**
     * Dropping a table that other tables reference errors on MySQL and PostgreSQL,
     * while SQLite performs an implicit DELETE that fires ON DELETE actions.
     */
    public function drop(string $table): void
    {
        self::check($table);
        $this->run([$this->db->dialect()->dropTableSql($this->db->prefix() . $table)]);
    }

    public function rename(string $from, string $to): void
    {
        self::check($from);
        self::check($to);
        $prefix = $this->db->prefix();
        $this->db->dialect()->renameTable($this->db->pdo(), $prefix . $from, $prefix . $to);
    }

    public function has(string $table): bool
    {
        return $this->db->hasTable($table);
    }

    /** @throws InvalidArgumentException for a name that is not a safe SQL identifier */
    public static function check(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier '{$name}'");
        }

        return $name;
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->db->pdo()->exec($sql);
        }
    }
}
