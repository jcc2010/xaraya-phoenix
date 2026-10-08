<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/hello', [HelloController::class, 'index'], 'hello.index');
        $routes->get('/hello.json', [HelloController::class, 'feed'], 'hello.feed')->middleware('cors', 'conditional');
        $routes->post('/hello/{name:[a-z]+}', [HelloController::class, 'create'], 'hello.create');
    }
}
