<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\TemplateLocator;
use Xaraya\Kernel\View\ThemeRegistry;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class TemplateLocatorTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $themes = $this->tmp . '/themes';
        Fixtures::theme($themes, 'base', ['engine' => 'php'], [
            'templates/layout.php' => 'base layout php',
            'templates/layout.twig' => 'base layout twig',
            'templates/error/404.php' => '404',
            'templates/modules/shop/item.twig' => 'base shop item twig',
        ]);
        Fixtures::theme($themes, 'kid', ['parent' => 'base'], [
            'templates/modules/shop/item.php' => 'kid shop item php',
            'templates/only-kid.twig' => 'kid only',
        ]);
        Fixtures::module($this->tmp . '/modules', 'shop', [], [
            'templates/item.php' => 'module item',
            'templates/list.php' => 'module list',
            'templates/partials/row.twig' => 'module row',
        ]);
    }

    private function locator(?string $engine = null, bool $twig = true): TemplateLocator
    {
        $c = $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid'])->container();

        return new TemplateLocator($c->get(ThemeRegistry::class), $c->get(ModuleRegistry::class), $engine, $twig);
    }

    private function path(string $relative): string
    {
        return realpath($this->tmp) . '/' . $relative;
    }

    public function testActiveThemeOverridesItsParentAndTheModule(): void
    {
        self::assertSame($this->path('themes/kid/templates/modules/shop/item.php'), $this->locator()->find('shop::item'));
    }

    public function testExtensionFilterSkipsToTheFirstMatchingFile(): void
    {
        $locator = $this->locator();
        self::assertSame($this->path('themes/base/templates/modules/shop/item.twig'), $locator->find('shop::item', 'twig'));
        self::assertSame($this->path('modules/shop/templates/partials/row.twig'), $locator->find('shop::partials/row', 'twig'));
        self::assertNull($locator->find('shop::partials/row', 'php'));
    }

    public function testModuleTemplatesAreTheLastResort(): void
    {
        self::assertSame($this->path('modules/shop/templates/list.php'), $this->locator()->find('shop::list'));
        self::assertNull($this->locator()->find('shop::nothing'));
    }

    public function testThemeLevelTemplatesFollowTheChainAndThePreference(): void
    {
        self::assertSame($this->path('themes/base/templates/layout.php'), $this->locator()->find('layout'));
        self::assertSame($this->path('themes/base/templates/layout.twig'), $this->locator('twig')->find('layout'));
        self::assertSame($this->path('themes/base/templates/layout.php'), $this->locator('twig', twig: false)->find('layout'), 'without Twig a .php sibling wins');
        self::assertSame($this->path('themes/kid/templates/only-kid.twig'), $this->locator(twig: false)->find('only-kid'), 'a lone .twig file is still found');
        self::assertSame($this->path('themes/base/templates/error/404.php'), $this->locator()->find('error/404'));
    }

    public function testPreferredEngine(): void
    {
        self::assertSame('php', $this->locator()->preferred());
        self::assertSame('twig', $this->locator('twig')->preferred());
        self::assertSame('php', $this->locator('twig', twig: false)->preferred());
    }

    public function testDirectoriesListTheLookupOrder(): void
    {
        $locator = $this->locator();
        self::assertSame([
            $this->path('themes/kid/templates/modules/shop'),
            $this->path('themes/base/templates/modules/shop'),
            $this->path('modules/shop/templates'),
        ], $locator->directories('shop::item'));
        self::assertSame([$this->path('themes/kid/templates'), $this->path('themes/base/templates')], $locator->directories('error/404'));
        self::assertSame([
            $this->path('themes/kid/templates/modules/ghost'),
            $this->path('themes/base/templates/modules/ghost'),
        ], $locator->directories('ghost::x'));
    }

    public function testInvalidNamesAndEnginesThrow(): void
    {
        foreach (['../secret', 'shop::', 'a//b', 'Shop::x', 'shop::../x', 'x.php', ''] as $name) {
            try {
                $this->locator()->find($name);
                self::fail("'{$name}' should be rejected");
            } catch (ViewException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(ViewException::class);
        $this->locator('smarty');
    }

    public function testTraversalAndUnsafeNamesAreRejected(): void
    {
        foreach (['../x', '/etc/passwd', "layout\0", "shop::item\0", 'module::../x', 'shop::../../x', "layout\n", '..', 'a/../b', 'a\\b'] as $name) {
            try {
                $this->locator()->find($name);
                self::fail('rejected: ' . addcslashes($name, "\0\n"));
            } catch (ViewException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testConfiguredEngineOverridesTheThemeAndIsValidated(): void
    {
        $c = $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid', 'view.engine' => 'twig'])->container();
        $expected = class_exists(\Twig\Environment::class) ? 'twig' : 'php';
        self::assertSame($expected, $c->get(TemplateLocator::class)->preferred());

        $c = $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid', 'view.engine' => ''])->container();
        self::assertSame('php', $c->get(TemplateLocator::class)->preferred());

        $this->expectException(ViewException::class);
        $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid', 'view.engine' => 'smarty'])->container()->get(TemplateLocator::class);
    }
}
