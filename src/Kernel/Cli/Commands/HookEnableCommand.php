<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

final class HookEnableCommand extends HookCommand
{
    public function name(): string
    {
        return 'hook:enable';
    }

    public function description(): string
    {
        return "Bind a module's display hooks to a subject module (and itemtype)";
    }

    protected function enabled(): bool
    {
        return true;
    }
}
