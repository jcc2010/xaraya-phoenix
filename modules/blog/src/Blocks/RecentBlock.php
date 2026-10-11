<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Module\Blog\Http\BlogPage;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\PostViewFactory;

/** blog.recent: the newest live posts ({"limit": 5}). */
final class RecentBlock implements Block
{
    public function __construct(
        private readonly CurrentBlog $current,
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
    ) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $limit = is_int($config['limit'] ?? null) ? max(1, min(20, $config['limit'])) : 5;
        $items = [];
        foreach ($this->posts->page($blog->id, BlogPage::now(), null, $limit) as $post) {
            $items[] = [
                'title' => $post->title,
                'url' => $this->factory->link($blog, $post),
                'publishedIso' => $post->datePublished->format(DATE_ATOM),
                'publishedDisplay' => PostViewFactory::display($post->datePublished),
            ];
        }

        return $items === [] ? '' : $context->helpers->render('blog::blocks/recent', ['posts' => $items]);
    }
}
