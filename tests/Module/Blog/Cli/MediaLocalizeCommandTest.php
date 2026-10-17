<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class MediaLocalizeCommandTest extends BlogTestCase
{
    public function testDownloadsRemoteMediaThenSwitchesTheBlogToLocal(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['image' => 'https://img.test/a.png', 'kind' => 'photo']));
        $this->addPost($app, $blog, self::postRecord(2, ['image' => 'https://img.test/missing.png', 'doc' => ['_athenana' => ['images' => [['url' => 'https://img.test/a.png']]]]]));
        $this->addPost($app, $blog, self::postRecord(3, ['image' => 'http://xar.test/media/01jabcdefghjkmnpqrstvwxyz0.png']));

        $fetcher = (new FixtureFetcher())->on('https://img.test/a.png', new FetchResult(200, (string) base64_decode(MediaStoreTest::PNG, true)));
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, $fetcher);
        [$code, $out, $err] = $this->xar(['media:localize', 'notes'], $app);
        self::assertSame(0, $code, $err);
        self::assertSame("notes: 2 media URL(s) stored locally, 1 left remote (see the log). Media is now local.\n", $out);
        $fetched = array_column($fetcher->requests, 'url');
        sort($fetched);
        self::assertSame(['https://img.test/a.png', 'https://img.test/missing.png'], $fetched, 'fetched once each; our own /media URL never');
        self::assertSame('local', $this->blogs($this->app())->find('notes')?->media);

        $feed = json_decode((string) $this->app()->handle(new ServerRequest('GET', '/blog/notes/feed.json'))->getBody(), true);
        $images = array_column($feed['items'], 'image');
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-z]{26}\.png$#', $images[0]);
        self::assertSame('https://img.test/missing.png', $images[1], 'a failed download stays remote');
    }

    public function testNeedsAKnownHandle(): void
    {
        $this->enableBlog();
        [$code, , $err] = $this->xar(['media:localize']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar media:localize <handle>', $err);
        [$code, , $err] = $this->xar(['media:localize', 'nope']);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'nope'", $err);
    }
}
