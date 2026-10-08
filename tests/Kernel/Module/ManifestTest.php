<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Module;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;

final class ManifestTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/modules';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            @unlink($dir . '/module.json');
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    public function testReadsAlpha(): void
    {
        $m = Manifest::fromDirectory(self::FIXTURES . '/alpha');
        self::assertSame('alpha', $m->name);
        self::assertSame('1.2.0', $m->version);
        self::assertSame('Xaraya\\Module\\Alpha\\', $m->namespace());
        self::assertSame(realpath(self::FIXTURES . '/alpha') . '/migrations', $m->migrationsPath());
        self::assertTrue($m->isContent());
        self::assertSame([['event' => 'Xaraya\\Kernel\\Events\\ItemCreated', 'listener' => 'Xaraya\\Module\\Alpha\\Thing', 'priority' => 5]], $m->subscribers());
        self::assertSame(['Xaraya\\Module\\Alpha\\AlphaPingCommand'], $m->commands());
        self::assertNull($m->routes());
        self::assertNull($m->provider());
        self::assertSame([], $m->requiredModules());
    }

    public function testReadsBetaRequirements(): void
    {
        $m = Manifest::fromDirectory(self::FIXTURES . '/beta');
        self::assertSame(['alpha' => '*'], $m->requiredModules());
        self::assertNull($m->migrationsPath());
        self::assertFalse($m->isContent());
    }

    public function testStudlyNamespaceForHyphenatedNames(): void
    {
        $dir = $this->tempModule('{"name": "hello-hooks", "version": "0.1.0"}');
        self::assertSame('Xaraya\\Module\\HelloHooks\\', Manifest::fromDirectory($dir)->namespace());
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ModuleException::class);
        Manifest::fromDirectory($this->tempModule('{nope'));
    }

    public function testBadNameThrows(): void
    {
        $this->expectException(ModuleException::class);
        Manifest::fromDirectory($this->tempModule('{"name": "Bad Name", "version": "1"}'));
    }

    public function testNameWithTrailingNewlineThrows(): void
    {
        $this->expectException(ModuleException::class);
        Manifest::fromDirectory($this->tempModule('{"name": "ok\n", "version": "1"}'));
    }

    public function testMissingVersionThrows(): void
    {
        $this->expectException(ModuleException::class);
        Manifest::fromDirectory($this->tempModule('{"name": "ok"}'));
    }

    private function tempModule(string $json): string
    {
        $dir = sys_get_temp_dir() . '/xar-mod-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $this->tempDirs[] = $dir;
        file_put_contents($dir . '/module.json', $json);

        return $dir;
    }
}
