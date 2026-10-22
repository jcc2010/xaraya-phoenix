<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Module\Blog\Admin\Http\DashboardController;
use Xaraya\Module\Blog\Admin\Http\PostListController;

/** The blog admin routes: all HTML, never cached, each behind an `auth:` check. */
final class AdminRoutes
{
    public static function define(RouteCollector $routes): void
    {
        $routes->group('/admin/blog', ['secureHtml', 'noStore'], static function (RouteCollector $admin): void {
            $admin->get('', [DashboardController::class, 'index'], 'admin.blog')
                ->middleware('auth:blog.post.edit');
            $admin->get('/{handle:[a-z0-9_-]{2,32}}/posts', [PostListController::class, 'index'], 'admin.blog.posts')
                ->middleware('auth:blog.post.edit');
        });
    }
}
