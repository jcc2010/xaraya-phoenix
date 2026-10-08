<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class ServeCommand extends Command
{
    public function __construct(private readonly App $app) {}

    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Run the PHP development server';
    }

    public function usage(): string
    {
        return 'serve [--host=127.0.0.1:8080]';
    }

    public function commandLine(string $host): string
    {
        return sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            escapeshellarg($this->app->path('public')),
            escapeshellarg($this->app->path('public/index.php')),
        );
    }

    public function run(Input $input, Output $output): int
    {
        $host = $input->option('host', '127.0.0.1:8080') ?? '127.0.0.1:8080';
        $output->line("Xaraya Phoenix running at http://{$host} (Ctrl+C to stop)");
        passthru($this->commandLine($host), $code);

        return $code;
    }
}
