<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\ConnectionFactory;

abstract class DbTestCase extends TestCase
{
    public const PREFIX = 'xt_';

    protected Connection $db;

    protected function setUp(): void
    {
        $this->db = self::connect();
        self::dropAll($this->db);
    }

    protected function tearDown(): void
    {
        self::dropAll($this->db);
    }

    /** @return array{dsn: string, user: ?string, password: ?string, prefix: string} */
    public static function dbConfig(): array
    {
        return [
            'dsn' => getenv('XAR_TEST_DSN') ?: 'sqlite::memory:',
            'user' => getenv('XAR_TEST_DB_USER') ?: null,
            'password' => getenv('XAR_TEST_DB_PASSWORD') ?: null,
            'prefix' => self::PREFIX,
        ];
    }

    public static function connect(): Connection
    {
        return ConnectionFactory::make(self::dbConfig());
    }

    public static function dropAll(Connection $db): void
    {
        $mysql = $db->dialect()->name() === 'mysql';
        if ($mysql) {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($db->tables() as $table) {
            $db->pdo()->exec($db->dialect()->dropTableSql($db->prefix() . $table));
        }
        if ($mysql) {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
