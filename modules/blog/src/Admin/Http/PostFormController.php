<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Media\MediaUploader;
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
 *
 * The form takes one image (the multipart field "file", images only) through MediaUploader, before the form is
 * checked: a refused file is the field error "file", and a stored one sets `image` and `_athenana.images` (on edit,
 * replacing any images the post had; without an upload they are kept unless "remove_image" is ticked). A form that
 * is shown again (422 or preview) carries the upload as "upload_id", which is taken back only for an image of this
 * blog. Preview never stores a file: the file is ignored, with a notice.
 */
final class PostFormController extends Controller
{
    private const FLASH_TITLE_WIDTH = 80;

    private const PREVIEW_NOTICE = 'Images are uploaded when you save, not on Preview: choose the file again before saving.';

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
        private readonly MediaUploader $uploader,
        private readonly MediaStore $store,
        private readonly LoggerInterface $logger,
    ) {}

    /** @param array<string, string> $params */
    public function newForm(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->nativeBlog($params['handle']);

        return $this->newPostForm($request, $blog, PostForm::blank(), [], [], null, self::noUpload());
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
        $upload = $this->upload($request, $blog, $form, $preview);

        $errors = $form->check($timezone) + ($upload['error'] === null ? [] : ['file' => $upload['error']]);
        $item = $form->toItem($timezone);
        if ($upload['row'] !== null) {
            $item = ['image' => $this->uploader->url($upload['row'])] + $item;
            $item['_athenana']['images'] = $this->images($upload['row']);
        }
        $formErrors = [];
        $post = null;
        try {
            // With the form's own errors, PostWriter still checks the item (writing nothing), so all are shown at once.
            $post = $errors === [] && !$preview ? $this->writer->create($blog, $item) : $this->writer->previewCreate($blog, $item);
        } catch (ValidationFailed $e) {
            [$itemErrors, $formErrors] = self::mapErrors($e->fields());
            $errors += $itemErrors; // the form's message wins for a field both report
        }
        if ($errors !== [] || $formErrors !== [] || $post === null) {
            return $this->newPostForm($request, $blog, $form, $errors, $formErrors, null, $upload, 422);
        }
        if ($preview) {
            return $this->newPostForm($request, $blog, $form, [], [], $this->preview($blog, $post), $upload);
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

        return $this->editForm($request, $blog, $post, PostForm::fromPost($post, $blog, $this->timezone()), [], [], null, self::noUpload());
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
        $upload = $this->upload($request, $blog, $form, $preview);

        $errors = $form->check($timezone) + ($upload['error'] === null ? [] : ['file' => $upload['error']]);
        $patch = $form->toPatch($post, $timezone);
        if ($upload['row'] !== null) {
            $patch['image'] = $this->uploader->url($upload['row']);
            $patch['_athenana']['images'] = $this->images($upload['row']); // a list: it replaces the post's images
        } elseif ($form->removeImage) {
            $patch['image'] = null;
            $patch['_athenana']['images'] = null;
        }
        $formErrors = [];
        $result = null;
        try {
            $result = $errors === [] && !$preview ? $this->writer->patch($post, $patch) : $this->writer->previewPatch($post, $patch);
        } catch (ValidationFailed $e) {
            [$itemErrors, $formErrors] = self::mapErrors($e->fields());
            $errors += $itemErrors; // the form's message wins for a field both report
        }
        if ($errors !== [] || $formErrors !== [] || $result === null) {
            return $this->editForm($request, $blog, $post, $form, $errors, $formErrors, null, $upload, 422);
        }
        if ($preview) {
            return $this->editForm($request, $blog, $post, $form, [], [], $this->preview($blog, $result), $upload);
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

    /**
     * The image this submit brings: a new upload (stored now, images only), else the earlier upload "upload_id"
     * names when it is an image of this blog. A refused file is the error; on Preview a file is ignored (notice).
     *
     * @return array{row: ?array<string, mixed>, error: ?string, notice: ?string}
     */
    private function upload(ServerRequestInterface $request, Blog $blog, PostForm $form, bool $preview): array
    {
        $file = $request->getUploadedFiles()[MediaUploader::FIELD] ?? null;
        $sent = $file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE;
        $error = null;
        if ($sent && !$preview) {
            try {
                return ['row' => $this->uploader->upload($blog, $request, AdminPage::now(), MediaUploader::IMAGE_TYPES), 'error' => null, 'notice' => null];
            } catch (HttpException $e) {
                if ($e->status() < 400 || $e->status() >= 500) {
                    throw $e;
                }
                $error = $e->getMessage();
            } catch (RuntimeException $e) {
                // PHP could not take the file (no temp dir, disk full, an extension): tell the user, log the fault.
                // A failure after PHP took the file (writing it to the media directory) stays a server error.
                if ($file->getError() === UPLOAD_ERR_OK) {
                    throw $e;
                }
                $this->logger->error('A post form upload to {blog} failed: {reason}', ['blog' => $blog->handle, 'reason' => $e->getMessage()]);
                $error = 'The upload failed; try again';
            }
        }

        return ['row' => $this->earlierUpload($blog, $form->uploadId), 'error' => $error, 'notice' => $sent && $preview ? self::PREVIEW_NOTICE : null];
    }

    /**
     * The media row of an earlier upload, only when it is an image of $blog: an id from another blog, or of audio,
     * is ignored.
     *
     * @return array<string, mixed>|null
     */
    private function earlierUpload(Blog $blog, string $id): ?array
    {
        $row = $id === '' ? null : $this->store->find($id);

        return $row !== null && (int) $row['blog_id'] === $blog->id && in_array((string) $row['mime'], MediaUploader::IMAGE_TYPES, true) ? $row : null;
    }

    /**
     * The `_athenana.images` list of an uploaded image.
     *
     * @param array<string, mixed> $row
     * @return list<array{url: string, width: ?int, height: ?int}>
     */
    private function images(array $row): array
    {
        $size = static fn(mixed $value): ?int => $value === null ? null : (int) $value;

        return [['url' => $this->uploader->url($row), 'width' => $size($row['width']), 'height' => $size($row['height'])]];
    }

    /** @return array{row: ?array<string, mixed>, error: ?string, notice: ?string} */
    private static function noUpload(): array
    {
        return ['row' => null, 'error' => null, 'notice' => null];
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
     * @param array{row: ?array<string, mixed>, error: ?string, notice: ?string} $upload
     */
    private function newPostForm(ServerRequestInterface $request, Blog $blog, PostForm $form, array $errors, array $formErrors, ?PostView $preview, array $upload, int $status = 200): Page
    {
        $action = $this->urls->generate('admin.blog.post.create', ['handle' => $blog->handle]);

        return $this->form($request, $blog, 'New post', $action, $form, $errors, $formErrors, $preview, $this->shownImage($upload, null), $upload['notice'], $status);
    }

    /**
     * @param array<string, string> $errors form field => message
     * @param list<string> $formErrors
     * @param array{row: ?array<string, mixed>, error: ?string, notice: ?string} $upload
     */
    private function editForm(ServerRequestInterface $request, Blog $blog, Post $post, PostForm $form, array $errors, array $formErrors, ?PostView $preview, array $upload, int $status = 200): Page
    {
        $action = $this->urls->generate('admin.blog.post.update', ['id' => $post->id]);

        return $this->form($request, $blog, 'Edit post', $action, $form, $errors, $formErrors, $preview, $this->shownImage($upload, $post), $upload['notice'], $status);
    }

    /**
     * The image the form shows: the upload it carries, else the post's current image. "removable" (the "remove
     * image" box) is for a post that has an image, whatever is shown.
     *
     * @param array{row: ?array<string, mixed>, error: ?string, notice: ?string} $upload
     * @return array{url: string, uploadId: ?string, removable: bool}|null
     */
    private function shownImage(array $upload, ?Post $post): ?array
    {
        $current = null;
        if ($post !== null) {
            $images = is_array($post->doc['_athenana']['images'] ?? null) ? $post->doc['_athenana']['images'] : [];
            $current = $post->image ?? (is_array($images[0] ?? null) && is_string($images[0]['url'] ?? null) ? $images[0]['url'] : null);
        }
        if ($upload['row'] !== null) {
            return ['url' => $this->uploader->url($upload['row']), 'uploadId' => (string) $upload['row']['id'], 'removable' => $current !== null];
        }

        return $current === null ? null : ['url' => $current, 'uploadId' => null, 'removable' => true];
    }

    /**
     * @param array<string, string> $errors form field => message
     * @param list<string> $formErrors
     * @param array{url: string, uploadId: ?string, removable: bool}|null $image
     */
    private function form(ServerRequestInterface $request, Blog $blog, string $heading, string $action, PostForm $form, array $errors, array $formErrors, ?PostView $preview, ?array $image, ?string $fileNotice, int $status): Page
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
            'image' => $image,
            'fileNotice' => $fileNotice,
            'imageTypes' => implode(',', MediaUploader::IMAGE_TYPES),
        ], $heading . ' · ' . $blog->title, $status);
    }

    /** The site's time zone; never taken from the request. */
    private function timezone(): string
    {
        return (string) $this->config->get('app.timezone', 'UTC');
    }
}
