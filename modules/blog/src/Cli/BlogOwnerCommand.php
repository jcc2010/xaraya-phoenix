<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use InvalidArgumentException;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;

/**
 * Assigns posts to an owner: by default the blog's ownerless posts (synced, imported or written by a scope-only token),
 * with --all every post, with --ids just the listed ones. Covers every status, so deleted posts can be undeleted.
 */
final class BlogOwnerCommand extends Command
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly UserRepository $users,
    ) {}

    public function name(): string
    {
        return 'blog:owner';
    }

    public function description(): string
    {
        return "Assign a blog's posts to a user (ownerless posts by default)";
    }

    public function usage(): string
    {
        return 'blog:owner <handle> --user=<user> [--all | --ids=<id,...>] [--dry-run]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        $userHandle = $input->option('user');
        if ($handle === null || $userHandle === null || $userHandle === '') {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $idList = $input->option('ids');
        if ($input->flag('all') && $input->flag('ids')) {
            $output->error('--all and --ids cannot be combined.');

            return 1;
        }
        $ids = $idList === null ? null : array_values(array_filter(array_map(trim(...), explode(',', $idList)), static fn(string $id): bool => $id !== ''));
        if ($input->flag('ids') && ($ids === null || $ids === [])) {
            $output->error('--ids needs at least one post id.');

            return 1;
        }
        $blog = $this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'");
        $user = $this->users->findByHandle($userHandle) ?? throw new InvalidArgumentException("Unknown user '{$userHandle}'");
        if (!$user->isActive()) {
            $output->error("User '{$userHandle}' is {$user->status}; activate it first.");

            return 1;
        }

        if ($ids === null) {
            $targets = $this->posts->ownerTargets($blog->id, $input->flag('all'));
        } else {
            $targets = array_values(array_unique($ids));
            $found = [];
            foreach ($this->posts->findMany($targets) as $post) {
                if ($post->blogId === $blog->id) {
                    $found[$post->id] = true;
                }
            }
            foreach ($targets as $id) {
                if (!isset($found[$id])) {
                    $output->error("No post '{$id}' in blog '{$handle}'; nothing was assigned.");

                    return 1;
                }
            }
        }

        if ($input->flag('dry-run')) {
            $output->line('Would assign ' . count($targets) . " post(s) in blog '{$handle}' to '{$userHandle}'.");

            return 0;
        }
        $count = $this->posts->assignOwner($targets, $user->id);
        $output->line("Assigned {$count} post(s) in blog '{$handle}' to '{$userHandle}'.");

        return 0;
    }
}
