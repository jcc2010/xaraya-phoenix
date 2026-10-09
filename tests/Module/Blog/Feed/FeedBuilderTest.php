<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\FeedBuilder;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;

final class FeedBuilderTest extends TestCase
{
    public function testEnvelope(): void
    {
        $blog = new Blog(
            1,
            'wyome',
            'John Cox',
            'Notes, photos and the things worth saying something about.',
            'John Cox',
            'https://www.wyome.com/blog/',
            'https://www.wyome.com/blog/',
            null,
            'en',
            'https://athenana.com/img/apple-touch-icon.png',
            'https://athenana.com/favicon.ico',
            'mirror',
            'https://athenana.com/blog/wyome/feed.json',
            'athena',
            'remote',
            null,
            null,
            null,
            ['_athenana' => ['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe'], '_other' => 1],
        );
        $post = new Post(
            '01m3t19wn8reaww81zg1m47tjq',
            1,
            'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq',
            'note',
            'Hi',
            null,
            null,
            null,
            null,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            null,
            'published',
            [],
            [],
        );

        $feed = (new FeedBuilder(new ItemSerializer()))->feed($blog, [$post], 'http://xar.test/blog/wyome/feed.json', 'http://xar.test/blog/wyome/feed.json?before=abc', 'http://xar.test');

        self::assertSame(
            ['version', 'title', 'description', 'home_page_url', 'feed_url', 'next_url', 'language', 'icon', 'favicon', 'authors', '_athenana', '_other', 'items'],
            array_keys($feed),
        );
        self::assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        self::assertSame([['name' => 'John Cox', 'url' => 'https://www.wyome.com/blog/']], $feed['authors']);
        self::assertSame(['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe'], $feed['_athenana']);
        self::assertSame('https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq', $feed['items'][0]['id']);
        self::assertSame(['kind' => 'note'], $feed['items'][0]['_athenana']);
    }

    public function testEmptyFeedKeepsItemsAndDropsNextUrl(): void
    {
        $blog = new Blog(2, 'empty', 'Empty', null, 'Empty', null, null, null, 'en', null, null, 'native', null, null, 'remote', 'https://x/s/1', null, null, []);
        $feed = (new FeedBuilder(new ItemSerializer()))->feed($blog, [], 'http://xar.test/blog/empty/feed.json', null, 'http://xar.test');
        self::assertSame([], $feed['items']);
        self::assertArrayNotHasKey('next_url', $feed);
        self::assertSame(['pinned_id' => 'https://x/s/1'], $feed['_athenana']);
        self::assertSame([['name' => 'Empty']], $feed['authors']);
    }
}
