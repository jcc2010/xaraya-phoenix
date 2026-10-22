<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use InvalidArgumentException;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

/**
 * A hidden prompt typed twice (the safe default), --password-stdin (one line from standard input, for scripts
 * and CI), or --password=<p>. The last leaves the password in shell history and in `ps`, so it warns.
 */
trait AsksForPassword
{
    private function password(Input $input, Output $output, string $option = 'password'): string
    {
        $password = $input->option($option);
        if ($password !== null && $input->flag($option . '-stdin')) {
            throw new InvalidArgumentException("Give --{$option}=<password> or --{$option}-stdin, not both");
        }
        if ($password !== null) {
            $output->error("Warning: --{$option}=<password> leaves the password in your shell history and in the process list. Prefer --{$option}-stdin or the prompt.");

            return $password;
        }
        if ($input->flag($option . '-stdin')) {
            return $output->readLine() ?? throw new InvalidArgumentException("No password on standard input for --{$option}-stdin");
        }
        $first = $output->ask('Password:', true);
        if ($first === null) {
            throw new InvalidArgumentException("Give --{$option}=<password> or type one (--{$option}-stdin is safer in scripts)");
        }
        if ($output->ask('Password again:', true) !== $first) {
            throw new InvalidArgumentException('The two passwords are not the same');
        }

        return $first;
    }
}
