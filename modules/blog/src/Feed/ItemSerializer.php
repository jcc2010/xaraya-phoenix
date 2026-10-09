<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;

final class ItemSerializer
{
    private const NEVER_REWRITE = ['id', 'url', 'external_url'];

    /**
     * @param array<string, string> $media source media URL => replacement URL
     * @return array<string, mixed>
     */
    public function item(Blog $blog, Post $post, string $appUrl, array $media = []): array
    {
        $doc = $post->doc;
        $athenana = is_array($doc['_athenana'] ?? null) ? $doc['_athenana'] : [];
        $tags = $doc['tags'] ?? array_column($post->tags, 'name');
        $attachments = $doc['attachments'] ?? null;
        unset($doc['_athenana'], $doc['tags'], $doc['attachments']);

        $item = [
            'id' => $post->itemId,
            'url' => $this->url($blog, $post, $appUrl),
            'external_url' => $post->externalUrl,
            'title' => $post->title,
            'content_html' => $post->contentHtml,
            'image' => $post->image,
            'date_published' => $post->datePublished->format(DATE_ATOM),
            'date_modified' => $post->dateModified?->format(DATE_ATOM),
            'tags' => $tags,
            'attachments' => $attachments,
        ] + $doc + ['_athenana' => ['kind' => $post->kind] + $athenana + ['tags' => $post->tags]];

        $item = self::clean($item);
        if ($media !== []) {
            foreach ($item as $key => $value) {
                if (!in_array($key, self::NEVER_REWRITE, true)) {
                    $item[$key] = self::rewrite($value, $media);
                }
            }
        }

        return $item;
    }

    public function url(Blog $blog, Post $post, string $appUrl): string
    {
        if ($blog->postUrlPattern !== null) {
            return str_replace('{id}', $post->id, $blog->postUrlPattern);
        }

        return $post->url ?? rtrim($appUrl, '/') . '/s/' . $post->id;
    }

    /**
     * Drops null, "" and [] recursively (Athena's clean()); keeps false and 0. Lists stay lists.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function clean(array $value): array
    {
        $out = [];
        foreach ($value as $key => $v) {
            if (is_array($v)) {
                $v = self::clean($v);
            }
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $out[$key] = $v;
        }

        return array_is_list($value) ? array_values($out) : $out;
    }

    /** @param array<string, string> $media */
    private static function rewrite(mixed $value, array $media): mixed
    {
        if (is_string($value)) {
            return $media[$value] ?? $value;
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $v): mixed => self::rewrite($v, $media), $value);
        }

        return $value;
    }
}
