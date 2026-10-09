<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Sync\Syncer;

final class BlogSyncCommand extends Command
{
    public function __construct(private readonly BlogRepository $blogs, private readonly Syncer $syncer) {}

    public function name(): string
    {
        return 'blog:sync';
    }

    public function description(): string
    {
        return 'Sync mirror blogs from their source feeds (quick by default, --full walks every page)';
    }

    public function usage(): string
    {
        return 'blog:sync <handle>|--all [--full]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null && !$input->flag('all')) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $targets = $handle !== null
            ? [$this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'")]
            : array_values(array_filter($this->blogs->all(), static fn(Blog $blog): bool => $blog->isMirror()));
        $code = 0;
        foreach ($targets as $blog) {
            try {
                $report = $this->syncer->sync($blog, $input->flag('full'), new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $output->line($report->summary());
                if (!$report->ok()) {
                    $code = 1;
                }
            } catch (Throwable $e) {
                $output->error("{$blog->handle}: {$e->getMessage()}");
                $code = 1;
            }
        }

        return $code;
    }
}
