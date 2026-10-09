<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;

final class PostRecord
{
    /**
     * @param array<string, mixed> $doc
     * @param list<array{name: string, slug: string}> $tags
     */
    public function __construct(
        public readonly string $itemId,
        public readonly ?string $ulid,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $url,
        public readonly ?string $externalUrl,
        public readonly ?string $contentHtml,
        public readonly ?string $image,
        public readonly DateTimeImmutable $datePublished,
        public readonly ?DateTimeImmutable $dateModified,
        public readonly array $doc,
        public readonly array $tags,
        public readonly string $sourceHash,
    ) {}
}
