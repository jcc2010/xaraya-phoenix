<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use DateTimeImmutable;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;

final class MediaLocalizer
{
    public function __construct(private readonly MediaStore $store) {}

    /** @return list<string> */
    public static function urls(PostRecord|Post $post): array
    {
        $doc = $post->doc;
        $urls = [$post->image];
        foreach (self::listAt($doc, '_athenana', 'images') as $image) {
            $urls[] = is_array($image) ? ($image['url'] ?? null) : null;
        }
        $urls[] = self::at($doc, '_video', 'thumbnail');
        $urls[] = self::at($doc, '_athenana', 'podcast', 'cover');
        foreach (self::listAt($doc, 'attachments') as $attachment) {
            $urls[] = is_array($attachment) ? ($attachment['url'] ?? null) : null;
        }
        $valid = array_filter($urls, static fn(mixed $url): bool => is_string($url) && preg_match('#^https?://#i', $url) === 1);

        return array_values(array_unique($valid));
    }

    public function localize(Blog $blog, PostRecord $record, DateTimeImmutable $now): int
    {
        $stored = 0;
        foreach (self::urls($record) as $url) {
            if ($this->store->localize($blog, $url, $now) !== null) {
                $stored++;
            }
        }

        return $stored;
    }

    /** @param array<mixed> $data */
    private static function at(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function listAt(array $data, string ...$keys): array
    {
        $value = self::at($data, ...$keys);

        return is_array($value) ? $value : [];
    }
}
