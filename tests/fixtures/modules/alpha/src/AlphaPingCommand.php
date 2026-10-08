<?php

declare(strict_types=1);

namespace Xaraya\Module\Alpha;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class AlphaPingCommand extends Command
{
    public function name(): string
    {
        return 'alpha:ping';
    }

    public function description(): string
    {
        return 'Print pong';
    }

    public function run(Input $input, Output $output): int
    {
        $output->line('pong');

        return 0;
    }
}
