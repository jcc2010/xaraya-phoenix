<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\Translator;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\TagCloud;

/** GET /blog/{handle}/tags: every tag with at least one live post. */
final class TagsController extends Controller
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /** @param array<string, string> $params */
    public function tags(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $tags = TagCloud::withUrls($this->posts->tagCounts($blog->id, BlogPage::now()), $this->urls, $blog->handle);

        return $this->pages->page(
            $blog,
            'blog::tags',
            ['tags' => $tags],
            $this->translator->t('Tags'),
            $this->urls->generate('blog.tags', ['handle' => $blog->handle], true),
        );
    }
}
