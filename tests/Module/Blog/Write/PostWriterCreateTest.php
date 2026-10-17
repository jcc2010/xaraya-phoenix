<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Write;

use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Write\ItemValidator;

final class PostWriterCreateTest extends PostWriterTestCase
{
    public function testCreatesANativePostWithAFreshIdAndItemId(): void
    {
        $post = $this->writer()->create($this->blog, ['title' => 'Hello', 'content_html' => '<p>Hi <script>x()</script></p>']);
        self::assertTrue(Ulid::isValid($post->id));
        self::assertSame("http://xar.test/s/{$post->id}", $post->itemId);
        self::assertSame('published', $post->status);
        self::assertNull($post->url);
        self::assertSame('<p>Hi <script>x()</script></p>', $post->contentHtml, 'stored as given; pages sanitise at render');
        self::assertNotNull($post->updatedAt);
        self::assertSame(1, $this->app->container()->get(PostRepository::class)->countLiveNative($this->blog->id));
        self::assertSame("http://xar.test/s/{$post->id}", $this->served($post)['url']);
    }

    public function testAnItemRoundTripsThroughTheSerializer(): void
    {
        $item = [
            'title' => 'A photo walk',
            'content_html' => '<p>Look.</p>',
            'summary' => 'Walk',
            'image' => 'https://img.test/a.jpg',
            'date_published' => '2026-10-01T08:00:00+00:00',
            'date_modified' => '2026-10-02T09:30:00+00:00',
            'tags' => ['Walks', 'Autumn light'],
            'attachments' => [['url' => 'https://cdn.test/a.mp3', 'mime_type' => 'audio/mpeg']],
            '_athenana' => [
                'kind' => 'photo',
                'images' => [['url' => 'https://img.test/a.jpg', 'width' => 800, 'height' => 600]],
                'tags' => [['name' => 'Walks', 'slug' => 'walks'], ['name' => 'Autumn light', 'slug' => 'autumn-light']],
            ],
            '_video' => ['provider' => 'youtube', 'id' => 'abc'],
        ];
        $post = $this->writer()->create($this->blog, $item);
        self::assertSame([['name' => 'Walks', 'slug' => 'walks'], ['name' => 'Autumn light', 'slug' => 'autumn-light']], $post->tags);
        self::assertSameItem(['id' => $post->itemId, 'url' => "http://xar.test/s/{$post->id}", ...$item], $this->served($post));
    }

    public function testDerivesTitleAndKindAndDefaultsTheDate(): void
    {
        $before = $this->now()->modify('-1 second');
        $note = $this->writer()->create($this->blog, ['content_html' => '<p>Hello <b>world</b></p>', 'tags' => ['Café Life']]);
        self::assertSame('Hello world', $note->title);
        self::assertSame('note', $note->kind);
        self::assertGreaterThanOrEqual($before, $note->datePublished);
        self::assertSame([['name' => 'Café Life', 'slug' => 'caf-life']], $note->tags);

        $link = $this->writer()->create($this->blog, ['external_url' => 'https://www.example.com/a/b/']);
        self::assertSame(['link', 'example.com/a/b'], [$link->kind, $link->title]);

        $photo = $this->writer()->create($this->blog, ['image' => 'https://img.test/x.png']);
        self::assertSame(['photo', 'Photo'], [$photo->kind, $photo->title]);
    }

    public function testAFutureDateIsStoredAsScheduled(): void
    {
        $post = $this->writer()->create($this->blog, ['title' => 'Later', 'date_published' => '2099-01-01T00:00:00Z']);
        self::assertSame('2099-01-01 00:00:00', $post->datePublished->format('Y-m-d H:i:s'));
        self::assertSame([], $this->app->container()->get(PostRepository::class)->page($this->blog->id, $this->now(), null, 50));
    }

