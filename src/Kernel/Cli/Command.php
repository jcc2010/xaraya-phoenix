<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

abstract class Command
{
    abstract public function name(): string;

    abstract public function description(): string;

    public function usage(): string
    {
        return $this->name();
    }

    abstract public function run(Input $input, Output $output): int;
}
