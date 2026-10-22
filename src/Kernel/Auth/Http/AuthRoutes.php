<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth\Http;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/** The kernel's sign-in and password reset pages (App::routes() adds them after the modules' routes). Every POST needs the CSRF token. */
final class AuthRoutes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/login', [LoginController::class, 'show'], 'auth.login')->middleware('secureHtml');
        $routes->post('/login', [LoginController::class, 'login'], 'auth.login.submit')->middleware('secureHtml');
        $routes->post('/logout', [LoginController::class, 'logout'], 'auth.logout')->middleware('secureHtml');
        $routes->get('/password/forgot', [PasswordController::class, 'forgotForm'], 'auth.forgot')->middleware('secureHtml');
        $routes->post('/password/forgot', [PasswordController::class, 'forgot'], 'auth.forgot.submit')
            ->middleware('secureHtml', 'throttle:password-forgot,5,900');
        $token = '{token:[A-Za-z0-9_-]{43}}';
        $routes->get('/password/reset/' . $token, [PasswordController::class, 'resetForm'], 'auth.reset')->middleware('secureHtml');
        $routes->post('/password/reset/' . $token, [PasswordController::class, 'reset'], 'auth.reset.submit')->middleware('secureHtml');
    }
}
