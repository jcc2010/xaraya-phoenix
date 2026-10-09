<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\StreamFetcher;

final class BlogServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(HttpFetcher::class, static fn(): HttpFetcher => new StreamFetcher());
    }
}
