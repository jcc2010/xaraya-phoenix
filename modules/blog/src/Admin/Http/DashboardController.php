<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;

/** Every blog with its post counts. The route needs edit on some post; grants are not per blog. */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
    ) {}

    public function index(ServerRequestInterface $request): Page
    {
        $now = AdminPage::now();
        $blogs = [];
        foreach ($this->blogs->all() as $blog) {
            $blogs[] = [
                'handle' => $blog->handle,
                'title' => $blog->title,
                'mode' => $blog->mode,
                'counts' => $this->posts->adminCounts($blog->id, $now),
                'publicUrl' => $this->urls->generate('blog.home', ['handle' => $blog->handle]),
                'postsUrl' => $this->blogUrl('admin.blog.posts', $blog),
                // Mirror blogs are written by their sync, never by hand.
                'newPostUrl' => $blog->isMirror() || !$this->access->canCreate() ? null : $this->blogUrl('admin.blog.post.new', $blog),
                'settingsUrl' => $this->access->canAdminBlogs() ? $this->blogUrl('admin.blog.settings', $blog) : null,
            ];
        }

        return $this->page->page($request, 'blog::admin/dashboard', [
            'blogs' => $blogs,
            'newBlogUrl' => $this->access->canAdminBlogs() && $this->urls->has('admin.blog.new') ? $this->urls->generate('admin.blog.new') : null,
        ], 'Blog admin');
    }

    /** The URL of a per-blog admin route; null until that route exists. */
    private function blogUrl(string $route, Blog $blog): ?string
    {
        return $this->urls->has($route) ? $this->urls->generate($route, ['handle' => $blog->handle]) : null;
    }
}
