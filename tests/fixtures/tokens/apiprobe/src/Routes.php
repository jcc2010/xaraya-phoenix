<?php

declare(strict_types=1);

namespace Xaraya\Module\Apiprobe;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Blocks\BlockInstance;
use Xaraya\Kernel\Blocks\LoginBlock;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Kernel\View\View;

/** Token-protected test routes for a site with the tokens module and no blog. */
final class Routes implements RouteProvider
{
    public function __construct(private readonly Auth $auth, private readonly View $view) {}

    public function routes(RouteCollector $routes): void
    {
        $routes->group('/apiprobe', ['csrf:off', 'token:apiprobe:write'], function (RouteCollector $api): void {
            $api->get('/whoami', fn(): ResponseInterface => new Response(200, [], ($this->auth->user()->handle ?? 'guest')
                . ' ' . ($this->auth->can('admin') ? 'admin' : 'not-admin')), 'apiprobe.whoami');
            // Everything that would mint or write a session on a browser request.
            $api->post('/page', function (ServerRequestInterface $r): ResponseInterface {
                $x = $this->view->helpers($r);
                $session = $r->getAttribute(Session::class);
                if ($session instanceof Session) {
                    $session->set('written', true);
                }
                $block = (new LoginBlock())->render([], new BlockContext(new BlockInstance(1, 'login', 'sidebar', null, [], 0, [], true), $x, 'apiprobe.page', []));

                return new Response(200, [], 'csrf[' . $x->csrf() . '] ' . $block);
            }, 'apiprobe.page');
            $api->get('/cached', static fn(): ResponseInterface => new Response(200, ['Cache-Control' => 'no-store'], 'ok'), 'apiprobe.cached');
        });
    }
}
