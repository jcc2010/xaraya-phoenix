<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens\Cli;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenRevokeCommand extends Command
{
    public function __construct(private readonly TokenRepository $tokens) {}

    public function name(): string
    {
        return 'token:revoke';
    }

    public function description(): string
    {
        return 'Delete an API token';
    }

    public function usage(): string
    {
        return 'token:revoke <id>';
    }

    public function run(Input $input, Output $output): int
    {
        $id = $input->argument(0);
        if ($id === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        if (!$this->tokens->revoke($id)) {
            $output->error("Unknown token '{$id}'");

            return 1;
        }
        $output->line("Revoked token {$id}.");

        return 0;
    }
}
