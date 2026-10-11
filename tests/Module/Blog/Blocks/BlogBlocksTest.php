<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Blocks;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Tests\Module\Blog\Http\BlogPageTestCase;
use Xaraya\Tests\Support\Html;

final class BlogBlocksTest extends BlogPageTestCase
{
    /** @return string the id of the newest post */
    private function seed(): string
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app, ['description_html' => '<p>' . str_repeat('word ', 60) . '</p><script>alert(1)</script>']);
        $tag = static fn(string $name): array => ['name' => $name, 'slug' => strtolower($name)];
        for ($n = 1; $n <= 7; $n++) {
            $newest = $this->addPost($app, $blog, self::postRecord($n, ['title' => "Post {$n}", 'tags' => $n <= 3 ? [$tag('Travel')] : [$tag('Art')]]));
            if ($n === 1) {
                $id = $newest;
            }
        }
        $this->addPost($app, $blog, self::postRecord(0, ['title' => 'Scheduled', 'tags' => [$tag('Hidden')], 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(8, ['title' => 'Deleted', 'tags' => [$tag('Gone')]]));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-8'], $this->now());

        return $id ?? self::fail('no post');
    }

    private static function sidebar(string $html): string
    {
        return preg_match('#<aside class="site-sidebar" aria-label="Sidebar">(.*)</aside>#s', $html, $m) === 1 ? $m[1] : '';
    }

    public function testEnablingSeedsFourSidebarBlocksForBlogRoutes(): void
    {
        $this->seed();
        $blocks = $this->site()->container()->get(BlockRepository::class)->forRegion('sidebar');
        self::assertSame(['blog.searchbox', 'blog.info', 'blog.recent', 'blog.tagcloud'], array_map(static fn($b): string => $b->type, $blocks));
        foreach ($blocks as $block) {
            self::assertSame(['routes' => ['blog.*']], $block->visibility);
        }
    }

    public function testTheSidebarOnTheHomePageInBothEngines(): void
    {
        $this->seed();
        [, $html] = $this->both('/blog/notes');
        $sidebar = self::sidebar($html);
        $description = rtrim(str_repeat('word ', 40)) . '…';
        foreach ([
            '<section class="block block-blog-searchbox" aria-labelledby="block-1-title"><h2 class="block-title" id="block-1-title">Search</h2>'
            . '<form class="search-form" role="search" method="get" action="/blog/notes/search">',
            '<section class="block block-blog-info" aria-labelledby="block-2-title"><h2 class="block-title" id="block-2-title">About</h2>'
            . '<p class="blog-info-description">' . $description . '</p><ul class="blog-info-links"><li><a href="/blog/notes/about">About Notes</a></li>'
            . '<li><a href="/blog/notes/feed.json" type="application/feed+json">JSON Feed</a></li><li><a href="/blog/notes/feed.xml" type="application/rss+xml">RSS</a></li></ul>',
            '<h2 class="block-title" id="block-3-title">Recent posts</h2><ul class="blog-recent"><li><a href="/s/',
            '<h2 class="block-title" id="block-4-title">Tags</h2><ul class="tag-cloud">'
            . '<li class="tag-step-5"><a href="/blog/notes/tag/art" rel="tag">Art</a><span class="tag-count">4</span></li>'
            . '<li class="tag-step-1"><a href="/blog/notes/tag/travel" rel="tag">Travel</a><span class="tag-count">3</span></li>'
            . '</ul><p class="blog-tags-all"><a href="/blog/notes/tags">All tags</a></p>',
        ] as $needle) {
            self::assertStringContainsString($needle, $sidebar);
        }
        preg_match_all('#<ul class="blog-recent">(.*?)</ul>#s', $sidebar, $recent);
        self::assertSame(5, substr_count($recent[1][0], '<li>'));
        foreach (['Scheduled', 'Deleted', 'Hidden', 'Gone', 'alert'] as $absent) {
            self::assertStringNotContainsString($absent, $html);
        }
    }

    public function testTheTagCloudBlockLeavesOutTagsNoRouteCanMatch(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => [['name' => 'Fine', 'slug' => 'fine'], ['name' => 'Slashed', 'slug' => 'a/b']]]));
        [, $html] = $this->both('/blog/notes');
        $sidebar = self::sidebar($html);
        self::assertStringContainsString('<a href="/blog/notes/tag/fine" rel="tag">Fine</a>', $sidebar);
        self::assertStringNotContainsString('Slashed', $sidebar);
        self::assertStringNotContainsString('a/b', $sidebar);
    }

    public function testBlocksFindTheBlogOnPostAndSearchPages(): void
    {
        $id = $this->seed();
        self::assertStringContainsString('<a href="/blog/notes/about">About Notes</a>', self::sidebar($this->both("/s/{$id}")[1]));
        $sidebar = self::sidebar($this->both('/blog/notes/search?q=post')[1]);
        self::assertStringContainsString('<input type="search" name="q" value="post" maxlength="200" required>', $sidebar);
        self::assertStringContainsString('<input type="search" name="q" value="" maxlength="200" required>', self::sidebar($this->both('/blog/notes/tags')[1]));
    }

    public function testBlocksStayOffOtherPages(): void
    {
        $this->seed();
        $home = Html::normalize((string) $this->site()->handle(new ServerRequest('GET', '/'))->getBody());
        self::assertStringNotContainsString('<aside', $home);
        [, $html] = $this->both('/blog/nope', 404);
        self::assertStringNotContainsString('<aside', $html, 'error pages have no route, so route-limited blocks hide');
    }

    public function testBlogBlocksSeedsOnlyWhenNoneExist(): void
    {
        $app = $this->enableBlog();
        [$code, $out] = $this->xar(['blog:blocks'], $app);
        self::assertSame(0, $code);
        self::assertSame("4 blog block(s) already exist; nothing to do.\n", $out);

        $db = $this->db($app);
        $db->pdo()->exec('DELETE FROM ' . $db->table('blocks'));
        [$code, $out] = $this->xar(['blog:blocks'], $app);
        self::assertSame(0, $code);
        self::assertSame("Created 4 blog block(s).\n", $out);
        self::assertSame(4, $db->select('blocks')->where('type', 'like', 'blog.%')->count());
    }
}
