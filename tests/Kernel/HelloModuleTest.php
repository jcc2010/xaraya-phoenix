<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Hello\CountGreetings;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Html;

final class HelloModuleTest extends AppTestCase
{
    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([dirname(__DIR__, 2) . '/examples'], [
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.locale' => 'en',
            ...$overrides,
        ]);
    }

    private function xar(string ...$args): void
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        $code = (new Application($this->app()))->run(['xar', ...$args], new Output($out, $out));
        rewind($out);
        self::assertSame(0, $code, (string) stream_get_contents($out));
    }

    private function enableHello(): void
    {
        $this->xar('module:enable', 'hello');
    }

    /** @param array<string, string> $body */
    private function greet(App $app, string $name, array $body = []): string
    {
        $request = new ServerRequest('POST', '/hello/' . $name);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        $response = $app->handle($request);
        self::assertSame(201, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['id'];
    }

    private function html(App $app, string $path): string
    {
        return Html::normalize((string) $app->handle(new ServerRequest('GET', $path))->getBody());
    }

    public function testRoutesAreAbsentUntilEnabled(): void
    {
        self::assertSame(404, $this->app()->handle(new ServerRequest('GET', '/hello'))->getStatusCode());
    }

    public function testCreateListAndEvents(): void
    {
        $this->enableHello();
        self::assertTrue($this->app()->container()->get(Connection::class)->hasTable('greetings'));

        $app = $this->app();
        $id = $this->greet($app, 'ada');
        self::assertTrue(Ulid::isValid($id));
        self::assertSame(1, $app->container()->get(CountGreetings::class)->count);

        $page = $app->handle(new ServerRequest('GET', '/hello'));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Hello, ada!', (string) $page->getBody());
    }

    public function testJsonFeedWithCorsAndConditionalGet(): void
    {
        $this->enableHello();
        $app = $this->app();
        $this->greet($app, 'grace');

        $feed = $app->handle(new ServerRequest('GET', '/hello.json'));
        self::assertSame(200, $feed->getStatusCode());
        self::assertSame('*', $feed->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(['greetings' => ['grace']], json_decode((string) $feed->getBody(), true));
        $etag = $feed->getHeaderLine('ETag');
        self::assertNotSame('', $etag);

        $again = $app->handle((new ServerRequest('GET', '/hello.json'))->withHeader('If-None-Match', $etag));
        self::assertSame(304, $again->getStatusCode());
        self::assertSame('*', $again->getHeaderLine('Access-Control-Allow-Origin'));

        $preflight = $app->handle(new ServerRequest('OPTIONS', '/hello.json'));
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testRoutingErrors(): void
    {
        $this->enableHello();
        $app = $this->app();

        $wrongMethod = $app->handle(new ServerRequest('DELETE', '/hello'));
        self::assertSame(405, $wrongMethod->getStatusCode());
        self::assertSame('GET', $wrongMethod->getHeaderLine('Allow'));

        self::assertSame(404, $app->handle(new ServerRequest('POST', '/hello/Not-Lowercase'))->getStatusCode());
    }

    public function testUrlGeneration(): void
    {
        $this->enableHello();
        $urls = $this->app()->container()->get(UrlGenerator::class);
        self::assertSame('http://xar.test/hello/ada', $urls->generate('hello.create', ['name' => 'ada'], true));
    }

    public function testPagesUseThePhoenixThemeAndSecurityHeaders(): void
    {
        $this->enableHello();
        $app = $this->app();
        $id = $this->greet($app, 'ada');

        $index = $app->handle(new ServerRequest('GET', '/hello'));
        self::assertSame(200, $index->getStatusCode());
        self::assertSame('nosniff', $index->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $index->getHeaderLine('ETag'));
        $html = Html::normalize((string) $index->getBody());
        self::assertStringContainsString('<a class="skip-link" href="#main">', $html);
        self::assertStringContainsString('<title>Hello</title>', $html);
        self::assertStringContainsString('<a href="/hello/' . $id . '">Hello, ada!</a>', $html);

        $missing = $app->handle(new ServerRequest('GET', '/hello/' . Ulid::generate()));
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString("default-src 'self'", $missing->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('<h1>Page not found</h1>', (string) $missing->getBody());
    }

    public function testTemplatesRenderIdenticallyInBothEngines(): void
    {
        $this->xar('module:enable', 'hello');
        $this->xar('module:enable', 'hello-hooks');
        $id = $this->greet($this->app(), 'grace', ['note' => 'parity']);
        foreach (['/hello', '/hello/' . $id] as $path) {
            self::assertSame(
                $this->html($this->app(['view.engine' => 'php']), $path),
                $this->html($this->app(['view.engine' => 'twig']), $path),
                $path,
            );
        }
    }

    public function testSidebarBlockHidesWhenItsVisibilityRuleExcludesTheRoute(): void
    {
        $this->enableHello();
        $app = $this->app();
        $id = $this->greet($app, 'ada');
        self::assertStringContainsString(
            '<aside class="site-sidebar" aria-label="Sidebar"><section class="block block-hello-count" aria-labelledby="block-1-title"><h2 class="block-title" id="block-1-title">Greetings</h2><p class="greeting-count">Greetings so far: 1</p></section></aside>',
            $this->html($app, '/hello'),
        );
        self::assertStringNotContainsString('greeting-count', $this->html($app, '/hello/' . $id));
    }

    public function testHelloHooksAddsAFragmentThatFollowsTheBinding(): void
    {
        $this->xar('module:enable', 'hello');
        $this->xar('module:enable', 'hello-hooks');
        $id = $this->greet($this->app(), 'ada', ['note' => 'likes engines']);
        self::assertStringContainsString('<aside class="hello-hooks" aria-label="Note">Note: likes engines</aside>', $this->html($this->app(), '/hello/' . $id));

        $this->xar('hook:disable', 'hello-hooks', 'hello', 'greeting');
        self::assertStringNotContainsString('hello-hooks', $this->html($this->app(), '/hello/' . $id));

        $this->xar('hook:enable', 'hello-hooks', 'hello', 'greeting');
        self::assertStringContainsString('Note: likes engines', $this->html($this->app(), '/hello/' . $id));
    }

    public function testHelloIsTranslatable(): void
    {
        $this->enableHello();
        $app = $this->app(['app.locale' => 'fr']);
        $this->greet($app, 'ada');
        $html = $this->html($app, '/hello');
        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringContainsString('<h1>Salutations</h1>', $html);
        self::assertStringContainsString('Bonjour, ada !', $html);
    }
}
