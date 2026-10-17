<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Write\PostWriter;

/**
 * The native JSON API (spec §5.3). Every route runs behind `token:blog:write`. Items are served as stored (no
 * local-media rewriting) with `_phoenix.status` = published, scheduled or deleted, which the writer ignores on input.
 */
final class PostApiController extends Controller
{
    /** PATCH takes a merge patch, or a plain JSON (Feed) object read as one. */
    private const PATCH_TYPES = ['application/merge-patch+json', 'application/json', 'application/feed+json'];

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly PostWriter $writer,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blogs->find($params['handle']) ?? throw new NotFound("Unknown blog '{$params['handle']}'");
        ApiAccess::allow($request, $blog);
        $post = $this->writer->create($blog, JsonBody::decode($request));

        return $this->item($blog, $post, 201)->withHeader('Location', '/api/posts/' . $post->id);
    }

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);

        return $this->item($blog, $post);
    }

    /** @param array<string, string> $params */
    public function replace(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);

        return $this->item($blog, $this->writer->replace($post, JsonBody::decode($request)));
    }

    /** @param array<string, string> $params */
    public function patch(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);
        $partial = JsonBody::decode($request, self::PATCH_TYPES);

        return $this->item($blog, $this->writer->patch($post, $partial));
    }

    /** @param array<string, string> $params */
    public function delete(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [, $post] = $this->load($request, $params['id']);
        $this->writer->delete($post);

        return new Response(204, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $params */
    public function undelete(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);

        return $this->item($blog, $this->writer->undelete($post));
    }

    /** @return array{0: Blog, 1: Post} */
    private function load(ServerRequestInterface $request, string $id): array
    {
        $post = $this->posts->find($id) ?? throw new NotFound('Unknown post');
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound('Unknown post');
        // The {id} route has no {handle}, so the token middleware cannot check a blog pin: check it here.
        ApiAccess::allow($request, $blog);

        return [$blog, $post];
    }

    private function item(Blog $blog, Post $post, int $status = 200): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $state = $post->status === 'deleted' ? 'deleted' : ($post->datePublished > $now ? 'scheduled' : 'published');
        $item = $this->items->item($blog, $post, rtrim((string) $this->config->get('app.url', ''), '/')) + ['_phoenix' => ['status' => $state]];

        return $this->json($item, $status)->withHeader('Cache-Control', 'no-store');
    }
}
