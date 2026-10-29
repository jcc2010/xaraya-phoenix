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
 * target. The request is plain HTTP/1.0 over a socket, so one deadline covers every hop: connect, TLS handshake,
 * headers and body. Only the DNS lookup can't be interrupted; the deadline is checked as soon as it returns.
 */
final class StreamFetcher implements HttpFetcher
{
    public const MAX_REDIRECTS = 5;
    public const MAX_HEADER_BYTES = 65_536;

    /** @param array<string, mixed> $tls extra `ssl` stream-context options (tests pass a cafile) */
    public function __construct(
        private readonly string $userAgent = 'XarayaPhoenix/' . BlogServiceProvider::VERSION,
        private readonly float $timeout = 15.0,
        private readonly int $maxBytes = 20_000_000,
        private readonly float $deadline = 60.0,
        private readonly bool $allowPrivateHosts = false,
        private readonly array $tls = [],
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
            $this->checkDeadline($current, $start);
            [$socket, $status, $parsed, $rest] = $this->request($current, $target, $etag, $start);
            try {
                $location = $parsed['location'] ?? null;
                if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== null && $location !== '') {
                    if ($hops >= self::MAX_REDIRECTS) {
                        throw new RuntimeException("Too many redirects fetching {$url}");
                    }
                    $current = self::resolveLocation($current, $location);
                    continue;
                }
                $chunked = str_contains(strtolower($parsed['transfer-encoding'] ?? ''), 'chunked');
                $length = !$chunked && isset($parsed['content-length']) && ctype_digit($parsed['content-length']) ? (int) $parsed['content-length'] : null;
                $body = $this->readBody($socket, $current, $start, $rest, $length);
                if ($chunked) {
                    $body = self::dechunk($body, $current);
                }
            } finally {
                fclose($socket);
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
            // Also refused: NAT64 local-use (64:ff9b:1::/48) and Teredo (2001::/32), whose IPv4 can't be judged.
            $blocked6 = ['fc00::' => 7, 'fe80::' => 10, 'fec0::' => 10, 'ff00::' => 8, '2001:db8::' => 32, '100::' => 64, '64:ff9b:1::' => 48, '2001::' => 32];
            foreach ($blocked6 as $net => $bits) {
                if (self::inCidr($packed, $net, $bits)) {
                    return false;
                }
            }
        } else {
            $blocked = [
                '0.0.0.0' => 8, '10.0.0.0' => 8, '100.64.0.0' => 10, '127.0.0.0' => 8, '169.254.0.0' => 16,
                '172.16.0.0' => 12, '192.0.0.0' => 24, '192.0.2.0' => 24, '192.88.99.0' => 24, '192.168.0.0' => 16, '198.18.0.0' => 15,
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
     * @return array{scheme: string, host: string, authority: string, ip: string, port: ?int, path: string}
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
        $path = ($parts['path'] ?? '') === '' ? '/' : $parts['path'];
        $path .= isset($parts['query']) ? '?' . $parts['query'] : '';
        if (preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            throw new RuntimeException("Refusing to fetch {$url}: the path contains spaces or control characters");
        }

        return [
            'scheme' => $scheme,
            'host' => $bare,
            'authority' => $host . ($port !== null ? ':' . $port : ''),
            'ip' => $ips[0],
            'port' => $port,
            'path' => $path,
        ];
    }

    /**
     * Connects to the validated address, sends the request and reads the response headers, all within the deadline.
     *
     * @param array{scheme: string, host: string, authority: string, ip: string, port: ?int, path: string} $target
     * @return array{0: resource, 1: int, 2: array<string, string>, 3: string} socket, status, headers, body bytes read so far
     */
    private function request(string $url, array $target, ?string $etag, float $start): array
    {
        $https = $target['scheme'] === 'https';
        $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];
        $remote = ($https ? 'ssl://' : 'tcp://') . $ip . ':' . ($target['port'] ?? ($https ? 443 : 80));
        $context = stream_context_create(['ssl' => [
            'peer_name' => $target['host'],
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ] + $this->tls]);
        // For ssl:// the connect timeout also bounds the TLS handshake.
        $socket = @stream_socket_client($remote, $errno, $error, $this->readTimeout($url, $start), STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            $this->checkDeadline($url, $start);
            $reason = (string) $error !== '' ? (string) $error : (error_get_last()['message'] ?? 'unknown error');
            if ($https && ($errno === 0 || str_contains($reason, 'SSL') || str_contains($reason, 'crypto'))) {
                $reason = 'TLS handshake with ' . $target['host'] . ' failed (' . $reason . '); check its certificate';
            }

            throw new RuntimeException("Fetching {$url} failed: {$reason}");
        }
        try {
            $headers = [
                "GET {$target['path']} HTTP/1.0",
                'Host: ' . $target['authority'],
                'User-Agent: ' . $this->userAgent,
                'Accept: application/feed+json, application/json;q=0.9, */*;q=0.1',
                'Accept-Encoding: identity',
                'Connection: close',
            ];
            if ($etag !== null && preg_match('/^[\x20-\x7e]*$/', $etag) === 1) {
                $headers[] = 'If-None-Match: ' . $etag;
            }
            $this->write($socket, implode("\r\n", $headers) . "\r\n\r\n", $url, $start);
            [$status, $parsed, $rest] = $this->readHeaders($socket, $url, $start);
        } catch (RuntimeException $e) {
            fclose($socket);

            throw $e;
        }

        return [$socket, $status, $parsed, $rest];
    }

