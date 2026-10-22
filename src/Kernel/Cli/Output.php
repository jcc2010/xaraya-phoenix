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
     * and the terminal settings are put back however the read ends.
     */
    public function ask(string $question, bool $secret = false): ?string
    {
        $in = $this->stdin ?? STDIN;
        fwrite($this->stdout, $question . ' ');
        $saved = $secret && stream_isatty($in) ? trim((string) shell_exec('stty -g')) : '';
        if ($saved !== '') {
            shell_exec('stty -echo');
        }
        try {
            $line = fgets($in);
        } finally {
            if ($saved !== '') {
                shell_exec('stty ' . escapeshellarg($saved));
                fwrite($this->stdout, PHP_EOL);
            }
        }

        return $line === false ? null : rtrim($line, "\r\n");
    }

    /** Reads one line with no prompt (a script piping a secret in): null at the end of input. */
    public function readLine(): ?string
    {
        $line = fgets($this->stdin ?? STDIN);

        return $line === false ? null : rtrim($line, "\r\n");
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
