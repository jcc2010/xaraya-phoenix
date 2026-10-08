<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

final class TooManyRequests extends HttpException
{
    public function __construct(int $retryAfter)
    {
        parent::__construct(429, '', ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
