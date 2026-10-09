<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

final class Blog
{
    public const MODES = ['mirror', 'native'];
    public const FORMATS = ['jsonfeed', 'athena'];
    public const MEDIA = ['remote', 'local'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly int $id,
        public readonly string $handle,
        public readonly string $title,
        public readonly ?string $descriptionHtml,
        public readonly string $authorName,
        public readonly ?string $authorUrl,
        public readonly ?string $homePageUrl,
        public readonly ?string $postUrlPattern,
        public readonly string $language,
        public readonly ?string $icon,
        public readonly ?string $favicon,
        public readonly string $mode,
        public readonly ?string $sourceUrl,
        public readonly ?string $sourceFormat,
        public readonly string $media,
        public readonly ?string $pinnedItemId,
        public readonly ?string $sourceEtag,
        public readonly ?string $lastSyncedAt,
        public readonly array $extra,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $s = static fn(string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;
        $extra = is_string($row['extra'] ?? null) ? json_decode($row['extra'], true) : null;

        return new self(
            (int) $row['id'],
            (string) $row['handle'],
            (string) $row['title'],
            $s('description_html'),
            (string) $row['author_name'],
            $s('author_url'),
            $s('home_page_url'),
            $s('post_url_pattern'),
            $s('language') ?? 'en',
            $s('icon'),
            $s('favicon'),
            (string) $row['mode'],
            $s('source_url'),
            $s('source_format'),
            $s('media') ?? 'remote',
            $s('pinned_item_id'),
            $s('source_etag'),
            $s('last_synced_at'),
            is_array($extra) ? $extra : [],
        );
    }

    public function isMirror(): bool
    {
        return $this->mode === 'mirror';
    }
}
