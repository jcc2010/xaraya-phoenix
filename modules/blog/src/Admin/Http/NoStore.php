<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Http\Exception\HttpException;

/**
 * Route middleware "noStore": admin pages are one user's, so every response is Cache-Control: private, no-store,
 * including the error pages. A thrown HttpException is rethrown carrying the header; ErrorHandler copies it onto
 * the page (and sets the same value itself for a signed-in request, so the two never disagree).
 */
final class NoStore implements MiddlewareInterface
{
    private const VALUE = 'private, no-store';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (HttpException $e) {
            throw new HttpException($e->status(), $e->getMessage(), [...$e->headers(), 'Cache-Control' => self::VALUE], $e->getPrevious());
        }

        return $response->withHeader('Cache-Control', self::VALUE);
    }
}
