<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Post;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Post\SaveResult;
use Xaraya\Tests\Module\Blog\BlogTestCase;

final class PostRepositoryTest extends BlogTestCase
{
    private App $app;
    private PostRepository $posts;
    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->posts = $this->app->container()->get(PostRepository::class);
        $this->blog = $this->blogs($this->app)->create(['handle' => 'one', 'mode' => 'native'], $this->now());
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /** @param list<array{name: string, slug: string}> $tags */
    private static function record(string $itemId, ?string $ulid, string $published, string $hash = 'h1', array $tags = [], array $doc = []): PostRecord
    {
        return new PostRecord($itemId, $ulid, 'note', "Title {$itemId}", null, null, '<p>Body</p>', null, self::at($published), null, $doc, $tags, sha1($hash));
    }

    public function testCreateUnchangedUpdated(): void
    {
        $ulid = Ulid::generate();
        $record = self::record('src:1', $ulid, '2026-10-01 10:00:00', 'h1', [['name' => 'B', 'slug' => 'b'], ['name' => 'A', 'slug' => 'a']], ['_x' => ['k' => 1]]);

        $created = $this->posts->save($this->blog->id, $record, $this->now());
        self::assertSame(SaveResult::CREATED, $created->status);
        self::assertSame($ulid, $created->id);
        self::assertSame(SaveResult::UNCHANGED, $this->posts->save($this->blog->id, $record, $this->now())->status);

        $changed = self::record('src:1', $ulid, '2026-10-01 10:00:00', 'h2', [['name' => 'C', 'slug' => 'c']]);
        $updated = $this->posts->save($this->blog->id, $changed, $this->now());
        self::assertSame(SaveResult::UPDATED, $updated->status);
        self::assertSame($ulid, $updated->id);

        $post = $this->posts->find($ulid);
        self::assertNotNull($post);
        self::assertSame('src:1', $post->itemId);
        self::assertSame([['name' => 'C', 'slug' => 'c']], $post->tags);
        self::assertSame([], $post->doc);
        self::assertSame('2026-10-01 10:00:00', $post->datePublished->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $post->datePublished->getTimezone()->getName());
        self::assertSame('published', $post->status);
    }

    public function testTagOrderAndDocRoundTrip(): void
    {
        $ulid = Ulid::generate();
        $tags = [['name' => 'zeta', 'slug' => 'zeta'], ['name' => 'alpha', 'slug' => 'alpha'], ['name' => 'mid', 'slug' => 'mid']];
        $doc = ['_athenana' => ['song' => ['duration_seconds' => 218]], 'attachments' => [['url' => 'https://a.test/x.mp3']]];
        $this->posts->save($this->blog->id, self::record('src:2', $ulid, '2026-10-01 10:00:00', 'h', $tags, $doc), $this->now());
        $post = $this->posts->find($ulid);
        self::assertSame($tags, $post?->tags);
        self::assertEquals($doc, $post?->doc);
    }

    public function testUlidCollisionAcrossBlogsGetsNewId(): void
    {
        $other = $this->blogs($this->app)->create(['handle' => 'two', 'mode' => 'native'], $this->now());
        $ulid = Ulid::generate();
        $first = $this->posts->save($this->blog->id, self::record('src:1', $ulid, '2026-10-01 10:00:00'), $this->now());
        $second = $this->posts->save($other->id, self::record('src:1', $ulid, '2026-10-01 10:00:00'), $this->now());
        self::assertSame($ulid, $first->id);
        self::assertNotSame($ulid, $second->id);
        self::assertTrue(Ulid::isValid($second->id));
        self::assertNull($this->posts->find('01aaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function testPagingHidesScheduledAndDeleted(): void
    {
        $times = ['2026-10-05 00:00:00', '2026-10-04 00:00:00', '2026-10-04 00:00:00', '2026-10-03 00:00:00', '2026-10-02 00:00:00'];
        $ids = [];
        foreach ($times as $i => $time) {
            $ids[] = $this->posts->save($this->blog->id, self::record("src:{$i}", Ulid::generate(), $time), $this->now())->id;
        }
        $this->posts->save($this->blog->id, self::record('future', Ulid::generate(), '2099-01-01 00:00:00'), $this->now());
        $now = self::at('2026-10-08 00:00:00');

        $page1 = $this->posts->page($this->blog->id, $now, null, 2);
        self::assertCount(2, $page1);
        $last = $page1[1];
        $page2 = $this->posts->page($this->blog->id, $now, [$last->datePublished, $last->id], 2);
        $page3 = $this->posts->page($this->blog->id, $now, [$page2[1]->datePublished, $page2[1]->id], 2);
        $walked = array_map(fn($p) => $p->id, [...$page1, ...$page2, ...$page3]);
        self::assertCount(5, $walked);
        self::assertSame(count($walked), count(array_unique($walked)));
        self::assertNotContains('future', array_map(fn($p) => $p->itemId, [...$page1, ...$page2, ...$page3]));

        $deleted = $this->posts->tombstone($this->blog->id, ['src:0', 'src:missing'], $this->now());
        self::assertSame([$ids[0]], $deleted);
        self::assertSame([], $this->posts->tombstone($this->blog->id, ['src:0'], $this->now()), 'already deleted');
        self::assertNotContains($ids[0], array_map(fn($p) => $p->id, $this->posts->page($this->blog->id, $now, null, 10)));
        self::assertSame(5, $this->posts->countLive($this->blog->id), '4 past + 1 scheduled are live');
        self::assertCount(5, $this->posts->liveItemIds($this->blog->id));
        self::assertSame('deleted', $this->posts->find($ids[0])?->status);
    }

    public function testTombstonedPostIsRestoredBySave(): void
    {
        $record = self::record('src:1', Ulid::generate(), '2026-10-01 10:00:00');
        $id = $this->posts->save($this->blog->id, $record, $this->now())->id;
        $this->posts->tombstone($this->blog->id, ['src:1'], $this->now());
        $result = $this->posts->save($this->blog->id, $record, $this->now());
        self::assertSame(SaveResult::UPDATED, $result->status);
        self::assertSame($id, $result->id);
        self::assertSame('published', $this->posts->find($id)?->status);
    }

    public function testTombstoneHandlesLargeLists(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->posts->save($this->blog->id, self::record("src:{$i}", Ulid::generate(), '2026-10-01 10:00:00'), $this->now());
        }
        $many = array_map(fn(int $i): string => "src:{$i}", range(0, 1200));
        self::assertCount(3, $this->posts->tombstone($this->blog->id, $many, $this->now()));
        self::assertSame(0, $this->posts->countLive($this->blog->id));
    }
}
