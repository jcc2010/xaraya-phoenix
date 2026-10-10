<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use FilesystemIterator;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Settings\Settings;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

final class PhoenixThemeTest extends AppTestCase
{
    private function theme(): string
    {
        return dirname(__DIR__, 3) . '/themes/phoenix';
    }

    /**
     * @param list<string> $modulePaths
     * @param array<string, mixed> $overrides
     */
    private function app(string $engine, array $modulePaths = [], array $overrides = []): App
    {
        return $this->boot($modulePaths, [
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.name' => 'Phoenix Test',
            'app.locale' => 'en',
            'view.engine' => $engine,
            ...$overrides,
        ]);
    }

    private static function sameHtml(ResponseInterface $php, ResponseInterface $twig): string
    {
        self::assertSame($php->getStatusCode(), $twig->getStatusCode());
        $html = Html::normalize((string) $php->getBody());
        self::assertSame($html, Html::normalize((string) $twig->getBody()));

        return $html;
    }

    public function testEveryTemplateShipsInBothEngines(): void
    {
        $names = ['php' => [], 'twig' => []];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->theme() . '/templates', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($this->theme() . '/templates/'));
            $extension = $file->getExtension();
            self::assertContains($extension, ['php', 'twig'], $relative);
            $names[$extension][] = substr($relative, 0, -strlen($extension) - 1);
        }
        sort($names['php']);
        sort($names['twig']);
        self::assertSame(['block', 'error/404', 'error/default', 'home', 'layout'], $names['php']);
        self::assertSame($names['php'], $names['twig']);
    }

    public function testHomePageIsIdenticalInBothEnginesAndAccessible(): void
    {
        $php = $this->app('php')->handle(new ServerRequest('GET', '/'));
        $twig = $this->app('twig')->handle(new ServerRequest('GET', '/'));
        self::assertSame(200, $php->getStatusCode());
        self::assertSame('nosniff', $php->getHeaderLine('X-Content-Type-Options'));
        $html = self::sameHtml($php, $twig);
        foreach ([
            '<html lang="en">',
            '<title>Phoenix Test</title>',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css">',
            '<a class="skip-link" href="#main">Skip to content</a>',
            '<header class="site-header"><p class="site-title"><a href="/" rel="home">Phoenix Test</a></p></header>',
            '<main id="main" class="site-main" tabindex="-1"><h1>Welcome to Phoenix Test</h1>',
            '<footer class="site-footer"><p class="site-credit">Powered by Xaraya Phoenix</p></footer>',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringNotContainsString('<aside', $html, 'an empty sidebar is left out');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testErrorPagesAreIdenticalInBothEngines(): void
    {
        $html = self::sameHtml(
            $this->app('php')->handle(new ServerRequest('GET', '/nope')),
            $this->app('twig')->handle(new ServerRequest('GET', '/nope')),
        );
        self::assertStringContainsString('<title>404 Not Found</title>', $html);
        self::assertStringContainsString('<h1>Page not found</h1>', $html);

        $html = self::sameHtml(
            $this->app('php')->handle(new ServerRequest('DELETE', '/')),
            $this->app('twig')->handle(new ServerRequest('DELETE', '/')),
        );
        self::assertStringContainsString('<h1>405 Method Not Allowed</h1>', $html);
    }

    public function testRegionsMetaFeedsAndSettingsAreIdenticalInBothEngines(): void
    {
        $app = $this->app('php');
        $this->migrateKernel($app);
        $blocks = $app->container()->get(BlockRepository::class);
        $blocks->create('text', 'header', null, ['body' => '<p>head</p>']);
        $blocks->create('text', 'sidebar', 'Side', ['body' => '<p>side</p>']);
        $blocks->create('text', 'footer', null, ['format' => 'markdown', 'body' => 'foot *note*']);
        $app->container()->get(Settings::class)->set('theme.phoenix', 'tagline', 'Small & neutral');
        $page = new Page(
            'home',
            title: 'Custom',
            meta: ['description' => 'D', 'og:type' => 'website'],
            feeds: [['type' => 'application/feed+json', 'title' => 'Feed', 'href' => '/feed.json']],
            canonical: 'http://xar.test/',
            lang: 'fr',
            styles: ['/assets/module/blog/blog.css'],
        );
        $rendered = [];
        foreach (['php', 'twig'] as $engine) {
            $rendered[$engine] = Html::normalize($this->app($engine)->container()->get(View::class)->page($page));
        }
        self::assertSame($rendered['php'], $rendered['twig']);
        foreach ([
            '<html lang="fr">',
            '<title>Custom</title>',
            '<meta name="description" content="D">',
            '<meta property="og:type" content="website">',
            '<link rel="canonical" href="http://xar.test/">',
            '<link rel="alternate" type="application/feed+json" title="Feed" href="/feed.json">',
            '<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css"><link rel="stylesheet" href="/assets/module/blog/blog.css">',
            '<p class="site-tagline">Small &amp; neutral</p><section class="block block-text"><p>head</p></section></header>',
            '<aside class="site-sidebar" aria-label="Sidebar"><section class="block block-text" aria-labelledby="block-2-title"><h2 class="block-title" id="block-2-title">Side</h2><p>side</p></section></aside>',
            '<p>foot<em>note</em></p>',
        ] as $needle) {
            self::assertStringContainsString($needle, $rendered['php']);
        }
    }

    public function testStylesheetHonoursUserPreferences(): void
    {
        $css = (string) file_get_contents($this->theme() . '/assets/phoenix.css');
        foreach (['prefers-color-scheme: dark', 'prefers-reduced-motion: reduce', ':focus-visible', '.skip-link:focus'] as $needle) {
            self::assertStringContainsString($needle, $css);
        }
        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('url(http', $css);
    }

    public function testAModuleCanClaimTheHomeRoute(): void
    {
        Fixtures::module($this->tmp . '/modules', 'front', ['routes' => 'Xaraya\\Module\\Front\\Routes'], ['src/Routes.php' => <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Xaraya\Module\Front;

            use Nyholm\Psr7\Response;
            use Xaraya\Kernel\Routing\RouteCollector;
            use Xaraya\Kernel\Routing\RouteProvider;

            final class Routes implements RouteProvider
            {
                public function routes(RouteCollector $routes): void
                {
                    $routes->get('/', fn() => new Response(200, [], 'front'), 'front.home');
                }
            }
            PHP]);
        $this->app('php', [$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('front');
        $app = $this->app('php', [$this->tmp . '/modules']);
        self::assertSame('front', (string) $app->handle(new ServerRequest('GET', '/'))->getBody());
        self::assertFalse($app->container()->get(UrlGenerator::class)->has('home'));
    }

    public function testHostileClientErrorMessagesAreEscapedInBothEngines(): void
    {
        Fixtures::module($this->tmp . '/modules', 'hostile', ['routes' => 'Xaraya\\Module\\Hostile\\Routes'], ['src/Routes.php' => <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Xaraya\Module\Hostile;

            use Xaraya\Kernel\Http\Exception\HttpException;
            use Xaraya\Kernel\Routing\RouteCollector;
            use Xaraya\Kernel\Routing\RouteProvider;

            final class Routes implements RouteProvider
            {
                public function routes(RouteCollector $routes): void
                {
                    $routes->get('/missing', fn() => throw new HttpException(404, '<script>alert(1)</script>'), 'hostile.missing');
                    $routes->get('/bad', fn() => throw new HttpException(400, '<script>alert(1)</script>'), 'hostile.bad');
                }
            }
            PHP]);
        $this->app('php', [$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('hostile');
        foreach (['/missing', '/bad'] as $path) {
            $html = self::sameHtml(
                $this->app('php', [$this->tmp . '/modules'])->handle(new ServerRequest('GET', $path)),
                $this->app('twig', [$this->tmp . '/modules'])->handle(new ServerRequest('GET', $path)),
            );
            self::assertStringContainsString('&lt;script&gt;', $html, $path);
            self::assertStringNotContainsString('<script', $html, $path);
        }
    }
}
