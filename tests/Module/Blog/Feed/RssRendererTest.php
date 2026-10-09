<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Feed\RssRenderer;
use Xaraya\Module\Blog\Post\Post;

final class RssRendererTest extends TestCase
{
    public function testRendersValidRss(): void
    {
        $blog = ItemSerializerTest::blog('https://www.wyome.com/blog/post.html?id={id}');
        $post = new Post(
            '01m3t19wn8reaww81zg1m47tjq',
            1,
            'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq',
            'photo',
            "Fish & chips \x01",
            null,
            null,
            '<p>Tasty</p>',
            'https://athenana.com/p/img',
            new DateTimeImmutable('2026-10-01T12:00:00Z'),
            null,
            'published',
            [],
            [['name' => 'Food', 'slug' => 'food']],
        );

        $xml = (new RssRenderer(new ItemSerializer()))->render($blog, [$post], 'http://xar.test/blog/wyome/feed.xml', 'http://xar.test');
        $rss = simplexml_load_string($xml);
        self::assertNotFalse($rss);
        $channel = $rss->channel;
        self::assertSame('John Cox', (string) $channel->title);
        self::assertSame('https://www.wyome.com/blog/', (string) $channel->link);
        self::assertSame('About', (string) $channel->description);
        $item = $channel->item[0];
        self::assertSame('Fish & chips ', (string) $item->title, 'control characters stripped');
        self::assertSame('https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq', (string) $item->link);
        self::assertSame('true', (string) $item->guid['isPermaLink']);
        self::assertStringContainsString('<p>Tasty</p><p><a href="https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq">Permalink</a></p>', (string) $item->description);
        self::assertSame('Thu, 01 Oct 2026 12:00:00 +0000', (string) $item->pubDate);
        self::assertSame('https://www.wyome.com/blog/tag/food', (string) $item->category['domain']);
        self::assertSame('Food', (string) $item->category);
        self::assertSame('John Cox', (string) $item->children('http://purl.org/dc/elements/1.1/')->creator);
        self::assertSame('https://athenana.com/p/img', (string) $item->children('http://search.yahoo.com/mrss/')->content->attributes()['url']);
        self::assertSame('http://xar.test/blog/wyome/feed.xml', (string) $channel->children('http://www.w3.org/2005/Atom')->link->attributes()['href']);
    }
}
