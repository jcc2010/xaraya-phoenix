<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens\Cli;

use DateTimeImmutable;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Tokens\OptionalBlogs;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenListCommand extends Command
{
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly OptionalBlogs $blogs,
        private readonly UserRepository $users,
    ) {}

    public function name(): string
    {
        return 'token:list';
    }

    public function description(): string
    {
        return 'List API tokens (never their values)';
    }

    public function run(Input $input, Output $output): int
    {
        $date = static fn(?DateTimeImmutable $d): string => $d === null ? '-' : $d->format('Y-m-d H:i');
        $blogs = $this->blogs->repository();
        $rows = [];
        foreach ($this->tokens->all() as $token) {
            $rows[] = [
                $token->id,
                $token->name,
                implode(',', $token->scopes),
                $token->blogId === null ? '*' : ($blogs?->findById($token->blogId)->handle ?? '?'),
                $token->userId === null ? '-' : ($this->users->find($token->userId)->handle ?? '?'),
                $date($token->lastUsedAt),
                $date($token->expiresAt),
            ];
        }
        $output->table(['id', 'name', 'scopes', 'blog', 'user', 'last used', 'expires'], $rows);

        return 0;
    }
}
