<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

interface Block
{
    /**
     * Returns the block's inner HTML, or '' to hide it. The theme's "block" template wraps it.
     *
     * @param array<string, mixed> $config
     */
    public function render(array $config, BlockContext $context): string;
}
