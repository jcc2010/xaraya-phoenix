<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\SafeUrl;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\Translator;

/** GET /blog/{handle}/about: the blog's description, author, feeds and subscribe link. */
final class AboutController extends Controller
{
    public function __construct(
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /** @param array<string, string> $params */
    public function about(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $athenana = is_array($blog->extra['_athenana'] ?? null) ? $blog->extra['_athenana'] : [];
        $subscribe = $athenana['subscribe_url'] ?? null;
        $handle = ['handle' => $blog->handle];

        return $this->pages->page(
            $blog,
            'blog::about',
            [
                'descriptionHtml' => $this->pages->descriptionHtml($blog),
                'authorName' => $blog->authorName,
                'authorUrl' => $blog->authorUrl !== null && SafeUrl::isSafe($blog->authorUrl) ? $blog->authorUrl : null,
                'feeds' => [
                    'json' => $this->urls->generate('blog.feed.json', $handle, true),
                    'rss' => $this->urls->generate('blog.feed.xml', $handle, true),
                ],
                'subscribeUrl' => is_string($subscribe) && SafeUrl::isSafe($subscribe) ? $subscribe : null,
            ],
            $this->translator->t('About'),
            $this->urls->generate('blog.about', $handle, true),
        );
    }
}
