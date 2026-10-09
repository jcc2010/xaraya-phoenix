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
}
