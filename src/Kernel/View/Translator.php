<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use InvalidArgumentException;
use Stringable;

/** PHP array catalogs at <dir>/<locale>.php; English source strings are the keys and the fallback. */
final class Translator
{
    /** @var array<string, string>|null */
    private ?array $messages = null;

    /** @param list<string> $dirs lang directories, lowest priority first */
    public function __construct(private readonly string $locale, private readonly array $dirs)
    {
        if (preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException("Invalid locale '{$locale}'");
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, mixed> $params values for {name} placeholders */
    public function t(string $key, array $params = []): string
    {
        $message = $this->messages()[$key] ?? $key;
        if ($params === []) {
            return $message;
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
        }

        return strtr($message, $replace);
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        if ($this->messages !== null) {
            return $this->messages;
        }
        $normal = str_replace('-', '_', $this->locale);
        $base = explode('_', $normal)[0];
        $locales = $base === $normal ? [$normal] : [$base, $normal];
        $messages = [];
        foreach ($this->dirs as $dir) {
            foreach ($locales as $locale) {
                $file = $dir . '/' . $locale . '.php';
                if (!is_file($file)) {
                    continue;
                }
                $catalog = (static fn(string $f): mixed => require $f)($file);
                if (!is_array($catalog)) {
                    throw new ViewException("Translation catalog {$file} must return an array");
                }
                foreach ($catalog as $from => $to) {
                    if (is_string($to)) {
                        $messages[(string) $from] = $to;
                    }
                }
            }
        }

        return $this->messages = $messages;
    }
}
