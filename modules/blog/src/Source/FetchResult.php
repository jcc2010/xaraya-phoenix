<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use JsonException;
use RuntimeException;

final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $etag = null,
        public readonly ?string $contentType = null,
    ) {}

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Response is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException('Response JSON is not an object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
