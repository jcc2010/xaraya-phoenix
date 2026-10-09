<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use JsonException;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Support\Text;

class JsonFeedAdapter implements SourceAdapter
{
    public const KINDS = ['note', 'photo', 'link', 'highlight'];

    private const COLUMN_KEYS = ['id', 'url', 'external_url', 'title', 'content_html', 'image', 'date_published', 'date_modified'];

    private const FEED_COLUMNS = [
        'title' => 'title',
        'description' => 'description_html',
        'home_page_url' => 'home_page_url',
        'icon' => 'icon',
        'favicon' => 'favicon',
        'language' => 'language',
    ];

    public function toRecord(array $item, DateTimeImmutable $now): PostRecord
    {
        $itemId = $item['id'] ?? null;
        if (is_int($itemId)) {
            $itemId = (string) $itemId;
        }
        if (!is_string($itemId) || $itemId === '') {
            throw new InvalidArgumentException('Item has no id');
        }
        if (strlen($itemId) > 512) {
            throw new InvalidArgumentException('Item id is longer than 512 bytes');
        }
        $contentHtml = self::str($item, 'content_html');
        $contentText = self::str($item, 'content_text');
        $externalUrl = self::str($item, 'external_url');
        $image = self::str($item, 'image');
        $url = self::str($item, 'url');
        foreach (['url' => [$url, 1024], 'external_url' => [$externalUrl, 2048], 'image' => [$image, 2048]] as $field => [$value, $max]) {
            if ($value !== null && strlen($value) > $max) {
                throw new InvalidArgumentException("Item {$field} is longer than {$max} bytes");
            }
        }
        $kind = $this->kind($item, $contentHtml ?? $contentText, $externalUrl, $image);
        $title = self::str($item, 'title')
            ?? Text::deriveTitle($kind, $contentHtml ?? ($contentText === null ? null : htmlspecialchars($contentText)), $externalUrl);
        $modified = self::date($item, 'date_modified');
        $published = self::date($item, 'date_published') ?? $modified ?? $now;
        $tags = $this->tags($item);

        try {
            $hash = sha1(Text::canonicalJson($item));
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Item cannot be hashed: ' . $e->getMessage(), 0, $e);
        }

        $doc = array_diff_key($item, array_flip(self::COLUMN_KEYS));
        unset($doc['tags']);
        if (array_key_exists('tags', $item) && $item['tags'] !== array_column($tags, 'name')) {
            $doc['tags'] = $item['tags'];
        }
        if (is_array($doc['_athenana'] ?? null)) {
            $athenana = $doc['_athenana'];
            unset($athenana['kind'], $athenana['tags']);
            if ($athenana === []) {
                unset($doc['_athenana']);
            } else {
                $doc['_athenana'] = $athenana;
            }
        }

        return new PostRecord(
            $itemId,
            self::ulidFrom($itemId),
            $kind,
            $title,
            $url,
            $externalUrl,
            $contentHtml,
            $image,
            $published,
            $modified,
            $doc,
            $tags,
            $hash,
        );
    }

    /**
     * @param array<array-key, mixed> $feed
     * @return array<string, mixed>
     */
    public function feedMeta(array $feed): array
    {
        $meta = [];
        foreach (self::FEED_COLUMNS as $key => $column) {
            $value = self::str($feed, $key);
            if ($value !== null) {
                $meta[$column] = $column === 'language' ? substr($value, 0, 16) : $value;
            }
        }
        $authors = $feed['authors'] ?? null;
        $author = is_array($authors) && is_array($authors[0] ?? null) ? $authors[0] : ($feed['author'] ?? null);
        if (is_array($author)) {
            if (($name = self::str($author, 'name')) !== null) {
                $meta['author_name'] = $name;
            }
            if (($url = self::str($author, 'url')) !== null) {
                $meta['author_url'] = $url;
            }
        }
        $extra = [];
        foreach ($feed as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                $extra[$key] = $value;
            }
        }
        $pinned = null;
        if (is_array($extra['_athenana'] ?? null)) {
            $athenana = $extra['_athenana'];
            $pinned = self::str($athenana, 'pinned_id');
            unset($athenana['pinned_id']);
            if ($athenana === []) {
                unset($extra['_athenana']);
            } else {
                $extra['_athenana'] = $athenana;
            }
        }
        $meta['pinned_item_id'] = $pinned;
        $meta['extra'] = $extra;

        return $meta;
    }

    /** @param array<string, mixed> $item */
    protected function kind(array $item, ?string $content, ?string $externalUrl, ?string $image): string
    {
        if ($externalUrl !== null) {
            return 'link';
        }
        if ($image !== null && ($content === null || Text::plain($content) === '')) {
            return 'photo';
        }

        return 'note';
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{name: string, slug: string}>
     */
    protected function tags(array $item): array
    {
        $tags = [];
        foreach (is_array($item['tags'] ?? null) ? $item['tags'] : [] as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $name = mb_substr(trim($name), 0, 128);
            $slug = Text::slug($name);
            $tags[$slug] ??= ['name' => $name, 'slug' => $slug];
        }

        return array_values($tags);
    }

    /** @param array<mixed> $data */
    protected static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $item */
    private static function date(array $item, string $key): ?DateTimeImmutable
    {
        $value = self::str($item, $key);
        if ($value === null) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new InvalidArgumentException("Item {$key} '{$value}' is not a date", 0, $e);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private static function ulidFrom(string $itemId): ?string
    {
        return preg_match('/(?:^|[^0-9a-z])([0-7][0-9a-hjkmnp-tv-z]{25})$/D', $itemId, $m) === 1 ? $m[1] : null;
    }
}
