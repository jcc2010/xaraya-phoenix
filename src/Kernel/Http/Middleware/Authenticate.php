<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\UserRepository;

/**
 * Global middleware: makes the session's user the request's identity. It never refuses a request; the `auth`
 * route middleware does. A session whose user is gone, pending or disabled is signed out.
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth, private readonly UserRepository $users) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        $userId = $session instanceof Session ? $session->userId() : null;
        $user = $userId === null ? null : $this->users->find($userId);
        if ($session instanceof Session && $userId !== null && ($user === null || !$user->isActive())) {
            $session->logout();
            $user = null;
        }
        $this->auth->actAs($user);

        return $handler->handle($request);
    }
}
