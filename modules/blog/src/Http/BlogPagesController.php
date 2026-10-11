<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\Cursor;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\PostView;
use Xaraya\Module\Blog\View\PostViewFactory;

/** The paged post lists: the blog home and a tag's posts. */
final class BlogPagesController extends Controller
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
        private readonly MediaUrls $media,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, string> $params */
    public function home(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $before = Paging::before($request);
        $now = BlogPage::now();
        $rows = $this->posts->page($blog->id, $now, $before, BlogPage::PAGE_SIZE + 1);
        $route = ['handle' => $blog->handle];
        [$posts, $pager] = $this->listing($blog, $rows, 'blog.home', $route, $before);
        $pinned = null;
        if ($before === null && $blog->pinnedItemId !== null) {
            // A pinned post that is deleted, scheduled or unknown is simply not shown.
            $post = $this->posts->findLiveByItemId($blog->id, $blog->pinnedItemId, $now);
            $pinned = $post === null ? null : $this->factory->make($blog, $post, $this->media->map($blog, [$post]), true);
        }

        return $this->pages->page(
            $blog,
            'blog::home',
            ['posts' => $posts, 'pinned' => $pinned, 'pager' => $pager],
            '',
            $this->urls->generate('blog.home', $route + Paging::cursorParam($before), true),
        );
    }

    /** @param array<string, string> $params */
    public function tag(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $slug = $params['slug'];
        if (!mb_check_encoding($slug, 'UTF-8')) {
            throw new NotFound(); // no stored slug can match, and databases reject the bytes
        }
        $before = Paging::before($request);
        $rows = $this->posts->pageByTag($blog->id, $slug, BlogPage::now(), $before, BlogPage::PAGE_SIZE + 1);
        if ($rows === [] && $before === null) {
            throw new NotFound('No published posts have this tag');
        }
        $name = $slug;
        foreach ($rows === [] ? [] : $rows[0]->tags as $tag) {
            if ($tag['slug'] === $slug) {
                $name = $tag['name'];
                break;
            }
        }
        $route = ['handle' => $blog->handle, 'slug' => $slug];
        [$posts, $pager] = $this->listing($blog, $rows, 'blog.tag', $route, $before);

        return $this->pages->page(
            $blog,
            'blog::tag',
            ['tag' => ['name' => $name, 'slug' => $slug], 'posts' => $posts, 'pager' => $pager],
            $name,
            $this->urls->generate('blog.tag', $route + Paging::cursorParam($before), true),
        );
    }

    /**
     * One page of a keyset-paged list.
     *
     * @param list<Post> $rows up to PAGE_SIZE + 1 posts; the extra one only says there is a next page
     * @param array<string, string> $route the list route's parameters
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return array{0: list<PostView>, 1: array{prev: ?string, next: ?string, prevLabel: string, nextLabel: string}}
     */
    private function listing(Blog $blog, array $rows, string $routeName, array $route, ?array $before): array
    {
        $page = array_slice($rows, 0, BlogPage::PAGE_SIZE);
        $next = null;
        if (count($rows) > BlogPage::PAGE_SIZE) {
            $last = $page[BlogPage::PAGE_SIZE - 1];
            $next = $this->urls->generate($routeName, $route + ['before' => Cursor::encode($last->datePublished, $last->id)]);
        }
        $media = $this->media->map($blog, $page);

        return [
            array_map(fn(Post $post): PostView => $this->factory->make($blog, $post, $media), $page),
            [
                'prev' => $before === null ? null : $this->urls->generate($routeName, $route),
                'next' => $next,
                'prevLabel' => 'Newest posts',
                'nextLabel' => 'Older posts',
            ],
        ];
    }
}
