<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Config\Config;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;

final class BlogModeCommand extends Command
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'blog:mode';
    }

    public function description(): string
    {
        return 'Switch a blog between mirror and native mode (ids never change)';
    }

    public function usage(): string
    {
        return 'blog:mode <handle> mirror|native [--source=<url> --format=athena|jsonfeed] [--force]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        $mode = $input->argument(1);
        if ($handle === null || !in_array($mode, Blog::MODES, true)) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $blog = $this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'");
        if ($blog->mode === $mode) {
            $output->line("Blog '{$handle}' is already {$mode}.");

            return 0;
        }
        if ($mode === 'mirror') {
            $prefix = rtrim((string) $this->config->get('app.url', ''), '/') . '/s/';
            $native = count(array_filter($this->posts->liveItemIds($blog->id), static fn(string $id): bool => str_starts_with($id, $prefix)));
            if ($native > 0 && !$input->flag('force')) {
                $output->error("Blog '{$handle}' has {$native} native post(s); the next full sync would tombstone them. Re-run with --force to switch anyway.");

                return 1;
            }
        }
        $fields = array_filter([
            'mode' => $mode,
            'source_url' => $input->option('source'),
            'source_format' => $input->option('format'),
        ], static fn(?string $value): bool => $value !== null);
        $this->blogs->update($blog, $fields, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $output->line("Blog '{$handle}' is now {$mode}.");

        return 0;
    }
}
