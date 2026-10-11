<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Search;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\LikeSearchIndex;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class LikeSearchIndexTest extends BlogTestCase
{
    private App $app;

    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->blog = $this->nativeBlog($this->app);
    }

    private function index(): SearchIndex
    {
        return $this->app->container()->get(SearchIndex::class);
    }

    /** @return list<string> titles of the matching posts, newest first */
    private function titles(string $query, int $limit = 20, int $offset = 0): array
    {
        $ids = $this->index()->search($this->blog->id, $query, $limit, $offset)['ids'];

        return array_map(static fn($post): string => $post->title, array_map(
            fn(string $id) => $this->app->container()->get(PostRepository::class)->find($id) ?? self::fail("no post {$id}"),
            $ids,
        ));
    }

    public function testTheBindingIsTheLikeIndex(): void
    {
        self::assertInstanceOf(LikeSearchIndex::class, $this->index());
    }

    public function testEveryTermMustMatchAndNewestComesFirst(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => 'Red apple']));
        $this->addPost($this->app, $this->blog, self::postRecord(2, ['title' => 'Green apple']));
        $this->addPost($this->app, $this->blog, self::postRecord(3, ['title' => 'Red car', 'content' => '<p>Fast</p>']));
        self::assertSame(['Red apple'], $this->titles('red APPLE'));
        self::assertSame(['Red apple', 'Green apple'], $this->titles('apple'));
        self::assertSame(['Red car'], $this->titles('fast'), 'content is indexed');
        self::assertSame([], $this->titles('red banana'));
        self::assertSame(['total' => 2, 'ids' => []], $this->index()->search($this->blog->id, 'apple', 0, 0));
        self::assertSame(['Green apple'], $this->titles('apple', 1, 1));
    }

    public function testAccentsAndCaseAreFolded(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => 'Crème brûlée']));
        self::assertSame(['Crème brûlée'], $this->titles('CREME brulee'));
        self::assertSame(['Crème brûlée'], $this->titles('crème'));
    }

    public function testWildcardsMatchLiterallyOrNotAtAll(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => '100% cotton']));
        $this->addPost($this->app, $this->blog, self::postRecord(2, ['title' => 'Plain']));
        self::assertSame(['100% cotton'], $this->titles('100%'));
        foreach (['%', '_', '%_!', '!!', '% %', '__a'] as $query) {
            self::assertSame(['total' => 0, 'ids' => []], $this->index()->search($this->blog->id, $query, 20, 0), "'{$query}' has no usable terms");
        }
        self::assertSame('100!%!_a!!b', LikeSearchIndex::escapeLike('100%_a!b'));
    }

    public function testHiddenPostsAndOtherBlogsAreExcluded(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => 'Visible match']));
        $this->addPost($this->app, $this->blog, self::postRecord(2, ['title' => 'Scheduled match', 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($this->app, $this->blog, self::postRecord(3, ['title' => 'Deleted match']));
        $other = $this->nativeBlog($this->app, ['handle' => 'other']);
        $this->addPost($this->app, $other, self::postRecord(4, ['title' => 'Other match']));
        $posts = $this->app->container()->get(PostRepository::class);
        foreach ($posts->tombstone($this->blog->id, ['item-3'], $this->now()) as $id) {
            $this->app->container()->get(EventDispatcher::class)->dispatch(new ItemDeleted('blog', 'post', $id, ['blog' => 'notes']));
        }
        self::assertSame(['Visible match'], $this->titles('match'));
        self::assertSame(1, $this->index()->search($this->blog->id, 'match', 20, 0)['total']);
        self::assertSame(3, $this->db($this->app)->select('post_search')->count(), 'the deleted post left the index; the scheduled one waits in it');
    }

    public function testSyncKeepsTheIndexCurrent(): void
    {
        $mirror = $this->blogs($this->app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena',
        ], $this->now());
        $this->app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->app->container()->get(Syncer::class)->sync($mirror, true, $this->now());
        $found = $this->index()->search($mirror->id, 'body t1', 20, 0); // tag t1 is on post 1 only
        self::assertSame(1, $found['total']);
    }
}
