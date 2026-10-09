<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\StreamFetcher;
use Xaraya\Module\Blog\Sync\SyncLock;

final class BlogServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(HttpFetcher::class, static fn(): HttpFetcher => new StreamFetcher());
        $container->set(SyncLock::class, static fn(Container $c): SyncLock => new SyncLock(
            (string) $c->get(Config::class)->get('blog.locks', $c->get(App::class)->path('var/locks')),
        ));
    }
}
