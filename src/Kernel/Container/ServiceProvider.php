<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Container;

interface ServiceProvider
{
    public function register(Container $container): void;
}
