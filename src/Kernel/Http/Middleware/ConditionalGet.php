<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ConditionalGet implements MiddlewareInterface
{
    private const KEEP = ['ETag', 'Last-Modified', 'Cache-Control', 'Access-Control-Allow-Origin', 'Vary'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return $response;
        }
        if (!$response->hasHeader('ETag')) {
            $response = $response->withHeader('ETag', 'W/"' . sha1((string) $response->getBody()) . '"');
        }
        if (!$this->notModified($request, $response)) {
            return $response;
        }
        $notModified = new Response(304);
        foreach (self::KEEP as $header) {
            if ($response->hasHeader($header)) {
                $notModified = $notModified->withHeader($header, $response->getHeaderLine($header));
            }
        }

        return $notModified;
    }

    private function notModified(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '') {
            $etag = self::weak($response->getHeaderLine('ETag'));
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || self::weak($candidate) === $etag) {
                    return true;
                }
            }

            return false;
        }
        $since = strtotime($request->getHeaderLine('If-Modified-Since'));
        $modified = strtotime($response->getHeaderLine('Last-Modified'));

        return $since !== false && $modified !== false
            && $request->getHeaderLine('If-Modified-Since') !== '' && $response->getHeaderLine('Last-Modified') !== ''
            && $modified <= $since;
    }

    private static function weak(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }
}
