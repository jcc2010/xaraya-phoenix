<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\PostController;

final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/feed.json', [FeedController::class, 'feedJson'], 'blog.feed.json')
            ->middleware('cors', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/feed.xml', [FeedController::class, 'feedXml'], 'blog.feed.xml')
            ->middleware('cors', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}.json', [PostController::class, 'show'], 'blog.post.json')
            ->middleware('cors', 'conditional');
    }
}
