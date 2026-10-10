<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

final class HookDisableCommand extends HookCommand
{
    public function name(): string
    {
        return 'hook:disable';
    }

    public function description(): string
    {
        return "Unbind a module's display hooks from a subject module (and itemtype)";
    }

    protected function enabled(): bool
    {
        return false;
    }
}
