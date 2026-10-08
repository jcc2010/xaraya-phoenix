<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;

final class MigrateStatusCommand extends Command
{
    public function __construct(private readonly Migrator $migrator, private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show which migrations have run';
    }

    public function run(Input $input, Output $output): int
    {
        $rows = array_map(
            fn(array $s): array => [$s['module'], $s['name'], $s['batch'] === null ? 'pending' : (string) $s['batch']],
            $this->migrator->status($this->modules->migrationPaths(all: true)),
        );
        $output->table(['Module', 'Migration', 'Batch'], $rows);

        return 0;
    }
}
