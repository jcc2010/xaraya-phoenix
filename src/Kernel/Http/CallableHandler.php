<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CallableHandler implements RequestHandlerInterface
{
    /** @param Closure(ServerRequestInterface): ResponseInterface $fn */
    public function __construct(private readonly Closure $fn) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->fn)($request);
    }
}
