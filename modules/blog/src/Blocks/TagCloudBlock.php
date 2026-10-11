<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Http\BlogPage;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\TagCloud;

/** blog.tagcloud: the most used tags ({"limit": 30}), sized, sorted and linked like the tags page. */
final class TagCloudBlock implements Block
{
    public function __construct(
        private readonly CurrentBlog $current,
        private readonly PostRepository $posts,
        private readonly UrlGenerator $urls,
    ) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $limit = is_int($config['limit'] ?? null) ? max(1, min(100, $config['limit'])) : 30;
        $tags = TagCloud::withUrls($this->posts->tagCounts($blog->id, BlogPage::now(), $limit), $this->urls, $blog->handle);
        if ($tags === []) {
            return '';
        }

        return $context->helpers->render('blog::blocks/tagcloud', [
            'tags' => $tags,
            'allUrl' => $this->urls->generate('blog.tags', ['handle' => $blog->handle]),
        ]);
    }
}
