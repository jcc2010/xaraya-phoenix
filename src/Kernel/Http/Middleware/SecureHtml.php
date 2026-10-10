<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Xaraya\Kernel\Http\Exception\HttpException;

/** Security headers for HTML routes (alias "secureHtml"); headers a route sets itself are kept. */
final class SecureHtml implements MiddlewareInterface
{
    public const HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self'; script-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (HttpException $e) {
            // ErrorHandler renders the page; it copies these headers onto it.
            throw new HttpException($e->status(), $e->getMessage(), [...self::HEADERS, ...$e->headers()], $e->getPrevious());
        } catch (Throwable $e) {
            // ErrorHandler masks the 5xx message and logs the wrapped exception.
            throw new HttpException(500, '', self::HEADERS, $e);
        }
        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
