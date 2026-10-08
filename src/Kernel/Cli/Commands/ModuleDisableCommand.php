<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Module\ModuleRegistry;

final class ModuleDisableCommand extends Command
{
    public function __construct(private readonly ModuleRegistry $modules, private readonly App $app) {}

    public function name(): string
    {
        return 'module:disable';
    }

    public function description(): string
    {
        return 'Disable a module (its tables are kept)';
    }

    public function usage(): string
    {
        return 'module:disable <name>';
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument(0);
        if ($name === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $this->modules->disable($name);
        $this->app->clearCache();
        $output->line("Disabled module '{$name}'.");

        return 0;
    }
}
