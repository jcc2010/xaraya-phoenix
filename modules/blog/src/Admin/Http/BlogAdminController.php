<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Sync\Syncer;

/**
 * Blog-level admin actions. "Sync now" runs a quick (non-full) sync of a mirror through the same Syncer as the
 * CLI, so the lock and the fetcher's SSRF guards apply. The route needs admin on blogs and the check is repeated
 * here; a native blog has nothing to sync (409). Every failure becomes an error flash, never a 500.
 */
final class BlogAdminController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly Syncer $syncer,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, string> $params */
    public function sync(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $this->access->canAdminBlogs() || throw new Forbidden();
        $blog = $this->blogs->find($params['handle']) ?? throw new NotFound("No blog '{$params['handle']}'");
        if (!$blog->isMirror()) {
            throw new HttpException(409, "Blog '{$blog->handle}' is native; there is nothing to sync");
        }
        $location = $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle]);
        try {
            $report = $this->syncer->sync($blog, false, AdminPage::now());
        } catch (RuntimeException $e) {
            return $this->page->done($request, $location, 'Sync failed: ' . $e->getMessage(), 'error');
        }

        return $this->page->done($request, $location, $report->summary(), $report->ok() ? 'status' : 'error');
    }
}
