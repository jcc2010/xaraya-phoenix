<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class UserCreateCommand extends Command
{
    use AsksForPassword;

    public function __construct(private readonly UserRepository $users) {}

    public function name(): string
    {
        return 'user:create';
    }

    public function description(): string
    {
        return 'Create a user (hidden password prompt; --password-stdin for scripts)';
    }

    public function usage(): string
    {
        return 'user:create <handle> --email=<email> [--name=<name>] [--role=<Role>,<Role>] [--status=active|pending|disabled] [--password-stdin] [--password=<password>, unsafe: shows in shell history and ps]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        $email = $input->option('email');
        if ($handle === null || $email === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $roles = array_values(array_filter(array_map('trim', explode(',', $input->option('role') ?? ''))));
        $user = $this->users->create(
            $handle,
            $email,
            $this->password($input, $output),
            $input->option('name') ?? '',
            $input->option('status') ?? 'active',
            $roles,
        );
        $output->line("Created user '{$user->handle}'" . ($roles === [] ? '' : ' (' . implode(', ', $roles) . ')') . '.');

        return 0;
    }
}
