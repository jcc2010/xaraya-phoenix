<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;
use Xaraya\Kernel\Db\Dialect\Dialect;
use Xaraya\Kernel\Db\Schema\Schema;

final class Connection
{
    private int $depth = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialect,
        private readonly string $prefix = 'xar_',
    ) {
        $dialect->onConnect($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier '{$name}'");
        }

        return $this->dialect->quote($name);
    }

    public function table(string $name): string
    {
        $this->ident($name);

        return $this->dialect->quote($this->prefix . $name);
    }

    public function sql(string $sql): string
    {
        return preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', fn(array $m): string => $this->table($m[1]), $sql) ?? $sql;
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($this->sql($sql));
        $statement->execute(array_map(self::normalize(...), $params));

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<int|string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): void
    {
        $columns = $this->columns($row);
        $this->query($this->dialect->insertSql($this->prefixed($table), $columns), array_values($row));
    }

    /**
     * Inserts a row, or updates its non-conflict columns when it collides on $conflict.
     *
     * On MySQL/MariaDB `ON DUPLICATE KEY UPDATE` fires on ANY unique key of the table, not only the
     * $conflict columns, so a collision on another unique index also turns into an update. It uses
     * `VALUES(col)`, which MySQL 8.0.20+ deprecates, because MariaDB 10.6 has no `AS new` row alias.
     *
     * @param array<string, mixed> $row
     * @param list<string> $conflict
     */
    public function upsert(string $table, array $row, array $conflict): void
    {
        if ($conflict === []) {
            throw new InvalidArgumentException('upsert() needs at least one conflict column');
        }
        array_map($this->ident(...), $conflict);
        $columns = $this->columns($row);
        $this->query($this->dialect->upsertSql($this->prefixed($table), $columns, $conflict), array_values($row));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $values, array $where): int
    {
        [$whereSql, $whereParams] = $this->whereSql($where);
        $set = implode(', ', array_map(fn(string $c): string => $this->ident($c) . ' = ?', $this->columns($values)));

        return $this->query(
            'UPDATE ' . $this->table($table) . " SET {$set} WHERE {$whereSql}",
            [...array_values($values), ...$whereParams],
        )->rowCount();
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        [$whereSql, $whereParams] = $this->whereSql($where);

        return $this->query('DELETE FROM ' . $this->table($table) . " WHERE {$whereSql}", $whereParams)->rowCount();
    }

    /**
     * Runs $fn in a transaction. Nested calls use savepoints: an exception inside a nested call rolls back
     * only that call's work and is rethrown, so the caller may catch it and carry on.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->depth > 0) {
            return $this->savepoint($fn);
        }
        $this->pdo->beginTransaction();
        $this->depth = 1;
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->depth = 0;
        }
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    private function savepoint(callable $fn): mixed
    {
        $name = 'xar_sp_' . $this->depth;
        $this->pdo->exec('SAVEPOINT ' . $name);
        $this->depth++;
        try {
            $result = $fn($this);
            $this->pdo->exec('RELEASE SAVEPOINT ' . $name);

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $name);
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }

    /** @return list<string> */
    public function tables(): array
    {
        $names = [];
        foreach ($this->query($this->dialect->listTablesSql())->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name) && str_starts_with($name, $this->prefix)) {
                $names[] = substr($name, strlen($this->prefix));
            }
        }

        return $names;
    }

    public function hasTable(string $name): bool
    {
        return in_array($name, $this->tables(), true);
    }

    public function schema(): Schema
    {
        return new Schema($this);
    }

    public function select(string $table): Select
    {
        return new Select($this, $table);
    }

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => (int) $value,
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    private function prefixed(string $table): string
    {
        $this->ident($table);

        return $this->prefix . $table;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function columns(array $row): array
    {
        $columns = array_map('strval', array_keys($row));
        array_map($this->ident(...), $columns);

        return $columns;
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: list<mixed>}
     */
    private function whereSql(array $where): array
    {
        if ($where === []) {
            throw new InvalidArgumentException('Refusing to UPDATE/DELETE without a WHERE clause');
        }
        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $parts[] = $this->ident($column) . ' IS NULL';
            } else {
                $parts[] = $this->ident($column) . ' = ?';
                $params[] = $value;
            }
        }

        return [implode(' AND ', $parts), $params];
    }
}
