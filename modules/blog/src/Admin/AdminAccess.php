<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin;

use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Module\Blog\Post\Post;

/**
 * Who may do what in the blog admin. Every decision about one post passes the post as the item, so an "own"
 * grant is matched against its owner; a check without an item would answer "on some post" and is only for
 * screen-level questions (may this user see the list, the new-post form, the pin control).
 */
final class AdminAccess
{
    public function __construct(private readonly Access $access) {}

    /** @return array{id: string, owner_id: ?string} */
    public static function item(Post $post): array
    {
        return ['id' => $post->id, 'owner_id' => $post->ownerId];
    }

    /** The posts screens: edit on some post. */
    public function canManagePosts(): bool
    {
        return $this->access->can('edit', 'blog', 'post');
    }

    public function canCreate(): bool
    {
        return $this->access->can('add', 'blog', 'post');
    }

    public function canEdit(Post $post): bool
    {
        return $this->access->can('edit', 'blog', 'post', self::item($post));
    }

    public function canDelete(Post $post): bool
    {
        return $this->access->can('delete', 'blog', 'post', self::item($post));
    }

    /** Pinning affects the whole blog, so it needs edit on the blog itself. */
    public function canPin(): bool
    {
        return $this->access->can('edit', 'blog', 'blog');
    }

    public function canAdminBlogs(): bool
    {
        return $this->access->can('admin', 'blog', 'blog');
    }

    /** @throws Forbidden */
    public function assertEdit(Post $post): void
    {
        $this->canEdit($post) || throw new Forbidden();
    }

    /** @throws Forbidden */
    public function assertDelete(Post $post): void
    {
        $this->canDelete($post) || throw new Forbidden();
    }
}
