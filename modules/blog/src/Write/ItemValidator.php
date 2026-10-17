<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Write;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;

/**
 * Checks a JSON Feed 1.1 item sent to the write path. Only the JSON Feed item fields and `_`-prefixed extension
 * objects are allowed. Returns field => message; an empty array means valid. Null values count as absent.
 * Nested fields are named by their dotted path (`_athenana.tags.0.slug`). Every string, nested ones and keys
 * included, must be valid UTF-8; a key that is not is named with its bad bytes scrubbed to `?`.
 */
final class ItemValidator
{
    public const FIELDS = [
        'id', 'url', 'external_url', 'title', 'content_html', 'content_text', 'summary', 'image', 'banner_image',
        'date_published', 'date_modified', 'authors', 'author', 'tags', 'language', 'attachments',
    ];
    public const MAX_TITLE = 1000;
    public const MAX_TAG = 128;
    /** Text::slug()'s alphabet: tag slugs become tag page URLs, so nothing else gets through. */
    public const TAG_SLUG = '/^[a-z0-9-]{1,128}$/D';

    private const STRINGS = ['id', 'url', 'external_url', 'title', 'content_html', 'content_text', 'summary', 'image', 'banner_image', 'date_published', 'date_modified', 'language'];
    /** field => longest value in bytes (the posts column sizes) */
    private const URLS = ['url' => 1024, 'external_url' => 2048, 'image' => 2048, 'banner_image' => 2048];

    /**
     * @param array<array-key, mixed> $item
     * @return array<string, string>
     */
    public function errors(array $item): array
    {
        $errors = [];
        foreach ($item as $key => $value) {
            $key = mb_scrub((string) $key, 'UTF-8');
            if ($value === null) {
                continue;
            }
            if (str_starts_with($key, '_')) {
                if (!self::isObject($value)) {
                    $errors[$key] = 'Extension fields must be JSON objects';
                }
            } elseif (!in_array($key, self::FIELDS, true)) {
                $errors[$key] = 'Unknown field';
            } elseif (in_array($key, self::STRINGS, true) && !is_string($value)) {
                $errors[$key] = 'Must be a string';
            }
        }
        $str = static fn(string $key): ?string => is_string($item[$key] ?? null) && $item[$key] !== '' ? $item[$key] : null;

        if (($title = $str('title')) !== null && mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'Must be at most ' . self::MAX_TITLE . ' characters';
        }
        foreach (self::URLS as $key => $max) {
            $url = $str($key);
            if ($url !== null && (!self::isHttpUrl($url) || strlen($url) > $max)) {
                $errors[$key] = "Must be an absolute http(s) URL of at most {$max} bytes";
            }
        }
        foreach (['date_published', 'date_modified'] as $key) {
            $date = $str($key);
            if ($date !== null && self::date($date) === null) {
                $errors[$key] = 'Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00';
            }
        }
        $tags = $item['tags'] ?? null;
        if ($tags !== null && (!is_array($tags) || !array_is_list($tags) || !self::allTags($tags))) {
            $errors['tags'] = 'Must be a list of tag names of 1 to ' . self::MAX_TAG . ' characters';
        }
        foreach (['attachments', 'authors'] as $key) {
            $list = $item[$key] ?? null;
            if ($list !== null && (!is_array($list) || !array_is_list($list) || array_filter($list, static fn(mixed $v): bool => !self::isObject($v)) !== [])) {
                $errors[$key] = 'Must be a list of objects';
            }
        }
        if (is_array($item['attachments'] ?? null) && !isset($errors['attachments'])) {
            foreach ($item['attachments'] as $attachment) {
                if (!is_array($attachment) || !is_string($attachment['url'] ?? null) || !self::isHttpUrl($attachment['url']) || !is_string($attachment['mime_type'] ?? null)) {
                    $errors['attachments'] = 'Each attachment needs an http(s) url and a mime_type';
                }
            }
        }
        if (($item['author'] ?? null) !== null && !self::isObject($item['author'])) {
            $errors['author'] = 'Must be an object';
        }
        $athenana = $item['_athenana'] ?? null;
        if (is_array($athenana)) {
            $kind = $athenana['kind'] ?? null;
            if ($kind !== null && !in_array($kind, JsonFeedAdapter::KINDS, true)) {
                $errors['_athenana.kind'] = 'Must be one of: ' . implode(', ', JsonFeedAdapter::KINDS);
            }
            $athenaTags = $athenana['tags'] ?? null;
            if ($athenaTags !== null && (!is_array($athenaTags) || !array_is_list($athenaTags)
                || array_filter($athenaTags, static fn(mixed $t): bool => !is_array($t) || !is_string($t['name'] ?? null)) !== [])) {
                $errors['_athenana.tags'] = 'Must be a list of {name, slug} objects';
            } elseif (is_array($athenaTags)) {
                foreach ($athenaTags as $i => $tag) {
                    /** @var array{name: string, slug?: mixed} $tag */
                    if (trim($tag['name']) === '' || mb_strlen($tag['name']) > self::MAX_TAG) {
                        $errors["_athenana.tags.{$i}.name"] = 'Must be a tag name of 1 to ' . self::MAX_TAG . ' characters';
                    }
                    $slug = $tag['slug'] ?? null;
                    if ($slug !== null && (!is_string($slug) || preg_match(self::TAG_SLUG, $slug) !== 1)) {
                        $errors["_athenana.tags.{$i}.slug"] = 'Must be 1 to 128 characters of a-z, 0-9 and -';
                    }
                }
            }
            $images = $athenana['images'] ?? null;
            if ($images !== null && (!is_array($images) || !array_is_list($images)
                || array_filter($images, static fn(mixed $i): bool => !is_array($i) || !is_string($i['url'] ?? null) || !self::isHttpUrl($i['url'])) !== [])) {
                $errors['_athenana.images'] = 'Must be a list of objects with an http(s) url';
            }
        }
        $hasContent = false;
        foreach (['content_html', 'content_text', 'title', 'external_url', 'image'] as $key) {
            $hasContent = $hasContent || $str($key) !== null;
        }
        if (!$hasContent) {
            $errors['content_html'] ??= 'An item needs content_html, content_text, a title, an external_url or an image';
        }
        foreach (self::notUtf8($item) as $path) {
            $errors[$path] = 'Must be valid UTF-8';
        }

        return $errors;
    }

