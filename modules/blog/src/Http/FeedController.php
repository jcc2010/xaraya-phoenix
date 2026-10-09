<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\Cursor;
use Xaraya\Module\Blog\Feed\FeedBuilder;
use Xaraya\Module\Blog\Feed\RssRenderer;
use Xaraya\Module\Blog\Post\PostRepository;

final class FeedController extends Controller
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly FeedBuilder $feeds,
        private readonly RssRenderer $rss,
        private readonly UrlGenerator $urls,
        private readonly Config $config,
        private readonly MediaUrls $media,
    ) {}

    /** @param array<string, string> $params */
    public function feedJson(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blog($params['handle']);
        $rows = $this->posts->page($blog->id, self::now(), self::before($request), self::PAGE_SIZE + 1);
        $page = array_slice($rows, 0, self::PAGE_SIZE);
        $next = null;
        if (count($rows) > self::PAGE_SIZE) {
            $last = $page[self::PAGE_SIZE - 1];
            $next = $this->urls->generate('blog.feed.json', [
                'handle' => $blog->handle,
                'before' => Cursor::encode($last->datePublished, $last->id),
            ], true);
        }
        $feedUrl = $this->urls->generate('blog.feed.json', ['handle' => $blog->handle], true);
        $feed = $this->feeds->feed($blog, $page, $feedUrl, $next, $this->appUrl(), $this->media->map($blog, $page));

        return CacheHeaders::apply($this->json($feed, 200, 'application/feed+json'));
    }

    /** @param array<string, string> $params */
    public function feedXml(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blog($params['handle']);
        $page = $this->posts->page($blog->id, self::now(), null, self::PAGE_SIZE);
        $self = $this->urls->generate('blog.feed.xml', ['handle' => $blog->handle], true);
        $xml = $this->rss->render($blog, $page, $self, $this->appUrl(), $this->media->map($blog, $page));

        return CacheHeaders::apply(new Response(200, ['Content-Type' => 'application/rss+xml; charset=utf-8'], $xml));
    }

    private function blog(string $handle): Blog
    {
        return $this->blogs->find($handle) ?? throw new NotFound("No blog '{$handle}'");
    }

    /** @return array{0: DateTimeImmutable, 1: string}|null */
    private static function before(ServerRequestInterface $request): ?array
    {
        parse_str($request->getUri()->getQuery(), $query);
        if (!array_key_exists('before', $query)) {
            return null;
        }
        if (!is_string($query['before'])) {
            throw new HttpException(400, 'Invalid before cursor');
        }
        try {
            return Cursor::decode($query['before']);
        } catch (InvalidArgumentException) {
            throw new HttpException(400, 'Invalid before cursor');
        }
    }

    private function appUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
