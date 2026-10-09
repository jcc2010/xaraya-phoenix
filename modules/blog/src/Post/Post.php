<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;
use DateTimeZone;

final class Post
{
    /**
     * @param array<string, mixed> $doc
     * @param list<array{name: string, slug: string}> $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly int $blogId,
        public readonly string $itemId,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $url,
        public readonly ?string $externalUrl,
        public readonly ?string $contentHtml,
        public readonly ?string $image,
        public readonly DateTimeImmutable $datePublished,
        public readonly ?DateTimeImmutable $dateModified,
        public readonly string $status,
        public readonly array $doc,
        public readonly array $tags,
    ) {}

    /**
     * @param array<string, mixed> $row
     * @param list<array{name: string, slug: string}> $tags
     */
    public static function fromRow(array $row, array $tags): self
    {
        $utc = new DateTimeZone('UTC');
        $s = static fn(string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;
        $doc = is_string($row['doc'] ?? null) ? json_decode($row['doc'], true) : null;
        $modified = $s('date_modified');

        return new self(
            (string) $row['id'],
            (int) $row['blog_id'],
            (string) $row['item_id'],
            (string) $row['kind'],
            (string) $row['title'],
            $s('url'),
            $s('external_url'),
            $s('content_html'),
            $s('image'),
            new DateTimeImmutable((string) $row['date_published'], $utc),
            $modified === null ? null : new DateTimeImmutable($modified, $utc),
            (string) $row['status'],
            is_array($doc) ? $doc : [],
            $tags,
        );
    }
}
