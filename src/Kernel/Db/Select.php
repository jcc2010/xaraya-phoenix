<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db;

use InvalidArgumentException;

final class Select
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like'];

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<string> */
    private array $wheres = [];

    /** @var list<mixed> */
    private array $params = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;
    private int $offset = 0;

    public function __construct(private readonly Connection $db, private readonly string $table) {}

    public function columns(string ...$columns): self
    {
        $this->columns = array_values(array_map($this->db->ident(...), $columns));

        return $this;
    }

    public function where(string $column, string $op, mixed $value): self
    {
        $op = strtolower($op);
        if (!in_array($op, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported operator '{$op}'");
        }
        $this->wheres[] = $this->db->ident($column) . ' ' . strtoupper($op) . ' ?';
        $this->params[] = Connection::normalize($value);

        return $this;
    }

    public function whereNull(string $column, bool $not = false): self
    {
        $this->wheres[] = $this->db->ident($column) . ($not ? ' IS NOT NULL' : ' IS NULL');

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            $this->wheres[] = '1 = 0';

            return $this;
        }
        $this->wheres[] = $this->db->ident($column) . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        foreach ($values as $value) {
            $this->params[] = Connection::normalize($value);
        }

        return $this;
    }

    /** @param array<string, mixed> $cursor ordered column => value */
    public function cursor(array $cursor, string $op = '<'): self
    {
        if (!in_array($op, ['<', '>'], true) || $cursor === []) {
            throw new InvalidArgumentException('cursor() needs columns and an operator of < or >');
        }
        $columns = array_keys($cursor);
        $values = array_values(array_map(Connection::normalize(...), $cursor));
        $alternatives = [];
        foreach ($columns as $i => $column) {
            $terms = [];
            for ($j = 0; $j < $i; $j++) {
                $terms[] = $this->db->ident($columns[$j]) . ' = ?';
                $this->params[] = $values[$j];
            }
            $terms[] = $this->db->ident($column) . " {$op} ?";
            $this->params[] = $values[$i];
            $alternatives[] = '(' . implode(' AND ', $terms) . ')';
        }
        $this->wheres[] = '(' . implode(' OR ', $alternatives) . ')';

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = $this->db->ident($column) . (strtolower($direction) === 'desc' ? ' DESC' : ' ASC');

        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = max(0, $limit);
        $this->offset = max(0, $offset);

        return $this;
    }

    /** @return array{0: string, 1: list<mixed>} */
    public function toSql(): array
    {
        return [$this->build(implode(', ', $this->columns), true), $this->params];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        [$sql, $params] = $this->toSql();

        return $this->db->fetchAll($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        [$sql, $params] = (clone $this)->limit(1)->toSql();

        return $this->db->fetchOne($sql, $params);
    }

    public function count(): int
    {
        return (int) $this->db->fetchValue($this->build('COUNT(*)', false), $this->params);
    }

    private function build(string $columns, bool $withOrderAndLimit): string
    {
        $sql = "SELECT {$columns} FROM " . $this->db->table($this->table);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        if ($withOrderAndLimit && $this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($withOrderAndLimit && $this->limit !== null) {
            $sql .= " LIMIT {$this->limit} OFFSET {$this->offset}";
        }

        return $sql;
    }
}