    /** @param resource $socket */
    private function write($socket, string $data, string $url, float $start): void
    {
        while ($data !== '') {
            $this->setTimeout($socket, $this->readTimeout($url, $start));
            $written = @fwrite($socket, $data);
            if ($written === false || $written === 0) {
                $this->checkDeadline($url, $start);

                throw new RuntimeException("Fetching {$url} failed: the connection closed while sending the request");
            }
            $data = substr($data, $written);
        }
    }

    /**
     * Reads up to the blank line that ends the headers; 1xx interim responses are skipped.
     *
     * @param resource $socket
     * @return array{0: int, 1: array<string, string>, 2: string} status, headers, the body bytes already read
     */
    private function readHeaders($socket, string $url, float $start): array
    {
        $buffer = '';
        while (true) {
            if (preg_match('/\r?\n\r?\n/', $buffer, $m, PREG_OFFSET_CAPTURE) === 1) {
                $head = substr($buffer, 0, $m[0][1]);
                $buffer = substr($buffer, $m[0][1] + strlen($m[0][0]));
                [$status, $headers] = self::parseHeaders(preg_split('/\r?\n/', $head) ?: []);
                if ($status >= 100 && $status < 200) {
                    continue;
                }

                return [$status, $headers, $buffer];
            }
            if (strlen($buffer) > self::MAX_HEADER_BYTES) {
                throw new RuntimeException("Response headers from {$url} exceed " . self::MAX_HEADER_BYTES . ' bytes');
            }
            $chunk = $this->read($socket, $url, $start);
            if ($chunk === '') {
                throw new RuntimeException("Fetching {$url} failed: the connection closed before the response headers ended");
            }
            $buffer .= $chunk;
        }
    }

    /** @param resource $socket */
    private function readBody($socket, string $url, float $start, string $body, ?int $length): string
    {
        if ($length !== null && $length > $this->maxBytes) {
            throw new RuntimeException("Response from {$url} exceeds {$this->maxBytes} bytes");
        }
        while ($length === null || strlen($body) < $length) {
            if (strlen($body) > $this->maxBytes) {
                throw new RuntimeException("Response from {$url} exceeds {$this->maxBytes} bytes");
            }
            $chunk = $this->read($socket, $url, $start);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        if (strlen($body) > $this->maxBytes) {
            throw new RuntimeException("Response from {$url} exceeds {$this->maxBytes} bytes");
        }
        if ($length !== null && strlen($body) < $length) {
            throw new RuntimeException("Fetching {$url} failed: the connection closed after " . strlen($body) . " of {$length} bytes");
        }

        return $length === null ? $body : substr($body, 0, $length);
    }

    /** Decodes a chunked body (some proxies chunk even for HTTP/1.0 clients); a truncated one is an error. */
    private static function dechunk(string $raw, string $url): string
    {
        $out = '';
        $at = 0;
        while (true) {
            $eol = strpos($raw, "\n", $at);
            if ($eol === false) {
                throw new RuntimeException("Fetching {$url} failed: the chunked body is truncated");
            }
            $line = trim(explode(';', substr($raw, $at, $eol - $at), 2)[0]);
            if ($line === '' || !ctype_xdigit($line) || strlen($line) > 8) {
                throw new RuntimeException("Fetching {$url} failed: malformed chunk size");
            }
            $size = (int) hexdec($line);
            $at = $eol + 1;
            if ($size === 0) {
                return $out;
            }
            if ($at + $size > strlen($raw)) {
                throw new RuntimeException("Fetching {$url} failed: the chunked body is truncated");
            }
            $out .= substr($raw, $at, $size);
            $at += $size;
            $at += substr($raw, $at, 2) === "\r\n" ? 2 : (($raw[$at] ?? '') === "\n" ? 1 : 0);
        }
    }

    /**
     * One read within the deadline; '' at end of stream.
     *
     * @param resource $socket
     */
    private function read($socket, string $url, float $start): string
    {
        if (feof($socket)) {
            return '';
        }
        $timeout = $this->readTimeout($url, $start);
        $this->setTimeout($socket, $timeout);
        $chunk = @fread($socket, 8192);
        if (stream_get_meta_data($socket)['timed_out']) {
            if ($timeout < $this->timeout) {
                throw new RuntimeException("Fetching {$url} exceeded the {$this->deadline}s deadline");
            }

            throw new RuntimeException("Fetching {$url} timed out after {$this->timeout}s without data");
        }
        if ($chunk === false) {
            $error = error_get_last()['message'] ?? 'unknown error';

            throw new RuntimeException("Fetching {$url} failed: {$error}");
        }
        $this->checkDeadline($url, $start);

        return $chunk;
    }

    /** @param resource $socket */
    private function setTimeout($socket, float $timeout): void
    {
        stream_set_timeout($socket, (int) floor($timeout), (int) (($timeout - floor($timeout)) * 1_000_000));
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
