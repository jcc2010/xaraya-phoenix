<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Column;

abstract class AbstractDialect implements Dialect
{
    abstract protected function typeSql(Column $column): string;

    abstract protected function incrementsSql(): string;

    protected function boolLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function tableSuffix(): string
    {
        return '';
    }

    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function insertSql(string $table, array $columns): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (' . $this->quoteList($columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
    }

    public function upsertSql(string $table, array $columns, array $conflict): string
    {
        $update = array_values(array_diff($columns, $conflict));
        $sql = $this->insertSql($table, $columns) . ' ON CONFLICT (' . $this->quoteList($conflict) . ') DO ';
        if ($update === []) {
            return $sql . 'NOTHING';
        }

        return $sql . 'UPDATE SET ' . implode(', ', array_map(
            fn(string $c): string => $this->quote($c) . ' = excluded.' . $this->quote($c),
            $update,
        ));
    }

    public function createTable(Blueprint $blueprint, string $prefix): array
    {
        $table = $prefix . $blueprint->table;
        $defs = [];
        $primary = $blueprint->primary;
        foreach ($blueprint->columns as $column) {
            $defs[] = $this->columnSql($column);
            if ($column->primary) {
                $primary[] = $column->name;
            }
        }
        if ($primary !== []) {
            $defs[] = 'PRIMARY KEY (' . $this->quoteList($primary) . ')';
        }
        foreach ($blueprint->foreignKeys as $fk) {
            $defs[] = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
                $this->quote($fk['column']),
                $this->quote($prefix . $fk['table']),
                $this->quote($fk['references']),
                strtoupper($fk['onDelete']),
            );
        }
        $create = 'CREATE TABLE ' . $this->quote($table) . " (\n  " . implode(",\n  ", $defs) . "\n)" . $this->tableSuffix();

        return [$create, ...$this->indexSql($blueprint, $table)];
    }

    public function alterTable(Blueprint $blueprint, string $prefix): array
    {
        $table = $prefix . $blueprint->table;
        $sql = [];
        foreach ($blueprint->columns as $column) {
            $sql[] = 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->columnSql($column);
        }

        return [...$sql, ...$this->indexSql($blueprint, $table)];
    }

    public function dropTableSql(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($table);
    }

    public function renameTableSql(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quote($from) . ' RENAME TO ' . $this->quote($to);
    }

    /** @param list<string> $columns */
    protected function quoteList(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    protected function columnSql(Column $column): string
    {
        if ($column->type === 'increments') {
            return $this->quote($column->name) . ' ' . $this->incrementsSql();
        }
        $sql = $this->quote($column->name) . ' ' . $this->typeSql($column) . ($column->nullable ? ' NULL' : ' NOT NULL');
        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->literal($column->default);
        }

        return $sql;
    }

    protected function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->boolLiteral($value),
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new \InvalidArgumentException('Unsupported default value type ' . get_debug_type($value)),
        };
    }

    /** @return list<string> */
    protected function indexSql(Blueprint $blueprint, string $table): array
    {
        $indexes = $blueprint->indexes;
        foreach ($blueprint->columns as $column) {
            if ($column->unique) {
                $indexes[] = ['columns' => [$column->name], 'unique' => true];
            }
        }
        $sql = [];
        foreach ($indexes as $index) {
            $sql[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                $this->quote($this->indexName($table, $index['columns'], $index['unique'])),
                $this->quote($table),
                $this->quoteList($index['columns']),
            );
        }

        return $sql;
    }

    /** @param list<string> $columns */
    protected function indexName(string $table, array $columns, bool $unique): string
    {
        $name = $table . '_' . implode('_', $columns) . ($unique ? '_uniq' : '_idx');

        return strlen($name) <= 60 ? $name : substr($name, 0, 51) . '_' . substr(md5($name), 0, 8);
    }
}
