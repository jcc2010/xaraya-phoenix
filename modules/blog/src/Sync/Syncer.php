<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Sync;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Post\SaveResult;
use Xaraya\Module\Blog\Source\AdapterRegistry;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\SourceAdapter;

final class Syncer
{
    public const MAX_PAGES = 10_000;

    public function __construct(
        private readonly Connection $db,
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly AdapterRegistry $adapters,
        private readonly HttpFetcher $fetcher,
        private readonly EventDispatcher $events,
        private readonly LoggerInterface $logger,
        private readonly SyncLock $lock,
    ) {}

    public function sync(Blog $blog, bool $full, DateTimeImmutable $now): SyncReport
    {
        if (!$blog->isMirror() || $blog->sourceUrl === null || $blog->sourceFormat === null) {
            throw new LogicException("Blog '{$blog->handle}' is not a mirror blog");
        }
        $report = new SyncReport($blog->handle, $full);
        if (!$this->lock->acquire($blog->handle)) {
            $report->alreadyRunning = true;

            return $report;
        }
        try {
            $adapter = $this->adapters->get($blog->sourceFormat);
            $url = $blog->sourceUrl;
            $result = $this->fetcher->get($url, $full ? null : $blog->sourceEtag);
            if ($result->status === 304) {
                $report->notModified = true;
                $this->blogs->update($blog, ['last_synced_at' => $now], $now);

                return $report;
            }
            /** @var array<string, true> $seen */
            $seen = [];
            $visited = [];
            while (true) {
                if ($result->status !== 200) {
                    throw new RuntimeException("Fetching {$url} returned HTTP {$result->status}");
                }
                $feed = $result->json();
                $version = $feed['version'] ?? null;
                $items = $feed['items'] ?? null;
                if (!is_string($version) || !str_starts_with($version, 'https://jsonfeed.org/version/1') || !is_array($items)) {
                    throw new RuntimeException("{$url} is not a JSON Feed");
                }
                if ($report->pages === 0) {
                    $blog = $this->blogs->update($blog, $adapter->feedMeta($feed) + ['source_etag' => $result->etag], $now);
                }
                $changed = $this->syncPage($blog, $adapter, $items, $now, $report, $seen);
                $report->pages++;
                $visited[$url] = true;
                $next = $feed['next_url'] ?? null;
                if ((!$full && $changed === 0) || !is_string($next) || $next === '' || isset($visited[$next]) || $report->pages >= self::MAX_PAGES) {
                    break;
                }
                $url = $next;
                $result = $this->fetcher->get($url);
            }
            if ($full) {
                $this->tombstoneMissing($blog, $seen, $now, $report);
            }
            $this->blogs->update($blog, ['last_synced_at' => $now] + ($full ? ['last_full_sync_at' => $now] : []), $now);

            return $report;
        } finally {
            $this->lock->release($blog->handle);
        }
    }

    /**
     * @param array<mixed> $items
     * @param array<string, true> $seen
     * @return int how many items were created or updated
     */
    private function syncPage(Blog $blog, SourceAdapter $adapter, array $items, DateTimeImmutable $now, SyncReport $report, array &$seen): int
    {
        /** @var array<string, PostRecord> $records */
        $records = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $report->skipped++;
                $this->logger->warning('Skipping item {index} from {blog}: not an object', ['index' => $index, 'blog' => $blog->handle]);
                continue;
            }
            try {
                $record = $adapter->toRecord($item, $now);
            } catch (InvalidArgumentException $e) {
                $report->skipped++;
                $this->logger->warning('Skipping item {index} from {blog}: {reason}', ['index' => $index, 'blog' => $blog->handle, 'reason' => $e->getMessage()]);
                continue;
            }
            if (isset($records[$record->itemId])) {
                $this->logger->warning('Item {id} appears twice on one page of {blog}; the last one wins', ['id' => $record->itemId, 'blog' => $blog->handle]);
            }
            $records[$record->itemId] = $record;
        }

        /** @var array<string, SaveResult> $results */
        $results = $this->db->transaction(function () use ($blog, $records, $now): array {
            $out = [];
            foreach ($records as $itemId => $record) {
                $out[(string) $itemId] = $this->posts->save($blog->id, $record, $now);
            }

            return $out;
        });

        $changed = 0;
        foreach ($results as $itemId => $result) {
            $itemId = (string) $itemId;
            $seen[$itemId] = true;
            if ($result->status === SaveResult::UNCHANGED) {
                $report->unchanged++;
                continue;
            }
            $changed++;
            $payload = ['blog' => $blog->handle, 'item_id' => $itemId];
            if ($result->status === SaveResult::CREATED) {
                $report->created++;
                $this->events->dispatch(new ItemCreated('blog', 'post', $result->id, $payload));
            } else {
                $report->updated++;
                $this->events->dispatch(new ItemUpdated('blog', 'post', $result->id, $payload));
            }
        }

        return $changed;
    }

    /** @param array<string, true> $seen */
    private function tombstoneMissing(Blog $blog, array $seen, DateTimeImmutable $now, SyncReport $report): void
    {
        $live = $this->posts->liveItemIds($blog->id);
        if ($live !== [] && count($seen) < 0.5 * count($live)) {
            $report->valveTripped = true;
            $this->logger->error('Full sync of {blog} saw {seen} of {live} live items; deleting nothing', [
                'blog' => $blog->handle, 'seen' => count($seen), 'live' => count($live),
            ]);

            return;
        }
        $missing = array_values(array_diff($live, array_map('strval', array_keys($seen))));
        foreach ($this->posts->tombstone($blog->id, $missing, $now) as $id) {
            $report->deleted++;
            $this->events->dispatch(new ItemDeleted('blog', 'post', $id, ['blog' => $blog->handle]));
        }
    }
}
