<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\View\Helpers;

/** What a block can see. HTML a block returns is trusted as is; escape config and user data with $helpers->e(). */
final class BlockContext
{
    /** @param array<string, string> $params the current route's parameters */
    public function __construct(
        public readonly BlockInstance $block,
        public readonly Helpers $helpers,
        public readonly ?string $routeName,
        public readonly array $params,
    ) {}

    public function request(): ?ServerRequestInterface
    {
        return $this->helpers->request();
    }
}
