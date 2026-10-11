<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\AboutController;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
use Xaraya\Module\Blog\Http\PostController;
use Xaraya\Module\Blog\Http\PostPageController;
use Xaraya\Module\Blog\Http\TagsController;

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
        $routes->get('/media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}', [MediaController::class, 'show'], 'blog.media');

        // HTML pages.
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}', [BlogPagesController::class, 'home'], 'blog.home')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/about', [AboutController::class, 'about'], 'blog.about')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tags', [TagsController::class, 'tags'], 'blog.tags')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', [BlogPagesController::class, 'tag'], 'blog.tag')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');
    }
}
