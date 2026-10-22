<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /** @var resource|null */
    private $stdin;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param resource|null $stdin where ask() reads; STDIN when null
     */
    public function __construct($stdout = null, $stderr = null, $stdin = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->stdin = $stdin;
    }

    /**
     * Prints $question and reads one line: null at the end of input. A secret answer is not echoed on a terminal,
     * and the terminal settings are put back however the read ends (an exception, or Ctrl-C when pcntl exists).
     */
    public function ask(string $question, bool $secret = false): ?string
    {
        $in = $this->stdin ?? STDIN;
        fwrite($this->stdout, $question . ' ');
        $saved = '';
        if ($secret && stream_isatty($in)) {
            $saved = trim((string) @shell_exec('stty -g 2>/dev/null'));
            if ($saved === '') {
                $this->error('Input will be visible; use --password-stdin');
            } else {
                @shell_exec('stty -echo 2>/dev/null');
            }
        }
        $restore = function () use ($saved): void {
            @shell_exec('stty ' . escapeshellarg($saved) . ' 2>/dev/null');
        };
        $previous = null;
        $async = false;
        $pcntl = $saved !== '' && function_exists('pcntl_signal');
        if ($pcntl) {
            $async = pcntl_async_signals(true);
            $previous = pcntl_signal_get_handler(SIGINT);
            pcntl_signal(SIGINT, function () use ($restore): never {
                $restore();
                fwrite($this->stdout, PHP_EOL);
                exit(130);
            });
        }
        try {
            $line = fgets($in);
        } finally {
            if ($pcntl) {
                pcntl_signal(SIGINT, $previous);
                pcntl_async_signals($async);
            }
            if ($saved !== '') {
                $restore();
                fwrite($this->stdout, PHP_EOL);
            }
        }

        return $line === false ? null : self::stripTerminator($line);
    }

    /** Reads one line with no prompt (a script piping a secret in): null at the end of input. */
    public function readLine(): ?string
    {
        $line = fgets($this->stdin ?? STDIN);

        return $line === false ? null : self::stripTerminator($line);
    }

    /** Exactly one line terminator: a password may end in spaces or carriage returns of its own. */
    private static function stripTerminator(string $line): string
    {
        return (string) preg_replace('/\r?\n$/D', '', $line);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $text . PHP_EOL);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }
        $format = fn(array $cells): string => rtrim(implode('  ', array_map(
            fn(string $cell, int $i): string => str_pad($cell, $widths[$i]),
            $cells,
            array_keys($cells),
        )));
        $this->line($format($headers));
        $this->line(implode('  ', array_map(fn(int $w): string => str_repeat('-', $w), $widths)));
        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }
}
