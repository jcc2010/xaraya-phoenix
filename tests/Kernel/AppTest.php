<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Tests\Support\AppTestCase;

final class AppTest extends AppTestCase
{
    public function testBootWiresCoreServices(): void
    {
        $app = $this->boot();
        $c = $app->container();
        self::assertSame($app, $c->get(App::class));
        self::assertSame('http://xar.test', $c->get(Config::class)->get('app.url'));
        self::assertInstanceOf(Connection::class, $c->get(Connection::class));
        self::assertInstanceOf(LoggerInterface::class, $c->get(LoggerInterface::class));
        self::assertInstanceOf(EventDispatcher::class, $c->get(EventDispatcher::class));
        self::assertSame([], $c->get(ModuleRegistry::class)->enabled());
        self::assertFalse($c->get(UrlGenerator::class)->has('anything'));
        self::assertTrue($app->debug());
        self::assertSame(dirname(__DIR__, 2) . '/public', $app->path('public'));
        self::assertSame('/abs', $app->path('/abs'));
    }

    public function testUnknownRoutesGiveHtmlOrJson404(): void
    {
        $app = $this->boot();
        $html = $app->handle(new ServerRequest('GET', '/'));
        self::assertSame(404, $html->getStatusCode());
        self::assertStringContainsString('text/html', $html->getHeaderLine('Content-Type'));

        $json = $app->handle(new ServerRequest('GET', '/nope.json'));
        self::assertSame(404, $json->getStatusCode());
        self::assertSame(404, json_decode((string) $json->getBody(), true)['error']['status']);
    }

    public function testClearCacheRemovesPhpFiles(): void
    {
        $app = $this->boot();
        mkdir($this->tmp . '/cache');
        file_put_contents($this->tmp . '/cache/routes.php', '<?php return [];');
        file_put_contents($this->tmp . '/cache/routes-abc123def456.php', '<?php return [];');
        file_put_contents($this->tmp . '/cache/keep.txt', 'x');
        self::assertGreaterThanOrEqual(2, $app->clearCache());
        self::assertFileDoesNotExist($this->tmp . '/cache/routes.php');
        self::assertFileDoesNotExist($this->tmp . '/cache/routes-abc123def456.php');
        self::assertFileExists($this->tmp . '/cache/keep.txt');
    }

    public function testRouterFailureInProductionBecomes500(): void
    {
        $app = $this->boot([], ['app.debug' => false, 'app.cache' => '/dev/null/cache']);
        $response = $app->handle(new ServerRequest('GET', '/x'));
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('/dev/null', (string) $response->getBody());
    }

    public function testInvalidGlobalMiddlewareBecomes500(): void
    {
        $app = $this->boot();
        $app->pushMiddleware(\stdClass::class);
        self::assertSame(500, $app->handle(new ServerRequest('GET', '/'))->getStatusCode());
    }

    public function testProductionModeWritesFingerprintedRouteCache(): void
    {
        $app = $this->boot([], ['app.debug' => false]);
        self::assertSame(404, $app->handle(new ServerRequest('GET', '/nope'))->getStatusCode());
        self::assertCount(1, glob($this->tmp . '/cache/routes-*.php') ?: []);
    }

    public function testPushedMiddlewareIsApplied(): void
    {
        $dir = $this->tmp . '/modules/gamma';
        mkdir($dir . '/src', 0775, true);
        file_put_contents($dir . '/module.json', '{"name":"gamma","version":"1.0.0","routes":"Xaraya\\\\Module\\\\Gamma\\\\Routes"}');
        file_put_contents($dir . '/src/Routes.php', <<<'PHP'
            <?php

            namespace Xaraya\Module\Gamma;

            use Nyholm\Psr7\Response;
            use Xaraya\Kernel\Routing\RouteCollector;
            use Xaraya\Kernel\Routing\RouteProvider;

            final class Routes implements RouteProvider
            {
                public function routes(RouteCollector $routes): void
                {
                    $routes->get('/', fn () => new Response(200, [], 'ok'));
                }
            }
            PHP);
        $this->boot([$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('gamma');

        $app = $this->boot([$this->tmp . '/modules']);
        $app->pushMiddleware(HeaderMiddleware::class);
        $response = $app->handle(new ServerRequest('GET', '/'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Test'));
    }
}

final class HeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Test', 'yes');
    }
}
