<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use PHPUnit\Framework\TestCase;

final class FeedFactoryTest extends TestCase
{
    public function testPagesChainAndFetcherServesThem(): void
    {
        $items = FeedFactory::items(120);
        self::assertCount(120, $items);
        self::assertSame('2026-10-01T12:00:00+00:00', $items[0]['date_published']);
        self::assertSame('2026-10-01T11:00:00+00:00', $items[1]['date_published']);

        $pages = FeedFactory::pages($items);
        self::assertSame(['https://src.test/blog/src/feed.json', 'https://src.test/blog/src/feed.json?page=2', 'https://src.test/blog/src/feed.json?page=3'], array_keys($pages));
        self::assertSame('https://src.test/blog/src/feed.json?page=2', $pages['https://src.test/blog/src/feed.json']['next_url']);
        self::assertArrayNotHasKey('next_url', $pages['https://src.test/blog/src/feed.json?page=3']);
        self::assertCount(20, $pages['https://src.test/blog/src/feed.json?page=3']['items']);

        $fetcher = FeedFactory::fetcher($pages);
        self::assertSame(200, $fetcher->get('https://src.test/blog/src/feed.json')->status);
        self::assertSame(404, $fetcher->get('https://src.test/other')->status);
        self::assertSame([['url' => 'https://src.test/blog/src/feed.json', 'etag' => null], ['url' => 'https://src.test/other', 'etag' => null]], $fetcher->requests);
    }
}
