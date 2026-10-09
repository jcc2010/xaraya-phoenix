<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Psr\Log\LoggerInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\StreamFetcher;
use Xaraya\Module\Blog\Sync\SyncLock;

final class BlogServiceProvider implements ServiceProvider
{
    /** Kept in sync with modules/blog/module.json (asserted by StreamFetcherTest). */
    public const VERSION = '0.1.0';

    public function register(Container $container): void
    {
        $container->set(HttpFetcher::class, static fn(Container $c): HttpFetcher => new StreamFetcher(
            allowPrivateHosts: (bool) $c->get(Config::class)->get('blog.fetch_allow_private', false),
        ));
        $container->set(SyncLock::class, static fn(Container $c): SyncLock => new SyncLock(
            (string) $c->get(Config::class)->get('blog.locks', $c->get(App::class)->path('var/locks')),
        ));
        $container->set(MediaStore::class, static fn(Container $c): MediaStore => new MediaStore(
            $c->get(Connection::class),
            $c->get(HttpFetcher::class),
            $c->get(LoggerInterface::class),
            (string) $c->get(Config::class)->get('blog.uploads', $c->get(App::class)->path('var/uploads')),
        ));
    }
}
