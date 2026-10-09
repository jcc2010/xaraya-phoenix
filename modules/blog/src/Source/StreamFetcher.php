<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use InvalidArgumentException;
use RuntimeException;
use Xaraya\Module\Blog\BlogServiceProvider;

/**
 * Fetches http(s) URLs with SSRF protection.
 *
 * Redirects are followed here (at most {@see self::MAX_REDIRECTS}), never by PHP, and every hop is
 * checked: the scheme must be http(s) and, unless private hosts are allowed, every address the host
 * resolves to must be public. The connection is pinned to the validated address (the Host header and
 * TLS peer name keep the original host), so a DNS rebind between check and connect cannot change the
 * target. One deadline covers every hop and the whole body.
 */
final class StreamFetcher implements HttpFetcher
{
    public const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly string $userAgent = 'XarayaPhoenix/' . BlogServiceProvider::VERSION,
        private readonly float $timeout = 15.0,
        private readonly int $maxBytes = 20_000_000,
        private readonly float $deadline = 60.0,
        private readonly bool $allowPrivateHosts = false,
    ) {}

    public function get(string $url, ?string $etag = null): FetchResult
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidArgumentException("Only http(s) URLs can be fetched: {$url}");
        }
        $start = microtime(true);
        $current = $url;
        for ($hops = 0; ; $hops++) {
            $target = self::target($current, $this->allowPrivateHosts);
            $handle = $this->open($current, $target, $etag, $start);
            try {
                $wrapperData = stream_get_meta_data($handle)['wrapper_data'] ?? [];
                $responseHeaders = is_array($wrapperData) ? array_values(array_filter($wrapperData, 'is_string')) : [];
                [$status, $parsed] = self::parseHeaders($responseHeaders);
                $location = $parsed['location'] ?? null;
                if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null && $location !== '') {
                    if ($hops >= self::MAX_REDIRECTS) {
                        throw new RuntimeException("Too many redirects fetching {$url}");
                    }
                    $current = self::resolveLocation($current, $location);
                    continue;
                }
                $body = $this->readBody($handle, $current, $start);
            } finally {
                fclose($handle);
            }

            return new FetchResult($status, $body, $parsed['etag'] ?? null, $parsed['content-type'] ?? null);
        }
    }

    /**
     * Throws unless the URL is http(s) and every address its host resolves to is public.
     *
     * @throws RuntimeException "Refusing to fetch {url}: {reason}"
     */
    public static function assertPublicTarget(string $url): void
    {
        self::target($url, false);
    }

    /** Resolves a Location header against the URL that returned it. */
    public static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException("Cannot resolve redirect from {$base}");
        }
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        $host = str_contains($parts['host'], ':') && !str_starts_with($parts['host'], '[') ? "[{$parts['host']}]" : $parts['host'];
        $origin = $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        if (str_starts_with($location, '?')) {
            return $origin . $path . $location;
        }
        if (str_starts_with($location, '#') || $location === '') {
            return $origin . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        }

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    /** True when the IP is routable on the public internet (IPv4-mapped/embedded forms are unwrapped). */
    public static function isPublicIp(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // IPv4-mapped (::ffff:0:0/96), IPv4-compatible (::/96), NAT64 (64:ff9b::/96): judge the IPv4 inside.
            if (self::inCidr($packed, '::ffff:0:0', 96) || self::inCidr($packed, '::', 96) || self::inCidr($packed, '64:ff9b::', 96)) {
                return self::isPublicIp((string) inet_ntop(substr($packed, 12)));
            }
            if (self::inCidr($packed, '2002::', 16)) { // 6to4
                return self::isPublicIp((string) inet_ntop(substr($packed, 2, 4)))
                    && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
            }
            foreach (['fc00::' => 7, 'fe80::' => 10, 'fec0::' => 10, 'ff00::' => 8, '2001:db8::' => 32, '100::' => 64] as $net => $bits) {
                if (self::inCidr($packed, $net, $bits)) {
                    return false;
                }
            }
        } else {
            $blocked = [
                '0.0.0.0' => 8, '10.0.0.0' => 8, '100.64.0.0' => 10, '127.0.0.0' => 8, '169.254.0.0' => 16,
                '172.16.0.0' => 12, '192.0.0.0' => 24, '192.0.2.0' => 24, '192.168.0.0' => 16, '198.18.0.0' => 15,
                '198.51.100.0' => 24, '203.0.113.0' => 24, '224.0.0.0' => 4, '240.0.0.0' => 4,
            ];
            foreach ($blocked as $net => $bits) {
                if (self::inCidr($packed, $net, $bits)) {
                    return false;
                }
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Validates one hop and picks the address to connect to.
     *
     * @return array{scheme: string, host: string, authority: string, ip: string, port: ?int, request: string}
     */
    private static function target(string $url, bool $allowPrivate): array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new RuntimeException("Refusing to fetch {$url}: only http(s) URLs with a host are allowed");
        }
        $host = strtolower((string) $parts['host']);
        $bare = trim($host, '[]');
        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            $ips = [$bare];
        } else {
            $ips = @gethostbynamel($bare);
            if ($ips === false || $ips === []) {
                throw new RuntimeException("Refusing to fetch {$url}: host {$bare} does not resolve");
            }
        }
        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new RuntimeException("Refusing to fetch {$url}: {$bare} resolves to non-public address {$ip}");
                }
            }
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $ip = $ips[0];
        $ipHost = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $request = $scheme . '://' . $ipHost . ($port !== null ? ':' . $port : '')
            . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return [
            'scheme' => $scheme,
            'host' => $bare,
            'authority' => $host . ($port !== null ? ':' . $port : ''),
            'ip' => $ip,
            'port' => $port,
            'request' => $request,
        ];
    }

    /**
     * @param array{scheme: string, host: string, authority: string, ip: string, port: ?int, request: string} $target
     * @return resource
     */
    private function open(string $url, array $target, ?string $etag, float $start)
    {
        $headers = [
            'Host: ' . $target['authority'],
            'User-Agent: ' . $this->userAgent,
            'Accept: application/feed+json, application/json;q=0.9, */*;q=0.1',
        ];
        if ($etag !== null) {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        $options = ['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $this->readTimeout($url, $start),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 1,
        ]];
        if ($target['scheme'] === 'https') {
            $options['ssl'] = [
                'peer_name' => $target['host'],
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ];
        }
        $handle = @fopen($target['request'], 'rb', false, stream_context_create($options));
        if ($handle === false) {
            $this->checkDeadline($url, $start);
            $error = error_get_last()['message'] ?? 'unknown error';

            throw new RuntimeException("Fetching {$url} failed: {$error}");
        }
        try {
            $this->checkDeadline($url, $start);
        } catch (RuntimeException $e) {
            fclose($handle);

            throw $e;
        }

        return $handle;
    }

    /** @param resource $handle */
    private function readBody($handle, string $url, float $start): string
    {
        $body = '';
        while (!feof($handle)) {
            $timeout = $this->readTimeout($url, $start);
            stream_set_timeout($handle, (int) floor($timeout), (int) (($timeout - floor($timeout)) * 1_000_000));
            $chunk = @fread($handle, 8192);
            if (stream_get_meta_data($handle)['timed_out']) {
                if ($timeout < $this->timeout) {
                    throw new RuntimeException("Fetching {$url} exceeded the {$this->deadline}s deadline");
                }

                throw new RuntimeException("Fetching {$url} timed out after {$this->timeout}s without data");
            }
            if ($chunk === false) {
                $error = error_get_last()['message'] ?? 'unknown error';

                throw new RuntimeException("Fetching {$url} failed: {$error}");
            }
            $body .= $chunk;
            if (strlen($body) > $this->maxBytes) {
                throw new RuntimeException("Response from {$url} exceeds {$this->maxBytes} bytes");
            }
            $this->checkDeadline($url, $start);
        }

        return $body;
    }

    /** The per-read timeout, capped by what is left of the deadline. */
    private function readTimeout(string $url, float $start): float
    {
        $this->checkDeadline($url, $start);

        return max(0.001, min($this->timeout, $this->deadline - (microtime(true) - $start)));
    }

    private function checkDeadline(string $url, float $start): void
    {
        if (microtime(true) - $start >= $this->deadline) {
            throw new RuntimeException("Fetching {$url} exceeded the {$this->deadline}s deadline");
        }
    }

    private static function inCidr(string $packed, string $network, int $bits): bool
    {
        $net = (string) inet_pton($network);
        if (strlen($net) !== strlen($packed)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (strncmp($packed, $net, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    /**
     * Uses the last status line, so headers from redirect hops are discarded.
     *
     * @param array<int, string> $lines
     * @return array{0: int, 1: array<string, string>}
     */
    private static function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        if ($status === 0) {
            throw new RuntimeException('Response had no HTTP status line');
        }

        return [$status, $headers];
    }
}
