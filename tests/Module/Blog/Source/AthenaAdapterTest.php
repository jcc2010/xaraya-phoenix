<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Source\AdapterRegistry;
use Xaraya\Module\Blog\Source\AthenaAdapter;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;
use Xaraya\Module\Blog\Support\Text;

final class AthenaAdapterTest extends TestCase
{
    public const SONG = <<<'JSON'
        {
          "id": "https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq",
          "url": "https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq",
          "external_url": "https://music.apple.com/us/album/flagpole-sitta/1440923485?i=1440923493",
          "title": "Flagpole Sitta by Harvey Danger on Apple Music",
          "content_html": "<p>Song · 1998 · Duration 3:37</p>",
          "date_published": "2026-09-30T20:50:08+00:00",
          "date_modified": "2026-10-04T03:40:43+00:00",
          "tags": ["music", "comedy", "1990s", "rock"],
          "_video": {"provider": "youtube", "id": "sVt1Dy_LblQ", "thumbnail": "https://athenana.com/p/01m3t1bb3wrk4azp4tth96cezk?v=48888177", "source": "matched"},
          "_source": {"name": "Apple Music - Web Player", "url": "https://music.apple.com/us/album/flagpole-sitta/1440923485?i=1440923493"},
          "_athenana": {
            "kind": "link",
            "song": {"channel": "Harvey Danger - Topic", "duration_seconds": 218},
            "tags": [{"name": "music", "slug": "music"}, {"name": "comedy", "slug": "comedy"}, {"name": "1990s", "slug": "1990s"}, {"name": "rock", "slug": "rock"}]
          }
        }
        JSON;

    /** @return array<string, mixed> */
    public static function song(): array
    {
        return json_decode(self::SONG, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSongLinkItem(): void
    {
        $r = (new AthenaAdapter())->toRecord(self::song(), new DateTimeImmutable('2026-10-08T00:00:00Z'));

        self::assertSame('https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq', $r->itemId);
        self::assertSame('01m3t19wn8reaww81zg1m47tjq', $r->ulid);
        self::assertSame('link', $r->kind);
        self::assertSame('Flagpole Sitta by Harvey Danger on Apple Music', $r->title);
        self::assertSame('https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq', $r->url);
        self::assertSame('<p>Song · 1998 · Duration 3:37</p>', $r->contentHtml);
        self::assertNull($r->image);
        self::assertSame('2026-09-30 20:50:08', $r->datePublished->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $r->datePublished->getTimezone()->getName());
        self::assertSame('2026-10-04 03:40:43', $r->dateModified?->format('Y-m-d H:i:s'));
        self::assertSame(['music', 'comedy', '1990s', 'rock'], array_column($r->tags, 'name'));
        self::assertSame(['_video', '_source', '_athenana'], array_keys($r->doc));
        self::assertSame(['song' => ['channel' => 'Harvey Danger - Topic', 'duration_seconds' => 218]], $r->doc['_athenana']);
        self::assertSame(sha1(Text::canonicalJson(self::song())), $r->sourceHash);
    }

    public function testHashIgnoresKeyOrder(): void
    {
        $reordered = array_reverse(self::song(), true);
        $now = new DateTimeImmutable();
        self::assertSame((new AthenaAdapter())->toRecord(self::song(), $now)->sourceHash, (new AthenaAdapter())->toRecord($reordered, $now)->sourceHash);
    }

    public function testNoteWithoutContentAndTagListThatDiffers(): void
    {
        $item = [
            'id' => 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjr',
            'title' => 'A note',
            'date_published' => '2026-10-01T10:00:00-04:00',
            'tags' => ['x'],
            '_athenana' => ['kind' => 'note', 'tags' => [['name' => 'Y', 'slug' => 'y']]],
        ];
        $r = (new AthenaAdapter())->toRecord($item, new DateTimeImmutable());
        self::assertSame('note', $r->kind);
        self::assertNull($r->contentHtml);
        self::assertSame('2026-10-01 14:00:00', $r->datePublished->format('Y-m-d H:i:s'));
        self::assertSame([['name' => 'Y', 'slug' => 'y']], $r->tags);
        self::assertSame(['x'], $r->doc['tags'], 'differing tags list kept verbatim');
        self::assertArrayNotHasKey('_athenana', $r->doc, 'empty _athenana dropped');
    }

    public function testUnknownKindFallsBackToInference(): void
    {
        $item = ['id' => 'x-1', 'title' => 'T', 'external_url' => 'https://e.test/', '_athenana' => ['kind' => 'hologram']];
        self::assertSame('link', (new AthenaAdapter())->toRecord($item, new DateTimeImmutable())->kind);
    }

    public function testRegistry(): void
    {
        $registry = new AdapterRegistry();
        self::assertSame(Blog::FORMATS, $registry->formats());
        self::assertInstanceOf(AthenaAdapter::class, $registry->get('athena'));
        self::assertInstanceOf(JsonFeedAdapter::class, $registry->get('jsonfeed'));
        $this->expectException(InvalidArgumentException::class);
        $registry->get('rss');
    }
}
