<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;

/** The blog a sidebar block is about: the route's {handle}, or on /s/{id} the post's blog. */
final class CurrentBlog
{
    public function __construct(private readonly BlogRepository $blogs, private readonly PostRepository $posts) {}

    public function of(BlockContext $context): ?Blog
    {
        $handle = $context->params['handle'] ?? null;
        if (is_string($handle)) {
            return $this->blogs->find($handle);
        }
        $id = $context->params['id'] ?? null;
        if ($context->routeName === 'blog.post' && is_string($id)) {
            $post = $this->posts->find($id);

            return $post === null ? null : $this->blogs->findById($post->blogId);
        }

        return null;
    }
}
