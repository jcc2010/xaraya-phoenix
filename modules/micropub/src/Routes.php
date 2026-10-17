<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/**
 * Micropub (W3C) on top of the blog's PostWriter. micropubErrors turns every 4xx into Micropub's error JSON, and
 * micropubToken moves a form `access_token` into the Authorization header before `token:` checks it.
 */
final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->post('/micropub', [MicropubController::class, 'post'], 'micropub')
            ->middleware('csrf:off', 'micropubErrors', 'micropubToken', 'token:blog:write');
    }
}
