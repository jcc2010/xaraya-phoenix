<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth\Http;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/** The kernel's sign-in pages (App::routes() adds them after the modules' routes). Both POSTs need the CSRF token. */
final class AuthRoutes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/login', [LoginController::class, 'show'], 'auth.login')->middleware('secureHtml');
        $routes->post('/login', [LoginController::class, 'login'], 'auth.login.submit')->middleware('secureHtml');
        $routes->post('/logout', [LoginController::class, 'logout'], 'auth.logout')->middleware('secureHtml');
    }
}
