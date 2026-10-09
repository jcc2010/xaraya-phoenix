<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Sync;

use LogicException;
use RuntimeException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Module\Blog\Sync\SyncLock;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class SyncerTest extends BlogTestCase
{
    private const SOURCE = 'https://src.test/blog/src/feed.json';

    private App $app;
    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->blog = $this->blogs($this->app)->create([
            'handle' => 'mirror', 'mode' => 'mirror', 'source_url' => self::SOURCE, 'source_format' => 'athena',
        ], $this->now());
    }

    /** Fresh app per sync so the container picks up the given fetcher. */
    private function syncer(FixtureFetcher $fetcher): Syncer
    {
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, $fetcher);

        return $app->container()->get(Syncer::class);
    }

    private function cappedSyncer(FixtureFetcher $fetcher, int $maxPages): Syncer
    {
        $c = $this->app()->container();
        $c->instance(HttpFetcher::class, $fetcher);

        return new Syncer(
            $c->get(\Xaraya\Kernel\Db\Connection::class),
            $c->get(\Xaraya\Module\Blog\BlogRepository::class),
            $c->get(PostRepository::class),
            $c->get(\Xaraya\Module\Blog\Source\AdapterRegistry::class),
            $fetcher,
            $c->get(EventDispatcher::class),
            $c->get(\Psr\Log\LoggerInterface::class),
            $c->get(SyncLock::class),
            $maxPages,
        );
    }

    private function blog(): Blog
    {
        return $this->blogs($this->app)->find('mirror') ?? throw new RuntimeException('blog missing');
    }

    private function posts(): PostRepository
    {
        return $this->app->container()->get(PostRepository::class);
    }

    public function testFullSyncImportsEveryPageAndUpdatesMeta(): void
    {
        $items = FeedFactory::items(120);
        $fetcher = FeedFactory::fetcher(FeedFactory::pages($items));
        $report = $this->syncer($fetcher)->sync($this->blog(), true, $this->now());

        self::assertSame(3, $report->pages);
        self::assertSame(120, $report->created);
        self::assertSame(0, $report->deleted);
        self::assertTrue($report->ok());
        self::assertSame(120, $this->posts()->countLive($this->blog->id));
        $blog = $this->blog();
        self::assertSame('Source Blog', $blog->title);
        self::assertSame('Source Author', $blog->authorName);
        self::assertNotNull($blog->lastSyncedAt);
        self::assertSame(3, count($fetcher->requests));
        self::assertNull($fetcher->requests[0]['etag'], 'full sync never sends the etag');
    }

    public function testQuickSyncStopsAtFirstUnchangedPage(): void
    {
        $items = FeedFactory::items(120);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());

        $fresh = FeedFactory::items(1, newest: '2026-10-02 12:00:00');
        $fetcher = FeedFactory::fetcher(FeedFactory::pages([...$fresh, ...$items]));
        $report = $this->syncer($fetcher)->sync($this->blog(), false, $this->now());
        self::assertSame(1, $report->created);
        self::assertSame(2, $report->pages, 'page 1 changed, page 2 was all unchanged, so it stopped');
        self::assertSame(2, count($fetcher->requests));

        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages([...$fresh, ...$items])))->sync($this->blog(), false, $this->now());
        self::assertSame(1, $report->pages);
        self::assertSame(0, $report->created + $report->updated);
    }

    public function testEditsDeepInTheFeedNeedAFullSync(): void
    {
        $items = FeedFactory::items(120);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        $items[110]['title'] = 'Edited';
        $pages = FeedFactory::pages($items);

        self::assertSame(0, $this->syncer(FeedFactory::fetcher($pages))->sync($this->blog(), false, $this->now())->updated);
        $report = $this->syncer(FeedFactory::fetcher($pages))->sync($this->blog(), true, $this->now());
        self::assertSame(1, $report->updated);
    }

    public function testNotModifiedFirstPageEndsQuickSync(): void
    {
        $items = FeedFactory::items(3);
        $page = FeedFactory::pages($items)[self::SOURCE];
        $fetcher = (new FixtureFetcher())->on(self::SOURCE, fn(?string $etag): FetchResult => $etag === '"e1"'
            ? new FetchResult(304, '', '"e1"')
            : FixtureFetcher::json($page, '"e1"'));
        $this->syncer($fetcher)->sync($this->blog(), false, $this->now());
        self::assertSame('"e1"', $this->blog()->sourceEtag);

        $report = $this->syncer($fetcher)->sync($this->blog(), false, $this->now());
        self::assertTrue($report->notModified);
        self::assertSame('"e1"', $fetcher->requests[1]['etag']);
    }

    public function testFullSyncTombstonesMissingItemsAndRestoresThem(): void
    {
        $items = FeedFactory::items(10);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());

        $without = $items;
        unset($without[4]);
        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages(array_values($without))))->sync($this->blog(), true, $this->now());
        self::assertSame(1, $report->deleted);
        self::assertSame(9, $this->posts()->countLive($this->blog->id));

        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        self::assertSame(1, $report->updated);
        self::assertSame(10, $this->posts()->countLive($this->blog->id));
    }

    public function testSafetyValveRefusesMassDeletion(): void
    {
        $items = FeedFactory::items(120);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());

        $before = $this->db($this->app)->fetchValue('SELECT last_full_sync_at FROM {blogs} WHERE handle = ?', ['mirror']);
        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages(array_slice($items, 0, 50))))->sync($this->blog(), true, $this->now()->modify('+1 hour'));
        self::assertTrue($report->valveTripped);
        self::assertFalse($report->ok());
        self::assertSame(0, $report->deleted);
        self::assertSame(120, $this->posts()->countLive($this->blog->id));
        self::assertStringContainsString('SAFETY VALVE', $report->summary());
        self::assertSame($before, $this->db($this->app)->fetchValue('SELECT last_full_sync_at FROM {blogs} WHERE handle = ?', ['mirror']), 'a tripped valve is not a completed full sync');
    }

    public function testHttpErrorMidWalkKeepsEarlierPagesAndDeletesNothing(): void
    {
        $items = FeedFactory::items(120);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        $items[0]['title'] = 'Changed on page one';
        $fetcher = FeedFactory::fetcher(FeedFactory::pages($items))->on(self::SOURCE . '?page=2', new FetchResult(500, 'down'));
        try {
            $this->syncer($fetcher)->sync($this->blog(), true, $this->now());
            self::fail('expected failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
        }
        self::assertSame(120, $this->posts()->countLive($this->blog->id), 'nothing was tombstoned');
        self::assertSame('Changed on page one', $this->posts()->find($this->posts()->page($this->blog->id, $this->now(), null, 1)[0]->id)?->title ?? null, 'page 1 was committed');
    }

    public function testLoopingNextUrlThrowsAndDeletesNothing(): void
    {
        $items = FeedFactory::items(150);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        $pages = FeedFactory::pages($items);
        $pages[self::SOURCE . '?page=3']['next_url'] = self::SOURCE;
        try {
            $this->syncer(FeedFactory::fetcher($pages))->sync($this->blog(), true, $this->now());
            self::fail('expected failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('loops back', $e->getMessage());
        }
        self::assertSame(150, $this->posts()->countLive($this->blog->id));
    }

    public function testPageCapThrowsAndDeletesNothing(): void
    {
        $items = FeedFactory::items(150);
        $pages = FeedFactory::pages($items);
        $this->syncer(FeedFactory::fetcher($pages))->sync($this->blog(), true, $this->now());
        try {
            $this->cappedSyncer(FeedFactory::fetcher($pages), 2)->sync($this->blog(), true, $this->now());
            self::fail('expected failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('exceeds 2 pages', $e->getMessage());
        }
        self::assertSame(150, $this->posts()->countLive($this->blog->id));
        $lock = new SyncLock($this->tmp . '/locks');
        self::assertTrue($lock->acquire('mirror'), 'lock was released');
        $lock->release('mirror');
    }

    public function testEtagIsStoredOnlyAfterASuccessfulWalk(): void
    {
        $pages = FeedFactory::pages(FeedFactory::items(120));
        $fetcher = (new FixtureFetcher())
            ->on(self::SOURCE, FixtureFetcher::json($pages[self::SOURCE], '"e1"'))
            ->on(self::SOURCE . '?page=2', new FetchResult(500, 'down'));
        try {
            $this->syncer($fetcher)->sync($this->blog(), false, $this->now());
            self::fail('expected failure');
        } catch (RuntimeException) {
        }
        self::assertNull($this->blog()->sourceEtag);
        $retry = FeedFactory::fetcher($pages);
        $this->syncer($retry)->sync($this->blog(), false, $this->now());
        self::assertNull($retry->requests[0]['etag']);
    }

    public function testItemThatFailsConversionIsNotTombstoned(): void
    {
        $items = FeedFactory::items(10);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        $items[3]['date_published'] = 'not-a-date';
        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        self::assertSame(1, $report->skipped);
        self::assertSame(0, $report->deleted);
        self::assertSame(10, $this->posts()->countLive($this->blog->id));
    }

    public function testMalformedItemsAreSkipped(): void
    {
        $items = FeedFactory::items(3);
        $items[1] = ['title' => 'no id'];
        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        self::assertSame(2, $report->created);
        self::assertSame(1, $report->skipped);
    }

    public function testRejectsNonJsonFeed(): void
    {
        $fetcher = (new FixtureFetcher())->on(self::SOURCE, FixtureFetcher::json(['hello' => 'world']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a JSON Feed');
        $this->syncer($fetcher)->sync($this->blog(), true, $this->now());
    }

    public function testLockedBlogIsSkipped(): void
    {
        $lock = new SyncLock($this->tmp . '/locks');
        self::assertTrue($lock->acquire('mirror'));
        $report = $this->syncer(FeedFactory::fetcher([]))->sync($this->blog(), true, $this->now());
        self::assertTrue($report->alreadyRunning);
        $lock->release('mirror');
    }

    public function testEventsAreDispatched(): void
    {
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $seen = [];
        $events = $app->container()->get(EventDispatcher::class);
        $events->listen(ItemCreated::class, function (ItemCreated $e) use (&$seen): void {
            $seen[] = "created:{$e->module}:{$e->itemtype}";
        });
        $events->listen(ItemDeleted::class, function () use (&$seen): void {
            $seen[] = 'deleted';
        });
        $app->container()->get(Syncer::class)->sync($this->blog(), true, $this->now());
        self::assertSame(['created:blog:post', 'created:blog:post', 'created:blog:post'], $seen);
    }

    public function testTombstoningDispatchesItemDeleted(): void
    {
        $items = FeedFactory::items(3);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        array_pop($items);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages($items)));
        $deleted = [];
        $app->container()->get(EventDispatcher::class)->listen(ItemDeleted::class, function (ItemDeleted $e) use (&$deleted): void {
            $deleted[] = [$e->id, $e->item];
        });
        $app->container()->get(Syncer::class)->sync($this->blog(), true, $this->now());
        self::assertCount(1, $deleted);
        self::assertSame(['blog' => 'mirror'], $deleted[0][1]);
        self::assertSame(26, strlen($deleted[0][0]));
    }

    public function testNativeBlogCannotSync(): void
    {
        $native = $this->blogs($this->app)->create(['handle' => 'native', 'mode' => 'native'], $this->now());
        $this->expectException(LogicException::class);
        $this->syncer(new FixtureFetcher())->sync($native, false, $this->now());
    }
}
