<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Source;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xaraya\Module\Blog\Source\StreamFetcher;

final class PublicTargetTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function blocked(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/feed.json'],
            'loopback range' => ['http://127.8.9.10:8080/feed.json'],
            'rfc1918 10' => ['http://10.0.0.1/feed.json'],
            'rfc1918 172' => ['http://172.16.5.4/feed.json'],
            'rfc1918 192' => ['https://192.168.1.1/feed.json'],
            'metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'cgnat' => ['http://100.64.0.1/feed.json'],
            'zero' => ['http://0.0.0.0/feed.json'],
            'multicast' => ['http://224.0.0.1/feed.json'],
            'reserved' => ['http://240.0.0.1/feed.json'],
            'broadcast' => ['http://255.255.255.255/feed.json'],
            'ipv6 loopback' => ['http://[::1]/feed.json'],
            'ipv6 unspecified' => ['http://[::]/feed.json'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/feed.json'],
            'ipv4-mapped private' => ['http://[::ffff:10.0.0.1]/feed.json'],
            'ula' => ['http://[fc00::1]/feed.json'],
            'ula fd' => ['http://[fd12:3456::1]/feed.json'],
            'ipv6 link-local' => ['http://[fe80::1]/feed.json'],
            'ipv6 multicast' => ['http://[ff02::1]/feed.json'],
            'localhost name' => ['http://localhost/feed.json'],
            'ftp scheme' => ['ftp://93.184.216.34/feed.json'],
        ];
    }

    #[DataProvider('blocked')]
    public function testRefusesNonPublicTargets(string $url): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to fetch ' . $url . ': ');
        StreamFetcher::assertPublicTarget($url);
    }

    public function testAllowsPublicIpLiterals(): void
    {
        StreamFetcher::assertPublicTarget('http://93.184.216.34/feed.json');
        StreamFetcher::assertPublicTarget('https://93.184.216.34:8443/feed.json?x=1');
        StreamFetcher::assertPublicTarget('https://[2606:2800:220:1:248:1893:25c8:1946]/feed.json');
        $this->addToAssertionCount(3);
    }

    public function testRedirectFromPublicHostToLoopbackIsRefused(): void
    {
        $from = 'https://93.184.216.34/blog/feed.json';
        StreamFetcher::assertPublicTarget($from);
        $hop = StreamFetcher::resolveLocation($from, 'http://127.0.0.1:8080/feed.json');
        self::assertSame('http://127.0.0.1:8080/feed.json', $hop);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to fetch http://127.0.0.1:8080/feed.json: ');
        StreamFetcher::assertPublicTarget($hop);
    }

    public function testResolvesRelativeLocations(): void
    {
        $base = 'https://example.com:8443/blog/feed.json?page=2';
        self::assertSame('https://example.com:8443/other.json', StreamFetcher::resolveLocation($base, '/other.json'));
        self::assertSame('https://example.com:8443/blog/page-3.json', StreamFetcher::resolveLocation($base, 'page-3.json'));
        self::assertSame('https://example.com:8443/blog/feed.json?page=3', StreamFetcher::resolveLocation($base, '?page=3'));
        self::assertSame('https://cdn.example.net/x.json', StreamFetcher::resolveLocation($base, '//cdn.example.net/x.json'));
        self::assertSame('http://10.0.0.1/', StreamFetcher::resolveLocation($base, 'http://10.0.0.1/'));
    }
}
