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
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Media\MediaLocalizer;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Post\PostRepository;

/**
 * Downloads every remote media URL of a blog's published posts (scheduled ones included) with the sync rules
 * (§3.4: allowed types only, deduplicated by URL then sha256, failures stay remote), then sets media=local.
 */
final class MediaLocalizeCommand extends Command
{
    private const CHUNK = 200;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly MediaStore $store,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'media:localize';
    }

    public function description(): string
    {
        return "Download a blog's remote media and serve it from /media (sets media=local)";
    }

    public function usage(): string
    {
        return 'media:localize <handle>';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $blog = $this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'");
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Uploads already live at {app.url}/media/…: never download our own files.
        $own = rtrim((string) $this->config->get('app.url', ''), '/') . '/media/';
        $local = $failed = 0;
        $after = null;
        while (($chunk = $this->posts->publishedAfter($blog->id, $after, self::CHUNK)) !== []) {
            foreach ($chunk as $post) {
                foreach (MediaLocalizer::urls($post) as $url) {
                    if (str_starts_with($url, $own)) {
                        continue;
                    }
                    $this->store->localize($blog, $url, $now) !== null ? $local++ : $failed++;
                }
            }
            $after = $chunk[count($chunk) - 1]->id;
        }
        $this->blogs->update($blog, ['media' => 'local'], $now);
        $output->line("{$handle}: {$local} media URL(s) stored locally, {$failed} left remote (see the log). Media is now local.");

        return 0;
    }
}
