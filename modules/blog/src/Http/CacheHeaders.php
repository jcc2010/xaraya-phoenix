<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Headers for feeds and single items.
 *
 * Deliberately no Last-Modified: a time computed from one page's posts cannot reflect tombstones or
 * blog metadata changes, so If-Modified-Since would yield stale 304s. The body-hash ETag added by the
 * conditional middleware is always correct.
 */
final class CacheHeaders
{
    public static function apply(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Cache-Control', 'public, max-age=60')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
