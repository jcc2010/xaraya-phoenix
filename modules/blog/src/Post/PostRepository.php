<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;

/**
 * Posts of every blog.
 *
 * Native vs mirrored: a post is native when its source_hash IS NULL. Only sync and the source
 * adapters set source_hash; native writes leave it null. Do not infer nativeness from item_id.
 */
final class PostRepository
{
    private const CHUNK = 500;

    public function __construct(private readonly Connection $db) {}

    public function save(int $blogId, PostRecord $record, DateTimeImmutable $now): SaveResult
    {
        return $this->db->transaction(function () use ($blogId, $record, $now): SaveResult {
            $existing = $this->db->select('posts')
                ->columns('id', 'status', 'source_hash')
                ->where('blog_id', '=', $blogId)
                ->where('item_id', '=', $record->itemId)
                ->first();
            $columns = [
                'kind' => $record->kind,
                'title' => $record->title,
                'url' => $record->url,
                'external_url' => $record->externalUrl,
                'content_html' => $record->contentHtml,
                'image' => $record->image,
                'date_published' => $record->datePublished,
                'date_modified' => $record->dateModified,
                'status' => 'published',
                'source_hash' => $record->sourceHash,
                'doc' => $record->doc === [] ? null : $record->doc,
                'updated_at' => $now,
            ];
            if ($existing !== null) {
                $id = (string) $existing['id'];
                if ($record->sourceHash !== null && $existing['source_hash'] === $record->sourceHash && $existing['status'] === 'published') {
                    return new SaveResult(SaveResult::UNCHANGED, $id);
                }
                $this->db->update('posts', $columns, ['id' => $id]);
                $this->writeTags($id, $blogId, $record->tags);

                return new SaveResult(SaveResult::UPDATED, $id);
            }
            $id = $record->ulid !== null && $this->db->select('posts')->where('id', '=', $record->ulid)->count() === 0
                ? $record->ulid
                : Ulid::generate();
            $this->db->insert('posts', ['id' => $id, 'blog_id' => $blogId, 'item_id' => $record->itemId, ...$columns, 'created_at' => $now]);
            $this->writeTags($id, $blogId, $record->tags);

            return new SaveResult(SaveResult::CREATED, $id);
        });
    }

    public function find(string $id): ?Post
    {
        $row = $this->db->select('posts')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return list<Post>
     */
    public function page(int $blogId, DateTimeImmutable $now, ?array $before, int $limit): array
    {
        $query = $this->db->select('posts')
            ->where('blog_id', '=', $blogId)
            ->where('status', '=', 'published')
            ->where('date_published', '<=', $now);
        if ($before !== null) {
            $query->cursor(['date_published' => $before[0], 'id' => $before[1]], '<');
        }

        return $this->hydrate($query->orderBy('date_published', 'desc')->orderBy('id', 'desc')->limit($limit)->all());
    }

    /** @return list<string> */
    public function liveItemIds(int $blogId): array
    {
        $rows = $this->db->select('posts')->columns('item_id')->where('blog_id', '=', $blogId)->where('status', '=', 'published')->all();

        return array_map(static fn(array $row): string => (string) $row['item_id'], $rows);
    }

    public function countLive(int $blogId): int
    {
        return $this->db->select('posts')->where('blog_id', '=', $blogId)->where('status', '=', 'published')->count();
    }

    /** Live posts written natively (source_hash IS NULL), which a full mirror sync would tombstone. */
    public function countLiveNative(int $blogId): int
    {
        return $this->db->select('posts')
            ->where('blog_id', '=', $blogId)
            ->where('status', '=', 'published')
            ->whereNull('source_hash')
            ->count();
    }

    /**
     * @param list<string> $itemIds
     * @return list<string> ids of the posts that were tombstoned
     */
    public function tombstone(int $blogId, array $itemIds, DateTimeImmutable $now): array
    {
        $deleted = [];
        foreach (array_chunk($itemIds, self::CHUNK) as $chunk) {
            $rows = $this->db->select('posts')->columns('id')
                ->where('blog_id', '=', $blogId)
                ->where('status', '=', 'published')
                ->whereIn('item_id', $chunk)
                ->all();
            foreach ($rows as $row) {
                $this->db->update('posts', ['status' => 'deleted', 'updated_at' => $now], ['id' => (string) $row['id']]);
                $deleted[] = (string) $row['id'];
            }
        }

        return $deleted;
    }

    /** @param list<array{name: string, slug: string}> $tags */
    private function writeTags(string $postId, int $blogId, array $tags): void
    {
        $this->db->delete('post_tags', ['post_id' => $postId]);
        foreach ($tags as $position => $tag) {
            $this->db->insert('post_tags', [
                'post_id' => $postId,
                'blog_id' => $blogId,
                'position' => $position,
                'name' => $tag['name'],
                'slug' => $tag['slug'],
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Post>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn(array $row): string => (string) $row['id'], $rows);
        $tags = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $tagRows = $this->db->select('post_tags')->whereIn('post_id', $chunk)->orderBy('post_id')->orderBy('position')->all();
            foreach ($tagRows as $tag) {
                $tags[(string) $tag['post_id']][] = ['name' => (string) $tag['name'], 'slug' => (string) $tag['slug']];
            }
        }

        return array_map(static fn(array $row): Post => Post::fromRow($row, $tags[(string) $row['id']] ?? []), $rows);
    }
}
