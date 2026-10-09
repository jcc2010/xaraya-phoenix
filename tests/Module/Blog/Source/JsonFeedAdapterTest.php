<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;

final class JsonFeedAdapterTest extends TestCase
{
    private function record(array $item): \Xaraya\Module\Blog\Post\PostRecord
    {
        return (new JsonFeedAdapter())->toRecord($item, new DateTimeImmutable('2026-10-08T12:00:00Z'));
    }

    public function testKindInference(): void
    {
        self::assertSame('link', $this->record(['id' => '1', 'external_url' => 'https://e.test/a'])->kind);
        self::assertSame('photo', $this->record(['id' => '2', 'image' => 'https://e.test/i.png'])->kind);
        self::assertSame('note', $this->record(['id' => '3', 'image' => 'https://e.test/i.png', 'content_html' => '<p>Words</p>'])->kind);
        self::assertSame('note', $this->record(['id' => '4', 'content_text' => 'Plain'])->kind);
    }

    public function testDerivedTitlesAndDates(): void
    {
        $note = $this->record(['id' => '1', 'content_text' => 'Hello <world>', 'date_modified' => '2026-10-02T00:00:00Z']);
        self::assertSame('Hello <world>', $note->title);
        self::assertSame('2026-10-02 00:00:00', $note->datePublished->format('Y-m-d H:i:s'), 'falls back to date_modified');
        self::assertSame(['content_text' => 'Hello <world>'], $note->doc);

        $link = $this->record(['id' => '2', 'external_url' => 'https://www.e.test/path/']);
        self::assertSame('e.test/path', $link->title);
        self::assertSame('2026-10-08 12:00:00', $link->datePublished->format('Y-m-d H:i:s'), 'falls back to now');
        self::assertNull($link->ulid);
    }

    public function testTagsAreSluggedAndDeduplicated(): void
    {
        $r = $this->record(['id' => '1', 'tags' => ['Rock & Roll', 'rock-roll', ' ', 'Jazz']]);
        self::assertSame([['name' => 'Rock & Roll', 'slug' => 'rock-roll'], ['name' => 'Jazz', 'slug' => 'jazz']], $r->tags);
        self::assertSame(['Rock & Roll', 'rock-roll', ' ', 'Jazz'], $r->doc['tags'], 'source list kept when it differs');

        self::assertArrayNotHasKey('tags', $this->record(['id' => '2', 'tags' => ['a', 'b']])->doc);
    }

    public function testUlidExtraction(): void
    {
        self::assertSame('01m3t19wn8reaww81zg1m47tjq', $this->record(['id' => 'tag:x,2026:01m3t19wn8reaww81zg1m47tjq'])->ulid);
        self::assertNull($this->record(['id' => 'x01m3t19wn8reaww81zg1m47tjq'])->ulid, 'must not be glued to other text');
        self::assertSame('42', $this->record(['id' => 42])->itemId);
    }

    public function testRejectsBadItems(): void
    {
        foreach ([['title' => 'no id'], ['id' => ''], ['id' => '1', 'date_published' => 'not a date'], ['id' => str_repeat('x', 513)]] as $item) {
            try {
                $this->record($item);
                self::fail('expected rejection: ' . json_encode($item));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFeedMeta(): void
    {
        $meta = (new JsonFeedAdapter())->feedMeta([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'John Cox',
            'description' => 'Notes, photos and the things worth saying something about.',
            'home_page_url' => 'https://www.wyome.com/blog/',
            'feed_url' => 'https://athenana.com/blog/wyome/feed.json',
            'language' => 'en',
            'icon' => 'https://athenana.com/img/apple-touch-icon.png',
            'favicon' => 'https://athenana.com/favicon.ico',
            'authors' => [['name' => 'John Cox', 'url' => 'https://www.wyome.com/blog/']],
            '_athenana' => ['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe', 'pinned_id' => 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq'],
            '_other' => ['x' => 1],
            'items' => [],
        ]);
        self::assertSame([
            'title' => 'John Cox',
            'description_html' => 'Notes, photos and the things worth saying something about.',
            'home_page_url' => 'https://www.wyome.com/blog/',
            'icon' => 'https://athenana.com/img/apple-touch-icon.png',
            'favicon' => 'https://athenana.com/favicon.ico',
            'language' => 'en',
            'author_name' => 'John Cox',
            'author_url' => 'https://www.wyome.com/blog/',
            'pinned_item_id' => 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq',
            'extra' => ['_athenana' => ['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe'], '_other' => ['x' => 1]],
        ], $meta);

        $bare = (new JsonFeedAdapter())->feedMeta(['version' => 'https://jsonfeed.org/version/1.1', 'items' => []]);
        self::assertSame(['pinned_item_id' => null, 'extra' => []], $bare);
    }

    public function testFeedMetaIgnoresIntegerKeys(): void
    {
        $feed = json_decode('{"1":"x","_a":1,"version":"https://jsonfeed.org/version/1.1","items":[]}', true);
        self::assertSame(['_a' => 1], (new JsonFeedAdapter())->feedMeta($feed)['extra']);
    }

    public function testNonFiniteNumberIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->record(json_decode('{"id":"1","n":1e999}', true));
    }

    public function testOverlongUrlsAreRejected(): void
    {
        foreach ([['url', 1025], ['external_url', 2049], ['image', 2049]] as [$key, $len]) {
            try {
                $this->record(['id' => '1', $key => 'https://e.test/' . str_repeat('a', $len)]);
                self::fail("expected rejection of {$key}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(2048, strlen((string) $this->record(['id' => '1', 'image' => str_repeat('a', 2048)])->image));
    }
}