    /** Parses an RFC 3339 date-time (seconds required, years 1970-9999) into UTC; null when it is not one. */
    public static function date(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:[Zz]|[+-](\d{2}):(\d{2}))$/D', $value, $m) !== 1) {
            return null;
        }
        $offsetOk = !isset($m[7]) || ((int) $m[7] <= 23 && (int) ($m[8] ?? '0') <= 59);
        if ((int) $m[1] < 1970 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 || !$offsetOk) {
            return null;
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s/?\#\\\\]+[^\s\\\\\x00-\x1f\x7f]*$#iD', $url) === 1;
    }

    /**
     * Dotted paths of the strings (values or keys) in $value that are not valid UTF-8.
     *
     * @param array<array-key, mixed> $value
     * @return list<string>
     */
    private static function notUtf8(array $value, string $prefix = ''): array
    {
        $paths = [];
        foreach ($value as $key => $v) {
            $path = $prefix . (string) $key;
            if (!mb_check_encoding($path, 'UTF-8') || (is_string($v) && !mb_check_encoding($v, 'UTF-8'))) {
                $paths[] = mb_scrub($path, 'UTF-8');
            } elseif (is_array($v)) {
                array_push($paths, ...self::notUtf8($v, $path . '.'));
            }
        }

        return $paths;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** @param list<mixed> $tags */
    private static function allTags(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '' || mb_strlen($tag) > self::MAX_TAG) {
                return false;
            }
        }

        return true;
    }
}
