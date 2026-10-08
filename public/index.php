<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    Xaraya\Kernel\App::boot(dirname(__DIR__))->run();
} catch (Throwable $e) {
    error_log((string) $e);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Server Error\n";
}
