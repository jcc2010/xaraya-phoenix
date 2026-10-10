<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

interface Block
{
    /**
     * Returns the block's inner HTML, or '' to hide it. The theme's "block" template wraps it and escapes the
     * title and type class, but the returned HTML is trusted: the block must escape its config and any user data.
     *
     * @param array<string, mixed> $config
     */
    public function render(array $config, BlockContext $context): string;
}
