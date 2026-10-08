<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Hello\CountGreetings;
use Xaraya\Tests\Support\AppTestCase;

final class HelloModuleTest extends AppTestCase
{
    private function app(): App
    {
        return $this->boot([dirname(__DIR__, 2) . '/examples']);
    }

    private function enableHello(): void
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        self::assertSame(0, (new Application($this->app()))->run(['xar', 'module:enable', 'hello'], new Output($out, $out)));
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
        $created = $app->handle(new ServerRequest('POST', '/hello/ada'));
        self::assertSame(201, $created->getStatusCode());
        $id = json_decode((string) $created->getBody(), true)['id'];
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
        $app->handle(new ServerRequest('POST', '/hello/grace'));

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
        $urls = $this->app()->container()->get(\Xaraya\Kernel\Routing\UrlGenerator::class);
        self::assertSame('http://xar.test/hello/ada', $urls->generate('hello.create', ['name' => 'ada'], true));
    }
}
