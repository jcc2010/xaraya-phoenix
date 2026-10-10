<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Blocks;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\RecentItem;
use Xaraya\Kernel\Events\RecentItemsQuery;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\ViewFixtures;

final class BuiltInBlocksTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ViewFixtures::themes($this->tmp . '/themes');
        ViewFixtures::shop($this->tmp . '/modules');
        $this->app()->container()->get(ModuleRegistry::class)->enable('shop');
    }

    private function app(): App
    {
        return $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'plain']);
    }

    private function repo(): BlockRepository
    {
        return $this->app()->container()->get(BlockRepository::class);
    }

    /** Renders the "footer" region (plain has no block template, so the fallback wrapper is used). */
    private function footer(App $app, string $path = '/shop/7'): string
    {
        $request = RouteHandler::attach(new ServerRequest('GET', $path), $app->container()->get(Router::class)->match('GET', $path));

        return $app->container()->get(View::class)->helpers($request)->blocks('footer');
    }

    public function testTextBlocksInHtmlAndMarkdown(): void
    {
        $repo = $this->repo();
        $repo->create('text', 'footer', 'About', ['format' => 'html', 'body' => '<p>Trusted <b>HTML</b></p>']);
        $repo->create('text', 'footer', null, ['format' => 'markdown', 'body' => "Hi **there**\n\n- <script>x</script>"]);
        $repo->create('text', 'footer', 'Empty', ['body' => '']);
        self::assertSame(
            "<section class=\"block block-text\"><h2 class=\"block-title\">About</h2><p>Trusted <b>HTML</b></p></section>\n"
            . "<section class=\"block block-text\"><p>Hi <strong>there</strong></p>\n<ul><li>&lt;script&gt;x&lt;/script&gt;</li></ul></section>\n",
            $this->footer($this->app()),
        );
    }

    public function testMenuBlockLinksRoutesAndUrlsAndMarksTheCurrentPage(): void
    {
        $this->repo()->create('menu', 'footer', 'Shop menu', ['links' => [
            ['label' => 'Item 7', 'route' => 'shop.item', 'params' => ['id' => 7]],
            ['label' => 'All', 'route' => 'shop.list'],
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['label' => 'No target'],
        ]]);
        $html = $this->footer($this->app());
        self::assertStringContainsString(
            '<nav aria-label="Shop menu"><ul class="menu"><li><a href="/shop/7" aria-current="page">Item 7</a></li><li><a href="/shop">All</a></li><li><a href="/">Home</a></li></ul></nav>',
            $html,
        );
        self::assertStringNotContainsString('Bad', $html);
    }

    public function testMenuBlockEscapesAndRejectsHostileLinks(): void
    {
        $this->repo()->create('menu', 'footer', 'M<"', ['links' => [
            ['label' => '<b>x</b>', 'url' => '/a?b=1&c="2"'],
            ['label' => 'ev', 'url' => '/\\evil.test'],
            ['label' => 'ev2', 'url' => '//evil.test'],
            ['label' => 'js', 'url' => 'JAVASCRIPT:alert(1)'],
            ['label' => 'ws', 'url' => "/a\nb"],
            ['label' => 'data', 'url' => 'data:text/html,x'],
            ['label' => 'ok', 'url' => 'https://x.test/p'],
        ]]);
        $html = $this->footer($this->app());
        self::assertSame(
            '<nav aria-label="M&lt;&quot;"><ul class="menu"><li><a href="/a?b=1&amp;c=&quot;2&quot;">&lt;b&gt;x&lt;/b&gt;</a></li><li><a href="https://x.test/p">ok</a></li></ul></nav>',
            trim($html) === '' ? '' : preg_replace('/^.*?(<nav.*<\/nav>).*$/s', '$1', $html),
        );
    }

    public function testMenuRejectsATrailingNewlineAndSkipsUnknownRoutes(): void
    {
        $this->repo()->create('menu', 'footer', 'M', ['links' => [
            ['label' => 'nl', 'url' => "/a\n"],
            ['label' => 'gone', 'route' => 'nope.nothing'],
            ['label' => 'ok', 'url' => '/ok'],
        ]]);
        $html = $this->footer($this->app());
        self::assertStringContainsString('<ul class="menu"><li><a href="/ok">ok</a></li></ul>', $html);
    }

    public function testRecentItemsSkipUnsafeUrlsAndOtherModules(): void
    {
        $app = $this->app();
        $app->container()->get(EventDispatcher::class)->listen(RecentItemsQuery::class, function (RecentItemsQuery $query): void {
            $query->add(new RecentItem('shop', 'Good', '/shop/1'));
            $query->add(new RecentItem('shop', 'Evil', 'javascript:alert(1)'));
            $query->add(new RecentItem('shop', 'Rel', '//evil.test'));
            $query->add(new RecentItem('blog', 'Other', '/b/1'));
        });
        $this->repo()->create('recent-items', 'footer', null, ['module' => 'shop']);
        $html = $this->footer($app);
        self::assertStringContainsString('Good', $html);
        foreach (['Evil', 'Rel', 'Other', 'javascript'] as $bad) {
            self::assertStringNotContainsString($bad, $html);
        }
    }

    public function testRecentItemsStillFillTheLimitWhenANewerItemIsFilteredOut(): void
    {
        $app = $this->app();
        $app->container()->get(EventDispatcher::class)->listen(RecentItemsQuery::class, function (RecentItemsQuery $query): void {
            $query->add(new RecentItem('shop', 'Evil', 'javascript:alert(1)', new DateTimeImmutable('2026-10-03T00:00:00Z')));
            $query->add(new RecentItem('blog', 'Other', '/b/1', new DateTimeImmutable('2026-10-02T00:00:00Z')));
            $query->add(new RecentItem('shop', 'Second', '/shop/2', new DateTimeImmutable('2026-10-01T00:00:00Z')));
            $query->add(new RecentItem('shop', 'First', '/shop/1', new DateTimeImmutable('2026-09-01T00:00:00Z')));
            $query->add(new RecentItem('shop', 'Oldest', '/shop/0', new DateTimeImmutable('2026-01-01T00:00:00Z')));
        });
        $this->repo()->create('recent-items', 'footer', null, ['module' => 'shop', 'limit' => 2]);
        $html = $this->footer($app);
        self::assertSame(2, substr_count($html, '<li>'));
        self::assertStringContainsString('Second', $html);
        self::assertStringContainsString('First', $html);
        self::assertStringNotContainsString('Oldest', $html);
    }

    public function testRecentItemsAskModulesThroughAnEvent(): void
    {
        $app = $this->app();
        $app->container()->get(EventDispatcher::class)->listen(RecentItemsQuery::class, function (RecentItemsQuery $query): void {
            if ($query->wants('shop', 'item')) {
                $query->add(new RecentItem('shop', 'Old', '/shop/1', new DateTimeImmutable('2026-01-01T00:00:00Z')));
                $query->add(new RecentItem('shop', 'New <1>', '/shop/2', new DateTimeImmutable('2026-10-01T00:00:00Z')));
                $query->add(new RecentItem('shop', 'Undated', '/shop/3'));
            }
            if ($query->wants('blog', 'post')) {
                $query->add(new RecentItem('blog', 'Post', '/b/1', new DateTimeImmutable('2026-12-01T00:00:00Z')));
            }
        });
        $this->repo()->create('recent-items', 'footer', 'Recent', ['module' => 'shop', 'limit' => 2]);
        self::assertSame(
            '<section class="block block-recent-items"><h2 class="block-title">Recent</h2><ul class="recent-items">'
            . '<li><a href="/shop/2">New &lt;1&gt;</a> <time datetime="2026-10-01T00:00:00+00:00">1 Oct 2026</time></li>'
            . '<li><a href="/shop/1">Old</a> <time datetime="2026-01-01T00:00:00+00:00">1 Jan 2026</time></li>'
            . "</ul></section>\n",
            $this->footer($app),
        );
    }

    public function testRecentItemsWithNoAnswersIsHidden(): void
    {
        $this->repo()->create('recent-items', 'footer', 'Recent');
        self::assertSame('', $this->footer($this->app()));
    }

    public function testUnknownTextFormatFails(): void
    {
        $this->repo()->create('text', 'footer', null, ['format' => 'bbcode', 'body' => 'x']);
        $this->expectException(\InvalidArgumentException::class);
        $this->footer($this->app());
    }
}
