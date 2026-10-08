<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Pipeline;

final class ConditionalGetTest extends TestCase
{
    private function send(ServerRequestInterface $request, ?ResponseInterface $response = null): ResponseInterface
    {
        $response ??= new Response(200, ['Last-Modified' => 'Wed, 08 Oct 2026 15:07:16 GMT', 'Cache-Control' => 'max-age=60'], '{"items":[]}');

        return (new Pipeline([new ConditionalGet()], new CallableHandler(fn() => $response)))->handle($request);
    }

    public function testAddsWeakEtag(): void
    {
        $r = $this->send(new ServerRequest('GET', '/f'));
        self::assertSame('W/"' . sha1('{"items":[]}') . '"', $r->getHeaderLine('ETag'));
        self::assertSame('{"items":[]}', (string) $r->getBody());
    }

    public function testBodyIsRewindedAfterEtagGeneration(): void
    {
        $r = $this->send(new ServerRequest('GET', '/f'));
        self::assertSame(0, $r->getBody()->tell());
        self::assertSame('{"items":[]}', $r->getBody()->getContents());
    }

    public function testIfNoneMatchGives304(): void
    {
        $etag = $this->send(new ServerRequest('GET', '/f'))->getHeaderLine('ETag');
        $r = $this->send((new ServerRequest('GET', '/f'))->withHeader('If-None-Match', '"other", ' . substr($etag, 2)));
        self::assertSame(304, $r->getStatusCode());
        self::assertSame('', (string) $r->getBody());
        self::assertSame($etag, $r->getHeaderLine('ETag'));
        self::assertSame('max-age=60', $r->getHeaderLine('Cache-Control'));
    }

    public function testNonMatchingEtagGives200EvenIfModifiedSinceMatches(): void
    {
        $r = $this->send((new ServerRequest('GET', '/f'))
            ->withHeader('If-None-Match', '"stale"')
            ->withHeader('If-Modified-Since', 'Thu, 09 Oct 2026 00:00:00 GMT'));
        self::assertSame(200, $r->getStatusCode());
    }

    public function testIfModifiedSince(): void
    {
        self::assertSame(304, $this->send((new ServerRequest('GET', '/f'))->withHeader('If-Modified-Since', 'Wed, 08 Oct 2026 15:07:16 GMT'))->getStatusCode());
        self::assertSame(200, $this->send((new ServerRequest('GET', '/f'))->withHeader('If-Modified-Since', 'Tue, 07 Oct 2026 00:00:00 GMT'))->getStatusCode());
    }

    public function testIgnoresNonGetAndNon200(): void
    {
        self::assertFalse($this->send(new ServerRequest('POST', '/f'))->hasHeader('ETag'));
        self::assertFalse($this->send(new ServerRequest('GET', '/f'), new Response(404, [], 'x'))->hasHeader('ETag'));
    }

    public function testKeepsExistingEtag(): void
    {
        $r = $this->send(new ServerRequest('GET', '/f'), new Response(200, ['ETag' => '"v1"'], 'x'));
        self::assertSame('"v1"', $r->getHeaderLine('ETag'));
        self::assertSame(304, $this->send((new ServerRequest('GET', '/f'))->withHeader('If-None-Match', 'W/"v1"'), new Response(200, ['ETag' => '"v1"'], 'x'))->getStatusCode());
    }
}
