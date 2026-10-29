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

    private static function fetcher(): StreamFetcher
    {
        return new StreamFetcher(allowPrivateHosts: true);
    }

    public function testFetchesJsonWithEtagAndUserAgent(): void
    {
        $result = (new StreamFetcher('PhoenixTest/1', allowPrivateHosts: true))->get(self::$base . '/feed.json');
        self::assertSame(200, $result->status);
        self::assertSame('"v1"', $result->etag);
        self::assertSame('application/feed+json', $result->contentType);
        self::assertSame('PhoenixTest/1', $result->json()['ua']);
    }

    public function testConditionalRequestGets304(): void
    {
        $result = (self::fetcher())->get(self::$base . '/feed.json', '"v1"');
        self::assertSame(304, $result->status);
        self::assertSame('', $result->body);
    }

    public function testFollowsRedirectsAndReturnsErrorStatuses(): void
    {
        self::assertSame('Fixture', (self::fetcher())->get(self::$base . '/moved')->json()['title']);
        self::assertSame(500, (self::fetcher())->get(self::$base . '/boom')->status);
        self::assertSame(404, (self::fetcher())->get(self::$base . '/missing')->status);
    }

    public function testDefaultUserAgentCarriesTheModuleVersion(): void
    {
        $module = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/blog/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(\Xaraya\Module\Blog\BlogServiceProvider::VERSION, $module['version']);
        self::assertSame('XarayaPhoenix/' . $module['version'], self::fetcher()->get(self::$base . '/feed.json')->json()['ua']);
    }

    public function testRefusesRedirectsToNonHttpSchemes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to fetch file:///etc/passwd');
        self::fetcher()->get(self::$base . '/to-file');
    }

    public function testStopsAfterFiveRedirects(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many redirects');
        self::fetcher()->get(self::$base . '/loop');
    }

    public function testDeadlineCoversTheWholeBody(): void
    {
        $start = microtime(true);
        try {
            (new StreamFetcher(deadline: 1.0, allowPrivateHosts: true))->get(self::$base . '/slow');
            self::fail('slow body was read past the deadline');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('exceeded the 1s deadline', $e->getMessage());
        }
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function testRefusesLoopbackWhenPrivateHostsAreNotAllowed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to fetch ' . self::$base . '/feed.json');
        (new StreamFetcher())->get(self::$base . '/feed.json');
    }

    public function testRejectsOversizedBodies(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds');
        (new StreamFetcher(maxBytes: 1024, allowPrivateHosts: true))->get(self::$base . '/big');
    }

    public function testRejectsNonHttpUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (self::fetcher())->get('file:///etc/passwd');
    }

    public function testUnreachableHostThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new StreamFetcher(timeout: 2.0, allowPrivateHosts: true))->get('http://127.0.0.1:1/feed.json');
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

    /** @var resource|null */
    private $raw = null;

    private string $rawDir = '';

    protected function tearDown(): void
    {
        if (is_resource($this->raw)) {
            proc_terminate($this->raw);
            proc_close($this->raw);
            $this->raw = null;
        }
        if ($this->rawDir !== '') {
            array_map('unlink', glob($this->rawDir . '/*') ?: []);
            @rmdir($this->rawDir);
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $options starts raw-http-server.php and returns its port */
    private function raw(array $options): int
    {
        $this->rawDir = sys_get_temp_dir() . '/xar-raw-' . bin2hex(random_bytes(4));
        mkdir($this->rawDir);
        $options['transcript'] = $this->rawDir . '/request.txt';
        if (!empty($options['tls'])) {
            $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($pkey);
            $csr = openssl_csr_new(['commonName' => 'localhost'], $pkey);
            self::assertNotFalse($csr);
            $x509 = openssl_csr_sign($csr, null, $pkey, 1);
            self::assertNotFalse($x509);
            openssl_x509_export_to_file($x509, $options['cert'] = $this->rawDir . '/cert.pem');
            openssl_pkey_export_to_file($pkey, $options['key'] = $this->rawDir . '/key.pem');
        }
        file_put_contents($json = $this->rawDir . '/options.json', json_encode($options));
        $process = proc_open([PHP_BINARY, __DIR__ . '/raw-http-server.php', $json], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        $this->raw = $process;
        $port = (int) fgets($pipes[1]);
        if ($port === 0) {
            self::fail('raw-http-server.php did not start');
        }

        return $port;
    }

    public function testDeadlineCoversSlowHeaders(): void
    {
        $port = $this->raw(['mode' => 'drip']);
        $began = microtime(true);
        try {
            (new StreamFetcher(timeout: 15.0, deadline: 1.0, allowPrivateHosts: true))->get("http://127.0.0.1:{$port}/feed.json");
            self::fail('headers dripped past the deadline');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('exceeded the 1s deadline', $e->getMessage());
        }
        self::assertLessThan(2.5, microtime(true) - $began);
    }

    public function testContentLengthEndsTheBodyAndTheRequestIsHttp10(): void
    {
        $port = $this->raw(['mode' => 'ok']);
        $began = microtime(true);
        $result = self::fetcher()->get("http://127.0.0.1:{$port}/feed.json?page=2", '"v1"');
        self::assertLessThan(2.0, microtime(true) - $began, 'Content-Length must end the read');
        self::assertSame(200, $result->status);
        self::assertSame('{"ok":true}', $result->body);
        self::assertSame('application/json', $result->contentType);
        $request = (string) file_get_contents($this->rawDir . '/request.txt');
        self::assertStringStartsWith("GET /feed.json?page=2 HTTP/1.0\r\nHost: 127.0.0.1:{$port}\r\n", $request);
        self::assertStringContainsString("\r\nIf-None-Match: \"v1\"\r\n", $request);
        self::assertStringContainsString("\r\nConnection: close\r\n", $request);
    }

    public function testSkipsInterimResponses(): void
    {
        $port = $this->raw(['mode' => 'interim']);
        $result = self::fetcher()->get("http://127.0.0.1:{$port}/");
        self::assertSame(200, $result->status);
        self::assertSame('{"ok":true}', $result->body);
    }

    public function testFetchesOverVerifiedTls(): void
    {
        $port = $this->raw(['mode' => 'ok', 'tls' => true]);
        $fetcher = new StreamFetcher(allowPrivateHosts: true, tls: ['cafile' => $this->rawDir . '/cert.pem']);
        $result = $fetcher->get("https://localhost:{$port}/feed.json");
        self::assertSame('{"ok":true}', $result->body);
        self::assertStringContainsString("Host: localhost:{$port}\r\n", (string) file_get_contents($this->rawDir . '/request.txt'));
    }

    public function testRefusesAnUntrustedCertificate(): void
    {
        $port = $this->raw(['mode' => 'ok', 'tls' => true]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TLS handshake with localhost failed/');
        (new StreamFetcher(allowPrivateHosts: true, deadline: 5.0))->get("https://localhost:{$port}/feed.json");
    }

    public function testRefusesControlCharactersInThePath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the path contains spaces or control characters');
        self::fetcher()->get("http://127.0.0.1:1/a b\r\nX-Injected: 1");
    }

    public function testAShortBodyIsAnError(): void
    {
        $port = $this->raw(['mode' => 'short']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the connection closed after 2 of 100 bytes');
        self::fetcher()->get("http://127.0.0.1:{$port}/");
    }

    public function testDecodesChunkedBodies(): void
    {
        $port = $this->raw(['mode' => 'chunked']);
        self::assertSame('{"ok":true}', self::fetcher()->get("http://127.0.0.1:{$port}/")->body);
    }

    public function testTruncatedChunkedBodyIsAnError(): void
    {
        $method = new \ReflectionMethod(StreamFetcher::class, 'dechunk');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the chunked body is truncated');
        $method->invoke(null, "5\r\nab", 'http://x.test/');
    }
}
