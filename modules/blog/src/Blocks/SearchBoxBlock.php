<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Http\Paging;

/** blog.searchbox: a GET form to the blog's search page. */
final class SearchBoxBlock implements Block
{
    public function __construct(private readonly CurrentBlog $current, private readonly UrlGenerator $urls) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $request = $context->request();
        $q = $context->routeName === 'blog.search' && $request !== null ? (Paging::query($request)['q'] ?? '') : '';

        return $context->helpers->render('blog::blocks/searchbox', [
            'action' => $this->urls->generate('blog.search', ['handle' => $blog->handle]),
            'q' => is_string($q) ? trim(mb_scrub($q, 'UTF-8')) : '',
        ]);
    }
}
