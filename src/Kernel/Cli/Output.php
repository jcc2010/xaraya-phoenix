<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
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
