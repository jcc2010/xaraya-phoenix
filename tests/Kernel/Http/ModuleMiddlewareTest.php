<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class ModuleMiddlewareTest extends AppTestCase
{
    private const STAMP = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Xaraya\Module\Mw;

        use Psr\Http\Message\ResponseInterface;
        use Psr\Http\Message\ServerRequestInterface;
        use Psr\Http\Server\MiddlewareInterface;
        use Psr\Http\Server\RequestHandlerInterface;

        final class Stamp implements MiddlewareInterface
        {
            /** @param list<string> $args */
            public function __construct(private readonly array $args = []) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Stamp', implode('|', $this->args));
            }
        }
        PHP;

    private const ROUTES = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Xaraya\Module\Mw;

        use Nyholm\Psr7\Response;
        use Xaraya\Kernel\Routing\RouteCollector;
        use Xaraya\Kernel\Routing\RouteProvider;

        final class Routes implements RouteProvider
        {
            public function routes(RouteCollector $routes): void
            {
                $routes->get('/stamped', fn() => new Response(200, [], 'ok'), 'mw.stamped')->middleware('stamp:blog:write,media:write');
                $routes->get('/cors', fn() => new Response(200, [], 'ok'), 'mw.cors')->middleware('cors');
            }
        }
        PHP;

    private function app(string $middleware): App
    {
        Fixtures::module($this->tmp . '/modules', 'mw', [
            'routes' => 'Xaraya\\Module\\Mw\\Routes',
            'middleware' => json_decode($middleware, true),
        ], ['src/Stamp.php' => self::STAMP, 'src/Routes.php' => self::ROUTES]);
        $this->boot([$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('mw');

        return $this->boot([$this->tmp . '/modules']);
    }

    public function testAModuleAliasTakesColonArguments(): void
    {
        $response = $this->app('{"stamp": "Xaraya\\\\Module\\\\Mw\\\\Stamp"}')->handle(new ServerRequest('GET', '/stamped'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('blog:write|media:write', $response->getHeaderLine('X-Stamp'));
    }

    public function testAModuleCannotReplaceAKernelAlias(): void
    {
        $app = $this->app('{"stamp": "Xaraya\\\\Module\\\\Mw\\\\Stamp", "cors": "Xaraya\\\\Module\\\\Mw\\\\Stamp"}');
        $response = $app->handle(new ServerRequest('GET', '/cors'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('X-Stamp'));
        self::assertStringContainsString("Module 'mw' cannot register middleware 'cors': the alias is taken", (string) file_get_contents((string) glob($this->tmp . '/logs/*.log')[0]));
    }

    public function testEnableRejectsABadMiddlewareMap(): void
    {
        Fixtures::module($this->tmp . '/modules', 'bad', ['middleware' => ['Bad Alias' => 'X']]);
        $this->expectException(ModuleException::class);
        $this->boot([$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('bad');
    }
}
