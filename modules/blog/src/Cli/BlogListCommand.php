<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\BlogRepository;

final class BlogListCommand extends Command
{
    public function __construct(private readonly BlogRepository $blogs, private readonly Connection $db) {}

    public function name(): string
    {
        return 'blog:list';
    }

    public function description(): string
    {
        return 'List blogs with their mode, source and live post count';
    }

    public function run(Input $input, Output $output): int
    {
        $rows = [];
        foreach ($this->blogs->all() as $blog) {
            $live = $this->db->select('posts')->where('blog_id', '=', $blog->id)->where('status', '=', 'published')->count();
            $rows[] = [$blog->handle, $blog->mode, $blog->sourceUrl ?? '-', (string) $live, $blog->lastSyncedAt ?? 'never'];
        }
        $output->table(['Handle', 'Mode', 'Source', 'Posts', 'Last sync'], $rows);

        return 0;
    }
}
