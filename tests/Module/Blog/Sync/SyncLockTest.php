<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Sync;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Sync\SyncLock;

final class SyncLockTest extends TestCase
{
    public function testSecondHolderIsRefusedUntilRelease(): void
    {
        $dir = sys_get_temp_dir() . '/xar-locks-' . bin2hex(random_bytes(4));
        $a = new SyncLock($dir);
        $b = new SyncLock($dir);
        self::assertTrue($a->acquire('wyome'));
        self::assertFalse($b->acquire('wyome'));
        self::assertTrue($b->acquire('other'));
        $a->release('wyome');
        self::assertTrue($b->acquire('wyome'));
        $b->release('wyome');
        $b->release('other');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
