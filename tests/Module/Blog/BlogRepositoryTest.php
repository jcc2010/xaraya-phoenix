<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class BlogRepositoryTest extends BlogTestCase
{
    public function testMigrationsCreateTables(): void
    {
        $db = $this->db($this->enableBlog());
        foreach (['blogs', 'posts', 'post_tags', 'media'] as $table) {
            self::assertTrue($db->hasTable($table), $table);
        }
    }

    public function testCreateMirrorBlog(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $blog = $repo->create([
            'handle' => 'wyome',
            'mode' => 'mirror',
            'source_url' => 'https://athenana.com/blog/wyome/feed.json',
            'source_format' => 'athena',
            'post_url_pattern' => 'https://www.wyome.com/blog/post.html?id={id}',
        ], $this->now());

        self::assertGreaterThan(0, $blog->id);
        self::assertSame('wyome', $blog->handle);
        self::assertSame('wyome', $blog->title, 'title defaults to the handle');
        self::assertSame('wyome', $blog->authorName);
        self::assertSame('en', $blog->language);
        self::assertSame('remote', $blog->media);
        self::assertTrue($blog->isMirror());
        self::assertSame([], $blog->extra);
        self::assertSame($blog->id, $repo->find('wyome')?->id);
        self::assertSame('wyome', $repo->findById($blog->id)?->handle);
        self::assertNull($repo->find('nope'));
    }

    public function testCreateNativeBlogAndList(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $repo->create(['handle' => 'zed', 'mode' => 'native', 'title' => 'Zed'], $this->now());
        $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
        self::assertSame(['amy', 'zed'], array_map(fn($b) => $b->handle, $repo->all()));
        self::assertFalse($repo->find('zed')?->isMirror());
    }

    public function testUpdateMergesAndValidates(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $blog = $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
        $updated = $repo->update($blog, ['title' => 'Amy', 'extra' => ['_athenana' => ['subscribe_url' => 'https://x.test/s']]], $this->now());
        self::assertSame('Amy', $updated->title);
        self::assertSame(['_athenana' => ['subscribe_url' => 'https://x.test/s']], $updated->extra);

        $this->expectException(InvalidArgumentException::class);
        $repo->update($updated, ['mode' => 'mirror'], $this->now());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBlogs(): array
    {
        return [
            'bad handle' => [['handle' => 'No Caps', 'mode' => 'native'], 'Handle'],
            'bad mode' => [['handle' => 'ok', 'mode' => 'remote'], 'Mode'],
            'mirror without source' => [['handle' => 'ok', 'mode' => 'mirror', 'source_format' => 'athena'], 'source URL'],
            'mirror with file url' => [['handle' => 'ok', 'mode' => 'mirror', 'source_url' => 'file:///etc/passwd', 'source_format' => 'athena'], 'source URL'],
            'mirror bad format' => [['handle' => 'ok', 'mode' => 'mirror', 'source_url' => 'https://x.test/f.json', 'source_format' => 'rss'], 'format'],
            'pattern without id' => [['handle' => 'ok', 'mode' => 'native', 'post_url_pattern' => 'https://x.test/p'], 'pattern'],
            'pattern with two ids' => [['handle' => 'ok', 'mode' => 'native', 'post_url_pattern' => 'https://x.test/{id}/{id}'], 'pattern'],
            'bad media' => [['handle' => 'ok', 'mode' => 'native', 'media' => 'cdn'], 'Media'],
            'unknown field' => [['handle' => 'ok', 'mode' => 'native', 'colour' => 'red'], 'Unknown'],
        ];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('invalidBlogs')]
    public function testRejectsInvalidBlogs(array $fields, string $message): void
    {
        $repo = $this->blogs($this->enableBlog());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $repo->create($fields, $this->now());
    }

    public function testRejectsDuplicateHandle(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
    }
}
