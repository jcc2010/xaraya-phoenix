<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

interface RouteProvider
{
    public function routes(RouteCollector $routes): void;
}
