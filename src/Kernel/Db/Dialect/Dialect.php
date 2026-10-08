<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use PDO;
use Xaraya\Kernel\Db\Schema\Blueprint;

interface Dialect
{
    public function name(): string;

    public function quote(string $identifier): string;

    public function onConnect(PDO $pdo): void;

    public function listTablesSql(): string;

    public function transactionalDdl(): bool;

    /** @param list<string> $columns */
    public function insertSql(string $table, array $columns): string;

    /**
     * @param list<string> $columns
     * @param list<string> $conflict
     */
    public function upsertSql(string $table, array $columns, array $conflict): string;

    /** @return list<string> */
    public function createTable(Blueprint $blueprint, string $prefix): array;

    /** @return list<string> */
    public function alterTable(Blueprint $blueprint, string $prefix): array;

    public function dropTableSql(string $table): string;

    /**
     * Renames a table together with its indexes (names starting with "<from>_"),
     * so the old name can be reused. Arguments are full, prefixed, unquoted names.
     */
    public function renameTable(PDO $pdo, string $from, string $to): void;
}
