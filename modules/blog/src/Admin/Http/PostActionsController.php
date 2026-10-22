<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Http\Paging;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Write\PostWriter;

/**
 * Delete and undelete, as POST forms only (CSRF is checked by the kernel). The user's right is decided per post
 * (404 for an unknown id, then 403 for someone else's post, before anything about it is revealed); PostWriter
 * refuses mirror posts (409) and is idempotent. The redirect returns to the list the user came from, using the
 * posted tab and page only when they are valid; they are never copied into the URL as sent.
 *
 * Deleting the post the blog has pinned clears that pin (a pin on a tombstone would hide nothing but confuse the
 * front page); undeleting does not bring the pin back.
 */
final class PostActionsController extends Controller
{
    private const FLASH_TITLE_WIDTH = 80;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly PostWriter $writer,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, string> $params */
    public function delete(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->target($params['id']);
        $deleted = $this->writer->delete($post);
        if ($blog->pinnedItemId !== null && $blog->pinnedItemId === $post->itemId) {
            $this->blogs->update($blog, ['pinned_item_id' => null], AdminPage::now());
        }

        return $this->page->done($request, $this->listUrl($request, $blog), 'Deleted “' . self::flashTitle($deleted) . '”.');
    }

    /** @param array<string, string> $params */
    public function undelete(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->target($params['id']);
        $restored = $this->writer->undelete($post);

        return $this->page->done($request, $this->listUrl($request, $blog), 'Restored “' . self::flashTitle($restored) . '”.');
    }

    /** @return array{0: Blog, 1: Post} */
    private function target(string $id): array
    {
        $post = $this->posts->find($id) ?? throw new NotFound();
        $this->access->assertDelete($post);
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound();
        PostWriter::assertNative($blog);

        return [$blog, $post];
    }

    /** The posts list of $blog with the posted tab (a known one other than the default) and page (above 1). */
    private function listUrl(ServerRequestInterface $request, Blog $blog): string
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $tab = $body['tab'] ?? null;
        $page = Paging::pageNumber($body['page'] ?? null);
        $query = [];
        if (is_string($tab) && $tab !== 'published' && in_array($tab, PostRepository::ADMIN_TABS, true)) {
            $query['tab'] = $tab;
        }
        if ($page !== null && $page > 1) {
            $query['page'] = (string) $page;
        }

        return $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle] + $query);
    }

    private static function flashTitle(Post $post): string
    {
        return mb_strimwidth($post->title, 0, self::FLASH_TITLE_WIDTH, '…');
    }
}
