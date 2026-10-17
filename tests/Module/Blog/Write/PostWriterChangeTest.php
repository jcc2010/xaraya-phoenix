<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Write;

use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Write\MergePatch;
use Xaraya\Module\Blog\Write\PostWriter;

final class PostWriterChangeTest extends PostWriterTestCase
{
    /** @var list<string> */
    private array $events = [];

    private function post(): Post
    {
        $post = $this->writer()->create($this->blog, [
            'title' => 'First',
            'content_html' => '<p>One</p>',
            'tags' => ['Old tag'],
            'date_published' => '2026-10-01T08:00:00Z',
        ]);
        $this->app->container()->get(EventDispatcher::class)->listen(ItemEvent::class, function (ItemEvent $e): void {
            $this->events[] = (new \ReflectionClass($e))->getShortName() . ':' . $e->id;
        });

        return $post;
    }

    private function backdate(Post $post): void
    {
        $this->db($this->app)->update('posts', ['updated_at' => '2000-01-01 00:00:00'], ['id' => $post->id]);
    }

    private static function statusOf(callable $write): int
    {
        try {
            $write();
        } catch (HttpException $e) {
            return $e->status();
        }
        self::fail('expected an HttpException');
    }

    public function testMergePatchFollowsRfc7396(): void
    {
        $target = ['a' => 'b', 'c' => ['d' => 'e', 'f' => 'g'], 'list' => [1, 2]];
        self::assertSame(['a' => 'z', 'c' => ['d' => 'e'], 'list' => [3]], MergePatch::apply($target, ['a' => 'z', 'c' => ['f' => null], 'list' => [3]]));
        self::assertSame(['a' => 'b', 'c' => ['d' => 'e', 'f' => 'g'], 'list' => [1, 2]], MergePatch::apply($target, []));
        self::assertSame(['x' => ['y' => 1]], MergePatch::apply(['x' => 'scalar'], ['x' => ['y' => 1]]));
        self::assertSame('replaced', MergePatch::apply($target, 'replaced'));
    }

    public function testReplaceRewritesEverythingButIdentityAndBumpsUpdatedAt(): void
    {
        $post = $this->post();
        $this->backdate($post);
        $item = ['title' => 'Second', 'content_text' => 'Two', 'tags' => ['New']];
        $replaced = $this->writer()->replace($post, $item);
        self::assertSame([$post->id, $post->itemId], [$replaced->id, $replaced->itemId]);
        self::assertSame('2026-10-01 08:00:00', $replaced->datePublished->format('Y-m-d H:i:s'), 'a missing date_published keeps the old one');
        self::assertNull($replaced->contentHtml);
        self::assertSame([['name' => 'New', 'slug' => 'new']], $replaced->tags);
        self::assertGreaterThan(new \DateTimeImmutable('2001-01-01'), $replaced->updatedAt);
        self::assertSame(["ItemUpdated:{$post->id}"], $this->events);
    }

    public function testAServedItemCanBePutBackUnchanged(): void
    {
        $post = $this->post();
        $served = $this->served($post);
        $again = $this->writer()->replace($post, [...$served, '_phoenix' => ['status' => 'published']]);
        self::assertSameItem($served, $this->served($again));
    }

    public function testReplaceRefusesToChangeIdOrUrl(): void
    {
        $post = $this->post();
        $fields = self::fieldErrors(fn() => $this->writer()->replace($post, ['title' => 'x', 'id' => 'other', 'url' => 'https://evil.test/']));
        self::assertSame(['id' => 'Cannot be changed', 'url' => 'Is assigned by the server; leave it out or send it unchanged'], $fields);
    }

    public function testPatchMergesIntoTheServedItem(): void
    {
        $post = $this->post();
        $this->backdate($post);
        $patched = $this->writer()->patch($post, ['title' => 'Patched', 'tags' => ['Fresh', 'Two words'], 'summary' => 'S']);
        self::assertSame('Patched', $patched->title);
        self::assertSame('<p>One</p>', $patched->contentHtml);
        self::assertSame([['name' => 'Fresh', 'slug' => 'fresh'], ['name' => 'Two words', 'slug' => 'two-words']], $patched->tags);
        self::assertSame('S', $patched->doc['summary'] ?? null);
        self::assertGreaterThan(new \DateTimeImmutable('2001-01-01'), $patched->updatedAt);

        $cleared = $this->writer()->patch($patched, ['summary' => null, 'tags' => null]);
        self::assertArrayNotHasKey('summary', $cleared->doc);
        self::assertSame([], $cleared->tags);
    }

    public function testADerivedTitleFollowsTheContentButAnExplicitOneStays(): void
    {
        $note = $this->writer()->create($this->blog, ['content_html' => '<p>Old words</p>']);
        self::assertSame('Old words', $note->title);
        self::assertSame('New words', $this->writer()->patch($note, ['content_html' => '<p>New words</p>'])->title);

        $titled = $this->post();
        self::assertSame('First', $this->writer()->patch($titled, ['content_html' => '<p>Changed</p>'])->title);
    }

