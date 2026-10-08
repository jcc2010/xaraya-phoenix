<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

final class Unauthorized extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(401, $message);
    }
}
