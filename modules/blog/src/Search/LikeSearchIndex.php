<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\Post\Post;

/**
 * Search on any database: xar_post_search holds each post's normalised text, and a query ANDs one
 * `body LIKE '%term%'` per term. Terms are normalised first, so they never hold % or _; escaping them is defence
 * in depth.
 */
final class LikeSearchIndex implements SearchIndex
{
    public function __construct(private readonly Connection $db) {}

    public function index(Post $post): void
    {
        if ($post->status !== 'published') {
            $this->remove($post->id);

            return;
        }
        $this->db->upsert('post_search', ['post_id' => $post->id, 'blog_id' => $post->blogId, 'body' => SearchText::body($post)], ['post_id']);
    }

    public function remove(string $postId): void
    {
        $this->db->delete('post_search', ['post_id' => $postId]);
    }

    public function clear(int $blogId): void
    {
        $this->db->delete('post_search', ['blog_id' => $blogId]);
    }

    public function search(int $blogId, string $query, int $limit, int $offset): array
    {
        $terms = SearchText::terms($query);
        if ($terms === []) {
            return ['total' => 0, 'ids' => []];
        }
        $where = ['s.blog_id = ?', 'p.blog_id = ?', 'p.status = ?', 'p.date_published <= ?'];
        $params = [$blogId, $blogId, 'published', new DateTimeImmutable('now', new DateTimeZone('UTC'))];
        foreach ($terms as $term) {
            $where[] = "s.body LIKE ? ESCAPE '!'";
            $params[] = '%' . self::escapeLike($term) . '%';
        }
        $from = 'FROM {post_search} s JOIN {posts} p ON p.id = s.post_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from}", $params);
        $rows = $this->db->fetchAll(
            "SELECT p.id AS id {$from} ORDER BY p.date_published DESC, p.id DESC LIMIT " . max(0, $limit) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return ['total' => $total, 'ids' => array_map(static fn(array $row): string => (string) $row['id'], $rows)];
    }

    /** Escapes LIKE wildcards with "!", the ESCAPE character every supported database accepts. */
    public static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
