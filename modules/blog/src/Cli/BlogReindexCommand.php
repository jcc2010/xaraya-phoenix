<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use InvalidArgumentException;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;

final class BlogReindexCommand extends Command
{
    private const CHUNK = 200;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly SearchIndex $index,
        private readonly Connection $db,
    ) {}

    public function name(): string
    {
        return 'blog:reindex';
    }

    public function description(): string
    {
        return 'Rebuild the search index of one blog or of every blog';
    }

    public function usage(): string
    {
        return 'blog:reindex <handle>|--all';
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
            : $this->blogs->all();
        foreach ($targets as $blog) {
            // One transaction per blog: searches keep seeing the old index until the new one is complete.
            $count = $this->db->transaction(function () use ($blog): int {
                $this->index->clear($blog->id);
                $count = 0;
                $after = null;
                while (($chunk = $this->posts->publishedAfter($blog->id, $after, self::CHUNK)) !== []) {
                    foreach ($chunk as $post) {
                        $this->index->index($post);
                        $count++;
                    }
                    $after = $chunk[count($chunk) - 1]->id;
                }

                return $count;
            });
            $output->line("{$blog->handle}: indexed {$count} post(s)");
        }

        return 0;
    }
}
