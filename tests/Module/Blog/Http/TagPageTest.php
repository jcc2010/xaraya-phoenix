<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Module\Blog\Post\PostRepository;

final class TagPageTest extends BlogPageTestCase
{
    public function testATagListsItsPostsNewestFirstTwentyAPage(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        for ($n = 0; $n < 30; $n++) { // every fifth post is untagged
            $this->addPost($app, $blog, self::postRecord($n, ['tags' => $n % 5 === 4 ? [] : [['name' => 'Travel', 'slug' => 'travel']]]));
        }
        [$response, $html] = $this->both('/blog/notes/tag/travel');
        self::assertSecureHtml($response);
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<title>Travel · Notes</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/tag/travel">', $html);
        $main = self::main($html);
        self::assertStringContainsString('<header class="blog-header"><p class="blog-name">Notes</p><h1 class="blog-title">Tagged “Travel”</h1></header>', $main);
        self::assertSame(20, substr_count($main, '<article '));
        self::assertStringContainsString('>Post 0</a></h2>', $main);
        self::assertStringNotContainsString('>Post 4</a>', $main, 'untagged');
        self::assertMatchesRegularExpression('#<a class="pager-next" rel="next" href="/blog/notes/tag/travel\?before=([A-Za-z0-9_-]+)">Older posts</a>#', $main);
        self::assertStringNotContainsString('pager-prev', $main);

        preg_match('#href="(/blog/notes/tag/travel\?before=[A-Za-z0-9_-]+)"#', $main, $m);
        [, $html] = $this->both($m[1]);
        $page2 = self::main($html);
        self::assertSame(['Post 25', 'Post 26', 'Post 27', 'Post 28'], self::titles($page2));
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes/tag/travel">Newest posts</a>', $page2);
        self::assertStringNotContainsString('pager-next', $page2);
    }

    public function testATagWithNoLivePostsIs404(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $tag = [['name' => 'gone', 'slug' => 'gone']];
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => $tag, 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(2, ['tags' => $tag]));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-2'], $this->now());
        [$response, $html] = $this->both('/blog/notes/tag/gone', 404);
        self::assertSecureHtml($response, self::ERROR_REFERRER);
        self::assertStringContainsString('<h1>Page not found</h1>', $html);
        $this->both('/blog/notes/tag/never', 404);
        $this->both('/blog/nope/tag/gone', 404);
    }

    public function testSlugsAreDecodedOnceAndComparedExactly(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['title' => 'Percent', 'tags' => [['name' => '100%', 'slug' => '100%']]]));
        $this->addPost($app, $blog, self::postRecord(2, ['title' => 'Unicode', 'tags' => [['name' => 'Café', 'slug' => 'café']]]));
        $this->addPost($app, $blog, self::postRecord(3, ['title' => 'Plus', 'tags' => [['name' => 'a+b', 'slug' => 'a+b']]]));
        self::assertSame(['Percent'], self::titles(self::main($this->both('/blog/notes/tag/100%25')[1])));
        self::assertSame(['Unicode'], self::titles(self::main($this->both('/blog/notes/tag/caf%C3%A9')[1])));
        self::assertSame(['Plus'], self::titles(self::main($this->both('/blog/notes/tag/a%2Bb')[1])));
        $this->both('/blog/notes/tag/100%2525', 404); // decoded once: "100%25", not "100%"
        $this->both('/blog/notes/tag/a%2Fb', 404);    // "/" never matches a slug segment
        $this->both('/blog/notes/tag/%FF', 404);      // not UTF-8: refused before the database sees it
        $this->both('/blog/notes/tag/%25', 404);
    }

    public function testMalformedCursorsAre400ThroughTheTheme(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => [['name' => 't', 'slug' => 't']]]));
        foreach (['?before=!!!', '?before=', '?before[]=x', '?before=' . rtrim(strtr(base64_encode('2026-13-45 99:99:99|01m3t19wn8reaww81zg1m47tjq'), '+/', '-_'), '=')] as $query) {
            [$response, $html] = $this->both('/blog/notes/tag/t' . $query, 400);
            self::assertSecureHtml($response, self::ERROR_REFERRER);
            self::assertStringContainsString('<h1>400 Bad Request</h1><p>Invalid before cursor</p>', $html, $query);
        }
    }

    /** @return list<string> the titles of the post cards, in page order */
    private static function titles(string $html): array
    {
        preg_match_all('#<h2 class="post-title"><a href="[^"]*">([^<]*)</a></h2>#', $html, $m);

        return $m[1];
    }
}
