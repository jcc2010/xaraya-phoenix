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

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Boots the app and runs the CLI. When a normal boot fails (say, a module provider throws), the error
     * goes to stderr and the app boots again in safe mode, so `module:disable` still works.
     *
     * @param list<string> $argv
     * @param array<string, mixed> $overrides
     */
    public static function main(string $root, array $argv, array $overrides = [], ?Output $output = null): int
    {
        $output ??= new Output();
        try {
            $app = App::boot($root, $overrides);
        } catch (Throwable $e) {
            $output->error('Boot failed: ' . $e->getMessage());
            $output->error('Retrying in safe mode (module providers, subscribers and commands are skipped).');
            try {
                $app = App::boot($root, $overrides, true);
            } catch (Throwable $e) {
                $output->error('Safe-mode boot failed: ' . $e->getMessage());

                return 1;
            }
        }

        return (new self($app))->run($argv, $output);
    }

    public function __construct(private readonly App $app)
    {
        foreach (self::KERNEL_COMMANDS as $class) {
            $this->add($class);
        }
        if ($app->safe()) {
            return;
        }
        foreach ($app->container()->get(ModuleRegistry::class)->enabled() as $manifest) {
            foreach ($manifest->commands() as $class) {
                $this->addModuleCommand($manifest->name, $class);
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
        foreach ($this->warnings as $warning) {
            $output->error('Warning: ' . $warning);
        }
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

    /** A module command that cannot be built, or that would shadow a kernel command, is skipped with a warning. */
    private function addModuleCommand(string $module, string $class): void
    {
        try {
            $command = $this->app->container()->make($class);
        } catch (Throwable $e) {
            $this->warnings[] = "module '{$module}': skipped command {$class}: " . $e->getMessage();

            return;
        }
        if (!$command instanceof Command) {
            $this->warnings[] = "module '{$module}': skipped {$class}, which is not a CLI Command";

            return;
        }
        $name = $command->name();
        if (isset($this->commands[$name])) {
            $this->warnings[] = "module '{$module}': skipped {$class}, which would replace the '{$name}' command";

            return;
        }
        $this->commands[$name] = $command;
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
