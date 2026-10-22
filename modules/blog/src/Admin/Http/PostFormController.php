<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Admin\PostForm;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Http\MediaUrls;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;
use Xaraya\Module\Blog\View\PostView;
use Xaraya\Module\Blog\View\PostViewFactory;
use Xaraya\Module\Blog\Write\PostWriter;

/**
 * The new and edit post forms. Posts are written only through PostWriter, which sets the acting user as the owner
 * and refuses mirror blogs (409); the forms are refused the same way before they are shown. Editing goes through
 * PostWriter::patch(), so fields the form does not show survive, and AdminAccess decides per post (the post is the
 * item, so an "own" grant covers only the user's posts). SecureHtml drops ValidationFailed's fields, so a failed
 * save is rendered here as a 422 form with inline errors: the form's own checks and PostWriter's in one list.
 * A Preview submit runs PostWriter's preview methods: nothing is written, and the sanitised HTML is not cached.
 */
final class PostFormController extends Controller
{
    private const FLASH_TITLE_WIDTH = 80;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly PostWriter $writer,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
        private readonly Config $config,
        private readonly PostViewFactory $views,
        private readonly MediaUrls $media,
    ) {}

    /** @param array<string, string> $params */
    public function newForm(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->nativeBlog($params['handle']);

        return $this->newPostForm($request, $blog, PostForm::blank(), [], [], null);
    }

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface|Page
    {
        $blog = $this->nativeBlog($params['handle']);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $form = PostForm::fromInput($body);
        $timezone = $this->timezone();
        $preview = self::isPreview($body);

        $errors = $form->check($timezone);
        $formErrors = [];
        $post = null;
        try {
            // With the form's own errors, PostWriter still checks the item (writing nothing), so all are shown at once.
            $post = $errors === [] && !$preview
                ? $this->writer->create($blog, $form->toItem($timezone))
                : $this->writer->previewCreate($blog, $form->toItem($timezone));
        } catch (ValidationFailed $e) {
            [$itemErrors, $formErrors] = self::mapErrors($e->fields());
            $errors += $itemErrors; // the form's message wins for a field both report
        }
        if ($errors !== [] || $formErrors !== [] || $post === null) {
            return $this->newPostForm($request, $blog, $form, $errors, $formErrors, null, 422);
        }
        if ($preview) {
            return $this->newPostForm($request, $blog, $form, [], [], $this->preview($blog, $post));
        }
        // Pinning is a blog-wide choice, so only users who may edit the blog get it; anyone else's "pinned" is ignored.
        if ($form->pinned && $this->access->canPin()) {
            $this->blogs->update($blog, ['pinned_item_id' => $post->itemId], AdminPage::now());
        }
        $scheduled = $post->datePublished > AdminPage::now();
        $location = $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle] + ($scheduled ? ['tab' => 'scheduled'] : []));

        return $this->page->done($request, $location, 'Created “' . self::flashTitle($post) . '”.');
    }

    /** @param array<string, string> $params */
    public function edit(ServerRequestInterface $request, array $params): Page
    {
        [$blog, $post] = $this->editable($params['id']);

        return $this->editForm($request, $blog, $post, PostForm::fromPost($post, $blog, $this->timezone()), [], [], null);
    }

    /** @param array<string, string> $params */
    public function update(ServerRequestInterface $request, array $params): ResponseInterface|Page
    {
        [$blog, $post] = $this->editable($params['id']);
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $form = PostForm::fromInput($body);
        $timezone = $this->timezone();
        $preview = self::isPreview($body);

        $errors = $form->check($timezone);
        $formErrors = [];
        $result = null;
        try {
            $patch = $form->toPatch($post, $timezone);
            $result = $errors === [] && !$preview ? $this->writer->patch($post, $patch) : $this->writer->previewPatch($post, $patch);
        } catch (ValidationFailed $e) {
            [$itemErrors, $formErrors] = self::mapErrors($e->fields());
            $errors += $itemErrors; // the form's message wins for a field both report
        }
        if ($errors !== [] || $formErrors !== [] || $result === null) {
            return $this->editForm($request, $blog, $post, $form, $errors, $formErrors, null, 422);
        }
        if ($preview) {
            return $this->editForm($request, $blog, $post, $form, [], [], $this->preview($blog, $result));
        }
        $pinChanged = $this->access->canPin() && $this->savePin($blog, $post, $form->pinned);
        // PostWriter::patch() hands back the very same object when the patch changed nothing.
        $message = $result === $post && !$pinChanged ? 'No changes.' : 'Saved “' . self::flashTitle($result) . '”.';

        return $this->page->done($request, $this->urls->generate('admin.blog.post.edit', ['id' => $post->id]), $message);
    }

    /**
     * The post behind an edit URL, once the user may edit it: 404 for an unknown id, 403 (before anything about
     * the post is revealed) for someone else's post without the grant, 409 for a mirror's post or a deleted one.
     *
     * @return array{0: Blog, 1: Post}
     */
    private function editable(string $id): array
    {
        $post = $this->posts->find($id) ?? throw new NotFound();
        $this->access->assertEdit($post);
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound();
        PostWriter::assertNative($blog);
        if ($post->status === 'deleted') {
            throw new HttpException(409, 'The post is deleted; undelete it first');
        }

        return [$blog, $post];
    }

    /**
     * Pins or unpins $post as the form asks; true when that changed the blog. Unpinning clears the blog's pin only
     * when it is this post's, so saving one post never unpins another.
     */
    private function savePin(Blog $blog, Post $post, bool $pinned): bool
    {
        $current = ($this->blogs->findById($blog->id) ?? $blog)->pinnedItemId;
        if ($pinned && $current !== $post->itemId) {
            $this->blogs->update($blog, ['pinned_item_id' => $post->itemId], AdminPage::now());

            return true;
        }
        if (!$pinned && $current === $post->itemId) {
            $this->blogs->update($blog, ['pinned_item_id' => null], AdminPage::now());

            return true;
        }

        return false;
    }

    /** The post as its page would show it, sanitised without the cache (the HTML is not saved). */
    private function preview(Blog $blog, Post $post): PostView
    {
        return $this->views->make($blog, $post, $this->media->map($blog, [$post]), cached: false);
    }

    /** @param array<array-key, mixed> $body */
    private static function isPreview(array $body): bool
    {
        return is_string($body['preview'] ?? null) && $body['preview'] !== '';
    }

    private static function flashTitle(Post $post): string
    {
        return mb_strimwidth($post->title, 0, self::FLASH_TITLE_WIDTH, '…');
    }

    /** A native blog; a mirror's posts come from its source, so it has no post form (409). */
    private function nativeBlog(string $handle): Blog
    {
        $blog = $this->blogs->find($handle) ?? throw new NotFound("No blog '{$handle}'");
        PostWriter::assertNative($blog);

        return $blog;
    }

    /**
     * Splits PostWriter's item errors into form-field errors (the first per field) and errors shown above the form.
     *
     * @param array<string, string> $fields
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function mapErrors(array $fields): array
    {
        $errors = [];
        $formErrors = [];
        foreach ($fields as $key => $message) {
            $field = PostForm::field($key);
            if ($field === null) {
                $formErrors[] = $message;
            } else {
                $errors[$field] ??= $message;
            }
        }

        return [$errors, $formErrors];
    }

    /**
     * @param array<string, string> $errors form field => message
     * @param list<string> $formErrors
     */
    private function newPostForm(ServerRequestInterface $request, Blog $blog, PostForm $form, array $errors, array $formErrors, ?PostView $preview, int $status = 200): Page
    {
        $action = $this->urls->generate('admin.blog.post.create', ['handle' => $blog->handle]);

        return $this->form($request, $blog, 'New post', $action, $form, $errors, $formErrors, $preview, $status);
    }

    /**
     * @param array<string, string> $errors form field => message
     * @param list<string> $formErrors
     */
    private function editForm(ServerRequestInterface $request, Blog $blog, Post $post, PostForm $form, array $errors, array $formErrors, ?PostView $preview, int $status = 200): Page
    {
        $action = $this->urls->generate('admin.blog.post.update', ['id' => $post->id]);

        return $this->form($request, $blog, 'Edit post', $action, $form, $errors, $formErrors, $preview, $status);
    }

    /**
     * @param array<string, string> $errors form field => message
     * @param list<string> $formErrors
     */
    private function form(ServerRequestInterface $request, Blog $blog, string $heading, string $action, PostForm $form, array $errors, array $formErrors, ?PostView $preview, int $status): Page
    {
        return $this->page->page($request, 'blog::admin/form', [
            'blog' => ['handle' => $blog->handle, 'title' => $blog->title],
            'heading' => $heading,
            'action' => $action,
            'values' => $form->values(),
            'errors' => $errors,
            'formErrors' => $formErrors,
            'kinds' => array_map(static fn(string $kind): array => ['value' => $kind, 'label' => ucfirst($kind)], JsonFeedAdapter::KINDS),
            'timezone' => $this->timezone(),
            'canPin' => $this->access->canPin(),
            'preview' => $preview,
        ], $heading . ' · ' . $blog->title, $status);
    }

    /** The site's time zone; never taken from the request. */
    private function timezone(): string
    {
        return (string) $this->config->get('app.timezone', 'UTC');
    }
}
