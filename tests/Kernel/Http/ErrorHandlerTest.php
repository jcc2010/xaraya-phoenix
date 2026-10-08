<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Pipeline;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = "{$level}: {$message}";
    }
}

final class ErrorHandlerTest extends TestCase
{
    private function send(ErrorHandler $eh, ServerRequestInterface $request, \Throwable $error): \Psr\Http\Message\ResponseInterface
    {
        return (new Pipeline([$eh], new CallableHandler(fn() => throw $error)))->handle($request);
    }

    public function testPassesThroughSuccess(): void
    {
        $eh = new ErrorHandler(new MemoryLogger());
        $response = (new Pipeline([$eh], new CallableHandler(fn() => new Response(204))))->handle(new ServerRequest('GET', '/'));
        self::assertSame(204, $response->getStatusCode());
    }

    public function testHttpExceptionAsHtml(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger()), new ServerRequest('GET', '/missing'), new NotFound());
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Not Found', (string) $response->getBody());
    }

    public function testJsonForJsonPathsAndAcceptHeader(): void
    {
        $eh = new ErrorHandler(new MemoryLogger());
        $r1 = $this->send($eh, new ServerRequest('GET', '/x/feed.json'), new NotFound());
        self::assertSame(['error' => ['status' => 404, 'message' => 'Not Found']], json_decode((string) $r1->getBody(), true));

        $r2 = $this->send($eh, (new ServerRequest('GET', '/x'))->withHeader('Accept', 'application/json'), new NotFound());
        self::assertStringContainsString('application/json', $r2->getHeaderLine('Content-Type'));
    }

    public function testExceptionHeadersArePreserved(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger()), new ServerRequest('PUT', '/x'), new MethodNotAllowed(['GET', 'POST']));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testUnexpectedErrorIsLoggedAndHiddenUnlessDebug(): void
    {
        $logger = new MemoryLogger();
        $response = $this->send(new ErrorHandler($logger), new ServerRequest('GET', '/x.json'), new \RuntimeException('db password leaked'));
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('leaked', (string) $response->getBody());
        self::assertSame(['error: db password leaked'], $logger->lines);

        $debug = $this->send(new ErrorHandler(new MemoryLogger(), debug: true), new ServerRequest('GET', '/x.json'), new \RuntimeException('visible'));
        $body = json_decode((string) $debug->getBody(), true);
        self::assertSame('visible', $body['error']['message']);
        self::assertArrayHasKey('trace', $body['error']);
    }

    public function testCustomHtmlRenderer(): void
    {
        $eh = new ErrorHandler(new MemoryLogger(), false, fn(int $status, string $message) => "<h1>Custom {$status}</h1>");
        $response = $this->send($eh, new ServerRequest('GET', '/x'), new NotFound());
        self::assertSame('<h1>Custom 404</h1>', (string) $response->getBody());
    }

    public function testServerHttpExceptionIsMaskedAndLogged(): void
    {
        $logger = new MemoryLogger();
        $e = new HttpException(503, 'DB host db1.internal down');
        $response = $this->send(new ErrorHandler($logger), new ServerRequest('GET', '/x'), $e);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('db1.internal', (string) $response->getBody());
        self::assertStringContainsString('Service Unavailable', (string) $response->getBody());
        self::assertSame(['error: DB host db1.internal down'], $logger->lines);

        $debug = $this->send(new ErrorHandler(new MemoryLogger(), debug: true), new ServerRequest('GET', '/x'), $e);
        self::assertStringContainsString('db1.internal', (string) $debug->getBody());
    }

    public function testInvalidUtf8MessageInDebugStillYieldsJson(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger(), debug: true), new ServerRequest('GET', '/x.json'), new \RuntimeException("bad \xB1 bytes"));
        self::assertSame(500, $response->getStatusCode());
        self::assertIsArray(json_decode((string) $response->getBody(), true));
    }

    public function testThrowingRendererFallsBack(): void
    {
        $eh = new ErrorHandler(new MemoryLogger(), false, function (): never {
            throw new \RuntimeException('boom');
        });
        $response = $this->send($eh, new ServerRequest('GET', '/x'), new NotFound());
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('Not Found', (string) $response->getBody());
    }

    public function testThrowingLoggerStillReturns500(): void
    {
        $logger = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger down');
            }
        };
        $response = $this->send(new ErrorHandler($logger), new ServerRequest('GET', '/x'), new \RuntimeException('x'));
        self::assertSame(500, $response->getStatusCode());
    }
}
