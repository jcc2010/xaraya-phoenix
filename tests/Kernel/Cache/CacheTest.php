<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Cache;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Cache\Cache;

final class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/xar-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        Cache::removeTree($this->dir);
        Cache::removeTree($this->dir . '-target');
    }

    public function testMissReturnsTheDefault(): void
    {
        $cache = new Cache($this->dir);
        self::assertNull($cache->get('nope'));
        self::assertSame('d', $cache->get('nope', 'd'));
        self::assertFalse($cache->has('nope'));
    }

    public function testRoundTripsScalarsArraysNullAndFalse(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('sanitized/01jabc-2026-10-09 10:00:00-content', '<p>ok</p>');
        $cache->set('list', ['a' => [1, 2.5, true]]);
        $cache->set('null', null);
        $cache->set('false', false);

        $fresh = new Cache($this->dir);
        self::assertSame('<p>ok</p>', $fresh->get('sanitized/01jabc-2026-10-09 10:00:00-content'));
        self::assertSame(['a' => [1, 2.5, true]], $fresh->get('list'));
        self::assertTrue($fresh->has('null'));
        self::assertNull($fresh->get('null', 'default'));
        self::assertFalse($fresh->get('false', 'default'));
    }

    public function testForget(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('k', 'v');
        $cache->forget('k');
        $cache->forget('never-set');
        self::assertFalse($cache->has('k'));
    }

    public function testRememberComputesOnce(): void
    {
        $cache = new Cache($this->dir);
        $calls = 0;
        $make = function () use (&$calls): string {
            $calls++;

            return 'v';
        };
        self::assertSame('v', $cache->remember('k', $make));
        self::assertSame('v', $cache->remember('k', $make));
        self::assertSame(1, $calls);
    }

    public function testCorruptEntryIsAMiss(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('k', 'v');
        $files = glob($this->dir . '/*/*.cache') ?: [];
        self::assertCount(1, $files);
        file_put_contents($files[0], 'not serialized');
        self::assertSame('d', $cache->get('k', 'd'));
    }

    public function testObjectsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Cache($this->dir))->set('k', ['nested' => new \stdClass()]);
    }

    public function testClearRemovesEveryEntry(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('a', 1);
        $cache->set('b', 2);
        self::assertSame(2, $cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testRemoveTreeDeletesLinksWithoutFollowingThem(): void
    {
        mkdir($this->dir . '-target');
        file_put_contents($this->dir . '-target/keep.txt', 'x');
        mkdir($this->dir);
        symlink($this->dir . '-target', $this->dir . '/link');
        self::assertSame(1, Cache::removeTree($this->dir));
        self::assertFileExists($this->dir . '-target/keep.txt');
    }
}
