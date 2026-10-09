<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class BlogSyncCommandTest extends BlogTestCase
{
    public function testSyncAllMirrors(): void
    {
        $this->enableBlog();
        $app = $this->app();
        $this->xar(['blog:create', 'src', '--mode=mirror', '--format=athena', '--source=https://src.test/blog/src/feed.json'], $app);
        $this->xar(['blog:create', 'mine', '--mode=native'], $app);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(5))));

        [$code, $out] = $this->xar(['blog:sync', '--all', '--full'], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString('src: 1 page(s), 5 created, 0 updated, 0 unchanged, 0 skipped, 0 deleted', $out);
        self::assertStringNotContainsString('mine', $out);
    }

    public function testErrors(): void
    {
        $app = $this->enableBlog();
        [$code, , $err] = $this->xar(['blog:sync'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar blog:sync', $err);

        [$code, , $err] = $this->xar(['blog:sync', 'ghost'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'ghost'", $err);

        $this->xar(['blog:create', 'mine', '--mode=native'], $app);
        [$code, , $err] = $this->xar(['blog:sync', 'mine'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('not a mirror blog', $err);
    }
}
