<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
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
        file_put_contents($this->tmp . '/cache/keep.txt', 'x');
        self::assertSame(1, $app->clearCache());
        self::assertFileDoesNotExist($this->tmp . '/cache/routes.php');
        self::assertFileExists($this->tmp . '/cache/keep.txt');
    }
}
