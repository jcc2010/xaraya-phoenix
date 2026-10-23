<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Kernel\Http\Controller;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\PostRepository;

final class PostPageTest extends BlogPageTestCase
{
    /** @return array{0: string, 1: string, 2: string} the ids of posts 1 (newer), 2 and 3 (older) */
    private function three(): array
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);

        return [
            $this->addPost($app, $blog, self::postRecord(1)),
            $this->addPost($app, $blog, self::postRecord(2, [
                'title' => 'Second <post>',
                'content' => '<p>Second body.</p><script>alert(1)</script>',
                'image' => 'https://cdn.test/2.jpg',
                'tags' => [['name' => 'Café', 'slug' => 'café'], ['name' => 'travel', 'slug' => 'travel']],
            ])),
            $this->addPost($app, $blog, self::postRecord(3)),
        ];
    }

    public function testAPostPageInBothEngines(): void
    {
        [$newer, $id, $older] = $this->three();
        [$response, $html] = $this->both("/s/{$id}");
        self::assertSecureHtml($response);
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Set-Cookie'), 'an anonymous page mints no session');
        self::assertNotSame('', $response->getHeaderLine('ETag'));
        self::assertFalse($response->hasHeader('Last-Modified'));
        foreach ([
            '<html lang="en">',
            '<title>Second &lt;post&gt; · Notes</title>',
            '<meta name="description" content="Second body.">',
            '<meta property="og:title" content="Second &lt;post&gt;">',
            '<meta property="og:type" content="article">',
            "<meta property=\"og:url\" content=\"http://xar.test/s/{$id}\">",
            '<meta property="og:image" content="https://cdn.test/2.jpg">',
            "<link rel=\"canonical\" href=\"http://xar.test/s/{$id}\">",
            '<link rel="alternate" type="application/feed+json" title="Notes" href="http://xar.test/blog/notes/feed.json">',
            '<link rel="alternate" type="application/rss+xml" title="Notes" href="http://xar.test/blog/notes/feed.xml">',
            '<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css"><link rel="stylesheet" href="/assets/module/blog/blog.css">',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        $main = self::main($html);
        foreach ([
            '<article class="post post-note"><h1 class="post-title">Second &lt;post&gt;</h1><div class="post-content"><p>Second body.</p></div>',
            '<li><a href="/blog/notes/tag/caf%C3%A9" rel="tag">Café</a></li><li><a href="/blog/notes/tag/travel" rel="tag">travel</a></li>',
            "<nav class=\"post-nav\" aria-label=\"More posts\"><p class=\"post-nav-newer\">Newer:<a href=\"/s/{$newer}\">Post 1</a></p><p class=\"post-nav-older\">Older:<a href=\"/s/{$older}\">Post 3</a></p></nav>",
        ] as $needle) {
            self::assertStringContainsString($needle, $main);
        }
        self::assertStringNotContainsString('<script', $html);

        [, $html] = $this->both("/s/{$newer}");
        self::assertStringNotContainsString('post-nav-newer', $html, 'the newest post has no newer neighbour');
        self::assertSame(304, $this->get("/s/{$id}", 'php', ['If-None-Match' => $response->getHeaderLine('ETag')])->getStatusCode());
    }

    public function testHiddenAndUnknownPostsAre404ThroughTheTheme(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $scheduled = $this->addPost($app, $blog, self::postRecord(1, ['date' => $this->now()->modify('+1 day')]));
        $deleted = $this->addPost($app, $blog, self::postRecord(2));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-2'], $this->now());
        foreach ([$scheduled, $deleted, '01m3t19wn8reaww81zg1m47tjq'] as $id) {
            [$response, $html] = $this->both("/s/{$id}", 404);
            self::assertSecureHtml($response, self::ERROR_REFERRER);
            self::assertStringContainsString('<title>404 Not Found</title>', $html);
            self::assertStringContainsString('<h1>Page not found</h1>', $html);
        }
        $visible = $this->addPost($app, $blog, self::postRecord(3));
        self::assertStringNotContainsString('post-nav', self::main($this->both("/s/{$visible}")[1]), 'hidden posts are never neighbours');
    }

    public function testTheJsonRouteIsUnchanged(): void
    {
        [, $id] = $this->three();
        $app = $this->site();
        $response = $this->get("/s/{$id}.json");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        $post = $app->container()->get(PostRepository::class)->find($id) ?? self::fail('post missing');
        $blog = $this->blogs($app)->find('notes') ?? self::fail('blog missing');
        $expected = Controller::jsonResponse((new ItemSerializer())->item($blog, $post, 'http://xar.test'));
        self::assertSame((string) $expected->getBody(), (string) $response->getBody());
    }

    public function testAMirrorPatternIsTheCanonicalAndTheLink(): void
    {
        $app = $this->enableBlog();
        $blog = $this->blogs($app)->create([
            'handle' => 'mirror', 'mode' => 'mirror', 'title' => 'Mirror',
            'source_url' => 'https://src.test/feed.json', 'source_format' => 'athena',
            'post_url_pattern' => 'https://www.example.test/post.html?id={id}',
        ], $this->now());
        $older = $this->addPost($app, $blog, self::postRecord(2));
        $id = $this->addPost($app, $blog, self::postRecord(1, ['url' => 'https://src.test/s/1']));
        [, $html] = $this->both("/s/{$id}");
        $canonical = "https://www.example.test/post.html?id={$id}";
        self::assertStringContainsString("<link rel=\"canonical\" href=\"{$canonical}\">", $html);
        self::assertStringContainsString("<meta property=\"og:url\" content=\"{$canonical}\">", $html);
        self::assertStringContainsString("<a class=\"post-permalink\" href=\"{$canonical}\">", $html);
        self::assertStringContainsString("Older:<a href=\"https://www.example.test/post.html?id={$older}\">Post 2</a>", $html);
    }
}
