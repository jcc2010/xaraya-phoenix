<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Blog\BlogRepository;

final class BlogCreateCommand extends Command
{
    public function __construct(private readonly BlogRepository $blogs) {}

    public function name(): string
    {
        return 'blog:create';
    }

    public function description(): string
    {
        return 'Create a mirror or native blog';
    }

    public function usage(): string
    {
        return 'blog:create <handle> --mode=mirror|native [--source=<url> --format=athena|jsonfeed]'
            . ' [--title=] [--author=] [--author-url=] [--home=] [--post-url=<pattern with {id}>]'
            . ' [--media=remote|local] [--language=]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $blog = $this->blogs->create([
            'handle' => $handle,
            'mode' => $input->option('mode'),
            'source_url' => $input->option('source'),
            'source_format' => $input->option('format'),
            'title' => $input->option('title'),
            'author_name' => $input->option('author'),
            'author_url' => $input->option('author-url'),
            'home_page_url' => $input->option('home'),
            'post_url_pattern' => $input->option('post-url'),
            'media' => $input->option('media'),
            'language' => $input->option('language'),
        ], new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $output->line("Created {$blog->mode} blog '{$blog->handle}'.");

        return 0;
    }
}
