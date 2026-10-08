<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db;

use InvalidArgumentException;
use PDO;
use Xaraya\Kernel\Db\Dialect\Mysql;
use Xaraya\Kernel\Db\Dialect\Pgsql;
use Xaraya\Kernel\Db\Dialect\Sqlite;

final class ConnectionFactory
{
    /** @param array<string, mixed> $config */
    public static function make(array $config): Connection
    {
        $dsn = $config['dsn'] ?? null;
        if (!is_string($dsn) || $dsn === '') {
            throw new InvalidArgumentException('Database config needs a "dsn" string');
        }
        $driver = strtolower((string) strstr($dsn, ':', true));
        $dialect = match ($driver) {
            'sqlite' => new Sqlite(),
            'mysql' => new Mysql(),
            'pgsql' => new Pgsql(),
            default => throw new InvalidArgumentException("Unsupported database DSN '{$dsn}'"),
        };
        if ($driver === 'sqlite') {
            $path = substr($dsn, 7);
            if ($path !== '' && $path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($driver === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }
        $user = $config['user'] ?? null;
        $password = $config['password'] ?? null;
        $pdo = new PDO($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null, $options);
        $prefix = $config['prefix'] ?? 'xar_';

        return new Connection($pdo, $dialect, is_string($prefix) ? $prefix : 'xar_');
    }
}
