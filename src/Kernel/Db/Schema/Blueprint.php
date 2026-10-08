<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Schema;

use InvalidArgumentException;

final class Blueprint
{
    /** @var list<Column> */
    public array $columns = [];

    /** @var list<array{columns: list<string>, unique: bool}> */
    public array $indexes = [];

    /** @var list<array{column: string, table: string, references: string, onDelete: string}> */
    public array $foreignKeys = [];

    /** @var list<string> */
    public array $primary = [];

    public function __construct(public readonly string $table) {}

    public function increments(string $name = 'id'): Column
    {
        return $this->add($name, 'increments');
    }

    public function ulid(string $name = 'id'): Column
    {
        return $this->add($name, 'ulid');
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add($name, 'string', $length);
    }

    public function text(string $name): Column
    {
        return $this->add($name, 'text');
    }

    public function int(string $name): Column
    {
        return $this->add($name, 'int');
    }

    public function bigint(string $name): Column
    {
        return $this->add($name, 'bigint');
    }

    public function bool(string $name): Column
    {
        return $this->add($name, 'bool');
    }

    public function datetime(string $name): Column
    {
        return $this->add($name, 'datetime');
    }

    public function json(string $name): Column
    {
        return $this->add($name, 'json');
    }

    public function index(string ...$columns): void
    {
        $this->indexes[] = ['columns' => self::names($columns), 'unique' => false];
    }

    public function unique(string ...$columns): void
    {
        $this->indexes[] = ['columns' => self::names($columns), 'unique' => true];
    }

    public function primary(string ...$columns): void
    {
        $this->primary = self::names($columns);
    }

    public function foreign(string $column, string $table, string $references = 'id', string $onDelete = 'cascade'): void
    {
        self::names([$column, $table, $references]);
        $onDelete = strtolower($onDelete);
        if (!in_array($onDelete, ['cascade', 'restrict', 'set null', 'no action'], true)) {
            throw new InvalidArgumentException("Unsupported ON DELETE action '{$onDelete}'");
        }
        $this->foreignKeys[] = ['column' => $column, 'table' => $table, 'references' => $references, 'onDelete' => $onDelete];
    }

    private function add(string $name, string $type, ?int $length = null): Column
    {
        $column = new Column(Schema::check($name), $type, $length);
        $this->columns[] = $column;

        return $column;
    }

    /**
     * @param array<int|string, string> $columns
     * @return list<string>
     */
    private static function names(array $columns): array
    {
        return array_values(array_map(Schema::check(...), $columns));
    }
}
