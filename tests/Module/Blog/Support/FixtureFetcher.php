<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;

final class FixtureFetcher implements HttpFetcher
{
    /** @var list<array{url: string, etag: ?string}> */
    public array $requests = [];

    /** @param array<string, FetchResult|callable(?string): FetchResult> $responses */
    public function __construct(private array $responses = []) {}

    public function on(string $url, FetchResult|callable $response): self
    {
        $this->responses[$url] = $response;

        return $this;
    }

    public function get(string $url, ?string $etag = null): FetchResult
    {
        $this->requests[] = ['url' => $url, 'etag' => $etag];
        $response = $this->responses[$url] ?? new FetchResult(404, '');

        return $response instanceof FetchResult ? $response : $response($etag);
    }

    /** @param array<string, mixed> $feed */
    public static function json(array $feed, ?string $etag = null): FetchResult
    {
        return new FetchResult(200, json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $etag, 'application/feed+json');
    }
}
