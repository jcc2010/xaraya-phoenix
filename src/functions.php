<?php

declare(strict_types=1);

namespace Xaraya;

use Xaraya\Kernel\Config\Env;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}
