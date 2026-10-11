<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\Translator;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Search\SearchText;
use Xaraya\Module\Blog\View\PostViewFactory;

/** GET /blog/{handle}/search?q=&page=: offset-paged search results with highlighted snippets. */
final class SearchController extends Controller
{
    public const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private readonly SearchIndex $index,
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /** @param array<string, string> $params */
    public function search(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $q = Paging::query($request)['q'] ?? '';
        if (!is_string($q)) {
            throw new HttpException(400, 'Invalid search query');
        }
        $q = trim(mb_scrub($q, 'UTF-8'));
        if (mb_strlen($q) > self::MAX_QUERY_LENGTH) {
            throw new HttpException(400, 'The search query is longer than ' . self::MAX_QUERY_LENGTH . ' characters');
        }
        $page = Paging::page($request);
        $total = 0;
        $results = [];
        if ($q !== '') {
            $found = $this->index->search($blog->id, $q, BlogPage::PAGE_SIZE, ($page - 1) * BlogPage::PAGE_SIZE);
            $total = $found['total'];
            $terms = SearchText::terms($q);
            foreach ($this->posts->findMany($found['ids']) as $post) {
                $results[] = [
                    'title' => $post->title,
                    'url' => $this->factory->link($blog, $post),
                    'snippet' => SearchText::snippet($this->factory->plainText($post), $terms),
                    'publishedIso' => $post->datePublished->format(DATE_ATOM),
                    'publishedDisplay' => PostViewFactory::display($post->datePublished),
                ];
            }
        }
        $route = fn(int $n): array => ['handle' => $blog->handle] + ($q === '' ? [] : ['q' => $q]) + ($n > 1 ? ['page' => (string) $n] : []);

        return $this->pages->page(
            $blog,
            'blog::search',
            [
                'action' => $this->urls->generate('blog.search', ['handle' => $blog->handle]),
                'q' => $q,
                'total' => $total,
                'results' => $results,
                'pager' => [
                    'prev' => $page > 1 ? $this->urls->generate('blog.search', $route($page - 1)) : null,
                    'next' => $page * BlogPage::PAGE_SIZE < $total ? $this->urls->generate('blog.search', $route($page + 1)) : null,
                    'prevLabel' => 'Previous page',
                    'nextLabel' => 'Next page',
                ],
            ],
            $this->translator->t('Search'),
            $this->urls->generate('blog.search', $route($page), true),
        );
    }
}
