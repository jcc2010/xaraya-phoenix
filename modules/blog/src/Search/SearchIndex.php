<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use Xaraya\Module\Blog\Post\Post;

/** Full-text search over a blog's live posts. LikeSearchIndex is the portable binding. */
interface SearchIndex
{
    /** Adds or replaces the post's entry; a post that is not published is removed instead. */
    public function index(Post $post): void;

    public function remove(string $postId): void;

    /** Removes every entry of the blog (blog:reindex rebuilds it). */
    public function clear(int $blogId): void;

    /** @return array{total: int, ids: list<string>} live posts matching every term, newest first */
    public function search(int $blogId, string $query, int $limit, int $offset): array;
}
