<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Media;

use RuntimeException;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Media\MediaLocalizer;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class MediaStoreTest extends BlogTestCase
{
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Blog $blog;
    private FixtureFetcher $fetcher;
    private MediaStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blogs($this->enableBlog())->create(['handle' => 'pics', 'mode' => 'native'], $this->now());
        $app = $this->app();
        $png = new FetchResult(200, (string) base64_decode(self::PNG, true));
        $this->fetcher = (new FixtureFetcher())
            ->on('https://img.test/a.png', $png)
            ->on('https://img.test/same.png', $png)
            ->on('https://img.test/page.html', new FetchResult(200, '<html>nope</html>'))
            ->on('https://img.test/huge.png', new FetchResult(200, (string) base64_decode(self::PNG, true) . str_repeat("\0", MediaStore::MAX_BYTES)))
            ->on('https://img.test/poly.gif', new FetchResult(200, 'GIF89a<html><script>alert(1)</script>'))
            ->on('https://img.test/x.svg', new FetchResult(200, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))
            ->on('https://img.test/boom', fn(): FetchResult => throw new RuntimeException('connection reset'));
        $app->container()->instance(HttpFetcher::class, $this->fetcher);
        $this->store = $app->container()->get(MediaStore::class);
        $this->blog = $this->blogs($app)->find('pics') ?? throw new RuntimeException();
    }

    public function testLocalizeStoresOnceAndDeduplicatesFiles(): void
    {
        $row = $this->store->localize($this->blog, 'https://img.test/a.png', $this->now());
        self::assertNotNull($row);
        self::assertSame('image/png', $row['mime']);
        self::assertSame(1, (int) $row['width']);
        self::assertSame(1, (int) $row['height']);
        self::assertMatchesRegularExpression('#^pics/[0-9a-z]{26}\.png$#', (string) $row['path']);
        self::assertFileExists($this->tmp . '/uploads/' . $row['path']);
        self::assertSame($this->tmp . '/uploads/' . $row['path'], $this->store->absolutePath($row));

        self::assertSame($row['id'], $this->store->localize($this->blog, 'https://img.test/a.png', $this->now())['id'] ?? null);
        self::assertCount(1, $this->fetcher->requests, 'second localize does not refetch');

        $same = $this->store->localize($this->blog, 'https://img.test/same.png', $this->now());
        self::assertNotSame($row['id'], $same['id'] ?? null);
        self::assertSame($row['path'], $same['path'] ?? null, 'identical bytes share one file');

        self::assertSame(
            ['https://img.test/a.png' => ['id' => (string) $row['id'], 'ext' => 'png']],
            $this->store->lookup($this->blog->id, ['https://img.test/a.png', 'https://img.test/unknown.png']),
        );
        self::assertSame((string) $row['id'], $this->store->find((string) $row['id'])['id'] ?? null);
    }

    public function testFailuresReturnNull(): void
    {
        foreach (['https://img.test/page.html', 'https://img.test/huge.png', 'https://img.test/boom', 'https://img.test/missing.png', 'https://img.test/poly.gif', 'https://img.test/x.svg'] as $url) {
            self::assertNull($this->store->localize($this->blog, $url, $this->now()), $url);
        }
    }

    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }

    public function testImageCheckDoesNotDependOnGd(): void
    {
        // A header-only PNG: getimagesize reads it, GD could not decode it. The check must not differ
        // between hosts with and without GD, so it is accepted everywhere.
        $row = $this->store->store($this->blog, self::pngHeader(2, 3), null, $this->now());
        self::assertNotNull($row);
        self::assertSame([2, 3], [(int) $row['width'], (int) $row['height']]);
        self::assertNull($this->store->store($this->blog, self::pngHeader(10_000, 5_001), null, $this->now()), 'over 50M pixels');
    }

    public function testLocalizeNeverThrowsEvenWhenTheLookupFails(): void
    {
        $this->db($this->app())->query('DROP TABLE {media}');
        self::assertNull($this->store->localize($this->blog, 'https://img.test/a.png', $this->now()));
        self::assertSame([], $this->fetcher->requests);
    }

    public function testUrlsCollectsEveryMediaField(): void
    {
        $record = new PostRecord(
            'i',
            null,
            'photo',
            'T',
            'https://site.test/p',
            'https://site.test/ext',
            null,
            'https://img.test/a.png',
            new \DateTimeImmutable(),
            null,
            [
                '_athenana' => ['images' => [['url' => 'https://img.test/b.png'], ['url' => 'https://img.test/a.png']], 'podcast' => ['cover' => 'https://img.test/c.jpg']],
                '_video' => ['thumbnail' => 'https://img.test/d.jpg'],
                'attachments' => [['url' => 'https://cdn.test/e.mp3'], ['url' => 'data:xyz']],
            ],
            [],
            'h',
        );
        self::assertSame(
            ['https://img.test/a.png', 'https://img.test/b.png', 'https://img.test/d.jpg', 'https://img.test/c.jpg', 'https://cdn.test/e.mp3'],
            MediaLocalizer::urls($record),
        );
    }
}
