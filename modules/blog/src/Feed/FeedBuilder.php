<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;

final class FeedBuilder
{
    public const VERSION = 'https://jsonfeed.org/version/1.1';

    public function __construct(private readonly ItemSerializer $items) {}

    /**
     * @param list<Post> $posts
     * @param array<string, string> $media
     * @return array<string, mixed>
     */
    public function feed(Blog $blog, array $posts, string $feedUrl, ?string $nextUrl, string $appUrl, array $media = []): array
    {
        $extra = $blog->extra;
        $athenana = is_array($extra['_athenana'] ?? null) ? $extra['_athenana'] : [];
        unset($extra['_athenana']);

        $envelope = ItemSerializer::clean([
            'version' => self::VERSION,
            'title' => $blog->title,
            'description' => $blog->descriptionHtml,
            'home_page_url' => $blog->homePageUrl,
            'feed_url' => $feedUrl,
            'next_url' => $nextUrl,
            'language' => $blog->language,
            'icon' => $blog->icon,
            'favicon' => $blog->favicon,
            'authors' => [['name' => $blog->authorName, 'url' => $blog->authorUrl]],
            '_athenana' => ['pinned_id' => $blog->pinnedItemId] + $athenana,
        ] + $extra);
        $envelope['items'] = array_map(fn(Post $post): array => $this->items->item($blog, $post, $appUrl, $media), $posts);

        return $envelope;
    }
}
