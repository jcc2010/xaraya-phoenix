<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Module\Blog\Write\ItemValidator;

/**
 * Blog-level admin actions. "Sync now" runs a quick (non-full) sync of a mirror through the same Syncer as the
 * CLI, so the lock and the fetcher's SSRF guards apply. The route needs admin on blogs and the check is repeated
 * here; a native blog has nothing to sync (409). Every failure becomes an error flash, never a 500.
 *
 * Settings and new blogs are for administrators of blogs. A mirror's title, description, author and language are
 * overwritten by every sync, so they are shown read-only and a posted value for them is ignored; only the post URL
 * pattern and the media mode are saved. Lengths are checked here, the rest by BlogRepository; every refusal is an
 * inline 422 with the posted values, never a 500. The mode never changes here (`bin/xar blog:mode`).
 */
final class BlogAdminController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly Syncer $syncer,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
        private readonly Access $auth,
    ) {}

    public const SETTINGS = ['title', 'description_html', 'author_name', 'author_url', 'language', 'post_url_pattern', 'media'];

    /** Settings a sync overwrites on a mirror: read-only there. */
    private const SYNCED = ['title', 'description_html', 'author_name', 'author_url', 'language'];

    /** Column length limits (bytes). */
    private const LIMITS = ['title' => 255, 'author_name' => 255, 'author_url' => 1024, 'post_url_pattern' => 1024, 'language' => 16];

    private const BAD_TEXT = 'Must be valid UTF-8 without NUL characters';

    private const LABELS = ['title' => 'Title', 'author_name' => 'Author name', 'author_url' => 'Author URL', 'language' => 'Language', 'post_url_pattern' => 'Post URL pattern', 'media' => 'Media', 'handle' => 'Handle'];

    /** @param array<string, string> $params */
    public function settings(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->adminBlog($params['handle']);

        return $this->settingsPage($request, $blog, self::valuesOf($blog), [], []);
    }

    /** @param array<string, string> $params */
    public function saveSettings(ServerRequestInterface $request, array $params): ResponseInterface|Page
    {
        $blog = $this->adminBlog($params['handle']);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $values = self::valuesOf($blog);
        $editable = $blog->isMirror() ? ['post_url_pattern', 'media'] : self::SETTINGS;
        foreach ($editable as $key) {
            $values[$key] = is_string($body[$key] ?? null) ? trim($body[$key]) : $values[$key];
        }
        $errors = [];
        foreach ($editable as $key) {
            if (!self::isClean($values[$key])) {
                $errors[$key] = self::BAD_TEXT;
            }
        }
        foreach (self::LIMITS as $key => $max) {
            if (in_array($key, $editable, true) && !isset($errors[$key]) && mb_strlen($values[$key]) > $max) {
                $errors[$key] = self::LABELS[$key] . " must be at most {$max} characters";
            }
        }
        if (in_array('author_name', $editable, true) && !isset($errors['author_name']) && $values['author_name'] === '') {
            $errors['author_name'] = 'Author name is required';
        }
        if (in_array('author_url', $editable, true) && $values['author_url'] !== '' && !isset($errors['author_url']) && !ItemValidator::isHttpUrl($values['author_url'])) {
            $errors['author_url'] = 'Author URL must be an http(s) URL';
        }
        if (in_array('language', $editable, true) && !isset($errors['language']) && preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/D', $values['language']) !== 1) {
            $errors['language'] = 'Language must be a tag like en or en-GB';
        }
        $formErrors = [];
        if ($errors === []) {
            $fields = [];
            foreach ($editable as $key) {
                $fields[$key] = $values[$key] === '' && in_array($key, ['description_html', 'author_url', 'post_url_pattern'], true) ? null : $values[$key];
            }
            try {
                $this->blogs->update($blog, $fields, AdminPage::now());

                return $this->page->done($request, $this->urls->generate('admin.blog.settings', ['handle' => $blog->handle]), 'Settings saved.');
            } catch (InvalidArgumentException $e) {
                [$errors, $formErrors] = self::fieldError($e->getMessage());
            }
        }

        return $this->settingsPage($request, $blog, $values, $errors, $formErrors, 422);
    }

    public function newBlogForm(ServerRequestInterface $request): Page
    {
        $this->access->canAdminBlogs() || throw new Forbidden();

        return $this->newBlogPage($request, ['handle' => '', 'title' => ''], [], []);
    }

    public function createBlog(ServerRequestInterface $request): ResponseInterface|Page
    {
        $this->access->canAdminBlogs() || throw new Forbidden();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $values = ['handle' => '', 'title' => ''];
        foreach ($values as $key => $_) {
            $values[$key] = is_string($body[$key] ?? null) ? trim($body[$key]) : '';
        }
        $errors = [];
        foreach ($values as $key => $value) {
            if (!self::isClean($value)) {
                $errors[$key] = self::BAD_TEXT;
            }
        }
        if (!isset($errors['handle']) && preg_match('/^[a-z0-9_-]{2,32}$/D', $values['handle']) !== 1) {
            $errors['handle'] = 'Handle must match [a-z0-9_-]{2,32}';
        }
        if (isset($errors['title'])) {
            // already refused as bad text
        } elseif ($values['title'] === '') {
            $errors['title'] = 'Title is required';
        } elseif (mb_strlen($values['title']) > self::LIMITS['title']) {
            $errors['title'] = 'Title must be at most ' . self::LIMITS['title'] . ' characters';
        }
        $formErrors = [];
        if ($errors === []) {
            $author = $this->auth->user()?->displayName();
            try {
                $blog = $this->blogs->create([
                    'handle' => $values['handle'],
                    'title' => $values['title'],
                    'mode' => 'native',
                    'author_name' => $author !== null && $author !== '' ? mb_strimwidth($author, 0, self::LIMITS['author_name']) : null,
                ], AdminPage::now());

                return $this->page->done(
                    $request,
                    $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle]),
                    'Created blog “' . mb_strimwidth($blog->title, 0, 80, '…') . '”.',
                );
            } catch (InvalidArgumentException $e) {
                [$errors, $formErrors] = self::fieldError($e->getMessage());
            }
        }

        return $this->newBlogPage($request, $values, $errors, $formErrors, 422);
    }

    /** A refused value as it is shown again: invalid bytes replaced, NUL dropped, so rendering cannot fail. */
    private static function scrub(string $value): string
    {
        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    /** Text a database column can hold: valid UTF-8, no NUL. */
    private static function isClean(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8') && !str_contains($value, "\0");
    }

    private function adminBlog(string $handle): Blog
    {
        $this->access->canAdminBlogs() || throw new Forbidden();

        return $this->blogs->find($handle) ?? throw new NotFound("No blog '{$handle}'");
    }

    /** @return array<string, string> */
    private static function valuesOf(Blog $blog): array
    {
        return [
            'title' => $blog->title,
            'description_html' => $blog->descriptionHtml ?? '',
            'author_name' => $blog->authorName,
            'author_url' => $blog->authorUrl ?? '',
            'language' => $blog->language,
            'post_url_pattern' => $blog->postUrlPattern ?? '',
            'media' => $blog->media,
        ];
    }

    /**
     * A repository message as a field error where it names a field, else an error above the form.
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function fieldError(string $message): array
    {
        $fields = ['Post URL pattern' => 'post_url_pattern', 'Handle' => 'handle', 'Blog' => 'handle', 'Title' => 'title', 'Media' => 'media'];
        foreach ($fields as $prefix => $field) {
            if (str_starts_with($message, $prefix)) {
                return [[$field => $message], []];
            }
        }

        return [[], [$message]];
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param list<string> $formErrors
     */
    private function settingsPage(ServerRequestInterface $request, Blog $blog, array $values, array $errors, array $formErrors, int $status = 200): Page
    {
        return $this->page->page($request, 'blog::admin/settings', [
            'blog' => ['handle' => $blog->handle, 'title' => $blog->title, 'mode' => $blog->mode],
            'mirror' => $blog->isMirror(),
            'synced' => self::SYNCED,
            'action' => $this->urls->generate('admin.blog.settings.save', ['handle' => $blog->handle]),
            'backUrl' => $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle]),
            'media' => Blog::MEDIA,
            'values' => array_map(self::scrub(...), $values),
            'errors' => $errors,
            'formErrors' => $formErrors,
        ], 'Settings · ' . $blog->title, $status);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param list<string> $formErrors
     */
    private function newBlogPage(ServerRequestInterface $request, array $values, array $errors, array $formErrors, int $status = 200): Page
    {
        return $this->page->page($request, 'blog::admin/new-blog', [
            'action' => $this->urls->generate('admin.blog.create'),
            'values' => array_map(self::scrub(...), $values),
            'errors' => $errors,
            'formErrors' => $formErrors,
        ], 'New blog', $status);
    }

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
