<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Sync;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Sync\SyncLock;

final class SyncLockTest extends TestCase
{
    public function testAcquireRestampsAnOldLockFile(): void
    {
        $dir = sys_get_temp_dir() . '/xar-locks-' . bin2hex(random_bytes(4));
        mkdir($dir);
        touch($dir . '/blog-old.lock', time() - 3600);
        $lock = new SyncLock($dir);
        self::assertTrue($lock->acquire('old'));
        self::assertGreaterThan(time() - 60, (int) $lock->heldSince('old'));
        $lock->release('old');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testSecondHolderIsRefusedUntilRelease(): void
    {
        $dir = sys_get_temp_dir() . '/xar-locks-' . bin2hex(random_bytes(4));
        $a = new SyncLock($dir);
        $b = new SyncLock($dir);
        self::assertTrue($a->acquire('wyome'));
        self::assertFalse($b->acquire('wyome'));
        self::assertTrue($b->acquire('other'));
        self::assertNotNull($a->heldSince('wyome'));
        self::assertEqualsWithDelta(time(), $a->heldSince('wyome'), 2, 'acquire stamps the start time');
        self::assertNull($a->heldSince('nobody'));
        $a->release('wyome');
        self::assertTrue($b->acquire('wyome'));
        $b->release('wyome');
        $b->release('other');
        self::assertNull((new SyncLock($dir . '/missing'))->heldSince('wyome'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
