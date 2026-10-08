<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

use LogicException;
use Throwable;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Commands\CacheClearCommand;
use Xaraya\Kernel\Cli\Commands\MigrateCommand;
use Xaraya\Kernel\Cli\Commands\MigrateRollbackCommand;
use Xaraya\Kernel\Cli\Commands\MigrateStatusCommand;
use Xaraya\Kernel\Cli\Commands\ModuleDisableCommand;
use Xaraya\Kernel\Cli\Commands\ModuleEnableCommand;
use Xaraya\Kernel\Cli\Commands\ModuleListCommand;
use Xaraya\Kernel\Cli\Commands\ServeCommand;
use Xaraya\Kernel\Module\ModuleRegistry;

final class Application
{
    private const KERNEL_COMMANDS = [
        MigrateCommand::class,
        MigrateRollbackCommand::class,
        MigrateStatusCommand::class,
        ModuleListCommand::class,
        ModuleEnableCommand::class,
        ModuleDisableCommand::class,
        ServeCommand::class,
        CacheClearCommand::class,
    ];

    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(private readonly App $app)
    {
        foreach (self::KERNEL_COMMANDS as $class) {
            $this->add($class);
        }
        foreach ($app->container()->get(ModuleRegistry::class)->enabled() as $manifest) {
            foreach ($manifest->commands() as $class) {
                $this->add($class);
            }
        }
    }

    public function add(string $class): void
    {
        $command = $this->app->container()->make($class);
        if (!$command instanceof Command) {
            throw new LogicException("{$class} is not a CLI Command");
        }
        $this->commands[$command->name()] = $command;
    }

    /** @param list<string> $argv */
    public function run(array $argv, ?Output $output = null): int
    {
        $output ??= new Output();
        $name = $argv[1] ?? 'help';
        if (in_array($name, ['help', 'list', '--help', '-h'], true)) {
            $this->help($output);

            return 0;
        }
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $output->error("Unknown command '{$name}'. Run 'xar help'.");

            return 1;
        }
        $input = new Input(array_slice($argv, 2));
        if ($input->flag('help')) {
            $output->line('Usage: xar ' . $command->usage());
            $output->line($command->description());

            return 0;
        }
        try {
            return $command->run($input, $output);
        } catch (Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }
    }

    private function help(Output $output): void
    {
        $output->line('Xaraya Phoenix');
        $output->line();
        $output->line('Usage: xar <command> [arguments] [--options]');
        $output->line();
        $commands = $this->commands;
        ksort($commands);
        foreach ($commands as $name => $command) {
            $output->line(sprintf('  %-20s %s', $name, $command->description()));
        }
    }
}
