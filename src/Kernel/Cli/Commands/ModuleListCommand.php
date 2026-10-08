<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Module\ModuleRegistry;

final class ModuleListCommand extends Command
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'module:list';
    }

    public function description(): string
    {
        return 'List discovered modules and whether they are enabled';
    }

    public function run(Input $input, Output $output): int
    {
        $enabled = $this->modules->enabled();
        $rows = [];
        foreach ($this->modules->discover() as $name => $manifest) {
            $rows[] = [$name, $manifest->version, isset($enabled[$name]) ? 'yes' : 'no', $manifest->path];
        }
        $output->table(['Module', 'Version', 'Enabled', 'Path'], $rows);

        return 0;
    }
}
