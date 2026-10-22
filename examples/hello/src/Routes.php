<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

final class Routes implements RouteProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/hello', [HelloController::class, 'index'], 'hello.index')->middleware('secureHtml', 'conditional');
        $routes->get('/hello.json', [HelloController::class, 'feed'], 'hello.feed')->middleware('cors', 'conditional');
        $routes->get('/hello/{id:[0-9a-hjkmnp-tv-z]{26}}', [HelloController::class, 'show'], 'hello.show')->middleware('secureHtml', 'conditional');
        $routes->post('/hello/{name:[a-z]+}', [HelloController::class, 'create'], 'hello.create');
        $routes->get('/hello/admin', [HelloController::class, 'admin'], 'hello.admin')->middleware('secureHtml', 'auth:hello.greeting.admin');
        // The token API needs the tokens module, which provides the `token` middleware.
        if ($this->modules->isEnabled('tokens')) {
            $routes->post('/api/hello/greetings', [HelloController::class, 'apiCreate'], 'hello.api.create')->middleware('csrf:off', 'token:hello:write');
        }
    }
}
