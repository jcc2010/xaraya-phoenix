<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Http\Paging;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;

/**
 * The posts of one blog by status tab, with a title search and 20 per page. Everyone with edit on some post
 * sees the list; scheduled and deleted posts are private, so without edit on every post those tabs (and their
 * counts) cover only the user's own posts. Row actions appear only where AdminAccess allows them for that post.
 */
final class PostListController extends Controller
{
    public const PAGE_SIZE = 20;
    private const MAX_QUERY_LENGTH = 200;

    /** @var array<string, string> owner id => handle */
    private array $handles = [];

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly UserRepository $users,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function index(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->blogs->find($params['handle']) ?? throw new NotFound("No blog '{$params['handle']}'");
        $query = Paging::query($request);
        $tab = $query['tab'] ?? 'published';
        if (!is_string($tab) || !in_array($tab, PostRepository::ADMIN_TABS, true)) {
            throw new HttpException(400, 'Invalid tab');
        }
        $q = $query['q'] ?? '';
        if (!is_string($q) || mb_strlen($q) > self::MAX_QUERY_LENGTH) {
            throw new HttpException(400, 'Invalid search query');
        }
        $q = trim(mb_scrub($q, 'UTF-8'));
        $pageNo = Paging::page($request);

        $now = AdminPage::now();
        $scope = $this->access->privateScope();
        $counts = $this->posts->adminCounts($blog->id, $now, $scope);
        $found = $this->posts->adminPage($blog->id, $tab, $q, $now, self::PAGE_SIZE, ($pageNo - 1) * self::PAGE_SIZE, $scope);

        $url = fn(string $tabName, int $n): string => $this->listUrl($blog, $tabName, $q, $n);
        $tabs = [];
        foreach (PostRepository::ADMIN_TABS as $name) {
            $tabs[] = [
                'name' => $name,
                'label' => ucfirst($name),
                'count' => $counts[$name],
                'url' => $url($name, 1),
                'current' => $name === $tab,
            ];
        }
        $zone = new DateTimeZone((string) $this->config->get('app.timezone', 'UTC'));
        $rows = [];
        foreach ($found['posts'] as $post) {
            $rows[] = [
                'id' => $post->id,
                'title' => $post->title,
                'kind' => $post->kind,
                'dateIso' => $post->datePublished->format(DATE_ATOM),
                'dateDisplay' => $post->datePublished->setTimezone($zone)->format('Y-m-d H:i'),
                'status' => $post->status === 'deleted' ? 'deleted' : ($post->datePublished > $now ? 'scheduled' : 'published'),
                'owner' => $this->owner($post),
                'tags' => array_map(static fn(array $tag): string => $tag['name'], $post->tags),
                'actions' => $this->rowActions($blog, $post, $tab, $pageNo),
            ];
        }

        return $this->page->page($request, 'blog::admin/posts', [
            'blog' => ['handle' => $blog->handle, 'title' => $blog->title, 'mode' => $blog->mode, 'sourceUrl' => $blog->sourceUrl],
            'tabs' => $tabs,
            'q' => $q,
            'tab' => $tab,
            'rows' => $rows,
            'pager' => [
                'prev' => $pageNo > 1 ? $url($tab, $pageNo - 1) : null,
                'next' => $pageNo * self::PAGE_SIZE < $found['total'] ? $url($tab, $pageNo + 1) : null,
                'prevLabel' => 'Previous page',
                'nextLabel' => 'Next page',
            ],
            'actions' => $this->headerActions($blog),
        ], 'Posts · ' . $blog->title);
    }

    /**
     * What may be done to one row: edit, and delete or undelete, each only when AdminAccess allows it for this
     * very post. Mirror posts are written by sync, so they get none. An action whose route does not exist is left out.
     *
     * @return list<array{label: string, url: string, method: 'get'|'post', csrf: bool, fields: array<string, string>}>
     */
    public function rowActions(Blog $blog, Post $post, string $tab, int $page): array
    {
        if ($blog->isMirror()) {
            return [];
        }
        $actions = [];
        $deleted = $post->status === 'deleted';
        if (!$deleted && $this->access->canEdit($post) && $this->urls->has('admin.blog.post.edit')) {
            $actions[] = ['label' => 'Edit', 'url' => $this->urls->generate('admin.blog.post.edit', ['id' => $post->id]), 'method' => 'get', 'csrf' => false, 'fields' => []];
        }
        $route = $deleted ? 'admin.blog.post.undelete' : 'admin.blog.post.delete';
        if ($this->access->canDelete($post) && $this->urls->has($route)) {
            $actions[] = [
                'label' => $deleted ? 'Undelete' : 'Delete',
                'url' => $this->urls->generate($route, ['id' => $post->id]),
                'method' => 'post',
                'csrf' => true,
                'fields' => ['tab' => $tab, 'page' => (string) $page],
            ];
        }

        return $actions;
    }

    /**
     * The links above the list: a new post, unless the blog is a mirror or the user may not add posts.
     *
     * @return list<array{label: string, url: string, method: 'get'|'post', csrf: bool, fields: array<string, string>}>
     */
    public function headerActions(Blog $blog): array
    {
        if ($blog->isMirror() || !$this->access->canCreate() || !$this->urls->has('admin.blog.post.new')) {
            return [];
        }

        return [['label' => 'New post', 'url' => $this->urls->generate('admin.blog.post.new', ['handle' => $blog->handle]), 'method' => 'get', 'csrf' => false, 'fields' => []]];
    }

    private function listUrl(Blog $blog, string $tab, string $q, int $page): string
    {
        return $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle]
            + ($tab === 'published' ? [] : ['tab' => $tab])
            + ($q === '' ? [] : ['q' => $q])
            + ($page > 1 ? ['page' => (string) $page] : []));
    }

    private function owner(Post $post): string
    {
        if ($post->ownerId === null) {
            return '—';
        }

        if (!isset($this->handles[$post->ownerId])) {
            $user = $this->users->find($post->ownerId);
            $this->handles[$post->ownerId] = $user === null ? '—' : $user->handle;
        }

        return $this->handles[$post->ownerId];
    }
}
