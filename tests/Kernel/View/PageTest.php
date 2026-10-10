<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Settings\Settings;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\View;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Html;
use Xaraya\Tests\Support\ViewFixtures;

final class PageTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ViewFixtures::themes($this->tmp . '/themes');
        ViewFixtures::shop($this->tmp . '/modules');
        $this->app()->container()->get(ModuleRegistry::class)->enable('shop');
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([$this->tmp . '/modules'], [
            'themes.paths' => [$this->tmp . '/themes'],
            'app.theme' => 'plain',
            'app.name' => 'Test Site',
            'app.locale' => 'en',
            ...$overrides,
        ]);
    }

    public function testControllersReturnPagesWrappedInTheThemeLayout(): void
    {
        $response = $this->app()->handle(new ServerRequest('GET', '/shop/7'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('1', $response->getHeaderLine('X-Shop'));
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<title>Item</title>', $html);
        self::assertStringContainsString('<meta name="description" content="An item">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Item 7">', $html);
        self::assertStringContainsString('<body data-tagline="Hi"><p>Item 7</p></body>', $html);
    }

    public function testControllerViewHelperSetsTitleAndStatus(): void
    {
        $app = $this->app();
        self::assertStringContainsString('<title>Shop</title>', (string) $app->handle(new ServerRequest('GET', '/shop'))->getBody());
        $gone = $app->handle(new ServerRequest('GET', '/shop/7/gone'));
        self::assertSame(410, $gone->getStatusCode());
        self::assertStringContainsString('<title>Gone</title>', (string) $gone->getBody());
    }

    public function testEmptyTitleFallsBackToTheSiteName(): void
    {
        $html = $this->app()->container()->get(View::class)->page(new Page('hello', ['name' => 'x']));
        self::assertStringContainsString('<title>Test Site</title>', $html);
    }

    public function testStoredThemeSettingsOverrideDefaults(): void
    {
        $app = $this->app();
        $settings = $app->container()->get(Settings::class);
        $settings->set('theme.plain', 'tagline', 'Yo');
        $settings->set('theme.plain', 'unknown', 'ignored');
        self::assertSame(['tagline' => 'Yo'], $this->app()->container()->get(View::class)->themeSettings());
        self::assertStringContainsString('data-tagline="Yo"', (string) $this->app()->handle(new ServerRequest('GET', '/shop/7'))->getBody());
    }

    public function testLangComesFromThePageOrTheLocale(): void
    {
        $view = $this->app(['app.locale' => 'pt_BR'])->container()->get(View::class);
        self::assertStringContainsString('<html lang="pt-BR">', $view->page(new Page('hello', ['name' => 'x'])));
        self::assertStringContainsString('<html lang="fr">', $view->page(new Page('hello', ['name' => 'x'], lang: 'fr')));
    }

    public function testBothEnginesProduceTheSamePage(): void
    {
        $bodies = [];
        foreach (['php', 'twig'] as $engine) {
            $bodies[$engine] = Html::normalize((string) $this->app(['view.engine' => $engine])->handle(new ServerRequest('GET', '/shop/7'))->getBody());
        }
        self::assertSame($bodies['php'], $bodies['twig']);
    }

    public function testSelfRenderingTemplateHitsTheDepthGuard(): void
    {
        $view = $this->app()->container()->get(View::class);
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('Template recursion deeper than 50');
        $view->render('loop');
    }

    public function testDepthIsRestoredAfterAFailedRender(): void
    {
        $view = $this->app()->container()->get(View::class);
        try {
            $view->render('loop');
        } catch (ViewException) {
        }
        self::assertSame('<p>x</p>', $view->render('hello', ['name' => 'x']));
    }

    public function testHttpExceptionsThrownInsideTwigKeepTheirTypeAndStatus(): void
    {
        $app = $this->app(['view.engine' => 'twig']);
        $response = $app->handle(new ServerRequest('GET', '/shop/lost'));
        self::assertSame(404, $response->getStatusCode());
        try {
            $app->container()->get(View::class)->render('lostwrap');
            self::fail('expected NotFound');
        } catch (NotFound $e) {
            self::assertSame(404, $e->status());
        }
    }
}
