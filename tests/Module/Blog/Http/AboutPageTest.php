<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

final class AboutPageTest extends BlogPageTestCase
{
    public function testTheAboutPageInBothEngines(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app, [
            'description_html' => '<p>Notes &amp; <a href="https://ext.test/" onclick="x()">links</a>.</p><script>alert(1)</script>',
            'author_url' => 'https://ada.test/',
        ]);
        $this->blogs($app)->update($blog, ['extra' => ['_athenana' => ['subscribe_url' => 'https://ada.test/subscribe']]], $this->now());
        [$response, $html] = $this->both('/blog/notes/about');
        self::assertSecureHtml($response);
        self::assertStringContainsString('<title>About · Notes</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Notes &amp; links.">', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/about">', $html);
        self::assertSame(
            '<article class="blog-about"><h1 class="blog-title">Notes</h1>'
            . '<div class="blog-description"><p>Notes &amp;<a href="https://ext.test/" rel="nofollow noopener ugc" target="_blank">links</a>.</p></div>'
            . '<p class="blog-author">By<a href="https://ada.test/" rel="author">Ada</a></p><h2>Follow</h2><ul class="blog-feeds">'
            . '<li><a href="http://xar.test/blog/notes/feed.json" type="application/feed+json">JSON Feed</a></li>'
            . '<li><a href="http://xar.test/blog/notes/feed.xml" type="application/rss+xml">RSS</a></li>'
            . '<li><a href="https://ada.test/subscribe">Subscribe by email</a></li></ul></article>',
            self::main($html),
        );
    }

    public function testMissingOrUnsafeLinksAreLeftOut(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app, ['description_html' => null, 'author_url' => null]);
        $this->blogs($app)->update($blog, ['extra' => ['_athenana' => ['subscribe_url' => 'javascript:alert(1)']]], $this->now());
        [, $html] = $this->both('/blog/notes/about');
        $main = self::main($html);
        self::assertStringNotContainsString('blog-description', $main);
        self::assertStringContainsString('<p class="blog-author">By Ada</p>', $main);
        self::assertStringNotContainsString('Subscribe', $main);
        self::assertStringNotContainsString('<meta name="description"', $html);
        $this->both('/blog/nope/about', 404);
    }
}
