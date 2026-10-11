<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\View;

use Xaraya\Kernel\Routing\UrlGenerator;

/** Sizes tags in five steps by count quantile and sorts them by name. */
final class TagCloud
{
    public const STEPS = 5;

    /**
     * Sizes, sorts and links tags: the one place that turns tag counts into cloud entries, for the tags page and the sidebar block.
     * A tag no tag route can match (a slug with "/" or over 128 bytes) is left out entirely, before the steps are computed.
     *
     * @param list<array{name: string, slug: string, count: int}> $tags
     * @return list<array{name: string, slug: string, count: int, step: int, url: string}>
     */
    public static function withUrls(array $tags, UrlGenerator $urls, string $handle): array
    {
        $linkable = array_values(array_filter(
            $tags,
            static fn(array $tag): bool => $tag['slug'] !== '' && !str_contains($tag['slug'], '/') && strlen($tag['slug']) <= 128,
        ));
        $out = [];
        foreach (self::build($linkable) as $tag) {
            $out[] = [...$tag, 'url' => $urls->generate('blog.tag', ['handle' => $handle, 'slug' => $tag['slug']])];
        }

        return $out;
    }

    /**
     * @param list<array{name: string, slug: string, count: int}> $tags
     * @return list<array{name: string, slug: string, count: int, step: int}> by name, then slug
     */
    public static function build(array $tags): array
    {
        $counts = array_column($tags, 'count');
        sort($counts);
        $total = count($counts);
        $out = [];
        foreach ($tags as $tag) {
            // The share of the other tags used less often than this one picks the step: 1 (least used) to 5 (most).
            $below = 0;
            while ($below < $total && $counts[$below] < $tag['count']) {
                $below++;
            }
            $out[] = [...$tag, 'step' => $total > 1 ? 1 + intdiv((self::STEPS - 1) * $below, $total - 1) : 1];
        }
        usort($out, static fn(array $a, array $b): int => [mb_strtolower($a['name']), $a['slug']] <=> [mb_strtolower($b['name']), $b['slug']]);

        return $out;
    }
}
