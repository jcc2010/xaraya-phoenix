<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

final class Input
{
    /** @var list<string> */
    private array $arguments = [];

    /** @var array<string, string|true> */
    private array $options = [];

    /** @param list<string> $tokens */
    public function __construct(array $tokens)
    {
        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                $this->arguments[] = $token;
                continue;
            }
            $token = substr($token, 2);
            if (str_contains($token, '=')) {
                [$key, $value] = explode('=', $token, 2);
                $this->options[$key] = $value;
            } else {
                $this->options[$token] = true;
            }
        }
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }
}
