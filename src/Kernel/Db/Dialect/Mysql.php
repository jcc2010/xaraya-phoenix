<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use PDO;
use Xaraya\Kernel\Db\Schema\Column;

final class Mysql extends AbstractDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function onConnect(PDO $pdo): void
    {
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec("SET time_zone = '+00:00'");
    }

    public function listTablesSql(): string
    {
        return 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name';
    }

    public function transactionalDdl(): bool
    {
        return false;
    }

    public function upsertSql(string $table, array $columns, array $conflict): string
    {
        $update = array_values(array_diff($columns, $conflict));
        if ($update === []) {
            $update = [$conflict[0] ?? $columns[0]];
        }

        return $this->insertSql($table, $columns) . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
            fn(string $c): string => $this->quote($c) . ' = VALUES(' . $this->quote($c) . ')',
            $update,
        ));
    }

    protected function defaultSql(Column $column): string
    {
        $literal = parent::defaultSql($column);

        // MySQL only allows defaults on TEXT/JSON columns in expression form (8.0.13+).
        return in_array($column->type, ['text', 'json'], true) ? '(' . $literal . ')' : $literal;
    }

    /** MySQL treats backslashes in string literals as escapes (unless NO_BACKSLASH_ESCAPES is set). */
    protected function literal(mixed $value): string
    {
        return is_string($value)
            ? "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'"
            : parent::literal($value);
    }

    /** Binary collation, so equality and unique keys compare bytes as SQLite and PostgreSQL do. */
    protected function tableSuffix(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    }

    protected function incrementsSql(): string
    {
        return 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'ulid' => 'CHAR(26)',
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'LONGTEXT',
            'json' => 'JSON',
            'int' => 'INT',
            'bigint' => 'BIGINT',
            'bool' => 'TINYINT(1)',
            'datetime' => 'DATETIME',
            default => throw new \InvalidArgumentException("Unknown column type '{$column->type}'"),
        };
    }
}
