<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Module\Blog\Post\PostRepository;

/**
 * Keeps the search index current: subscribed to ItemCreated, ItemUpdated and ItemDeleted (module.json).
 * Dispatch item events outside any open transaction (Syncer does): on Postgres a swallowed index failure would
 * still abort the outer transaction.
 */
final class SearchIndexer
{
    public function __construct(
        private readonly SearchIndex $index,
        private readonly PostRepository $posts,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ItemEvent $event): void
    {
        if ($event->module !== 'blog' || $event->itemtype !== 'post') {
            return;
        }
        try {
            if ($event instanceof ItemDeleted) {
                $this->index->remove($event->id);
            } elseif ($event instanceof ItemCreated || $event instanceof ItemUpdated) {
                $post = $this->posts->find($event->id);
                $post === null ? $this->index->remove($event->id) : $this->index->index($post);
            }
        } catch (Throwable $e) {
            // A failing index must never stop a sync; blog:reindex repairs it.
            $this->logger->error('Search index update for post {id} failed: {reason}', ['id' => $event->id, 'reason' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
