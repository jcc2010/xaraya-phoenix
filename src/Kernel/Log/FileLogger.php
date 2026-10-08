<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

final class FileLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public function __construct(private readonly string $directory, private readonly string $minLevel = LogLevel::INFO) {}

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? $level : '';
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException("Unknown log level '{$level}'");
        }
        if (self::LEVELS[$level] < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if ($key !== 'exception' && (is_scalar($value) || $value instanceof Stringable)) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        $line = sprintf('[%s] %s: %s', gmdate('Y-m-d\TH:i:s\Z'), strtoupper($level), strtr((string) $message, $replace));
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $line .= "\n" . $exception::class . ' in ' . $exception->getFile() . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString();
        }
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        file_put_contents($this->directory . '/xaraya-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
