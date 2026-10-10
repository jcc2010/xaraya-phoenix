<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Assets\AssetPublisher;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class AssetPublishCommand extends Command
{
    public function __construct(private readonly AssetPublisher $publisher) {}

    public function name(): string
    {
        return 'asset:publish';
    }

    public function description(): string
    {
        return 'Link (or copy) module and theme assets into public/assets';
    }

    public function usage(): string
    {
        return 'asset:publish [--copy]';
    }

    public function run(Input $input, Output $output): int
    {
        $done = $this->publisher->publish($input->flag('copy'));
        if ($done === []) {
            $output->line('No module or theme has an assets directory.');

            return 0;
        }
        $output->table(['Kind', 'Name', 'Method', 'Target'], array_map(
            static fn(array $row): array => [$row['kind'], $row['name'], $row['method'], $row['target']],
            $done,
        ));

        return 0;
    }
}
