<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Xaraya\Kernel\Http\Exception\HttpException;

final class Cors implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = (new Response(204))
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                ->withHeader('Access-Control-Max-Age', '86400');
            $requested = $request->getHeaderLine('Access-Control-Request-Headers');

            return $requested === '' ? $response : $response->withHeader('Access-Control-Allow-Headers', $requested);
        }

        try {
            $response = $handler->handle($request);
        } catch (HttpException $e) {
            throw new HttpException($e->status(), $e->getMessage(), [...$e->headers(), 'Access-Control-Allow-Origin' => '*'], $e->getPrevious());
        } catch (Throwable $e) {
            // ErrorHandler masks the 5xx message and logs the wrapped exception.
            throw new HttpException(500, '', ['Access-Control-Allow-Origin' => '*'], $e);
        }

        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}
