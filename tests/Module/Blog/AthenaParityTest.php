<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class AthenaParityTest extends BlogTestCase
{
    private const SOURCE = 'https://athenana.com/blog/wyome/feed.json';
    private const DIR = __DIR__ . '/../../fixtures/athena';

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::DIR . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function cursor(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query['before'] ?? null) ? $query['before'] : null;
    }

    public function testServedFeedMatchesAthena(): void
    {
        $pages = [self::fixture('page-1'), self::fixture('page-2'), self::fixture('page-3')];
        $fetcher = (new FixtureFetcher())
            ->on(self::SOURCE, FixtureFetcher::json($pages[0]))
            ->on((string) $pages[0]['next_url'], FixtureFetcher::json($pages[1]))
            ->on((string) $pages[1]['next_url'], FixtureFetcher::json($pages[2]));

        $app = $this->enableBlog();
        [$code, $out, $err] = $this->xar([
            'blog:create', 'wyome', '--mode=mirror', '--format=athena', '--source=' . self::SOURCE,
            '--post-url=https://www.wyome.com/blog/post.html?id={id}',
        ], $app);
        self::assertSame(0, $code, $out . $err);

        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, $fetcher);
        $blog = $this->blogs($app)->find('wyome');
        self::assertNotNull($blog);
        $report = $app->container()->get(Syncer::class)->sync($blog, true, $this->now());
        $total = array_sum(array_map(static fn(array $page): int => count($page['items']), $pages));
        self::assertSame(3, $report->pages);
        self::assertSame($total, $report->created);
        self::assertSame(0, $report->skipped);

        $app = $this->app();
        $uri = '/blog/wyome/feed.json';
        foreach ($pages as $n => $expected) {
            $response = $app->handle(new ServerRequest('GET', $uri));
            self::assertSame(200, $response->getStatusCode());
            $served = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(self::cursor($expected['next_url'] ?? null), self::cursor($served['next_url'] ?? null), 'page ' . ($n + 1) . ' cursor');
            $next = $served['next_url'] ?? null;
            unset($expected['feed_url'], $expected['next_url'], $served['feed_url'], $served['next_url']);
            self::assertEquals($expected, $served, 'page ' . ($n + 1) . ' differs from Athena');
            if (!is_string($next)) {
                break;
            }
            $parts = parse_url($next);
            $uri = ($parts['path'] ?? '') . '?' . ($parts['query'] ?? '');
        }

        $item = self::fixture('item');
        $single = $app->handle(new ServerRequest('GET', '/s/' . substr((string) $item['id'], -26) . '.json'));
        self::assertSame(200, $single->getStatusCode());
        self::assertEquals($item, json_decode((string) $single->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
