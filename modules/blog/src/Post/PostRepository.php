<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;
use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Select;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Search\LikeSearchIndex;

/**
 * Posts of every blog.
 *
 * Native vs mirrored: a post is native when its source_hash IS NULL. Only sync and the source
 * adapters set source_hash; native writes leave it null. Do not infer nativeness from item_id.
 */
final class PostRepository
{
    private const CHUNK = 500;

    /** The admin list's tabs, in display order. */
    public const ADMIN_TABS = ['published', 'scheduled', 'deleted'];

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
    public function insert(int $blogId, string $id, PostRecord $record, DateTimeImmutable $now, ?string $ownerId = null): void
    {
        $this->db->transaction(function () use ($blogId, $id, $record, $now, $ownerId): void {
            $this->db->insert('posts', [
                'id' => $id,
                'blog_id' => $blogId,
                'item_id' => $record->itemId,
                ...$this->columns($record, $now),
                'status' => 'published',
                'created_at' => $now,
                'owner_id' => $ownerId,
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

    /**
     * Ids of a blog's posts (every status) for an owner backfill: the ownerless ones, or all of them with $all.
     *
     * @return list<string>
     */
    public function ownerTargets(int $blogId, bool $all): array
    {
        $query = $this->db->select('posts')->columns('id')->where('blog_id', '=', $blogId);
        if (!$all) {
            $query->whereNull('owner_id');
        }

        return array_map(static fn(array $row): string => (string) $row['id'], $query->orderBy('id')->all());
    }

    /**
     * Sets the owner of the given posts in one transaction. Ownership isn't part of a post's content, so updated_at
     * stays as it is; returns the number of posts assigned.
     *
     * @param list<string> $ids
     */
    public function assignOwner(array $ids, string $ownerId): int
    {
        return $this->db->transaction(function () use ($ids, $ownerId): int {
            $count = 0;
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $marks = implode(', ', array_fill(0, count($chunk), '?'));
                $count += $this->db->query("UPDATE {posts} SET owner_id = ? WHERE id IN ({$marks})", [$ownerId, ...$chunk])->rowCount();
            }

            return $count;
        });
    }

    public function find(string $id): ?Post
    {
        $row = $this->db->select('posts')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * The admin tabs' counts: published (live now), scheduled (published, dated in the future) and deleted.
     * With $ownerId, scheduled and deleted (drafts are private) count only that owner's posts; published stays blog-wide.
     *
     * @return array{published: int, scheduled: int, deleted: int}
     */
    public function adminCounts(int $blogId, DateTimeImmutable $now, ?string $ownerId = null): array
    {
        $scope = function (Select $query) use ($ownerId): Select {
            return $ownerId === null ? $query : $query->where('owner_id', '=', $ownerId);
        };
        $live = fn(string $op): int => $this->db->select('posts')
            ->where('blog_id', '=', $blogId)
            ->where('status', '=', 'published')
            ->where('date_published', $op, $now)
            ->count();

        return [
            'published' => $live('<='),
            'scheduled' => $scope($this->db->select('posts')
                ->where('blog_id', '=', $blogId)
                ->where('status', '=', 'published')
                ->where('date_published', '>', $now))->count(),
            'deleted' => $scope($this->db->select('posts')->where('blog_id', '=', $blogId)->where('status', '=', 'deleted'))->count(),
        ];
    }

    /**
     * One page of the admin list for a tab, optionally narrowed by a case-insensitive title substring (wildcards
     * match literally). With $ownerId, the private tabs (scheduled, deleted) list only that owner's posts.
     *
     * @return array{total: int, posts: list<Post>}
     */
    public function adminPage(int $blogId, string $tab, string $title, DateTimeImmutable $now, int $limit, int $offset, ?string $ownerId = null): array
    {
        $where = ['blog_id = ?'];
        $params = [$blogId];
        switch ($tab) {
            case 'published':
                array_push($where, 'status = ?', 'date_published <= ?');
                array_push($params, 'published', $now);
                break;
            case 'scheduled':
                array_push($where, 'status = ?', 'date_published > ?');
                array_push($params, 'published', $now);
                break;
            case 'deleted':
                $where[] = 'status = ?';
                $params[] = 'deleted';
                break;
            default:
                throw new InvalidArgumentException("Unknown tab '{$tab}'");
        }
        if ($ownerId !== null && $tab !== 'published') {
            $where[] = 'owner_id = ?';
            $params[] = $ownerId;
        }
        if ($title !== '') {
            // SQLite's LOWER() folds only ASCII, so also match the query as typed ("Été" finds "Été").
            $where[] = "(LOWER(title) LIKE ? ESCAPE '!' OR title LIKE ? ESCAPE '!')";
            array_push($params, '%' . LikeSearchIndex::escapeLike(mb_strtolower($title)) . '%', '%' . LikeSearchIndex::escapeLike($title) . '%');
        }
        $from = 'FROM {posts} WHERE ' . implode(' AND ', $where);
        $rows = $this->db->fetchAll(
            "SELECT * {$from} ORDER BY date_published DESC, id DESC LIMIT " . max(0, $limit) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return ['total' => (int) $this->db->fetchValue("SELECT COUNT(*) {$from}", $params), 'posts' => $this->hydrate($rows)];
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
     * Every tag of a blog's non-deleted posts (scheduled ones included), by slug: the admin tags screen. A slug's
     * name is its smallest stored name in byte order, picked here rather than with MIN(), whose answer for "PHP"
     * against "php" depends on the database's collation (Postgres differs from SQLite and MySQL's utf8mb4_bin).
     *
     * @return list<array{name: string, slug: string, count: int}>
     */
    public function adminTagCounts(int $blogId): array
    {
        // A post has one row per slug (the primary key), so the counts of a slug's names add up to its posts.
        $sql = 'SELECT t.slug AS slug, t.name AS name, COUNT(*) AS uses FROM {post_tags} t JOIN {posts} p ON p.id = t.post_id'
            . ' WHERE t.blog_id = ? AND p.blog_id = ? AND p.status = ?'
            . ' GROUP BY t.slug, t.name';
        $tags = [];
        foreach ($this->db->fetchAll($sql, [$blogId, $blogId, 'published']) as $row) {
            $slug = (string) $row['slug'];
            $name = (string) $row['name'];
            $tag = $tags[$slug] ?? ['name' => $name, 'slug' => $slug, 'count' => 0];
            $tags[$slug] = [
                'name' => strcmp($name, $tag['name']) < 0 ? $name : $tag['name'],
                'slug' => $slug,
                'count' => $tag['count'] + (int) $row['uses'],
            ];
        }
        ksort($tags, SORT_STRING);

        return array_values($tags);
    }

    /**
     * A blog's non-deleted posts (scheduled ones included) carrying the tag $slug (compared exactly), in id order.
     *
     * @return list<Post>
     */
    public function liveWithTag(int $blogId, string $slug): array
    {
        $sql = 'SELECT p.* FROM {posts} p WHERE p.blog_id = ? AND p.status = ?'
            . ' AND EXISTS (SELECT 1 FROM {post_tags} t WHERE t.post_id = p.id AND t.blog_id = ? AND t.slug = ?)'
            . ' ORDER BY p.id';

        return $this->hydrate($this->db->fetchAll($sql, [$blogId, 'published', $blogId, $slug]));
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
