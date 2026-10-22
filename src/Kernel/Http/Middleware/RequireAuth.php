<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Http\Exception\Unauthorized;

/**
 * Route middleware "auth" (require a signed-in user) and "auth:<module>.<component>.<level>" (require that level
 * for the component). The component check has no item, so it passes when the user holds the level on ANY instance:
 * a user with only an "own" grant passes "auth:blog.post.delete". Item-level checks belong in the controller, which
 * calls Access::can() with the item (id, owner_id) it is about to touch; this middleware is never enough for that.
 *
 * A guest who fails is sent to the login page on an HTML GET or HEAD and gets a 401 otherwise (JSON on JSON
 * routes); a signed-in user who fails gets a 403.
 */
final class RequireAuth implements MiddlewareInterface
{
    public const LOGIN_PATH = '/login';

    /** @var array{0: string, 1: string, 2: string}|null module, component, level */
    private readonly ?array $required;

    /** @param list<string> $args */
    public function __construct(private readonly Auth $auth, array $args = [])
    {
        $spec = implode(',', $args);
        $parts = explode('.', $spec);
        if ($spec !== '' && (count($parts) !== 3 || !isset(Access::LEVELS[$parts[2]]) || in_array('', $parts, true))) {
            throw new LogicException("The auth middleware takes <module>.<component>.<level>, not '{$spec}'");
        }
        $this->required = $spec === '' ? null : [$parts[0], $parts[1], $parts[2]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $allowed = $this->required === null
            ? $this->auth->check()
            : $this->auth->can($this->required[2], $this->required[0], $this->required[1]);
        if ($allowed) {
            return $handler->handle($request);
        }
        if ($this->auth->check()) {
            throw new Forbidden();
        }
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && !ErrorHandler::wantsJson($request)) {
            $uri = $request->getUri();
            $next = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

            return new Response(302, ['Location' => self::LOGIN_PATH . '?next=' . rawurlencode($next), 'Cache-Control' => 'no-store']);
        }
        throw new Unauthorized('Log in to continue');
    }
}
