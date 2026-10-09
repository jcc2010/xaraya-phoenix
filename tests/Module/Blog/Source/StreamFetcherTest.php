<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\StreamFetcher;

final class StreamFetcherTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('no free port');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::$base = "http://127.0.0.1:{$port}";
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixture-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::$server = is_resource($server) ? $server : null;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        self::tearDownAfterClass();

        throw new RuntimeException('fixture server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    public function testFetchesJsonWithEtagAndUserAgent(): void
    {
        $result = (new StreamFetcher('PhoenixTest/1'))->get(self::$base . '/feed.json');
        self::assertSame(200, $result->status);
        self::assertSame('"v1"', $result->etag);
        self::assertSame('application/feed+json', $result->contentType);
        self::assertSame('PhoenixTest/1', $result->json()['ua']);
    }

    public function testConditionalRequestGets304(): void
    {
        $result = (new StreamFetcher())->get(self::$base . '/feed.json', '"v1"');
        self::assertSame(304, $result->status);
        self::assertSame('', $result->body);
    }

    public function testFollowsRedirectsAndReturnsErrorStatuses(): void
    {
        self::assertSame('Fixture', (new StreamFetcher())->get(self::$base . '/moved')->json()['title']);
        self::assertSame(500, (new StreamFetcher())->get(self::$base . '/boom')->status);
        self::assertSame(404, (new StreamFetcher())->get(self::$base . '/missing')->status);
    }

    public function testRejectsOversizedBodies(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds');
        (new StreamFetcher(maxBytes: 1024))->get(self::$base . '/big');
    }

    public function testRejectsNonHttpUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StreamFetcher())->get('file:///etc/passwd');
    }

    public function testUnreachableHostThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new StreamFetcher(timeout: 2.0))->get('http://127.0.0.1:1/feed.json');
    }

    public function testJsonRejectsNonObjects(): void
    {
        foreach (['not json', '[1,2]', '"text"'] as $body) {
            try {
                (new FetchResult(200, $body))->json();
                self::fail("accepted {$body}");
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], (new FetchResult(200, '{}'))->json());
    }

    public function testParseHeadersKeepsOnlyTheFinalHop(): void
    {
        $method = new \ReflectionMethod(StreamFetcher::class, 'parseHeaders');
        [$status, $headers] = $method->invoke(null, [
            'HTTP/1.1 301 Moved Permanently',
            'Location: /feed.json',
            'ETag: "old"',
            'HTTP/1.1 200 OK',
            'Content-Type: application/feed+json',
            'ETag: "v1"',
        ]);
        self::assertSame(200, $status);
        self::assertSame(['content-type' => 'application/feed+json', 'etag' => '"v1"'], $headers);
    }
}
