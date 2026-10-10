<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\View\Page;

/** The theme's "home" page at "/", until a module claims that route. */
final class HomeController
{
    /** @param array<string, string> $params */
    public function __invoke(ServerRequestInterface $request, array $params): Page
    {
        return new Page('home');
    }
}
