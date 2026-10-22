<?php

declare(strict_types=1);

namespace Xaraya\Module\Probe;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/** Test routes that expose the session and identity machinery. */
final class Routes implements RouteProvider
{
    public function __construct(private readonly SessionStore $store) {}

    public function routes(RouteCollector $routes): void
    {
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
        $routes->get('/probe/public', static fn(): ResponseInterface => new Response(200, ['Cache-Control' => 'public, max-age=60'], 'public'), 'probe.public');
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
