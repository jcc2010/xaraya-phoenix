<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;

final class MigrateRollbackCommand extends Command
{
    public function __construct(private readonly Migrator $migrator, private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Roll back the last migration batch (or --step=N batches)';
    }

    public function usage(): string
    {
        return 'migrate:rollback [--step=N]';
    }

    public function run(Input $input, Output $output): int
    {
        $rolled = $this->migrator->rollback($this->modules->migrationPaths(all: true), (int) $input->option('step', '1'));
        if ($rolled === []) {
            $output->line('Nothing to roll back.');
        }
        foreach ($rolled as $name) {
            $output->line("Rolled back: {$name}");
        }

        return 0;
    }
}
