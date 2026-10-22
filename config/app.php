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
    'themes' => [
        // THEME_PATHS is a comma-separated list of directories, relative to the project root.
        'paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('THEME_PATHS', 'themes'))))),
    ],
    'view' => [
        // "twig" or "php" overrides the active theme's engine preference; unset keeps the theme's choice.
        'engine' => env('VIEW_ENGINE'),
    ],
    'assets' => [
        // Where `xar asset:publish` links module and theme assets, and the URL they are served from.
        'path' => $root . '/public/assets',
        'url' => env('ASSET_URL', '/assets'),
    ],
    'session' => [
        'cookie' => env('SESSION_COOKIE', 'xar_session'),
        // Idle minutes before a session expires; expired rows are deleted on about 1 in 100 session writes.
        'lifetime' => (int) env('SESSION_LIFETIME', 120),
        'gc_probability' => 0.01,
    ],
    'log' => [
        'path' => $root . '/var/logs',
        'level' => env('LOG_LEVEL', 'info'),
    ],
];
