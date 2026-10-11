<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;

/** Seeds the blog's default sidebar blocks on an install that enabled blog before they existed. */
final class BlogBlocksCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly BlockRepository $blocks,
        private readonly ModuleRegistry $modules,
    ) {}

    public function name(): string
    {
        return 'blog:blocks';
    }

    public function description(): string
    {
        return 'Create the default blog sidebar blocks, unless blog blocks already exist';
    }

    public function run(Input $input, Output $output): int
    {
        $existing = $this->db->select('blocks')->where('type', 'like', 'blog.%')->count();
        if ($existing > 0) {
            $output->line("{$existing} blog block(s) already exist; nothing to do.");

            return 0;
        }
        $manifest = $this->modules->get('blog');
        $this->blocks->seed($manifest);
        $output->line(sprintf('Created %d blog block(s).', count($manifest->blockDefaults())));

        return 0;
    }
}
