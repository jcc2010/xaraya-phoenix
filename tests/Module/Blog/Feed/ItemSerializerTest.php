<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Source\AthenaAdapter;
use Xaraya\Tests\Module\Blog\Source\AthenaAdapterTest;

final class ItemSerializerTest extends TestCase
{
    public static function blog(?string $pattern = null, string $mode = 'mirror'): Blog
    {
        return new Blog(
            1,
            'wyome',
            'John Cox',
            '<p>About</p>',
            'John Cox',
            'https://www.wyome.com/blog/',
            'https://www.wyome.com/blog/',
            $pattern,
            'en',
            null,
            null,
            $mode,
            'https://athenana.com/blog/wyome/feed.json',
            'athena',
            'remote',
            null,
            null,
            null,
            [],
        );
    }

    public static function postFrom(PostRecord $r, string $id): Post
    {
        return new Post(
            $id,
            1,
            $r->itemId,
            $r->kind,
            $r->title,
            $r->url,
            $r->externalUrl,
            $r->contentHtml,
            $r->image,
            $r->datePublished,
            $r->dateModified,
            'published',
            $r->doc,
            $r->tags,
        );
    }

    public function testAthenaItemRoundTripsExactly(): void
    {
        $source = AthenaAdapterTest::song();
        $record = (new AthenaAdapter())->toRecord($source, new DateTimeImmutable());
        $post = self::postFrom($record, (string) $record->ulid);
        $item = (new ItemSerializer())->item(self::blog('https://www.wyome.com/blog/post.html?id={id}'), $post, 'http://xar.test');

        self::assertEquals($source, $item);
        self::assertSame(['id', 'url', 'external_url', 'title', 'content_html', 'date_published', 'date_modified', 'tags', '_video', '_source', '_athenana'], array_keys($item));
        self::assertSame(['kind', 'song', 'tags'], array_keys($item['_athenana']));
    }

    public function testUrlFallbacks(): void
    {
        $s = new ItemSerializer();
        $record = (new AthenaAdapter())->toRecord(AthenaAdapterTest::song(), new DateTimeImmutable());
        $post = self::postFrom($record, '01m3t19wn8reaww81zg1m47tjq');
        self::assertSame('https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq', $s->url(self::blog(), $post, 'http://xar.test'), 'stored source url');

        $native = new Post(
            '01m3t19wn8reaww81zg1m47tjq',
            1,
            'http://xar.test/s/01m3t19wn8reaww81zg1m47tjq',
            'note',
            'N',
            null,
            null,
            '<p>x</p>',
            null,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            null,
            'published',
            [],
            [],
        );
        self::assertSame('http://xar.test/s/01m3t19wn8reaww81zg1m47tjq', $s->url(self::blog(null, 'native'), $native, 'http://xar.test/'));
    }

    public function testCleanDropsEmptiesButKeepsFalseAndZero(): void
    {
        self::assertSame(
            ['a' => false, 'b' => 0, 'list' => ['x'], 'nested' => ['keep' => '0']],
            ItemSerializer::clean(['a' => false, 'b' => 0, 'n' => null, 'e' => '', 'empty' => [], 'list' => [null, 'x', ''], 'nested' => ['gone' => [], 'keep' => '0']]),
        );
    }

    public function testMediaRewriteSkipsIdUrlAndExternalUrl(): void
    {
        $img = 'https://athenana.com/p/abc';
        $post = new Post(
            '01m3t19wn8reaww81zg1m47tjq',
            1,
            $img,
            'photo',
            'P',
            $img,
            $img,
            null,
            $img,
            new DateTimeImmutable('2026-10-01T00:00:00Z'),
            null,
            'published',
            ['_athenana' => ['images' => [['url' => $img, 'width' => 10]]]],
            [],
        );
        $item = (new ItemSerializer())->item(self::blog(), $post, 'http://xar.test', [$img => 'http://xar.test/media/x.png']);
        self::assertSame($img, $item['id']);
        self::assertSame($img, $item['url']);
        self::assertSame($img, $item['external_url']);
        self::assertSame('http://xar.test/media/x.png', $item['image']);
        self::assertSame('http://xar.test/media/x.png', $item['_athenana']['images'][0]['url']);
    }

