<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class FeedHttpTest extends BlogTestCase
{
    private function seeded(int $count): App
    {
        $this->enableBlog();
        $app = $this->app();
        $blog = $this->blogs($app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena',
        ], $this->now());
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items($count))));
        $app->container()->get(Syncer::class)->sync($blog, true, $this->now());

        return $this->app();
    }

    /** @param array<string, string> $headers */
    private function get(App $app, string $uri, array $headers = [], string $method = 'GET'): ResponseInterface
    {
        $request = new ServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPagingWalksEveryItemOnceNewestFirst(): void
    {
        $app = $this->seeded(120);
        $response = $this->get($app, '/blog/src/feed.json');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/feed+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Thu, 01 Oct 2026 12:00:00 GMT', $response->getHeaderLine('Last-Modified'));

        $feed = self::body($response);
        self::assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        self::assertSame('Source Blog', $feed['title']);
        self::assertSame('http://xar.test/blog/src/feed.json', $feed['feed_url']);
        self::assertStringStartsWith('http://xar.test/blog/src/feed.json?before=', $feed['next_url']);

        $titles = [];
        $pages = 0;
        while (true) {
            $pages++;
            foreach ($feed['items'] as $item) {
                $titles[] = $item['title'];
            }
            if (!isset($feed['next_url'])) {
                break;
            }
            $next = parse_url($feed['next_url']);
            $feed = self::body($this->get($app, $next['path'] . '?' . $next['query']));
        }
        self::assertSame(3, $pages);
        self::assertSame(array_map(fn(int $n): string => "Post {$n}", range(0, 119)), $titles);
    }

    public function testConditionalGetAndPreflight(): void
    {
        $app = $this->seeded(3);
        $first = $this->get($app, '/blog/src/feed.json');
        $again = $this->get($app, '/blog/src/feed.json', ['If-None-Match' => $first->getHeaderLine('ETag')]);
        self::assertSame(304, $again->getStatusCode());

        $preflight = $this->get($app, '/blog/src/feed.json', ['Access-Control-Request-Method' => 'GET'], 'OPTIONS');
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('*', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testErrorsAreJsonWithCors(): void
    {
        $app = $this->seeded(3);
        $bad = $this->get($app, '/blog/src/feed.json?before=not-a-cursor');
        self::assertSame(400, $bad->getStatusCode());
        self::assertSame('*', $bad->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(400, self::body($bad)['error']['status']);

        $missing = $this->get($app, '/blog/nope/feed.json');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('*', $missing->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSingleItemAndHiddenPosts(): void
    {
        $app = $this->seeded(3);
        $feed = self::body($this->get($app, '/blog/src/feed.json'));
        $first = $feed['items'][0];
        $id = substr($first['id'], -26);

        $single = $this->get($app, "/s/{$id}.json");
        self::assertSame(200, $single->getStatusCode());
        self::assertSame('application/json', $single->getHeaderLine('Content-Type'));
        self::assertSame('*', $single->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertEquals($first, self::body($single));

        $posts = $app->container()->get(PostRepository::class);
        $blog = $this->blogs($app)->find('src');
        self::assertNotNull($blog);
        $future = Ulid::generate();
        $posts->save($blog->id, new PostRecord(
            "https://src.test/s/{$future}",
            $future,
            'note',
            'Later',
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2099-01-01', new DateTimeZone('UTC')),
            null,
            [],
            [],
            sha1('later'),
        ), $this->now());
        $posts->tombstone($blog->id, [$first['id']], $this->now());

        $titles = array_column(self::body($this->get($app, '/blog/src/feed.json'))['items'], 'title');
        self::assertSame(['Post 1', 'Post 2'], $titles);
        self::assertSame(404, $this->get($app, "/s/{$future}.json")->getStatusCode());
        self::assertSame(404, $this->get($app, "/s/{$id}.json")->getStatusCode());
        self::assertSame(404, $this->get($app, '/s/' . Ulid::generate() . '.json')->getStatusCode());
    }

    public function testRss(): void
    {
        $app = $this->seeded(60);
        $response = $this->get($app, '/blog/src/feed.xml');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/rss+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $rss = simplexml_load_string((string) $response->getBody());
        self::assertNotFalse($rss);
        self::assertCount(50, $rss->channel->item);
        self::assertSame('Post 0', (string) $rss->channel->item[0]->title);
    }
}
