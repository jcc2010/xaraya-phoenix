<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Pipeline;

final class CorsTest extends TestCase
{
    public function testAddsOriginHeaderToResponses(): void
    {
        $response = (new Pipeline([new Cors()], new CallableHandler(fn() => new Response(200))))->handle(new ServerRequest('GET', '/feed.json'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAnswersPreflightWithoutCallingHandler(): void
    {
        $request = (new ServerRequest('OPTIONS', '/feed.json'))->withHeader('Access-Control-Request-Headers', 'If-None-Match');
        $response = (new Pipeline([new Cors()], new CallableHandler(fn() => throw new \LogicException('not reached'))))->handle($request);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('If-None-Match', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testHttpExceptionsGainAllowOrigin(): void
    {
        $cause = new \RuntimeException('cause');
        try {
            (new Pipeline([new Cors()], new CallableHandler(fn() => throw new MethodNotAllowed(['GET']))))->handle(new ServerRequest('PUT', '/feed.json'));
            self::fail('expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status());
            self::assertSame(['Allow' => 'GET', 'Access-Control-Allow-Origin' => '*'], $e->headers());
        }
        try {
            (new Pipeline([new Cors()], new CallableHandler(fn() => throw new HttpException(400, 'bad page', [], $cause))))->handle(new ServerRequest('GET', '/feed.json'));
            self::fail('expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status());
            self::assertSame('bad page', $e->getMessage());
            self::assertSame($cause, $e->getPrevious());
            self::assertSame('*', $e->headers()['Access-Control-Allow-Origin']);
        }
    }

    public function testOtherFailuresBecome500WithAllowOrigin(): void
    {
        $cause = new \RuntimeException('db down');
        try {
            (new Pipeline([new Cors()], new CallableHandler(fn() => throw $cause)))->handle(new ServerRequest('GET', '/feed.json'));
            self::fail('expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(500, $e->status());
            self::assertSame($cause, $e->getPrevious());
            self::assertSame(['Access-Control-Allow-Origin' => '*'], $e->headers());
        }
    }
}
