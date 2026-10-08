<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;

final class MigrateCommand extends Command
{
    public function __construct(private readonly Migrator $migrator, private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run pending kernel and module migrations';
    }

    public function run(Input $input, Output $output): int
    {
        $ran = $this->migrator->migrate($this->modules->migrationPaths());
        if ($ran === []) {
            $output->line('Nothing to migrate.');
        }
        foreach ($ran as $name) {
            $output->line("Migrated: {$name}");
        }

        return 0;
    }
}
