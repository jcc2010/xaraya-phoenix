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
    'mail' => [
        // "log" writes each message to mail.path as an .eml file (development only); "mail" sends with PHP's mail().
        'driver' => env('MAIL_DRIVER', 'log'),
        // Empty means phoenix@<host of app.url>, or phoenix@example.invalid when that host has no dot.
        'from' => env('MAIL_FROM', ''),
        'path' => $root . '/var/mail',
    ],
    'session' => [
        'cookie' => env('SESSION_COOKIE', 'xar_session'),
        // Idle minutes before a session expires; expired rows are deleted on about 1 in 100 session writes.
        'lifetime' => (int) env('SESSION_LIFETIME', 120),
        // Seconds after creation (or the last login) when a session ends whatever its activity.
        'max_lifetime' => (int) env('SESSION_MAX_LIFETIME', 86400),
        'gc_probability' => 0.01,
    ],
    'http' => [
        // Reverse proxies (exact IPs, comma-separated) whose X-Forwarded-For is believed. Empty: use REMOTE_ADDR only.
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    ],
    'log' => [
        'path' => $root . '/var/logs',
        'level' => env('LOG_LEVEL', 'info'),
    ],
];
