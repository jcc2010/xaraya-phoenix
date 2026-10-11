<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\View;

/**
 * Engine-neutral template data for one post: scalars, arrays and nulls only. HTML fields (contentHtml,
 * commentHtml) are sanitised; every URL passed SafeUrl. PHP templates read $post->title, Twig post.title.
 */
final class PostView
{
    /**
     * @param list<array{name: string, slug: string, url: string}> $tags
     * @param array{name: string, url: ?string}|null $source
     * @param list<array{url: string, width: ?int, height: ?int}> $images
     * @param array{provider: string, id: string, thumbnail: ?string, watchUrl: string}|null $video
     * @param array{show: ?string, episode: ?string, durationDisplay: ?string, audioUrl: ?string, cover: ?string}|null $podcast
     * @param array{channel: ?string, durationDisplay: ?string}|null $song
     * @param list<array{source: string, title: string, date: ?string}> $mentions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $url,
        public readonly string $canonicalUrl,
        public readonly ?string $externalUrl,
        public readonly string $contentHtml,
        public readonly string $excerpt,
        public readonly ?string $image,
        public readonly string $publishedIso,
        public readonly string $publishedDisplay,
        public readonly array $tags,
        public readonly ?array $source,
        public readonly ?string $quote,
        public readonly ?string $commentHtml,
        public readonly array $images,
        public readonly ?array $video,
        public readonly ?array $podcast,
        public readonly ?array $song,
        public readonly array $mentions,
        public readonly bool $pinned,
    ) {}
}
