<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Module\Blog\Post\PostRepository;

final class SearchPageTest extends BlogPageTestCase
{
    private function seed(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['title' => 'Crème brûlée', 'content' => '<p>A <b>classic</b> dessert &amp; more.</p>']));
        $this->addPost($app, $blog, self::postRecord(2, ['title' => 'Dessert wine', 'content' => '<p>Sweet <script>alert(1)</script>wine.</p>']));
        $this->addPost($app, $blog, self::postRecord(3, ['title' => 'Scheduled dessert', 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(4, ['title' => 'Deleted dessert']));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-4'], $this->now());
        $other = $this->nativeBlog($app, ['handle' => 'other']);
        $this->addPost($app, $other, self::postRecord(5, ['title' => 'Other dessert']));
    }

    public function testHitsAreHighlightedInBothEngines(): void
    {
        $this->seed();
        [$response, $html] = $this->both('/blog/notes/search?q=DESSERT');
        self::assertSecureHtml($response);
        self::assertStringContainsString('<title>Search · Notes</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/search?q=DESSERT">', $html);
        $main = self::main($html);
        self::assertStringContainsString(
            '<form class="search-form" role="search" method="get" action="/blog/notes/search"><label><span class="visually-hidden">Search this blog</span>'
            . '<input type="search" name="q" value="DESSERT" maxlength="200" required></label><button type="submit">Search</button></form>'
            . '<p class="search-count">2 results</p><ol class="search-results">',
            $main,
        );
        self::assertStringContainsString('<h2 class="search-result-title"><a href="/s/', $main);
        self::assertStringContainsString('>Crème brûlée</a></h2><p class="search-snippet">A classic<mark>dessert</mark>&amp; more.</p><p class="post-meta"><time datetime="2026-10-01T11:00:00+00:00">1 Oct 2026</time></p>', $main);
        self::assertStringContainsString('<p class="search-snippet">Sweet wine.</p>', $main, 'snippets come from the sanitised text');
        foreach (['Scheduled', 'Deleted', 'Other dessert'] as $absent) {
            self::assertStringNotContainsString($absent, $main);
        }
        self::assertStringNotContainsString('<script', $html);

        [, $html] = $this->both('/blog/notes/search?q=creme+brulee');
        self::assertStringContainsString('<p class="search-count">1 result</p>', self::main($html));
        self::assertStringContainsString('<a href="/s/', self::main($html));
    }

    public function testMissesEmptyQueriesAndPunctuation(): void
    {
        $this->seed();
        [, $html] = $this->both('/blog/notes/search');
        $main = self::main($html);
        self::assertStringContainsString('value=""', $main);
        self::assertStringNotContainsString('search-count', $main);
        foreach (['?q=nothing', '?q=%25_%21', '?q=%25', '?q=a'] as $query) {
            [, $html] = $this->both('/blog/notes/search' . $query);
            self::assertStringContainsString('<p class="search-count">0 results</p>', self::main($html), $query);
            self::assertStringNotContainsString('search-results', self::main($html), $query);
        }
        [, $html] = $this->both('/blog/notes/search?q=%22%3E%3Cscript%3E');
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;"', $html);
        $this->both('/blog/nope/search?q=x', 404);
    }

    public function testOffsetPaging(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        for ($n = 0; $n < 45; $n++) {
            $this->addPost($app, $blog, self::postRecord($n, ['title' => "Match {$n}"]));
        }
        [, $html] = $this->both('/blog/notes/search?q=match');
        $main = self::main($html);
        self::assertStringContainsString('<p class="search-count">45 results</p>', $main);
        self::assertSame(20, substr_count($main, '<li class="search-result">'));
        self::assertStringContainsString('<a class="pager-next" rel="next" href="/blog/notes/search?q=match&amp;page=2">Next page</a>', $main);
        self::assertStringNotContainsString('pager-prev', $main);

        [, $html] = $this->both('/blog/notes/search?q=match&page=3');
        $main = self::main($html);
        self::assertSame(5, substr_count($main, '<li class="search-result">'));
        self::assertStringContainsString('>Match 40</a>', $main);
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes/search?q=match&amp;page=2">Previous page</a>', $main);
        self::assertStringNotContainsString('pager-next', $main);
        [, $html] = $this->both('/blog/notes/search?q=match&page=2');
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes/search?q=match">Previous page</a>', self::main($html));
        [, $html] = $this->both('/blog/notes/search?q=match&page=9');
        self::assertStringNotContainsString('search-results', self::main($html), 'past the end is an empty page');
    }

    public function testBadParametersAre400(): void
    {
        $this->seed();
        foreach ([
            '?q=x&page=0' => 'Invalid page number',
            '?q=x&page=-1' => 'Invalid page number',
            '?q=x&page=abc' => 'Invalid page number',
            '?q=x&page[]=2' => 'Invalid page number',
            '?q[]=x' => 'Invalid search query',
            '?q=' . str_repeat('é', 201) => 'The search query is longer than 200 characters',
        ] as $query => $message) {
            [$response, $html] = $this->both('/blog/notes/search' . $query, 400);
            self::assertSecureHtml($response, self::ERROR_REFERRER);
            self::assertStringContainsString("<h1>400 Bad Request</h1><p>{$message}</p>", $html, $query);
        }
        $this->both('/blog/notes/search?q=' . str_repeat('é', 200));
    }
}
