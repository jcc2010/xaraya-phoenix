<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use Xaraya\Module\Blog\View\PostView;

/** PostViews for template tests: a plain note, with any constructor argument overridden by name. */
final class PostViews
{
    /** @param array<string, mixed> $overrides PostView constructor arguments by name */
    public static function make(array $overrides = []): PostView
    {
        $defaults = [
            'id' => '01m3t19wn8reaww81zg1m47tjq',
            'kind' => 'note',
            'title' => 'A <title>',
            'url' => '/s/01m3t19wn8reaww81zg1m47tjq',
            'canonicalUrl' => 'http://xar.test/s/01m3t19wn8reaww81zg1m47tjq',
            'externalUrl' => null,
            'contentHtml' => '<p>Body &amp; soul</p>',
            'excerpt' => 'Body & soul',
            'image' => null,
            'publishedIso' => '2026-10-01T09:05:00+00:00',
            'publishedDisplay' => '1 Oct 2026',
            'tags' => [],
            'source' => null,
            'quote' => null,
            'commentHtml' => null,
            'images' => [],
            'video' => null,
            'podcast' => null,
            'song' => null,
            'mentions' => [],
            'pinned' => false,
        ];

        return new PostView(...[...$defaults, ...$overrides]);
    }

    /** @return array<string, PostView> one post of every kind, with every extra somewhere */
    public static function everyKind(): array
    {
        return [
            'note' => self::make([
                'tags' => [['name' => 'Café', 'slug' => 'café', 'url' => '/blog/notes/tag/caf%C3%A9']],
                'pinned' => true,
                'mentions' => [
                    ['source' => 'https://other.test/a', 'title' => 'A "reply"', 'date' => '3 Oct 2026'],
                    ['source' => 'https://other.test/b', 'title' => 'https://other.test/b', 'date' => null],
                ],
                'song' => ['channel' => 'Rick & Co', 'durationDisplay' => '3:33'],
            ]),
            'link' => self::make([
                'kind' => 'link',
                'externalUrl' => 'https://example.test/story?a=1&b=2',
                'source' => ['name' => 'example.test', 'url' => 'https://example.test/'],
                'commentHtml' => '<p>My take.</p>',
                'contentHtml' => '<p>The description.</p>',
                'video' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'thumbnail' => 'https://cdn.test/t.jpg', 'watchUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ]),
            'highlight' => self::make([
                'kind' => 'highlight',
                'quote' => 'To be <or> not',
                'source' => ['name' => 'Someone', 'url' => null],
                'commentHtml' => '<p>Indeed.</p>',
                'podcast' => ['show' => 'Show', 'episode' => 'Ep 1', 'durationDisplay' => '1:02:05', 'audioUrl' => 'https://cdn.test/ep.mp3', 'cover' => 'https://cdn.test/c.jpg'],
            ]),
            'photo' => self::make([
                'kind' => 'photo',
                'contentHtml' => '',
                'image' => 'https://cdn.test/a.jpg',
                'images' => [
                    ['url' => 'https://cdn.test/a.jpg', 'width' => 800, 'height' => 600],
                    ['url' => 'https://cdn.test/b.jpg', 'width' => null, 'height' => null],
                ],
            ]),
        ];
    }
}