    private const ULID = '01m3t19wn8reaww81zg1m47tjq';

    /**
     * @param array<string, mixed> $source
     * @param array<string, string> $media
     * @return array<string, mixed>
     */
    private static function roundTrip(array $source, array $media = []): array
    {
        $record = (new AthenaAdapter())->toRecord($source, new DateTimeImmutable());
        $post = self::postFrom($record, self::ULID);

        return (new ItemSerializer())->item(self::blog('https://www.wyome.com/blog/p/{id}'), $post, 'http://xar.test', $media);
    }

    /** @return array<string, mixed> */
    private static function base(): array
    {
        return [
            'id' => 'https://athenana.com/s/' . self::ULID,
            'url' => 'https://www.wyome.com/blog/p/' . self::ULID,
            'title' => 'T',
            'date_published' => '2026-10-01T10:00:00+00:00',
        ];
    }

    public function testPodcastAttachmentsRoundTripAndRewrite(): void
    {
        $audio = 'https://athenana.com/p/ep1.mp3';
        $source = self::base() + [
            'tags' => ['audio'],
            'attachments' => [['url' => $audio, 'mime_type' => 'audio/mpeg', 'duration_in_seconds' => 4648, 'size_in_bytes' => 0]],
            '_athenana' => ['kind' => 'note', 'podcast' => ['show' => 'S', 'episode' => 3], 'tags' => [['name' => 'audio', 'slug' => 'audio']]],
        ];
        $item = self::roundTrip($source);
        self::assertEquals($source, $item);
        $keys = array_keys($item);
        self::assertSame('attachments', $keys[array_search('tags', $keys, true) + 1]);

        $rewritten = self::roundTrip($source, [$audio => 'http://xar.test/media/ep1.mp3']);
        self::assertSame('http://xar.test/media/ep1.mp3', $rewritten['attachments'][0]['url']);
        self::assertSame(0, $rewritten['attachments'][0]['size_in_bytes']);
    }

    public function testFalseAndZeroSurvive(): void
    {
        $source = self::base() + [
            '_x' => ['n' => 0, 'flag' => false],
            '_athenana' => ['kind' => 'note', 'pinned' => false],
        ];
        $item = self::roundTrip($source);
        self::assertEquals($source, $item);
        self::assertFalse($item['_athenana']['pinned']);
        self::assertSame(0, $item['_x']['n']);
        self::assertFalse($item['_x']['flag']);
    }

    public function testDifferingTagsListRoundTrips(): void
    {
        $source = self::base() + [
            'tags' => ['Foo Bar', 'x'],
            '_athenana' => ['kind' => 'note', 'tags' => [['name' => 'foo bar', 'slug' => 'foo-bar']]],
        ];
        self::assertEquals($source, self::roundTrip($source));
    }

    public function testEmptyExtras(): void
    {
        $source = self::base() + [
            'tags' => ['Foo'],
            '_athenana' => ['kind' => 'note', 'tags' => [['name' => 'Foo', 'slug' => 'foo']]],
        ];
        $item = self::roundTrip($source);
        self::assertEquals($source, $item);
        self::assertSame(['kind', 'tags'], array_keys($item['_athenana']));

        $empty = self::base() + ['tags' => [], '_athenana' => ['kind' => 'note', 'tags' => []]];
        $item = self::roundTrip($empty);
        self::assertArrayNotHasKey('tags', $item);
        self::assertSame(['kind' => 'note'], $item['_athenana']);
        $expected = self::base() + ['_athenana' => ['kind' => 'note']];
        self::assertEquals($expected, $item);
    }
}
