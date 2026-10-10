<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Pipeline;

final class SecureHtmlTest extends TestCase
{
    private const CSP = "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self'; script-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'";

    private function through(\Closure $final): ResponseInterface
    {
        return (new Pipeline([new ErrorHandler(new NullLogger()), new SecureHtml()], new CallableHandler($final)))
            ->handle(new ServerRequest('GET', '/x'));
    }

    public function testAddsTheSecurityHeaders(): void
    {
        $response = $this->through(fn() => new Response(200, [], 'ok'));
        self::assertSame(self::CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testKeepsAPolicyTheRouteSetItself(): void
    {
        $response = $this->through(fn() => new Response(200, ['Content-Security-Policy' => "default-src 'none'"]));
        self::assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testErrorPagesCarryTheHeaders(): void
    {
        $notAllowed = $this->through(fn() => throw new MethodNotAllowed(['GET']));
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertSame('GET', $notAllowed->getHeaderLine('Allow'));
        self::assertSame('nosniff', $notAllowed->getHeaderLine('X-Content-Type-Options'));

        $broken = $this->through(fn() => throw new \RuntimeException('db down'));
        self::assertSame(500, $broken->getStatusCode());
        self::assertSame(self::CSP, $broken->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('db down', (string) $broken->getBody());
    }
}
