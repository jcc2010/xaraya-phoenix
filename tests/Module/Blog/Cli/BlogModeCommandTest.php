<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class BlogModeCommandTest extends BlogTestCase
{
    public function testMirrorToNativeKeepsIds(): void
    {
        $app = $this->enableBlog();
        $this->xar(['blog:create', 'src', '--mode=mirror', '--format=athena', '--source=https://src.test/blog/src/feed.json'], $app);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->xar(['blog:sync', 'src', '--full'], $app);
        $before = array_column(json_decode((string) $this->app()->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true)['items'], 'id');

        [$code, $out] = $this->xar(['blog:mode', 'src', 'native']);
        self::assertSame(0, $code);
        self::assertStringContainsString("Blog 'src' is now native.", $out);
        $after = array_column(json_decode((string) $this->app()->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true)['items'], 'id');
        self::assertSame($before, $after);

        [$code, , $err] = $this->xar(['blog:sync', 'src']);
        self::assertSame(1, $code);
        self::assertStringContainsString('not a mirror blog', $err);

        [$code, $out] = $this->xar(['blog:mode', 'src', 'native']);
        self::assertSame(0, $code);
        self::assertStringContainsString("Blog 'src' is already native.", $out);
    }

    public function testNativeToMirrorNeedsSourceAndForceForNativePosts(): void
    {
        $app = $this->enableBlog();
        $this->xar(['blog:create', 'mine', '--mode=native'], $app);

        [$code, , $err] = $this->xar(['blog:mode', 'mine', 'mirror']);
        self::assertSame(1, $code);
        self::assertStringContainsString('source URL', $err);

        $blog = $this->blogs($this->app())->find('mine');
        self::assertNotNull($blog);
        $ulid = Ulid::generate();
        $this->app()->container()->get(PostRepository::class)->save($blog->id, new PostRecord(
            "http://xar.test/s/{$ulid}",
            $ulid,
            'note',
            'Mine',
            null,
            null,
            '<p>x</p>',
            null,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            null,
            [],
            [],
            'h',
        ), $this->now());

        $args = ['blog:mode', 'mine', 'mirror', '--source=https://src.test/blog/src/feed.json', '--format=athena'];
        [$code, , $err] = $this->xar($args);
        self::assertSame(1, $code);
        self::assertStringContainsString('1 native post(s)', $err);
        self::assertSame('native', $this->blogs($this->app())->find('mine')?->mode);

        [$code, $out] = $this->xar([...$args, '--force']);
        self::assertSame(0, $code);
        self::assertStringContainsString("Blog 'mine' is now mirror.", $out);
        $blog = $this->blogs($this->app())->find('mine');
        self::assertSame('https://src.test/blog/src/feed.json', $blog?->sourceUrl);
        self::assertSame('athena', $blog?->sourceFormat);
    }

    public function testUsageErrors(): void
    {
        $app = $this->enableBlog();
        foreach ([['blog:mode'], ['blog:mode', 'x'], ['blog:mode', 'x', 'sideways']] as $args) {
            [$code, , $err] = $this->xar($args, $app);
            self::assertSame(1, $code);
            self::assertStringContainsString('Usage: xar blog:mode', $err);
        }
        [$code, , $err] = $this->xar(['blog:mode', 'ghost', 'native'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'ghost'", $err);
    }
}
