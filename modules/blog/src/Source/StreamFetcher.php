<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use InvalidArgumentException;
use RuntimeException;

final class StreamFetcher implements HttpFetcher
{
    public function __construct(
        private readonly string $userAgent = 'XarayaPhoenix/0.1',
        private readonly float $timeout = 15.0,
        private readonly int $maxBytes = 20_000_000,
    ) {}

    public function get(string $url, ?string $etag = null): FetchResult
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidArgumentException("Only http(s) URLs can be fetched: {$url}");
        }
        $headers = [
            'User-Agent: ' . $this->userAgent,
            'Accept: application/feed+json, application/json;q=0.9, */*;q=0.1',
        ];
        if ($etag !== null) {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ]]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("Fetching {$url} failed: {$error}");
        }
        try {
            $wrapperData = stream_get_meta_data($handle)['wrapper_data'] ?? [];
            $responseHeaders = is_array($wrapperData) ? array_values(array_filter($wrapperData, 'is_string')) : [];
            $body = @stream_get_contents($handle, max(0, $this->maxBytes) + 1);
        } finally {
            fclose($handle);
        }
        if ($body === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("Fetching {$url} failed: {$error}");
        }
        if (strlen($body) > $this->maxBytes) {
            throw new RuntimeException("Response from {$url} exceeds {$this->maxBytes} bytes");
        }
        [$status, $parsed] = self::parseHeaders($responseHeaders);

        return new FetchResult($status, $body, $parsed['etag'] ?? null, $parsed['content-type'] ?? null);
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
