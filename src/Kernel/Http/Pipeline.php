<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Pipeline implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $final,
        private readonly int $index = 0,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            return $this->final->handle($request);
        }

        return $this->middleware[$this->index]->process($request, new self($this->middleware, $this->final, $this->index + 1));
    }
}
