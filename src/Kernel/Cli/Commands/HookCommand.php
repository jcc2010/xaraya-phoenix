<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Hooks\HookBindings;
use Xaraya\Kernel\Module\ModuleRegistry;

abstract class HookCommand extends Command
{
    public function __construct(private readonly HookBindings $bindings, private readonly ModuleRegistry $modules) {}

    abstract protected function enabled(): bool;

    public function usage(): string
    {
        return $this->name() . ' <observer> <subject> [itemtype]';
    }

    public function run(Input $input, Output $output): int
    {
        $observer = $input->argument(0);
        $subject = $input->argument(1);
        $itemtype = $input->argument(2) ?? '*';
        if ($observer === null || $subject === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $manifest = $this->modules->get($observer);
        $this->modules->get($subject);
        if ($manifest->displayHooks() === []) {
            $output->error("Module '{$observer}' declares no displayHooks");

            return 1;
        }
        $this->bindings->set($observer, $subject, $itemtype, $this->enabled());
        $output->line(sprintf(
            "%s display hooks from '%s' on '%s' (itemtype %s).",
            $this->enabled() ? 'Enabled' : 'Disabled',
            $observer,
            $subject,
            $itemtype,
        ));

        return 0;
    }
}
