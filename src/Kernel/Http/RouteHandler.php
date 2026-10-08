<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Closure;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Routing\RouteMatch;
use Xaraya\Kernel\Routing\Router;

final class RouteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly MiddlewareRegistry $middleware,
        private readonly Container $container,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::class);
        if (!$match instanceof RouteMatch) {
            $request = self::attach($request, $match = $this->match($request));
        }
        $stack = $this->middleware->stack($match->route->getMiddleware());

        return (new Pipeline($stack, new CallableHandler(fn(ServerRequestInterface $r): ResponseInterface => $this->invoke($match, $r))))
            ->handle($request);
    }

    /** Matches the request's method and decoded path; throws NotFound or MethodNotAllowed. */
    public function match(ServerRequestInterface $request): RouteMatch
    {
        return $this->router->match($request->getMethod(), rawurldecode($request->getUri()->getPath()));
    }

    /** Attaches the match and its params as request attributes. */
    public static function attach(ServerRequestInterface $request, RouteMatch $match): ServerRequestInterface
    {
        foreach ($match->params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }

        return $request->withAttribute(RouteMatch::class, $match);
    }

    private function invoke(RouteMatch $match, ServerRequestInterface $request): ResponseInterface
    {
        $handler = $match->route->handler;
        if (is_string($handler) && str_contains($handler, '::')) {
            $handler = explode('::', $handler, 2);
        }
        $callable = match (true) {
            $handler instanceof Closure => $handler,
            is_array($handler) => [$this->container->get($handler[0]), $handler[1]],
            default => $this->container->get($handler),
        };
        if (!is_callable($callable)) {
            throw new LogicException('Route handler for ' . $match->route->path . ' is not callable');
        }
        $result = $callable($request, $match->params);
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if (is_string($result)) {
            return Controller::htmlResponse($result);
        }
        throw new LogicException('Controllers must return a ResponseInterface or a string');
    }
}
