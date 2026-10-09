<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\PostRepository;

final class PostController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $post = $this->posts->find($params['id']);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($post === null || $post->status !== 'published' || $post->datePublished > $now) {
            throw new NotFound();
        }
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound();
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');

        return CacheHeaders::apply($this->json($this->items->item($blog, $post, $appUrl), 200, 'application/json'), [$post]);
    }
}
