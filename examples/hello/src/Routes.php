<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/hello', [HelloController::class, 'index'], 'hello.index')->middleware('secureHtml', 'conditional');
        $routes->get('/hello.json', [HelloController::class, 'feed'], 'hello.feed')->middleware('cors', 'conditional');
        $routes->get('/hello/{id:[0-9a-hjkmnp-tv-z]{26}}', [HelloController::class, 'show'], 'hello.show')->middleware('secureHtml', 'conditional');
        $routes->post('/hello/{name:[a-z]+}', [HelloController::class, 'create'], 'hello.create');
    }
}