    public function testPatchCannotRemoveTheDateOrEveryPieceOfContent(): void
    {
        $post = $this->post();
        self::assertSame(['date_published' => 'Cannot be removed'], self::fieldErrors(fn() => $this->writer()->patch($post, ['date_published' => null])));
        $fields = self::fieldErrors(fn() => $this->writer()->patch($post, ['title' => null, 'content_html' => null]));
        self::assertSame('An item needs content_html, content_text, a title, an external_url or an image', $fields['content_html'] ?? null);
        self::assertSame('First', $this->writer()->patch($post, [])->title, 'the refused patches changed nothing');
    }

    public function testDeleteAndUndeleteAreIdempotentAndFireOnce(): void
    {
        $post = $this->post();
        $index = $this->app->container()->get(SearchIndex::class);
        self::assertSame(1, $index->search($this->blog->id, 'first', 10, 0)['total']);

        $deleted = $this->writer()->delete($post);
        self::assertSame('deleted', $deleted->status);
        self::assertSame('deleted', $this->writer()->delete($deleted)->status);
        self::assertSame(0, $index->search($this->blog->id, 'first', 10, 0)['total']);

        $back = $this->writer()->undelete($deleted);
        self::assertSame('published', $back->status);
        $this->writer()->undelete($back);
        self::assertSame(1, $index->search($this->blog->id, 'first', 10, 0)['total']);
        self::assertSame(["ItemDeleted:{$post->id}", "ItemUpdated:{$post->id}"], $this->events);
    }

    public function testADeletedPostMustBeUndeletedBeforeItIsEdited(): void
    {
        $post = $this->writer()->delete($this->post());
        self::assertSame(409, self::statusOf(fn() => $this->writer()->replace($post, ['title' => 'x'])));
        self::assertSame(409, self::statusOf(fn() => $this->writer()->patch($post, ['title' => 'x'])));
    }

