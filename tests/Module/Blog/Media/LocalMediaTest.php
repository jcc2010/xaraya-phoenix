<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Media;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class LocalMediaTest extends BlogTestCase
{
    private HttpFetcher $fetcher;

    /** @param array<string, mixed> $overrides */
    private function seeded(string $media, array $overrides = []): App
    {
        $this->enableBlog();
        $app = $this->app($overrides);
        $blog = $this->blogs($app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json',
            'source_format' => 'athena', 'media' => $media,
        ], $this->now());
        $items = FeedFactory::items(2);
        $items[0]['image'] = 'https://img.test/a.png';
        $fetcher = FeedFactory::fetcher(FeedFactory::pages($items))
            ->on('https://img.test/a.png', new FetchResult(200, (string) base64_decode(MediaStoreTest::PNG, true)));
        $this->fetcher = $fetcher;
        $app->container()->instance(HttpFetcher::class, $fetcher);
        $app->container()->get(Syncer::class)->sync($blog, true, $this->now());

        return $this->app($overrides);
    }

    public function testLocalBlogServesRewrittenMedia(): void
    {
        $app = $this->seeded('local');
        $feed = json_decode((string) $app->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true);
        $image = $feed['items'][0]['image'];
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-z]{26}\.png$#', $image);

        $single = json_decode((string) $app->handle(new ServerRequest('GET', '/s/' . substr($feed['items'][0]['id'], -26) . '.json'))->getBody(), true);
        self::assertSame($image, $single['image']);

        $rss = (string) $app->handle(new ServerRequest('GET', '/blog/src/feed.xml'))->getBody();
        self::assertStringContainsString('<media:content url="' . $image . '"', $rss);

        $file = $app->handle(new ServerRequest('GET', (string) parse_url($image, PHP_URL_PATH)));
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('image/png', $file->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $file->getHeaderLine('Cache-Control'));
        self::assertSame(base64_decode(MediaStoreTest::PNG, true), (string) $file->getBody());
        self::assertSame('nosniff', $file->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $file->getHeaderLine('Content-Security-Policy'));

        $id = substr((string) parse_url($image, PHP_URL_PATH), 7, 26);
        self::assertSame(404, $app->handle(new ServerRequest('GET', "/media/{$id}.jpg"))->getStatusCode(), 'wrong extension');
        self::assertSame(404, $app->handle(new ServerRequest('GET', '/media/01aaaaaaaaaaaaaaaaaaaaaaaa.png'))->getStatusCode());
    }

    public function testRemoteBlogKeepsSourceUrls(): void
    {
        $app = $this->seeded('remote');
        $feed = json_decode((string) $app->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true);
        self::assertSame('https://img.test/a.png', $feed['items'][0]['image']);
        self::assertDirectoryDoesNotExist($this->tmp . '/uploads/src');
    }

    public function testStorageFailureDoesNotAbortSync(): void
    {
        file_put_contents($this->tmp . '/notadir', 'x');
        $app = $this->seeded('local', ['blog.uploads' => $this->tmp . '/notadir']);
        $feed = json_decode((string) $app->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true);
        self::assertCount(2, $feed['items']);
        self::assertSame('https://img.test/a.png', $feed['items'][0]['image']);
    }

    public function testFullSyncBackfillsAfterSwitchingToLocal(): void
    {
        $app = $this->seeded('remote');
        $app->container()->instance(HttpFetcher::class, $this->fetcher);
        $repo = $this->blogs($app);
        $blog = $repo->update($repo->find('src') ?? throw new \RuntimeException(), ['media' => 'local'], $this->now());
        $app->container()->get(Syncer::class)->sync($blog, true, $this->now());
        $feed = json_decode((string) $app->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true);
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-z]{26}\.png$#', $feed['items'][0]['image']);
    }
}
