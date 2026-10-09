<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Module\Blog\Post\Post;

final class CacheHeaders
{
    /** @param list<Post> $posts */
    public static function apply(ResponseInterface $response, array $posts): ResponseInterface
    {
        $latest = null;
        foreach ($posts as $post) {
            $time = $post->dateModified ?? $post->datePublished;
            if ($latest === null || $time > $latest) {
                $latest = $time;
            }
        }
        $response = $response->withHeader('Cache-Control', 'public, max-age=60');

        return $latest === null
            ? $response
            : $response->withHeader('Last-Modified', $latest->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT');
    }
}
