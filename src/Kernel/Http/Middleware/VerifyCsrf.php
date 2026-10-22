<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Routing\RouteMatch;

/**
 * Global middleware: a request other than GET, HEAD or OPTIONS must carry the session's synchronizer token, as the
 * form field "_csrf" (the csrf() helper) or the X-CSRF-Token header, unless its route is marked "csrf:off"
 * (token-authenticated APIs). A missing or wrong token is a 403.
 */
final class VerifyCsrf implements MiddlewareInterface
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true) || self::exempt($request)) {
            return $handler->handle($request);
        }
        $session = $request->getAttribute(Session::class);
        $expected = $session instanceof Session ? $session->get('_csrf') : null;
        $body = $request->getParsedBody();
        $sent = is_array($body) && is_string($body[self::FIELD] ?? null) ? $body[self::FIELD] : $request->getHeaderLine(self::HEADER);
        if (!is_string($expected) || $expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            throw new Forbidden('The form has expired or was not sent from this site. Reload the page and try again.');
        }

        return $handler->handle($request);
    }

    private static function exempt(ServerRequestInterface $request): bool
    {
        $match = $request->getAttribute(RouteMatch::class);

        return $match instanceof RouteMatch && in_array('csrf:off', $match->route->getMiddleware(), true);
    }
}
