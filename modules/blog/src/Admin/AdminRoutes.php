<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Module\Blog\Admin\Http\BlogAdminController;
use Xaraya\Module\Blog\Admin\Http\DashboardController;
use Xaraya\Module\Blog\Admin\Http\PostActionsController;
use Xaraya\Module\Blog\Admin\Http\PostFormController;
use Xaraya\Module\Blog\Admin\Http\PostListController;
use Xaraya\Module\Blog\Admin\Http\TagAdminController;

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
            $admin->get('/{handle:[a-z0-9_-]{2,32}}/posts/new', [PostFormController::class, 'newForm'], 'admin.blog.post.new')
                ->middleware('auth:blog.post.add');
            $admin->post('/{handle:[a-z0-9_-]{2,32}}/posts/new', [PostFormController::class, 'create'], 'admin.blog.post.create')
                ->middleware('auth:blog.post.add');
            $admin->post('/{handle:[a-z0-9_-]{2,32}}/sync', [BlogAdminController::class, 'sync'], 'admin.blog.sync')
                ->middleware('auth:blog.blog.admin');
            $admin->get('/new', [BlogAdminController::class, 'newBlogForm'], 'admin.blog.new')
                ->middleware('auth:blog.blog.admin');
            $admin->post('/new', [BlogAdminController::class, 'createBlog'], 'admin.blog.create')
                ->middleware('auth:blog.blog.admin');
            $admin->get('/{handle:[a-z0-9_-]{2,32}}/settings', [BlogAdminController::class, 'settings'], 'admin.blog.settings')
                ->middleware('auth:blog.blog.admin');
            $admin->post('/{handle:[a-z0-9_-]{2,32}}/settings', [BlogAdminController::class, 'saveSettings'], 'admin.blog.settings.save')
                ->middleware('auth:blog.blog.admin');
            // The tags screen needs edit on every post (AdminAccess::canEditAll), checked in the controller.
            $admin->get('/{handle:[a-z0-9_-]{2,32}}/tags', [TagAdminController::class, 'index'], 'admin.blog.tags')
                ->middleware('auth:blog.post.edit');
            $admin->post('/{handle:[a-z0-9_-]{2,32}}/tags/{slug:[^/]{1,128}}/rename', [TagAdminController::class, 'rename'], 'admin.blog.tag.rename')
                ->middleware('auth:blog.post.edit');
            $admin->post('/{handle:[a-z0-9_-]{2,32}}/tags/{slug:[^/]{1,128}}/remove', [TagAdminController::class, 'remove'], 'admin.blog.tag.remove')
                ->middleware('auth:blog.post.edit');
            // Which posts the user may edit is decided per post (AdminAccess::assertEdit) in the controller.
            $admin->get('/post/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostFormController::class, 'edit'], 'admin.blog.post.edit')
                ->middleware('auth:blog.post.edit');
            $admin->post('/post/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostFormController::class, 'update'], 'admin.blog.post.update')
                ->middleware('auth:blog.post.edit');
            $admin->post('/post/{id:[0-9a-hjkmnp-tv-z]{26}}/delete', [PostActionsController::class, 'delete'], 'admin.blog.post.delete')
                ->middleware('auth:blog.post.delete');
            $admin->post('/post/{id:[0-9a-hjkmnp-tv-z]{26}}/undelete', [PostActionsController::class, 'undelete'], 'admin.blog.post.undelete')
                ->middleware('auth:blog.post.delete');
        });
    }
}
