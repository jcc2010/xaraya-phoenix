<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens\Cli;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Tokens\OptionalBlogs;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenCreateCommand extends Command
{
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly OptionalBlogs $blogs,
        private readonly UserRepository $users,
    ) {}

    public function name(): string
    {
        return 'token:create';
    }

    public function description(): string
    {
        return 'Create an API token (shown once; only its hash is stored)';
    }

    public function usage(): string
    {
        return 'token:create --name=<name> --scopes=blog:write,media:write [--blog=<handle>] [--user=<handle>] [--expires=<N>d]';
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->option('name');
        $scopes = $input->option('scopes');
        if ($name === null || $scopes === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $blogId = null;
        $handle = $input->option('blog');
        if ($handle !== null) {
            $blogs = $this->blogs->repository() ?? throw new InvalidArgumentException('--blog needs the blog module, which is not enabled');
            $blogId = ($blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'"))->id;
        }
        $userId = null;
        $userHandle = $input->option('user');
        if ($userHandle !== null) {
            $user = $this->users->findByHandle($userHandle) ?? throw new InvalidArgumentException("Unknown user '{$userHandle}'");
            $userId = $user->isActive() ? $user->id : throw new InvalidArgumentException("The user '{$userHandle}' is not active");
        }
        $expiresAt = null;
        $expires = $input->option('expires');
        if ($expires !== null) {
            if (preg_match('/^([1-9][0-9]{0,4})d$/D', $expires, $m) !== 1) {
                throw new InvalidArgumentException('--expires must be a number of days, like 90d');
            }
            $expiresAt = $now->modify("+{$m[1]} days");
        }
        [$token, $plaintext] = $this->tokens->create($name, array_map('trim', explode(',', $scopes)), $blogId, $expiresAt, $now, $userId);
        $output->line(sprintf(
            "Created token %s '%s' (%s%s%s%s).",
            $token->id,
            $token->name,
            implode(', ', $token->scopes),
            $handle === null ? '' : "; blog {$handle}",
            $userHandle === null ? '' : "; user {$userHandle}",
            $expiresAt === null ? '' : '; expires ' . $expiresAt->format('Y-m-d H:i') . ' UTC',
        ));
        $output->line('Copy it now; it is not shown again:');
        $output->line($plaintext);

        return 0;
    }
}
