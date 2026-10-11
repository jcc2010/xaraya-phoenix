<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use Xaraya\Kernel\Assets\AssetPublisher;

final class BlogAssetsTest extends BlogTestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/modules/blog/assets/blog.css');
    }

    public function testTheStylesheetCoversThePartialsAndUserPreferences(): void
    {
        $css = $this->css();
        foreach ([
            '.visually-hidden', '.post-title', '.post-pinned', '.post-comment', '.post-quote', '.gallery', '.video-play',
            '.post-podcast', '.post-tags', '.post-nav', '.pager', '.tag-step-1', '.tag-step-5', '.search-form',
            '.search-snippet mark', '.blog-recent', ':focus-visible', 'prefers-color-scheme: dark', 'prefers-reduced-motion: reduce',
        ] as $needle) {
            self::assertStringContainsString($needle, $css);
        }
        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('url(', $css, 'no fonts or images: the CSP allows only same-origin styles');
    }

    public function testAssetPublishPublishesItUnderModuleBlog(): void
    {
        $app = $this->app(['assets.path' => $this->tmp . '/public/assets']);
        $done = $app->container()->get(AssetPublisher::class)->publish(copy: true);
        self::assertContains(['module', 'blog'], array_map(static fn(array $row): array => [$row['kind'], $row['name']], $done));
        self::assertSame($this->css(), file_get_contents($this->tmp . '/public/assets/module/blog/blog.css'));
    }
}
