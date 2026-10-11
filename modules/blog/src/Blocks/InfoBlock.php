<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Http\BlogPage;

/** blog.info: the start of the blog's description, the about page and the feeds. */
final class InfoBlock implements Block
{
    public const SNIPPET_LENGTH = 200;

    public function __construct(
        private readonly CurrentBlog $current,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
    ) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $description = $this->pages->summary($blog)['description'];
        if (mb_strlen($description) > self::SNIPPET_LENGTH) {
            $description = rtrim(mb_substr($description, 0, self::SNIPPET_LENGTH - 1)) . '…';
        }
        $handle = ['handle' => $blog->handle];

        return $context->helpers->render('blog::blocks/info', [
            'title' => $blog->title,
            'description' => $description,
            'aboutUrl' => $this->urls->generate('blog.about', $handle),
            'jsonUrl' => $this->urls->generate('blog.feed.json', $handle),
            'rssUrl' => $this->urls->generate('blog.feed.xml', $handle),
        ]);
    }
}
