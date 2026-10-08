<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

        return $handler->handle($request)->withHeader('Access-Control-Allow-Origin', '*');
    }
}
