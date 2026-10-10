<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class CacheClearCommand extends Command
{
    public function __construct(private readonly App $app) {}

    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Delete cached config, routes, compiled templates and cache entries';
    }

    public function run(Input $input, Output $output): int
    {
        $output->line(sprintf('Cleared %d cache file(s).', $this->app->clearCache()));

        return 0;
    }
}
