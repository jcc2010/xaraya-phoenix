<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Throttle;
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Exception\TooManyRequests;

/** Route middleware "throttle:<key>,<max>,<seconds>": at most <max> requests per client IP in <seconds>, else 429. */
final class ThrottleRequests implements MiddlewareInterface
{
    private readonly string $key;
    private readonly int $max;
    private readonly int $seconds;

    /** @param list<string> $args */
    public function __construct(private readonly Throttle $throttle, private readonly ClientIp $ip, array $args = [])
    {
        if (count($args) !== 3 || preg_match('/^[a-z][a-z0-9_.-]*$/D', $args[0]) !== 1 || !ctype_digit($args[1]) || !ctype_digit($args[2]) || (int) $args[1] < 1 || (int) $args[2] < 1) {
            throw new LogicException("The throttle middleware takes <key>,<max>,<seconds>, not '" . implode(',', $args) . "'");
        }
        [$this->key, $this->max, $this->seconds] = [$args[0], (int) $args[1], (int) $args[2]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $key = 'route:' . $this->key . '|' . ClientIp::bucket($this->ip->of($request));
        $retryAfter = $this->throttle->retryAfter($key, $this->max, $now);
        if ($retryAfter > 0) {
            throw new TooManyRequests($retryAfter);
        }
        $this->throttle->hit($key, $this->seconds, $now);

        return $handler->handle($request);
    }
}
