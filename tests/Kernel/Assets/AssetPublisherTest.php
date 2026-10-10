<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Assets;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Assets\AssetPublisher;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class AssetPublisherTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Fixtures::module($this->tmp . '/modules', 'shop', [], ['assets/shop.css' => 'shop{}', 'assets/img/logo.svg' => '<svg/>']);
        Fixtures::module($this->tmp . '/modules', 'bare');
        Fixtures::theme($this->tmp . '/themes', 'plain', [], ['assets/site.css' => 'body{}']);
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([$this->tmp . '/modules'], [
            'themes.paths' => [$this->tmp . '/themes'],
            'app.theme' => 'plain',
            'assets.path' => $this->tmp . '/public/assets',
            ...$overrides,
        ]);
    }

    public function testSymlinksModuleAndThemeAssets(): void
    {
        $done = $this->app()->container()->get(AssetPublisher::class)->publish();
        self::assertSame(
            [['module', 'shop', 'symlink'], ['theme', 'plain', 'symlink']],
            array_map(fn(array $row): array => [$row['kind'], $row['name'], $row['method']], $done),
        );
        self::assertTrue(is_link($this->tmp . '/public/assets/module/shop'));
        self::assertSame('<svg/>', file_get_contents($this->tmp . '/public/assets/module/shop/img/logo.svg'));
        self::assertSame('body{}', file_get_contents($this->tmp . '/public/assets/theme/plain/site.css'));
        self::assertFileDoesNotExist($this->tmp . '/public/assets/module/bare');
    }

    public function testRepublishingReplacesLinksAndCopiesWithoutTouchingSources(): void
    {
        $publisher = $this->app()->container()->get(AssetPublisher::class);
        $publisher->publish();
        $publisher->publish(copy: true);
        self::assertFalse(is_link($this->tmp . '/public/assets/module/shop'));
        self::assertSame('shop{}', file_get_contents($this->tmp . '/public/assets/module/shop/shop.css'));
        self::assertFileExists($this->tmp . '/modules/shop/assets/shop.css', 'replacing a link must not delete its source');
        $publisher->publish();
        self::assertTrue(is_link($this->tmp . '/public/assets/module/shop'));
        self::assertFileExists($this->tmp . '/modules/shop/assets/img/logo.svg', 'replacing a copy must not touch the source');
    }

    public function testLinkedKindDirectoryCannotRedirectWritesOutsideTarget(): void
    {
        mkdir($this->tmp . '/outside', 0775, true);
        mkdir($this->tmp . '/public/assets', 0775, true);
        symlink($this->tmp . '/outside', $this->tmp . '/public/assets/module');
        $this->app()->container()->get(AssetPublisher::class)->publish(copy: true);
        self::assertSame([], array_values(array_diff((array) scandir($this->tmp . '/outside'), ['.', '..'])));
        self::assertFileExists($this->tmp . '/public/assets/module/shop/shop.css');
    }

    public function testCommandPrintsWhatItPublished(): void
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        self::assertSame(0, (new Application($this->app()))->run(['xar', 'asset:publish', '--copy'], new Output($out, $out)));
        rewind($out);
        $text = (string) stream_get_contents($out);
        self::assertMatchesRegularExpression('/module\s+shop\s+copy/', $text);
        self::assertMatchesRegularExpression('/theme\s+plain\s+copy/', $text);
    }

    public function testAssetHelperUsesTheConfiguredUrl(): void
    {
        $x = $this->app(['assets.url' => 'https://cdn.test/a/'])->container()->get(View::class)->helpers();
        self::assertSame('https://cdn.test/a/theme/plain/site.css', $x->asset('/theme/plain/site.css'));
    }
}
