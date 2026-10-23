<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Admin\TagEditor;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Support\Text;
use Xaraya\Module\Blog\Write\PostWriter;

/**
 * The tags of one blog: every tag on its non-deleted posts, by slug, with a rename and a remove form per tag. Both
 * rewrite other authors' posts, so the screen needs edit on every post (403 otherwise; the route only asks for
 * edit on some post). A mirror's tags come from its source: they are listed without forms, and a POST is the
 * writer's 409. A refused rename is an inline 422 on that tag's row; the work is TagEditor's.
 *
 * `q` filters the list by name or slug (case-insensitive substring). Every row is still rendered and the ones that
 * don't match are `hidden`, so admin-tags.js can filter as you type over the same rows; without the script the
 * GET form does the same. Rename and remove carry `q` along, and redirect back to the filtered list; a rename
 * also names the renamed tag (`tag`), whose row stays shown and is marked even when the filter no longer matches it.
 */
final class TagAdminController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly TagEditor $editor,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, string> $params */
    public function index(ServerRequestInterface $request, array $params): Page
    {
        $query = $request->getQueryParams();
        $q = $query['q'] ?? '';
        $changed = $query['tag'] ?? '';

        return $this->tagsPage($request, $this->tagBlog($params['handle']), self::filter(is_string($q) ? $q : ''), changed: is_string($changed) ? $changed : '');
    }

    /** @param array<string, string> $params */
    public function rename(ServerRequestInterface $request, array $params): ResponseInterface|Page
    {
        $blog = $this->tagBlog($params['handle']);
        PostWriter::assertNative($blog);
        $body = AdminPage::body($request);
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $slug = is_string($body['slug'] ?? null) ? trim($body['slug']) : '';
        $q = self::filter(is_string($body['q'] ?? null) ? $body['q'] : '');
        $old = $this->tagName($blog, $params['slug']);
        try {
            $changed = $this->editor->rename($blog, $params['slug'], $name, $slug);
        } catch (ValidationFailed $e) {
            $form = ['slug' => $params['slug'], 'values' => ['name' => self::scrub($name), 'slug' => self::scrub($slug)], 'errors' => $e->fields()];

            return $this->tagsPage($request, $blog, $q, $form, 422);
        }

        return $this->page->done(
            $request,
            $this->tagsUrl($blog, $q, $slug === '' ? Text::slug($name) : $slug),
            'Renamed tag “' . AdminPage::flashTitle($old) . '” to “' . AdminPage::flashTitle($name) . '” on ' . self::posts($changed) . '.',
        );
    }

    /** @param array<string, string> $params */
    public function remove(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->tagBlog($params['handle']);
        PostWriter::assertNative($blog);
        $body = AdminPage::body($request);
        $q = self::filter(is_string($body['q'] ?? null) ? $body['q'] : '');
        $old = $this->tagName($blog, $params['slug']);
        $changed = $this->editor->remove($blog, $params['slug']);

        return $this->page->done(
            $request,
            $this->tagsUrl($blog, $q),
            'Removed tag “' . AdminPage::flashTitle($old) . '” from ' . self::posts($changed) . '.',
        );
    }

    private function tagBlog(string $handle): Blog
    {
        $this->access->canEditAll() || throw new Forbidden();

        return $this->blogs->find($handle) ?? throw new NotFound("No blog '{$handle}'");
    }

    /** The listed name of the tag $slug (its smallest stored name); 404 when no live post has it. */
    private function tagName(Blog $blog, string $slug): string
    {
        foreach ($this->posts->adminTagCounts($blog->id) as $tag) {
            if ($tag['slug'] === $slug) {
                return $tag['name'];
            }
        }

        throw new NotFound("No tag '{$slug}'");
    }

    /** A filter as it is used and shown again: one trimmed line of valid UTF-8, at most 128 characters. */
    private static function filter(string $q): string
    {
        $q = trim((string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub($q, 'UTF-8')));

        return mb_substr($q, 0, 128);
    }

    /** The tags screen, filtered by $q, with the row of $changed (a slug) kept in view and jumped to. */
    private function tagsUrl(Blog $blog, string $q, string $changed = ''): string
    {
        $url = $this->urls->generate('admin.blog.tags', ['handle' => $blog->handle]);
        $query = http_build_query(array_filter(['q' => $q, 'tag' => $changed], static fn(string $v): bool => $v !== ''), '', '&', PHP_QUERY_RFC3986);

        return $url . ($query === '' ? '' : '?' . $query) . ($changed === '' ? '' : '#tag-' . rawurlencode($changed));
    }

    private static function posts(int $n): string
    {
        return $n === 1 ? '1 post' : "{$n} posts";
    }

    /** A refused value as it is shown again: invalid bytes replaced, NUL dropped, so rendering cannot fail. */
    private static function scrub(string $value): string
    {
        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    /**
     * @param array{slug: string, values: array{name: string, slug: string}, errors: array<string, string>}|null $form
     *        the refused rename, shown again on its row
     */
    private function tagsPage(ServerRequestInterface $request, Blog $blog, string $q, ?array $form = null, int $status = 200, string $changed = ''): Page
    {
        $mirror = $blog->isMirror();
        $needle = mb_strtolower($q);
        $rows = [];
        $shown = 0;
        foreach ($this->posts->adminTagCounts($blog->id) as $tag) {
            $refused = $form !== null && $form['slug'] === $tag['slug'];
            // The same test admin-tags.js makes: the filter within "name\nslug", case-insensitively.
            $match = $needle === '' || $refused || $tag['slug'] === $changed || str_contains(mb_strtolower($tag['name'] . "\n" . $tag['slug']), $needle);
            $shown += $match ? 1 : 0;
            $rows[] = [
                'hidden' => !$match,
                'changed' => $tag['slug'] === $changed,
                'name' => $tag['name'],
                'slug' => $tag['slug'],
                'count' => $tag['count'],
                'publicUrl' => $this->urls->has('blog.tag') ? $this->urls->generate('blog.tag', ['handle' => $blog->handle, 'slug' => $tag['slug']]) : null,
                'renameUrl' => $mirror ? null : $this->urls->generate('admin.blog.tag.rename', ['handle' => $blog->handle, 'slug' => $tag['slug']]),
                'values' => $refused ? $form['values'] : ['name' => $tag['name'], 'slug' => $tag['slug']],
                'errors' => $refused ? $form['errors'] : [],
                'actions' => $mirror ? [] : [[
                    'label' => 'Remove',
                    'url' => $this->urls->generate('admin.blog.tag.remove', ['handle' => $blog->handle, 'slug' => $tag['slug']]),
                    'method' => 'post',
                    'csrf' => true,
                    'fields' => $q === '' ? [] : ['q' => $q],
                ]],
            ];
        }

        return $this->page->page($request, 'blog::admin/tags', [
            'blog' => ['handle' => $blog->handle, 'title' => $blog->title],
            'mirror' => $mirror,
            'rows' => $rows,
            'q' => $q,
            'shown' => $shown,
            'clearUrl' => $this->tagsUrl($blog, ''),
            'backUrl' => $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle]),
        ], 'Tags · ' . $blog->title, $status, ['module/blog/admin-tags.js']);
    }
}
