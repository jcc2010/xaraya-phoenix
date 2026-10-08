<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use PDO;
use Xaraya\Kernel\Db\Schema\Column;

final class Sqlite extends AbstractDialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function onConnect(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function listTablesSql(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
    }

    public function renameTable(PDO $pdo, string $from, string $to): void
    {
        parent::renameTable($pdo, $from, $to);
        $statement = $pdo->prepare("SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL");
        $statement->execute([$to]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $index) {
            $old = (string) $index['name'];
            if (!str_starts_with($old, $from . '_')) {
                continue;
            }
            $new = $this->renamedIndexName($from, $to, $old);
            $pdo->exec('DROP INDEX ' . $this->quote($old));
            $pdo->exec(preg_replace('/' . preg_quote($this->quote($old), '/') . '/', $this->quote($new), (string) $index['sql'], 1) ?? '');
        }
    }

    protected function incrementsSql(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'ulid' => 'CHAR(26)',
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text', 'json' => 'TEXT',
            'int', 'bool' => 'INTEGER',
            'bigint' => 'BIGINT',
            'datetime' => 'DATETIME',
            default => throw new \InvalidArgumentException("Unknown column type '{$column->type}'"),
        };
    }
}
