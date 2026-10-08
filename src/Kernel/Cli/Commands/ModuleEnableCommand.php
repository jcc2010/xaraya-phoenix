<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Module\ModuleRegistry;

final class ModuleEnableCommand extends Command
{
    public function __construct(private readonly ModuleRegistry $modules, private readonly App $app) {}

    public function name(): string
    {
        return 'module:enable';
    }

    public function description(): string
    {
        return 'Enable a module and run its migrations';
    }

    public function usage(): string
    {
        return 'module:enable <name>';
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument(0);
        if ($name === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $this->modules->enable($name);
        $this->app->clearCache();
        $output->line("Enabled module '{$name}'.");

        return 0;
    }
}
