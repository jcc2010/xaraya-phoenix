<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\Cursor;
use Xaraya\Module\Blog\Post\PostRepository;

final class HomePageTest extends BlogPageTestCase
{
    private App $site;

    private Blog $blog;

    /** Posts 0 (newest) to $count - 1, an hour apart. */
    private function posts(int $count): void
    {
        $this->site = $this->enableBlog();
        $this->blog = $this->nativeBlog($this->site);
        for ($n = 0; $n < $count; $n++) {
            $this->addPost($this->site, $this->blog, self::postRecord($n));
        }
    }

    private function pin(string $itemId): void
    {
        $this->blogs($this->site)->update($this->blog, ['pinned_item_id' => $itemId], $this->now());
    }

    /** @return list<string> the titles of the post cards, in page order */
    private static function titles(string $html): array
    {
        preg_match_all('#<h2 class="post-title"><a href="[^"]*">([^<]*)</a></h2>#', $html, $m);

        return $m[1];
    }

    private static function next(string $html): string
    {
        self::assertSame(1, preg_match('#<a class="pager-next" rel="next" href="(/blog/notes\?before=[A-Za-z0-9_-]+)">Older posts</a>#', $html, $m), 'no next link');

        return $m[1];
    }

    public function testTheHomePagePagesThroughEveryPostNewestFirst(): void
    {
        $this->posts(45);
        [$response, $html] = $this->both('/blog/notes');
        self::assertSecureHtml($response);
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        foreach ([
            '<html lang="en">',
            '<title>Notes</title>',
            '<meta name="description" content="Short notes &amp; links.">',
            '<meta property="og:title" content="Notes">',
            '<meta property="og:type" content="website">',
            '<meta property="og:url" content="http://xar.test/blog/notes">',
            '<link rel="canonical" href="http://xar.test/blog/notes">',
            '<link rel="alternate" type="application/feed+json" title="Notes" href="http://xar.test/blog/notes/feed.json">',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        $main = self::main($html);
        self::assertStringStartsWith('<header class="blog-header"><h1 class="blog-title">Notes</h1><p class="blog-description">Short notes &amp; links.</p></header>', $main);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(0, 19)), self::titles($main));
        self::assertStringNotContainsString('pager-prev', $main);

        [, $html] = $this->both(self::next($main));
        $page2 = self::main($html);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(20, 39)), self::titles($page2));
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes">Newest posts</a>', $page2);

        [, $html] = $this->both(self::next($page2));
        $page3 = self::main($html);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(40, 44)), self::titles($page3));
        self::assertStringNotContainsString('pager-next', $page3);
    }

    public function testAnEmptyBlogAndAnUnknownBlog(): void
    {
        $this->posts(0);
        [, $html] = $this->both('/blog/notes');
        self::assertStringContainsString('<p class="blog-empty">No posts yet.</p>', self::main($html));
        self::assertStringNotContainsString('class="pager"', $html);
        [$response, $html] = $this->both('/blog/nope', 404);
        self::assertSecureHtml($response);
        self::assertStringContainsString('<h1>Page not found</h1>', $html);
    }

    public function testThePinnedPostSitsAbovePageOneOnly(): void
    {
        $this->posts(25);
        $this->pin('item-22');
        [, $html] = $this->both('/blog/notes');
        $main = self::main($html);
        self::assertStringContainsString(
            '<section class="blog-pinned" aria-label="Pinned post"><article class="post post-note post-pinned"><p class="post-pinned-label">Pinned</p><h2 class="post-title"><a href="/s/',
            $main,
        );
        self::assertSame('Post 22', self::titles($main)[0]);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(0, 19)), array_slice(self::titles($main), 1));
        [, $html] = $this->both(self::next($main));
        self::assertStringNotContainsString('blog-pinned', $html);
    }

    public function testThePinnedBadgeIsOnlyOnThePinnedSection(): void
    {
        $this->posts(25);
        $this->pin('item-3'); // inside page 1's range
        [, $html] = $this->both('/blog/notes');
        $main = self::main($html);
        self::assertSame(2, count(array_keys(self::titles($main), 'Post 3', true)));
        self::assertSame(1, substr_count($main, 'post-pinned">'), 'only the pinned section carries the badge class');
        self::assertSame(1, substr_count($main, 'post-pinned-label'));
        self::assertSame(1, preg_match('#<section class="blog-pinned".*?</section>#', $main, $m));
        self::assertStringContainsString('post-pinned">', $m[0]);

        $this->pin('item-22'); // on page 2
        [, $html] = $this->both('/blog/notes');
        [, $html] = $this->both(self::next(self::main($html)));
        $page2 = self::main($html);
        self::assertContains('Post 22', self::titles($page2));
        self::assertStringNotContainsString('post-pinned', $page2);
    }

    public function testAPinnedPostThatIsHiddenIsNotShown(): void
    {
        $this->posts(3);
        $this->addPost($this->site, $this->blog, self::postRecord(9, ['itemId' => 'later', 'date' => $this->now()->modify('+1 day')]));
        $this->site->container()->get(PostRepository::class)->tombstone($this->blog->id, ['item-2'], $this->now());
        foreach (['later', 'item-2', 'no-such-item'] as $itemId) {
            $this->pin($itemId);
            [, $html] = $this->both('/blog/notes');
            self::assertStringNotContainsString('blog-pinned', $html, $itemId);
            self::assertSame(['Post 0', 'Post 1'], self::titles(self::main($html)), $itemId);
        }
    }

    public function testCursorTampering(): void
    {
        $this->posts(3);
        $scheduled = $this->addPost($this->site, $this->blog, self::postRecord(9, ['title' => 'Scheduled', 'date' => $this->now()->modify('+1 year')]));
        foreach (['?before=!!!', '?before=', '?before[]=x', '?before=' . str_repeat('A', 201)] as $query) {
            [$response, $html] = $this->both('/blog/notes' . $query, 400);
            self::assertSecureHtml($response);
            self::assertStringContainsString('<h1>400 Bad Request</h1><p>Invalid before cursor</p>', $html, $query);
        }
        // A well-formed cursor from the far future still never reaches a scheduled post.
        $future = Cursor::encode($this->now()->modify('+10 years'), $scheduled);
        [, $html] = $this->both('/blog/notes?before=' . $future);
        self::assertSame(['Post 0', 'Post 1', 'Post 2'], self::titles(self::main($html)));
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes?before=' . $future . '">', $html);
        // One from before the oldest post is an empty page, not an error.
        [, $html] = $this->both('/blog/notes?before=' . Cursor::encode($this->now()->modify('-10 years'), $scheduled));
        self::assertStringContainsString('<p class="blog-empty">No posts yet.</p>', self::main($html));
    }
}
