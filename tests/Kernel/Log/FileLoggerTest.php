<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Log;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Log\FileLogger;

final class FileLoggerTest extends TestCase
{
    public function testWritesInterpolatedLinesAboveMinimumLevel(): void
    {
        $dir = sys_get_temp_dir() . '/xar-log-' . bin2hex(random_bytes(4));
        $logger = new FileLogger($dir, 'info');
        $logger->debug('hidden');
        $logger->info('Imported {count} items from {source}', ['count' => 3, 'source' => 'athena']);
        $logger->error('Failed', ['exception' => new \RuntimeException('boom')]);

        $file = $dir . '/xaraya-' . gmdate('Y-m-d') . '.log';
        $log = (string) file_get_contents($file);
        self::assertStringNotContainsString('hidden', $log);
        self::assertStringContainsString('INFO: Imported 3 items from athena', $log);
        self::assertStringContainsString('ERROR: Failed', $log);
        self::assertStringContainsString('RuntimeException in', $log);
        unlink($file);
        rmdir($dir);
    }

    public function testUnknownLevelThrows(): void
    {
        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        (new FileLogger(sys_get_temp_dir()))->log('loud', 'x');
    }
}
