<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteMatch;
use Xaraya\Kernel\Routing\Router;

final class EchoController extends Controller
{
    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        return $this->json(['id' => $params['id'], 'attr' => $request->getAttribute('id'), 'matched' => $request->getAttribute(RouteMatch::class) instanceof RouteMatch]);
    }

    /** @param array<string, string> $params */
    public function __invoke(ServerRequestInterface $request, array $params): string
    {
        return '<p>invoked</p>';
    }
}

final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $tag = 'x') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withAddedHeader('X-Tags', $this->tag);
    }
}

final class NeedMiddleware implements MiddlewareInterface
{
    /** @param list<string> $args */
    public function __construct(public readonly array $args = []) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Need', implode(',', $this->args));
    }
}

final class RouteHandlerTest extends TestCase
{
    private function handler(): RouteHandler
    {
        $container = new Container();
        $registry = new MiddlewareRegistry($container);
        $registry->register('tag', fn(Container $c, array $args) => new TagMiddleware($args[0] ?? 'none'));
        $registry->register('plain', TagMiddleware::class);

        $r = new RouteCollector();
        $r->get('/items/{id}', [EchoController::class, 'show']);
        $r->get('/static/{id}', EchoController::class . '::show');
        $r->get('/invoke', EchoController::class);
        $r->get('/closure', fn(ServerRequestInterface $req, array $p) => new Response(201))->middleware('tag:outer', 'tag:inner', 'plain');

        return new RouteHandler(new Router($r->routes()), $registry, $container);
    }

    public function testArrayAndStaticStringHandlers(): void
    {
        foreach (['/items/42', '/static/42'] as $path) {
            $response = $this->handler()->handle(new ServerRequest('GET', $path));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame(['id' => '42', 'attr' => '42', 'matched' => true], json_decode((string) $response->getBody(), true));
        }
    }

    public function testInvokableReturningStringIsHtml(): void
    {
        $response = $this->handler()->handle(new ServerRequest('GET', '/invoke'));
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<p>invoked</p>', (string) $response->getBody());
    }

    public function testRouteMiddlewareRunsInDeclaredOrderWithArgs(): void
    {
        $response = $this->handler()->handle(new ServerRequest('GET', '/closure'));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['x', 'inner', 'outer'], $response->getHeader('X-Tags'), 'innermost adds its header first');
    }

    public function testUnknownMiddlewareAliasThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new MiddlewareRegistry(new Container()))->resolve('nope');
    }

    public function testClassStringFactoryReceivesArgs(): void
    {
        $registry = new MiddlewareRegistry(new Container());
        $registry->register('need', NeedMiddleware::class);
        $middleware = $registry->resolve('need:admin');
        self::assertInstanceOf(NeedMiddleware::class, $middleware);
        self::assertSame(['admin'], $middleware->args);
        self::assertSame([], $registry->resolve('need')->args);
    }

    public function testClassStringFactoryWithoutArgsParameterRejectsArgs(): void
    {
        $registry = new MiddlewareRegistry(new Container());
        $registry->register('plain', TagMiddleware::class);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Middleware 'plain' does not accept arguments");
        $registry->resolve('plain:admin');
    }

    public function testMarkerAliasesAreSkippedInTheRouteStack(): void
    {
        $container = new Container();
        $registry = new MiddlewareRegistry($container);
        $registry->registerMarker('csrf');
        $registry->register('tag', fn(Container $c, array $args) => new TagMiddleware($args[0] ?? 'none'));
        $r = new RouteCollector();
        $r->post('/hook', fn() => new Response(200))->middleware('csrf:off', 'tag:a');
        $response = (new RouteHandler(new Router($r->routes()), $registry, $container))->handle(new ServerRequest('POST', '/hook'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['a'], $response->getHeader('X-Tags'));
        self::assertTrue($registry->isMarker('csrf:off'));
        self::assertFalse($registry->isMarker('tag:a'));
    }

    public function testReusesAnAttachedRouteMatch(): void
    {
        $container = new Container();
        $r = new RouteCollector();
        $r->get('/real', fn() => new Response(200, [], 'real'));
        $other = new RouteCollector();
        $other->get('/elsewhere', fn() => new Response(202, [], 'attached'));
        $match = (new Router($other->routes()))->match('GET', '/elsewhere');
        $request = (new ServerRequest('GET', '/not-routed'))->withAttribute(RouteMatch::class, $match);
        $response = (new RouteHandler(new Router($r->routes()), new MiddlewareRegistry($container), $container))->handle($request);
        self::assertSame(202, $response->getStatusCode());
    }
}
