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
        $match = $this->router->match($request->getMethod(), rawurldecode($request->getUri()->getPath()));
        foreach ($match->params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }
        $request = $request->withAttribute(RouteMatch::class, $match);
        $stack = array_map($this->middleware->resolve(...), $match->route->getMiddleware());

        return (new Pipeline($stack, new CallableHandler(fn(ServerRequestInterface $r): ResponseInterface => $this->invoke($match, $r))))
            ->handle($request);
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
