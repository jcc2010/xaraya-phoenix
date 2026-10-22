<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use InvalidArgumentException;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;

/** The password-reset fallback (kernel spec §4.4): also ends the user's sessions and reset links. */
final class UserPasswordCommand extends Command
{
    use AsksForPassword;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionStore $sessions,
        private readonly Connection $db,
    ) {}

    public function name(): string
    {
        return 'user:password';
    }

    public function description(): string
    {
        return "Set a user's password and sign them out everywhere";
    }

    public function usage(): string
    {
        return 'user:password <handle> [--password-stdin] [--password=<password>, unsafe: shows in shell history and ps]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $user = $this->users->findByHandle($handle) ?? throw new InvalidArgumentException("Unknown user '{$handle}'");
        $this->users->setPassword($user, $this->password($input, $output));
        $this->db->delete('password_resets', ['user_id' => $user->id]);
        $ended = $this->sessions->deleteForUser($user->id);
        $output->line("Changed the password of '{$user->handle}'" . ($ended > 0 ? " and ended {$ended} session(s)" : '') . '.');

        return 0;
    }
}
