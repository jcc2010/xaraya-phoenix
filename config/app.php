<?php

declare(strict_types=1);

use function Xaraya\env;

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => env('APP_NAME', 'Xaraya Phoenix'),
        'url' => env('APP_URL', 'http://localhost:8080'),
        'debug' => env('APP_DEBUG', false) === true,
        'timezone' => env('APP_TIMEZONE', 'UTC'),
        'locale' => env('APP_LOCALE', 'en'),
        'theme' => env('APP_THEME', 'phoenix'),
        'cache' => $root . '/var/cache',
    ],
    'db' => [
        'dsn' => env('DB_DSN', 'sqlite:' . $root . '/var/database.sqlite'),
        'user' => env('DB_USER'),
        'password' => env('DB_PASSWORD'),
        'prefix' => env('DB_PREFIX', 'xar_'),
    ],
    'modules' => [
        // MODULE_PATHS is a comma-separated list of directories, relative to the project root.
        'paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('MODULE_PATHS', 'modules'))))),
    ],
    'log' => [
        'path' => $root . '/var/logs',
        'level' => env('LOG_LEVEL', 'info'),
    ],
];
