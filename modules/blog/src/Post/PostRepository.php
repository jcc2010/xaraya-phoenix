<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Select;
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
            $columns = [...$this->columns($record, $now), 'status' => 'published'];
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

    /**
     * Inserts a post written natively, under the id its item_id already embeds. Unlike save(), it never swaps a
     * colliding id: a collision is an error.
     */
    public function insert(int $blogId, string $id, PostRecord $record, DateTimeImmutable $now): void
    {
        $this->db->transaction(function () use ($blogId, $id, $record, $now): void {
            $this->db->insert('posts', [
                'id' => $id,
                'blog_id' => $blogId,
                'item_id' => $record->itemId,
                ...$this->columns($record, $now),
                'status' => 'published',
                'created_at' => $now,
            ]);
            $this->writeTags($id, $blogId, $record->tags);
        });
    }

    /** Replaces a post's content columns, doc and tags. Its id, item_id, status and created_at stay. */
    public function rewrite(Post $post, PostRecord $record, DateTimeImmutable $now): void
    {
        $this->db->transaction(function () use ($post, $record, $now): void {
            $this->db->update('posts', $this->columns($record, $now), ['id' => $post->id]);
            $this->writeTags($post->id, $post->blogId, $record->tags);
        });
    }

    /** Sets a post's status (published or deleted) and bumps updated_at; false when it already had that status. */
    public function setStatus(string $id, string $status, DateTimeImmutable $now): bool
    {
        return $this->db->query(
            'UPDATE {posts} SET status = ?, updated_at = ? WHERE id = ? AND status <> ?',
            [$status, $now, $id, $status],
        )->rowCount() > 0;
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

    /**
     * @param list<string> $ids
     * @return list<Post> in the order of $ids; unknown ids are skipped
     */
    public function findMany(array $ids): array
    {
        $byId = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->hydrate($this->db->select('posts')->whereIn('id', $chunk)->all()) as $post) {
                $byId[$post->id] = $post;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /** The blog's post with this item id, whatever its status. */
    public function findByItemId(int $blogId, string $itemId): ?Post
    {
        $row = $this->db->select('posts')->where('blog_id', '=', $blogId)->where('item_id', '=', $itemId)->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** The blog's post with this source item id, if it is live: published and not scheduled. */
    public function findLiveByItemId(int $blogId, string $itemId, DateTimeImmutable $now): ?Post
    {
        $row = $this->db->select('posts')
            ->where('blog_id', '=', $blogId)
            ->where('item_id', '=', $itemId)
            ->where('status', '=', 'published')
            ->where('date_published', '<=', $now)
            ->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * The live posts just before (older) and just after (newer) $post in its blog, by (date_published, id).
     *
     * @return array{0: ?Post, 1: ?Post}
     */
    public function neighbours(Post $post, DateTimeImmutable $now): array
    {
        $live = fn(): Select => $this->db->select('posts')
            ->where('blog_id', '=', $post->blogId)
            ->where('status', '=', 'published')
            ->where('date_published', '<=', $now);
        $at = ['date_published' => $post->datePublished, 'id' => $post->id];
        $older = $live()->cursor($at, '<')->orderBy('date_published', 'desc')->orderBy('id', 'desc')->first();
        $newer = $live()->cursor($at, '>')->orderBy('date_published')->orderBy('id')->first();

        return [
            $older === null ? null : $this->hydrate([$older])[0],
            $newer === null ? null : $this->hydrate([$newer])[0],
        ];
    }

    /**
     * A blog's live posts carrying the tag $slug (compared exactly), newest first, keyset-paged like page().
     *
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return list<Post>
     */
    public function pageByTag(int $blogId, string $slug, DateTimeImmutable $now, ?array $before, int $limit): array
    {
        $sql = 'SELECT p.* FROM {posts} p JOIN {post_tags} t ON t.post_id = p.id'
            . ' WHERE t.blog_id = ? AND t.slug = ? AND p.blog_id = ? AND p.status = ? AND p.date_published <= ?';
        $params = [$blogId, $slug, $blogId, 'published', $now];
        if ($before !== null) {
            $sql .= ' AND (p.date_published < ? OR (p.date_published = ? AND p.id < ?))';
            array_push($params, $before[0], $before[0], $before[1]);
        }
        $sql .= ' ORDER BY p.date_published DESC, p.id DESC LIMIT ' . max(0, $limit);

        return $this->hydrate($this->db->fetchAll($sql, $params));
    }

    /**
     * Tags with at least one live post, most used first, then by slug. A slug's name is its smallest stored name.
     *
     * @return list<array{name: string, slug: string, count: int}>
     */
    public function tagCounts(int $blogId, DateTimeImmutable $now, ?int $limit = null): array
    {
        $sql = 'SELECT t.slug AS slug, MIN(t.name) AS name, COUNT(*) AS uses FROM {post_tags} t JOIN {posts} p ON p.id = t.post_id'
            . ' WHERE t.blog_id = ? AND p.blog_id = ? AND p.status = ? AND p.date_published <= ?'
            . ' GROUP BY t.slug ORDER BY uses DESC, t.slug ASC'
            . ($limit === null ? '' : ' LIMIT ' . max(0, $limit));
        $rows = $this->db->fetchAll($sql, [$blogId, $blogId, 'published', $now]);

        return array_map(static fn(array $row): array => [
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['uses'],
        ], $rows);
    }

    /**
     * Published posts of a blog, scheduled ones included, in id order after $afterId: the chunks blog:reindex walks.
     *
     * @return list<Post>
     */
    public function publishedAfter(int $blogId, ?string $afterId, int $limit): array
    {
        $query = $this->db->select('posts')->where('blog_id', '=', $blogId)->where('status', '=', 'published');
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        return $this->hydrate($query->orderBy('id')->limit($limit)->all());
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

    /** @return array<string, mixed> every content column, plus updated_at */
    private function columns(PostRecord $record, DateTimeImmutable $now): array
    {
        return [
            'kind' => $record->kind,
            'title' => $record->title,
            'url' => $record->url,
            'external_url' => $record->externalUrl,
            'content_html' => $record->contentHtml,
            'image' => $record->image,
            'date_published' => $record->datePublished,
            'date_modified' => $record->dateModified,
            'source_hash' => $record->sourceHash,
            'doc' => $record->doc === [] ? null : $record->doc,
            'updated_at' => $now,
        ];
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
