<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\View;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Kernel\Support\SafeUrl;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;
use Xaraya\Module\Blog\Support\Text;

/** Builds PostView template data from stored posts. The only caller of the sanitiser for post HTML. */
final class PostViewFactory
{
    public const EXCERPT_LENGTH = 160;
    public const DATE_FORMAT = 'j M Y';

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly Cache $cache,
        private readonly UrlGenerator $urls,
        private readonly ItemSerializer $items,
        private readonly string $appUrl,
    ) {}

    /** @param array<string, string> $media source media URL => local URL, from MediaUrls::map() */
    public function make(Blog $blog, Post $post, array $media = []): PostView
    {
        $doc = $post->doc;
        $athenana = self::map($doc, '_athenana');
        $comment = self::str($athenana, 'comment');
        $content = $post->contentHtml ?? '';
        if ($comment !== null && str_starts_with($content, $comment)) {
            // The wyome rule: the owner's comment repeats the start of content_html; show each once.
            $content = ltrim(substr($content, strlen($comment)));
        }
        $contentHtml = $this->clean($post, 'content', $content);
        $commentHtml = $comment === null ? '' : $this->clean($post, 'comment', $comment);
        $quote = self::str($athenana, 'quote');
        $plain = Text::plain($contentHtml);
        if ($plain === '') {
            $plain = $quote ?? Text::plain($commentHtml);
        }
        $local = static fn(?string $url): ?string => self::safe($url === null ? null : ($media[$url] ?? $url));
        $source = self::map($doc, '_source');
        $sourceName = self::str($source, 'name');

        return new PostView(
            id: $post->id,
            kind: in_array($post->kind, JsonFeedAdapter::KINDS, true) ? $post->kind : 'note',
            title: $post->title,
            url: $this->link($blog, $post),
            canonicalUrl: $this->items->url($blog, $post, $this->appUrl),
            externalUrl: self::safe($post->externalUrl),
            contentHtml: $contentHtml,
            excerpt: mb_substr($plain, 0, self::EXCERPT_LENGTH),
            image: $local($post->image),
            publishedIso: $post->datePublished->format(DATE_ATOM),
            publishedDisplay: self::display($post->datePublished),
            tags: $this->tags($blog, $post),
            source: $sourceName === null ? null : ['name' => $sourceName, 'url' => self::safe(self::str($source, 'url'))],
            quote: $quote,
            commentHtml: $commentHtml === '' ? null : $commentHtml,
            images: self::images($athenana, $local),
            video: self::video(self::map($doc, '_video'), $local),
            podcast: self::podcast(self::map($athenana, 'podcast'), $local),
            song: self::song(self::map($athenana, 'song')),
            mentions: self::mentions($athenana),
            pinned: $blog->pinnedItemId !== null && $blog->pinnedItemId === $post->itemId,
        );
    }

    /** The post's visible text: its sanitised content as plain text, else its quote. Search snippets are cut from it. */
    public function plainText(Post $post): string
    {
        $plain = Text::plain($this->clean($post, 'content', $post->contentHtml ?? ''));

        return $plain !== '' ? $plain : (self::str(self::map($post->doc, '_athenana'), 'quote') ?? '');
    }

    /** Where pages link a post: a mirror's post_url_pattern (the canonical address), otherwise /s/{id}. */
    public function link(Blog $blog, Post $post): string
    {
        if ($blog->isMirror() && $blog->postUrlPattern !== null) {
            return str_replace('{id}', $post->id, $blog->postUrlPattern);
        }

        return $this->urls->generate('blog.post', ['id' => $post->id]);
    }

    public static function cacheKey(Post $post, string $field, string $html): string
    {
        // updated_at has one-second resolution, so the input's hash keeps two writes in one second apart.
        return 'sanitized/' . $post->id . '-' . ($post->updatedAt?->format('YmdHis') ?? '0') . '-' . $field . '-' . substr(sha1($html), 0, 12);
    }

    public static function display(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(self::DATE_FORMAT);
    }

    /** "m:ss", or "h:mm:ss" from an hour up; null for anything but a positive number of seconds. */
    public static function duration(mixed $seconds): ?string
    {
        $seconds = is_int($seconds) ? $seconds : (is_float($seconds) && is_finite($seconds) ? (int) round($seconds) : 0);
        if ($seconds <= 0) {
            return null;
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds % 60)
            : sprintf('%d:%02d', $minutes, $seconds % 60);
    }

    private function clean(Post $post, string $field, string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $key = self::cacheKey($post, $field, $html);
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached;
        }
        $clean = $this->sanitizer->clean($html);
        try {
            $this->cache->set($key, $clean);
        } catch (RuntimeException) {
            // The cache is an optimisation: an unwritable var/cache must not break the page.
        }

        return $clean;
    }

    /** @return list<array{name: string, slug: string, url: string}> */
    private function tags(Blog $blog, Post $post): array
    {
        $out = [];
        foreach ($post->tags as $tag) {
            if ($tag['slug'] === '' || str_contains($tag['slug'], '/')) {
                continue; // no tag route can match it
            }
            $out[] = [
                'name' => $tag['name'],
                'slug' => $tag['slug'],
                'url' => $this->urls->generate('blog.tag', ['handle' => $blog->handle, 'slug' => $tag['slug']]),
            ];
        }

        return $out;
    }

    /**
     * @param array<mixed> $athenana
     * @param Closure(?string): ?string $local
     * @return list<array{url: string, width: ?int, height: ?int}>
     */
    private static function images(array $athenana, Closure $local): array
    {
        $out = [];
        foreach (is_array($athenana['images'] ?? null) ? $athenana['images'] : [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $url = $local(self::str($image, 'url'));
            if ($url !== null) {
                $out[] = ['url' => $url, 'width' => self::size($image, 'width'), 'height' => self::size($image, 'height')];
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $video
     * @param Closure(?string): ?string $local
     * @return array{provider: string, id: string, thumbnail: ?string, watchUrl: string}|null
     */
    private static function video(array $video, Closure $local): ?array
    {
        $id = self::str($video, 'id');
        if (self::str($video, 'provider') !== 'youtube' || $id === null || preg_match('/^[A-Za-z0-9_-]{6,32}$/D', $id) !== 1) {
            return null;
        }

        return [
            'provider' => 'youtube',
            'id' => $id,
            'thumbnail' => $local(self::str($video, 'thumbnail')),
            'watchUrl' => 'https://www.youtube.com/watch?v=' . $id,
        ];
    }

    /**
     * @param array<mixed> $podcast
     * @param Closure(?string): ?string $local
     * @return array{show: ?string, episode: ?string, durationDisplay: ?string, audioUrl: ?string, cover: ?string}|null
     */
    private static function podcast(array $podcast, Closure $local): ?array
    {
        $view = [
            'show' => self::str($podcast, 'show'),
            'episode' => self::str($podcast, 'episode'),
            'durationDisplay' => self::duration($podcast['duration_seconds'] ?? null),
            'audioUrl' => $local(self::str($podcast, 'audio_url')),
            'cover' => $local(self::str($podcast, 'cover')),
        ];

        return $view['show'] === null && $view['episode'] === null && $view['audioUrl'] === null ? null : $view;
    }

    /**
     * @param array<mixed> $song
     * @return array{channel: ?string, durationDisplay: ?string}|null
     */
    private static function song(array $song): ?array
    {
        $view = ['channel' => self::str($song, 'channel'), 'durationDisplay' => self::duration($song['duration_seconds'] ?? null)];

        return $view['channel'] === null && $view['durationDisplay'] === null ? null : $view;
    }

    /**
     * @param array<mixed> $athenana
     * @return list<array{source: string, title: string, date: ?string}>
     */
    private static function mentions(array $athenana): array
    {
        $out = [];
        foreach (is_array($athenana['mentions'] ?? null) ? $athenana['mentions'] : [] as $mention) {
            $source = is_array($mention) ? self::safe(self::str($mention, 'source')) : null;
            if (!is_array($mention) || $source === null) {
                continue;
            }
            $date = null;
            $when = self::str($mention, 'date');
            if ($when !== null) {
                try {
                    $date = self::display(new DateTimeImmutable($when));
                } catch (Exception) {
                    $date = null;
                }
            }
            $out[] = ['source' => $source, 'title' => self::str($mention, 'title') ?? $source, 'date' => $date];
        }

        return $out;
    }

    private static function safe(?string $url): ?string
    {
        return $url !== null && SafeUrl::isSafe($url) ? $url : null;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function map(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /** @param array<mixed> $data */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<mixed> $data */
    private static function size(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) && $value > 0 && $value < 100000 ? $value : null;
    }
}
