<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\PostViewFactory;

/** GET /s/{id}: one post in full. GET /s/{id}.json stays with PostController. */
final class PostPageController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
        private readonly MediaUrls $media,
        private readonly BlogPage $pages,
    ) {}

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): Page
    {
        $now = BlogPage::now();
        $post = $this->posts->find($params['id']);
        if ($post === null || $post->status !== 'published' || $post->datePublished > $now) {
            throw new NotFound();
        }
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound();
        $view = $this->factory->make($blog, $post, $this->media->map($blog, [$post]));
        [$older, $newer] = $this->posts->neighbours($post, $now);

        return $this->pages->page(
            $blog,
            'blog::post',
            ['post' => $view, 'older' => $this->neighbour($blog, $older), 'newer' => $this->neighbour($blog, $newer)],
            $view->title,
            $view->canonicalUrl,
            $view->excerpt !== '' ? $view->excerpt : null,
            'article',
            $view->image,
        );
    }

    /** @return array{title: string, url: string}|null */
    private function neighbour(Blog $blog, ?Post $post): ?array
    {
        return $post === null ? null : ['title' => $post->title, 'url' => $this->factory->link($blog, $post)];
    }
}