    public function testMirrorPostsCannotBeChanged(): void
    {
        $post = $this->post();
        $this->db($this->app)->update('blogs', ['mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], ['id' => $this->blog->id]);
        foreach ([
            fn() => $this->writer()->replace($post, ['title' => 'x']),
            fn() => $this->writer()->patch($post, ['title' => 'x']),
            fn() => $this->writer()->delete($post),
            fn() => $this->writer()->undelete($post),
        ] as $write) {
            self::assertSame(409, self::statusOf($write));
        }
        self::assertSame([], $this->events);
    }

    public function testEventsSeeTheCommittedRow(): void
    {
        $post = $this->post();
        $titles = [];
        $this->app->container()->get(EventDispatcher::class)->listen(ItemUpdated::class, function (ItemUpdated $e) use (&$titles): void {
            $titles[] = $this->app->container()->get(PostRepository::class)->find($e->id)?->title;
        });
        $this->writer()->patch($post, ['title' => 'Seen']);
        self::assertSame(['Seen'], $titles);
    }

    private function tagged(): Post
    {
        return $this->writer()->create($this->blog, [
            'title' => 'Tagged',
            'content_html' => '<p>T</p>',
            'date_published' => '2026-10-01T08:00:00Z',
            'tags' => ['Café Life', 'Old tag'],
            '_athenana' => ['tags' => [['name' => 'Café Life', 'slug' => 'cafe-life'], ['name' => 'Old tag', 'slug' => 'old-tag']]],
        ]);
    }

    /**
     * The served `tags`, the served `_athenana.tags`, the stored post_tags and the tag pages all agree on $expected.
     *
     * @param list<array{name: string, slug: string}> $expected
     */
    private function assertTagsAgree(array $expected, Post $post): void
    {
        $posts = $this->app->container()->get(PostRepository::class);
        $served = $this->served($post);
        self::assertSame($expected, $post->tags, 'post_tags');
        self::assertSame(array_column($expected, 'name'), $served['tags'] ?? [], 'served tags');
        self::assertSame($expected, $served['_athenana']['tags'] ?? [], 'served _athenana.tags');
        $counts = array_map(static fn(array $t): array => ['name' => $t['name'], 'slug' => $t['slug']], $posts->tagCounts($this->blog->id, $this->now()));
        $sorted = $expected;
        usort($sorted, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        self::assertSame($sorted, $counts, 'tag index');
        foreach ($expected as $tag) {
            self::assertSame([$post->id], array_map(static fn(Post $p): string => $p->id, $posts->pageByTag($this->blog->id, $tag['slug'], $this->now(), null, 10)), "tag page {$tag['slug']}");
        }
    }

    public function testAServedBodyEditedInTagsIsPutBackWithTheNewTags(): void
    {
        $post = $this->tagged();
        $served = $this->served($post);
        $served['tags'] = ['Café Life', 'New one'];
        $replaced = $this->writer()->replace($post, $served);
        $this->assertTagsAgree([['name' => 'Café Life', 'slug' => 'cafe-life'], ['name' => 'New one', 'slug' => 'new-one']], $replaced);
        self::assertSame([], $this->app->container()->get(PostRepository::class)->pageByTag($this->blog->id, 'old-tag', $this->now(), null, 10));
        self::assertArrayNotHasKey('tags', $replaced->doc, 'the names match the stored tags, so no raw copy is kept');
    }

    public function testAServedBodyEditedOnlyInTheSlugsKeepsTheNewSlugs(): void
    {
        $post = $this->tagged();
        $served = $this->served($post);
        $served['_athenana']['tags'][1]['slug'] = 'old';
        $this->assertTagsAgree([['name' => 'Café Life', 'slug' => 'cafe-life'], ['name' => 'Old tag', 'slug' => 'old']], $this->writer()->replace($post, $served));
    }

    public function testPatchingTagsReusesTheSlugsOfNamesThatStay(): void
    {
        $patched = $this->writer()->patch($this->tagged(), ['tags' => ['Café Life', 'Second']]);
        $this->assertTagsAgree([['name' => 'Café Life', 'slug' => 'cafe-life'], ['name' => 'Second', 'slug' => 'second']], $patched);
    }

    public function testPatchingOnlyTheAthenanaTagsMovesTheServedTags(): void
    {
        $patched = $this->writer()->patch($this->tagged(), ['_athenana' => ['tags' => [['name' => 'Renamed', 'slug' => 'renamed'], ['name' => 'Café Life', 'slug' => 'cafe-life']]]]);
        $this->assertTagsAgree([['name' => 'Renamed', 'slug' => 'renamed'], ['name' => 'Café Life', 'slug' => 'cafe-life']], $patched);

        $cleared = $this->writer()->patch($patched, ['_athenana' => ['tags' => null]]);
        $this->assertTagsAgree([], $cleared);
    }

    public function testTagNamesAreServedAsStoredOnCreate(): void
    {
        $spaced = $this->writer()->create($this->blog, ['title' => 'A', 'date_published' => '2026-10-01T08:00:00Z', 'tags' => [' Café ', 'New']]);
        $this->assertTagsAgree([['name' => 'Café', 'slug' => 'caf'], ['name' => 'New', 'slug' => 'new']], $spaced);
        $this->writer()->delete($spaced);

        $twins = $this->writer()->create($this->blog, ['title' => 'B', 'date_published' => '2026-10-01T08:00:00Z', 'tags' => ['Foo Bar', 'foo bar'], '_athenana' => ['tags' => [['name' => 'Foo Bar', 'slug' => 'foo-bar'], ['name' => 'foo bar']]]]);
        $this->assertTagsAgree([['name' => 'Foo Bar', 'slug' => 'foo-bar']], $twins);
    }

    public function testTagNamesAreServedAsStoredOnReplaceAndPatch(): void
    {
        $post = $this->tagged();
        $served = $this->served($post);
        $served['tags'] = [' Café Life ', 'New'];
        $replaced = $this->writer()->replace($post, $served);
        $this->assertTagsAgree([['name' => 'Café Life', 'slug' => 'cafe-life'], ['name' => 'New', 'slug' => 'new']], $replaced);

        $patched = $this->writer()->patch($replaced, ['tags' => ['Foo Bar', 'foo bar']]);
        $this->assertTagsAgree([['name' => 'Foo Bar', 'slug' => 'foo-bar']], $patched);
        self::assertArrayNotHasKey('tags', $patched->doc);
    }

    public function testANoOpPatchWritesNothingAndFiresNothing(): void
    {
        $note = $this->writer()->create($this->blog, ['content_html' => '<p>Derived</p>', 'tags' => ['A']]);
        $post = $this->post();
        $this->backdate($note);
        $this->backdate($post);
        foreach ([[$post, []], [$post, ['title' => 'First', 'tags' => ['Old tag']]], [$note, []], [$note, ['content_html' => '<p>Derived</p>']], [$post, ['_phoenix' => ['status' => 'published']]]] as [$target, $partial]) {
            self::assertSame($target, $this->writer()->patch($target, $partial), 'the post comes back as it was');
            $stored = $this->app->container()->get(PostRepository::class)->find($target->id);
            self::assertSame('2000-01-01', $stored?->updatedAt?->format('Y-m-d'), json_encode($partial) ?: '');
        }
        self::assertSame([], $this->events);
    }

    public function testHasDerivedTitle(): void
    {
        self::assertTrue(PostWriter::hasDerivedTitle($this->writer()->create($this->blog, ['content_html' => '<p>Words</p>'])));
        self::assertTrue(PostWriter::hasDerivedTitle($this->writer()->create($this->blog, ['content_text' => 'Plain & simple'])));
        self::assertFalse(PostWriter::hasDerivedTitle($this->post()));
    }
}