    public function testRejectsInvalidItemsWithFieldMessages(): void
    {
        $cases = [
            [['title' => 'x', 'foo' => 1], 'foo', 'Unknown field'],
            [['title' => 'x', '_ext' => 'str'], '_ext', 'Extension fields must be JSON objects'],
            [['title' => 5], 'title', 'Must be a string'],
            [['title' => str_repeat('t', ItemValidator::MAX_TITLE + 1)], 'title', 'Must be at most 1000 characters'],
            [['title' => 'x', 'date_published' => 'tomorrow'], 'date_published', 'Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00'],
            [['title' => 'x', 'date_published' => '2026-02-30T10:00:00Z'], 'date_published', 'Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00'],
            [['title' => 'x', 'external_url' => 'javascript:alert(1)'], 'external_url', 'Must be an absolute http(s) URL of at most 2048 bytes'],
            [['title' => 'x', 'image' => '/relative.png'], 'image', 'Must be an absolute http(s) URL of at most 2048 bytes'],
            [['title' => 'x', 'tags' => 'a,b'], 'tags', 'Must be a list of tag names of 1 to 128 characters'],
            [['title' => 'x', 'tags' => ['ok', '']], 'tags', 'Must be a list of tag names of 1 to 128 characters'],
            [['title' => 'x', 'attachments' => [['url' => 'ftp://x']]], 'attachments', 'Each attachment needs an http(s) url and a mime_type'],
            [['title' => 'x', '_athenana' => ['kind' => 'essay']], '_athenana.kind', 'Must be one of: note, photo, link, highlight'],
            [['title' => 'x', '_athenana' => ['tags' => [['name' => str_repeat('n', ItemValidator::MAX_TAG + 1), 'slug' => 'n']]]], '_athenana.tags.0.name', 'Must be a tag name of 1 to 128 characters'],
            [['title' => 'x', '_athenana' => ['tags' => [['name' => '   ', 'slug' => 'blank']]]], '_athenana.tags.0.name', 'Must be a tag name of 1 to 128 characters'],
            [['title' => 'x', '_athenana' => ['tags' => [['name' => 'ok', 'slug' => 'ok'], ['name' => 'A b', 'slug' => 'a b']]]], '_athenana.tags.1.slug', 'Must be 1 to 128 characters of a-z, 0-9 and -'],
            [['title' => 'x', '_athenana' => ['tags' => [['name' => 'Up', 'slug' => '../up']]]], '_athenana.tags.0.slug', 'Must be 1 to 128 characters of a-z, 0-9 and -'],
            [['title' => 'x', '_athenana' => ['tags' => [['name' => 'Up', 'slug' => 'Up']]]], '_athenana.tags.0.slug', 'Must be 1 to 128 characters of a-z, 0-9 and -'],
            [['title' => 'x', '_athenana' => ['tags' => [['name' => 'Long', 'slug' => str_repeat('s', 129)]]]], '_athenana.tags.0.slug', 'Must be 1 to 128 characters of a-z, 0-9 and -'],
            [['title' => 'x', 'id' => 'mine'], 'id', 'Is assigned by the server; leave it out'],
            [['title' => 'x', 'url' => 'https://elsewhere.test/'], 'url', 'Is assigned by the server; leave it out'],
            [[], 'content_html', 'An item needs content_html, content_text, a title, an external_url or an image'],
            [['content_html' => ''], 'content_html', 'An item needs content_html, content_text, a title, an external_url or an image'],
        ];
        foreach ($cases as [$item, $field, $message]) {
            $fields = self::fieldErrors(fn() => $this->writer()->create($this->blog, $item));
            self::assertSame($message, $fields[$field] ?? null, json_encode($item) . ' => ' . json_encode($fields));
        }
        self::assertSame(0, $this->app->container()->get(PostRepository::class)->countLive($this->blog->id));
    }

    public function testInvalidUtf8IsRejectedFieldByField(): void
    {
        $fields = self::fieldErrors(fn() => $this->writer()->create($this->blog, [
            'title' => "bad \xC3\x28",
            'authors' => [['name' => 'Ada'], ['name' => "B\xFF"]],
            '_athenana' => ['tags' => [['name' => "\xFE", 'slug' => 'x']]],
            "k\xFFy" => ['v' => 1],
        ]));
        self::assertSame('Must be valid UTF-8', $fields['title'] ?? null);
        self::assertSame('Must be valid UTF-8', $fields['authors.1.name'] ?? null);
        self::assertSame('Must be valid UTF-8', $fields['_athenana.tags.0.name'] ?? null);
        self::assertSame('Must be valid UTF-8', $fields['k?y'] ?? null);
        self::assertArrayNotHasKey('item', $fields);
        json_encode($fields, JSON_THROW_ON_ERROR); // every key and message can be sent back
    }

    public function testATagSlugMayBeLeftOutAndIsDerived(): void
    {
        $post = $this->writer()->create($this->blog, ['title' => 'x', 'tags' => ['Two words'], '_athenana' => ['tags' => [['name' => 'Two words']]]]);
        self::assertSame([['name' => 'Two words', 'slug' => 'two-words']], $post->tags);
    }

    public function testReservedAndNullFieldsAreIgnored(): void
    {
        $post = $this->writer()->create($this->blog, ['title' => 'x', 'summary' => null, '_phoenix' => ['status' => 'deleted']]);
        self::assertSame('published', $post->status);
        self::assertArrayNotHasKey('_phoenix', $post->doc);
        self::assertArrayNotHasKey('summary', $post->doc);
    }

    public function testMirrorBlogsRefuseWritesWith409(): void
    {
        $mirror = $this->blogs($this->app)->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/feed.json', 'source_format' => 'athena'], $this->now());
        try {
            $this->writer()->create($mirror, ['title' => 'x']);
            self::fail('expected 409');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertSame("Blog 'src' is a mirror: its posts come from its source", $e->getMessage());
        }
    }

    public function testEventsFireAfterTheCommitAndTheSearchIndexFollows(): void
    {
        $seen = [];
        $db = $this->app->container()->get(Connection::class);
        $this->app->container()->get(EventDispatcher::class)->listen(ItemCreated::class, static function (ItemCreated $e) use (&$seen, $db): void {
            $seen[] = [$e->module, $e->itemtype, $e->item['blog'] ?? null, $db->pdo()->inTransaction()];
        });
        $post = $this->writer()->create($this->blog, ['title' => 'Searchable walrus']);
        self::assertSame([['blog', 'post', 'notes', false]], $seen);
        self::assertSame([$post->id], $this->app->container()->get(SearchIndex::class)->search($this->blog->id, 'walrus', 10, 0)['ids']);
    }
}
