<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\GuestAccess;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\PhpEngine;
use Xaraya\Kernel\View\TemplateLocator;
use Xaraya\Kernel\View\TwigEngine;
use Xaraya\Kernel\View\View;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\ViewFixtures;

final class ViewTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ViewFixtures::themes($this->tmp . '/themes');
        ViewFixtures::shop($this->tmp . '/modules');
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'plain', ...$overrides]);
    }

    private function view(string $engine = 'php'): View
    {
        return $this->app(['view.engine' => $engine])->container()->get(View::class);
    }

    public function testRendersPhpAndTwigTemplatesWithTheSameOutput(): void
    {
        foreach (['php', 'twig'] as $engine) {
            self::assertSame('<p>&lt;Ada&gt;</p>', trim($this->view($engine)->render('hello', ['name' => '<Ada>'])), $engine);
        }
    }

    public function testHelpersBehaveTheSameInBothEngines(): void
    {
        $this->app()->container()->get(ModuleRegistry::class)->enable('shop');
        $expected = '<a href="/shop/7">Item 7</a>|/assets/theme/plain/site.css|r|http://xar.test|guest||&lt;b&gt;|&lt;i&gt;';
        foreach (['php', 'twig'] as $engine) {
            self::assertSame($expected, trim($this->view($engine)->render('helpers')), $engine);
        }
    }

    public function testIncludeStaysInItsEngineAndRenderCrossesEngines(): void
    {
        foreach (['php', 'twig'] as $engine) {
            self::assertSame('<div><b>v</b></div>', trim($this->view($engine)->render('page', ['v' => 'v'])), $engine);
        }
        self::assertSame('<i><u>x</u></i>', trim($this->view()->render('mixed', ['v' => 'x'])));
    }

    public function testModuleTemplatesRender(): void
    {
        self::assertSame('<p>Item 3</p>', trim($this->view('twig')->render('shop::item', ['id' => 3])));
        self::assertTrue($this->view()->exists('shop::list'));
        self::assertFalse($this->view()->exists('shop::nothing'));
    }

    public function testMissingTemplateNamesTheDirectoriesSearched(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage("Template 'nope' not found in: ");
        $this->view()->render('nope');
    }

    public function testPhpTemplateFailureDiscardsItsOutputBuffer(): void
    {
        $level = ob_get_level();
        try {
            $this->view()->render('boom');
            self::fail('expected the template to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($level, ob_get_level());
    }

    public function testTwigMissingGivesAClearException(): void
    {
        $c = $this->app()->container();
        $engine = new TwigEngine($c->get(TemplateLocator::class), null, true, installed: false);
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('composer require twig/twig');
        $engine->render('hello', $this->tmp . '/themes/plain/templates/hello.twig', ['name' => 'x'], $c->get(View::class)->helpers());
    }

    public function testGuestAccessAllowsReadingOnly(): void
    {
        $access = new GuestAccess();
        self::assertTrue($access->can('overview'));
        self::assertTrue($access->can('read', 'blog', 'post'));
        self::assertFalse($access->can('comment'));
        self::assertFalse($access->can('admin'));
        self::assertNull($access->user());
        self::assertSame(['Anonymous'], $access->roles());
        $this->expectException(\InvalidArgumentException::class);
        $access->can('superuser');
    }

    public function testEscapeAndAssetGuards(): void
    {
        $x = $this->view()->helpers();
        self::assertSame('', $x->e(null));
        self::assertSame('&quot;a&quot; &amp; &#039;b&#039;', $x->e('"a" & \'b\''));
        try {
            $x->e(['array']);
            self::fail('arrays cannot be escaped');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $x->asset('../config/app.php');
    }

    public function testMissingTemplateListsDirectoriesAndExtensions(): void
    {
        try {
            $this->view()->render('shop::nope');
            self::fail('expected a ViewException');
        } catch (ViewException $e) {
            self::assertStringContainsString($this->tmp . '/themes/plain/templates/modules/shop', $e->getMessage());
            self::assertStringContainsString($this->tmp . '/modules/shop/templates', $e->getMessage());
            self::assertStringContainsString('nope.php, nope.twig', $e->getMessage());
        }
        $view = $this->view();
        try {
            $view->renderWith('mixed', [], $view->helpers(), 'php');
            self::fail('expected a ViewException');
        } catch (ViewException $e) {
            self::assertStringContainsString("Template 'mixed' (php) not found in: ", $e->getMessage());
            self::assertStringContainsString('looking for mixed.php)', $e->getMessage());
        }
    }

    public function testTwigTemplateFoundWithoutTwigInstalledSaysHowToFixIt(): void
    {
        $c = $this->app()->container();
        $locator = $c->get(TemplateLocator::class);
        $view = new View($locator, new PhpEngine(), new TwigEngine($locator, null, true, installed: false), $c);
        try {
            $view->render('mixed', ['v' => 'x']);
            self::fail('expected a ViewException');
        } catch (ViewException $e) {
            self::assertStringContainsString('composer require twig/twig', $e->getMessage());
            self::assertStringContainsString('view.engine', $e->getMessage());
            self::assertStringContainsString('mixed.twig', $e->getMessage());
        }
    }

    public function testTemplateDataCannotOverwriteHelpersOrEngineInternals(): void
    {
        $data = ['name' => 'ok', 'x' => 'evil', '__file' => '/etc/passwd', '__data' => [], 'this' => 'evil', 'level' => 99];
        self::assertSame('<p>ok</p>', trim($this->view()->render('hello', $data)));
    }
}
