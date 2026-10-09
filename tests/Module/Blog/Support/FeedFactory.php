<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Support\Ulid;

final class FeedFactory
{
    /** @return list<array<string, mixed>> newest first, one hour apart */
    public static function items(int $count, string $base = 'https://src.test', string $newest = '2026-10-01 12:00:00'): array
    {
        $start = new DateTimeImmutable($newest, new DateTimeZone('UTC'));
        $items = [];
        for ($n = 0; $n < $count; $n++) {
            $published = $start->modify("-{$n} hours");
            $ulid = Ulid::generate($published->getTimestamp() * 1000);
            $tag = 't' . ($n % 3);
            $items[] = [
                'id' => "{$base}/s/{$ulid}",
                'url' => "{$base}/s/{$ulid}",
                'title' => "Post {$n}",
                'content_html' => "<p>Body {$n}</p>",
                'date_published' => $published->format(DATE_ATOM),
                'date_modified' => $published->format(DATE_ATOM),
                'tags' => [$tag],
                '_athenana' => ['kind' => 'note', 'tags' => [['name' => $tag, 'slug' => $tag]]],
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, array<string, mixed>> page URL => feed page
     */
    public static function pages(array $items, string $base = 'https://src.test', int $perPage = 50): array
    {
        $first = "{$base}/blog/src/feed.json";
        $chunks = array_chunk($items, $perPage) ?: [[]];
        $pages = [];
        foreach ($chunks as $i => $chunk) {
            $url = $i === 0 ? $first : $first . '?page=' . ($i + 1);
            $page = [
                'version' => 'https://jsonfeed.org/version/1.1',
                'title' => 'Source Blog',
                'home_page_url' => "{$base}/blog/src",
                'feed_url' => $first,
                'authors' => [['name' => 'Source Author', 'url' => "{$base}/blog/src"]],
            ];
            if ($i < count($chunks) - 1) {
                $page['next_url'] = $first . '?page=' . ($i + 2);
            }
            $page['items'] = $chunk;
            $pages[$url] = $page;
        }

        return $pages;
    }

    /** @param array<string, array<string, mixed>> $pages */
    public static function fetcher(array $pages): FixtureFetcher
    {
        $fetcher = new FixtureFetcher();
        foreach ($pages as $url => $page) {
            $fetcher->on($url, FixtureFetcher::json($page));
        }

        return $fetcher;
    }
}
