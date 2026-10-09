<?php

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

switch ($path) {
    case '/feed.json':
        header('Content-Type: application/feed+json');
        header('ETag: "v1"');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"v1"') {
            http_response_code(304);

            return true;
        }
        echo json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Fixture',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'items' => [],
        ]);

        return true;
    case '/moved':
        header('Location: /feed.json', true, 301);

        return true;
    case '/boom':
        http_response_code(500);
        echo 'nope';

        return true;
    case '/big':
        echo str_repeat('x', 4096);

        return true;
}
http_response_code(404);

return true;
