<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Admin\PostForm;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;
use Xaraya\Module\Blog\Write\PostWriter;

/**
 * The new post form. Posts are written only through PostWriter, which sets the acting user as the owner and
 * refuses mirror blogs (409); the form is refused the same way before it is shown. SecureHtml drops
 * ValidationFailed's fields, so a failed save is rendered here as a 422 form with inline errors.
 */
final class PostFormController extends Controller
{
    private const FLASH_TITLE_WIDTH = 80;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostWriter $writer,
        private readonly AdminAccess $access,
        private readonly AdminPage $page,
        private readonly UrlGenerator $urls,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function newForm(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->nativeBlog($params['handle']);

        return $this->form($request, $blog, PostForm::blank(), [], []);
    }

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface|Page
    {
        $blog = $this->nativeBlog($params['handle']);
        $body = $request->getParsedBody();
        $form = PostForm::fromInput(is_array($body) ? $body : []);
        $timezone = $this->timezone();

        $errors = $form->check($timezone);
        if ($errors !== []) {
            return $this->form($request, $blog, $form, $errors, [], 422);
        }
        try {
            $post = $this->writer->create($blog, $form->toItem($timezone));
        } catch (ValidationFailed $e) {
            [$errors, $formErrors] = self::mapErrors($e->fields());

            return $this->form($request, $blog, $form, $errors, $formErrors, 422);
        }
        // Pinning is a blog-wide choice, so only users who may edit the blog get it; anyone else's "pinned" is ignored.
        if ($form->pinned && $this->access->canPin()) {
            $this->blogs->update($blog, ['pinned_item_id' => $post->itemId], AdminPage::now());
        }
        $scheduled = $post->datePublished > AdminPage::now();
        $location = $this->urls->generate('admin.blog.posts', ['handle' => $blog->handle] + ($scheduled ? ['tab' => 'scheduled'] : []));

        return $this->page->done($request, $location, 'Created “' . mb_strimwidth($post->title, 0, self::FLASH_TITLE_WIDTH, '…') . '”.');
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
    private function form(ServerRequestInterface $request, Blog $blog, PostForm $form, array $errors, array $formErrors, int $status = 200): Page
    {
        return $this->page->page($request, 'blog::admin/form', [
            'blog' => ['handle' => $blog->handle, 'title' => $blog->title],
            'heading' => 'New post',
            'action' => $this->urls->generate('admin.blog.post.create', ['handle' => $blog->handle]),
            'values' => $form->values(),
            'errors' => $errors,
            'formErrors' => $formErrors,
            'kinds' => array_map(static fn(string $kind): array => ['value' => $kind, 'label' => ucfirst($kind)], JsonFeedAdapter::KINDS),
            'timezone' => $this->timezone(),
            'canPin' => $this->access->canPin(),
        ], 'New post · ' . $blog->title, $status);
    }

    /** The site's time zone; never taken from the request. */
    private function timezone(): string
    {
        return (string) $this->config->get('app.timezone', 'UTC');
    }
}
