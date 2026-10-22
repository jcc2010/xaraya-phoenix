<?php

declare(strict_types=1);

namespace Xaraya\Module\Probe;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Kernel\View\View;

/** Test routes that expose the session and identity machinery. */
final class Routes implements RouteProvider
{
    public function __construct(private readonly SessionStore $store, private readonly Auth $auth, private readonly View $view) {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/probe/whoami', fn(): ResponseInterface => new Response(200, [], (string) json_encode([
            'user' => $this->auth->user()?->handle,
            'roles' => $this->auth->roles(),
        ])), 'probe.whoami');
        $routes->any('/probe/form', fn(ServerRequestInterface $r): ResponseInterface => new Response(
            200,
            [],
            $r->getMethod() === 'GET' ? '<form method="post">' . $this->view->helpers($r)->csrf() . '</form>' : 'ok ' . $r->getMethod(),
        ), 'probe.form');
        $whoami = fn(): ResponseInterface => new Response(200, [], (string) $this->auth->user()?->handle);
        $routes->get('/probe/members', $whoami, 'probe.members')->middleware('auth');
        $routes->get('/probe/members.json', $whoami, 'probe.members.json')->middleware('auth');
        $routes->post('/probe/members', $whoami, 'probe.members.post')->middleware('auth');
        $routes->get('/probe/admin', $whoami, 'probe.admin')->middleware('secureHtml', 'auth:probe.secret.admin');
        $routes->get('/probe/public', $whoami, 'probe.public')->middleware('auth:probe.secret.read');
        $routes->get('/probe/notes', $whoami, 'probe.notes')->middleware('auth:probe.note.delete');
        // The controller checks the item itself: the route middleware only asks about the component.
        $routes->get('/probe/notes/{owner}', function (ServerRequestInterface $r, array $params): ResponseInterface {
            $this->auth->can('delete', 'probe', 'note', ['id' => 'n1', 'owner_id' => $params['owner']]) || throw new Forbidden();

            return new Response(200, [], 'deleted');
        }, 'probe.note')->middleware('auth:probe.note.delete');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
            $session = self::session($r);
            $n = (int) $session->get('n', 0) + 1;
            $session->set('n', $n);

            return new Response(200, ['Cache-Control' => 'public, max-age=60'], (string) $n);
        }, 'probe.count');
        $routes->get('/probe/session', static fn(ServerRequestInterface $r): ResponseInterface => new Response(200, [], (string) json_encode([
            'user' => self::session($r)->userId(),
            'n' => self::session($r)->get('n'),
        ])), 'probe.session');
        $routes->post('/probe/login/{id}', static function (ServerRequestInterface $r, array $params): ResponseInterface {
            self::session($r)->login((string) $params['id']);

            return new Response(204);
        }, 'probe.login')->middleware('csrf:off');
        $routes->post('/probe/logout', static function (ServerRequestInterface $r): ResponseInterface {
            self::session($r)->logout();

            return new Response(204);
        }, 'probe.logout')->middleware('csrf:off');
        $routes->get('/probe/shared', static fn(): ResponseInterface => new Response(200, ['Cache-Control' => 'public, max-age=60'], 'public'), 'probe.shared');
        $routes->get('/probe/forget', static function (ServerRequestInterface $r): ResponseInterface {
            self::session($r)->forget('n');

            return new Response(200, ['Cache-Control' => 'public, max-age=60'], 'forgot');
        }, 'probe.forget');
        // Another request ends this user's sessions while this one is still running, then this one writes.
        $routes->get('/probe/race', function (ServerRequestInterface $r): ResponseInterface {
            $session = self::session($r);
            $this->store->deleteForUser((string) $session->userId());
            $session->set('n', 99);

            return new Response(200, [], 'raced');
        }, 'probe.race');
        $routes->get('/probe/boom', static function (ServerRequestInterface $r): never {
            self::session($r)->set('secret', 'session-secret-value');

            throw new \RuntimeException('probe boom');
        }, 'probe.boom');
    }

    private static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);

        return $session instanceof Session ? $session : throw new \LogicException('no session');
    }
}
