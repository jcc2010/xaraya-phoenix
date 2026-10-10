<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\View\Theme;
use Xaraya\Kernel\View\ThemeRegistry;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class ThemeRegistryTest extends AppTestCase
{
    private function registry(string $active): ThemeRegistry
    {
        return new ThemeRegistry([$this->tmp . '/themes'], $active);
    }

    public function testDiscoversThemesWithDefaults(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'base');
        $theme = $this->registry('base')->active();
        self::assertSame('base', $theme->name);
        self::assertSame('1.0.0', $theme->version);
        self::assertSame(realpath($this->tmp . '/themes/base'), $theme->path);
        self::assertNull($theme->parent);
        self::assertSame('php', $theme->engine);
        self::assertSame([], $theme->regions);
        self::assertSame([], $theme->settings);
    }

    public function testChainRegionsAndSettingsFollowTheParent(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'base', [
            'engine' => 'twig',
            'regions' => ['header', 'sidebar'],
            'settings' => ['tagline' => ['default' => 'Base'], 'accent' => ['default' => 'blue']],
        ]);
        Fixtures::theme($this->tmp . '/themes', 'kid', ['parent' => 'base', 'settings' => ['tagline' => ['default' => 'Kid']]]);
        $registry = $this->registry('kid');
        self::assertSame(['kid', 'base'], array_map(fn(Theme $t): string => $t->name, $registry->chain()));
        self::assertSame(['header', 'sidebar'], $registry->regions());
        self::assertSame(['tagline' => 'Kid', 'accent' => 'blue'], $registry->settingDefaults());
    }

    public function testUnknownActiveThemeThrows(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage("Unknown theme 'kid'");
        $this->registry('kid')->active();
    }

    public function testCircularParentsThrow(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'aa', ['parent' => 'bb']);
        Fixtures::theme($this->tmp . '/themes', 'bb', ['parent' => 'aa']);
        $this->expectExceptionMessage('Circular theme parents: aa -> bb -> aa');
        $this->registry('aa')->chain();
    }

    public function testInvalidManifestsThrow(): void
    {
        $bad = [['engine' => 'smarty'], ['regions' => ['Bad Region']], ['parent' => 'Not-Lower'], ['settings' => ['tagline' => 'not-an-object']], ['version' => '']];
        foreach ($bad as $i => $json) {
            $dir = Fixtures::theme($this->tmp . '/bad', 'bad' . $i, $json);
            try {
                Theme::fromDirectory($dir);
                self::fail('expected a ViewException for ' . json_encode($json));
            } catch (ViewException) {
                self::addToAssertionCount(1);
            }
        }
        file_put_contents($this->tmp . '/bad/bad0/theme.json', '{not json');
        $this->expectExceptionMessage('Invalid JSON');
        Theme::fromDirectory($this->tmp . '/bad/bad0');
    }

    public function testDuplicateNamesAcrossPathsThrow(): void
    {
        Fixtures::theme($this->tmp . '/one', 'same');
        Fixtures::theme($this->tmp . '/two', 'same');
        $this->expectExceptionMessage("Theme 'same' found twice");
        (new ThemeRegistry([$this->tmp . '/one', $this->tmp . '/two'], 'same'))->discover();
    }

    public function testAppWiresPathsAndActiveThemeFromConfig(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'kid');
        $app = $this->boot([], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid']);
        self::assertSame('kid', $app->container()->get(ThemeRegistry::class)->active()->name);
    }
}
