<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Config;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Config\Config;

final class ConfigTest extends TestCase
{
    public function testDotNotationGetAndSet(): void
    {
        $c = new Config(['db' => ['dsn' => 'sqlite::memory:', 'prefix' => 'xar_'], 'app' => ['debug' => false]]);
        self::assertSame('sqlite::memory:', $c->get('db.dsn'));
        self::assertSame(['dsn' => 'sqlite::memory:', 'prefix' => 'xar_'], $c->get('db'));
        self::assertSame('x', $c->get('db.missing', 'x'));
        self::assertSame('x', $c->get('app.debug.deeper', 'x'));

        $c->set('mail.smtp.host', 'localhost');
        $c->set('db.prefix', 'xt_');
        self::assertSame('localhost', $c->get('mail.smtp.host'));
        self::assertSame('xt_', $c->get('db.prefix'));
        self::assertSame('sqlite::memory:', $c->get('db.dsn'));
    }

    public function testLoadAndCache(): void
    {
        $dir = sys_get_temp_dir() . '/xar-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/app.php';
        $cache = $dir . '/cache/config.php';
        file_put_contents($file, "<?php return ['app' => ['debug' => false, 'name' => 'one']];");

        self::assertSame('one', Config::load($file, $cache)->get('app.name'));
        self::assertFileExists($cache);

        file_put_contents($file, "<?php return ['app' => ['debug' => false, 'name' => 'two']];");
        self::assertSame('one', Config::load($file, $cache)->get('app.name'), 'cache is used while it exists');

        unlink($cache);
        file_put_contents($file, "<?php return ['app' => ['debug' => true, 'name' => 'three']];");
        self::assertSame('three', Config::load($file, $cache)->get('app.name'));
        self::assertFileDoesNotExist($cache, 'debug mode never writes the cache');

        unlink($file);
        rmdir($dir . '/cache');
        rmdir($dir);
    }

    public function testNonArrayConfigFileThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($file, '<?php return "nope";');
        $this->expectException(\RuntimeException::class);
        try {
            Config::load($file);
        } finally {
            unlink($file);
        }
    }

    public function testWriteCacheToUnwritableLocationThrows(): void
    {
        $blockingFile = tempnam(sys_get_temp_dir(), 'block');
        $cacheFile = $blockingFile . '/sub/config.php';
        $c = new Config(['test' => 'data']);

        $this->expectException(\RuntimeException::class);
        try {
            $c->writeCache($cacheFile);
        } finally {
            unlink($blockingFile);
            // Verify no temp file was left behind
            $pattern = dirname($cacheFile) === $blockingFile ? dirname($cacheFile) . '.*' : $cacheFile . '.*';
            foreach (glob($pattern) as $leftover) {
                unlink($leftover);
            }
        }
    }
}
