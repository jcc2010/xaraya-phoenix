<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Admin\Http\AdminPage;
use Xaraya\Module\Blog\Admin\Http\PostListController;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Tests\Support\Html;

final class PostListTest extends AdminTestCase
{
    private Blog $blog;
    private User $ada;

    private function setUpPosts(App $app): void
    {
        $this->blog = $this->nativeBlog($app);
        $this->ada = $this->user($app, 'ada', ['Authors']);
    }

    public function testTabsSplitPublishedScheduledAndDeleted(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        $this->postAs($app, $this->ada, $this->blog, ['title' => 'Live one', 'content_html' => '<p>x</p>']);
        $this->postAs($app, $this->ada, $this->blog, ['title' => 'Future one', 'content_html' => '<p>x</p>', 'date_published' => '2999-01-01T00:00:00Z']);
        $this->user($app, 'ed', ['Editors']);
        $gone = $this->postAs($app, null, $this->blog, ['title' => 'Gone one', 'content_html' => '<p>x</p>']);
        $app->container()->get(PostWriter::class)->delete($gone);

        $published = self::main($this->both('/admin/blog/notes/posts', 'ada'));
        self::assertStringContainsString('Live one', $published);
        self::assertStringContainsString('<div class="admin-scroll"><table class="admin-posts">', $published, 'a wide table scrolls on its own');
        self::assertStringNotContainsString('Future one', $published);
        self::assertStringNotContainsString('Gone one', $published);
        self::assertStringContainsString('<a href="/admin/blog/notes/posts" aria-current="page">Published (1)</a>', $published);
        self::assertStringContainsString('<td class="admin-owner">ada</td>', $published);

        $scheduled = self::main($this->both('/admin/blog/notes/posts?tab=scheduled', 'ada'));
        self::assertStringContainsString('Future one', $scheduled);
        self::assertStringNotContainsString('Live one', $scheduled);
        $deleted = self::main($this->both('/admin/blog/notes/posts?tab=deleted', 'ed'));
        self::assertStringContainsString('Gone one', $deleted);
        self::assertStringContainsString('<td class="admin-owner">—</td>', $deleted);
    }

    public function testTitleSearchIgnoresCaseAndTreatsWildcardsLiterally(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        foreach (['Coffee notes', 'COFFEE at noon', '100% tea', 'tea_time', 'Water'] as $title) {
            $this->postAs($app, $this->ada, $this->blog, ['title' => $title, 'content_html' => '<p>x</p>']);
        }
        $browser = $this->signIn($app, 'ada');
        $titles = function (string $q) use ($browser): array {
            preg_match_all('#<tr id="post-[^"]+"><td[^>]*>(?:<a[^>]*>)?([^<]+)#', self::main(Html::normalize((string) $browser->get('/admin/blog/notes/posts?q=' . rawurlencode($q))->getBody())), $m);
            sort($m[1]);

            return $m[1];
        };
        self::assertSame(['COFFEE at noon', 'Coffee notes'], $titles('coffee'));
        self::assertSame(['100% tea'], $titles('%'));
        self::assertSame(['tea_time'], $titles('_'));
        self::assertSame([], $titles("a\0b"), 'an embedded NUL is dropped, never an error');
        self::assertSame(200, $browser->get('/admin/blog/notes/posts?q=a%00b')->getStatusCode());
        self::assertSame(200, $browser->get('/admin/blog/notes/posts?q=%00')->getStatusCode());
        self::assertSame(400, $browser->get('/admin/blog/notes/posts?tab=drafts')->getStatusCode());
        self::assertSame(400, $browser->get('/admin/blog/notes/posts?q[]=x')->getStatusCode());
        self::assertSame(400, $browser->get('/admin/blog/notes/posts?q=' . str_repeat('a', 201))->getStatusCode());
        self::assertSame(404, $browser->get('/admin/blog/nope/posts')->getStatusCode());
    }

    public function testTitleSearchFindsNonAsciiTitles(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        foreach (['Été à Paris', 'Winter'] as $title) {
            $this->postAs($app, $this->ada, $this->blog, ['title' => $title, 'content_html' => '<p>x</p>']);
        }
        $browser = $this->signIn($app, 'ada');
        foreach (['Été', 'à Paris'] as $q) {
            $list = self::main((string) $browser->get('/admin/blog/notes/posts?q=' . rawurlencode($q))->getBody());
            self::assertStringContainsString('Été à Paris', $list, $q);
            self::assertStringNotContainsString('Winter', $list, $q);
        }
    }

    public function testBadPagesAre400AndEveryResponseIsNoStore(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        $browser = $this->signIn($app, 'ada');
        foreach (['page=0', 'page=abc', 'page[]=1'] as $query) {
            $response = $browser->get('/admin/blog/notes/posts?' . $query);
            self::assertSame(400, $response->getStatusCode(), $query);
            self::assertPrivateNoStore($response);
        }
        foreach (['/admin/blog/notes/posts', '/admin/blog/notes/posts?tab=nope', '/admin/blog/nope/posts'] as $path) {
            self::assertPrivateNoStore($browser->get($path));
        }
    }

    public function testTwentyPerPageWithAPagerThatKeepsTheTabAndQuery(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        for ($i = 1; $i <= 21; $i++) {
            $this->postAs($app, $this->ada, $this->blog, ['title' => "Tea {$i}", 'content_html' => '<p>x</p>', 'date_published' => sprintf('2999-01-%02dT00:00:00Z', $i)]);
        }
        $browser = $this->signIn($app, 'ada');
        $first = (string) $browser->get('/admin/blog/notes/posts?tab=scheduled&q=tea')->getBody();
        self::assertSame(20, substr_count($first, '<tr id="post-'));
        self::assertStringContainsString('href="/admin/blog/notes/posts?tab=scheduled&amp;q=tea&amp;page=2"', $first);
        $second = (string) $browser->get('/admin/blog/notes/posts?tab=scheduled&q=tea&page=2')->getBody();
        self::assertSame(1, substr_count($second, '<tr id="post-'));
        self::assertStringContainsString('Tea 1<', $second, 'oldest last');
    }

    public function testTitlesAndTagsAreEscapedInTheList(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        $this->postAs($app, $this->ada, $this->blog, ['title' => '"><script>alert(1)</script>', 'content_html' => '<p>x</p>', 'tags' => ['"><img src=x onerror=alert(1)>']]);
        $html = $this->both('/admin/blog/notes/posts', 'ada');
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x', $html);
    }

    public function testAuthorsSeeOnlyTheirOwnPrivateTabsAndCountsEditorsSeeAll(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        $bob = $this->user($app, 'bob', ['Authors']);
        $this->user($app, 'ed', ['Editors']);
        $writer = $app->container()->get(PostWriter::class);
        foreach ([[$this->ada, 'Ada live'], [$bob, 'Bob live']] as [$owner, $title]) {
            $this->postAs($app, $owner, $this->blog, ['title' => $title, 'content_html' => '<p>x</p>']);
        }
        foreach ([[$this->ada, 'Ada future'], [$bob, 'Bob future']] as [$owner, $title]) {
            $this->postAs($app, $owner, $this->blog, ['title' => $title, 'content_html' => '<p>x</p>', 'date_published' => '2999-01-01T00:00:00Z']);
        }
        foreach ([[$this->ada, 'Ada gone'], [$bob, 'Bob gone']] as [$owner, $title]) {
            $writer->delete($this->postAs($app, $owner, $this->blog, ['title' => $title, 'content_html' => '<p>x</p>']));
        }

        $ada = $this->signIn($app, 'ada');
        $published = (string) $ada->get('/admin/blog/notes/posts')->getBody();
        self::assertStringContainsString('Ada live', $published);
        self::assertStringContainsString('Bob live', $published);
        self::assertStringContainsString('Published (2)', $published);
        self::assertStringContainsString('Scheduled (1)', $published);
        self::assertStringContainsString('Deleted (1)', $published);
        foreach (['scheduled' => ['Ada future', 'Bob future'], 'deleted' => ['Ada gone', 'Bob gone']] as $tab => [$mine, $theirs]) {
            $html = (string) $ada->get('/admin/blog/notes/posts?tab=' . $tab)->getBody();
            self::assertStringContainsString($mine, $html, $tab);
            self::assertStringNotContainsString($theirs, $html, $tab);
            $searched = (string) $ada->get('/admin/blog/notes/posts?tab=' . $tab . '&q=bob')->getBody();
            self::assertStringNotContainsString($theirs, $searched, $tab);
        }

        $ed = $this->signIn($app, 'ed');
        $all = (string) $ed->get('/admin/blog/notes/posts')->getBody();
        self::assertStringContainsString('Scheduled (2)', $all);
        self::assertStringContainsString('Deleted (2)', $all);
        self::assertStringContainsString('Bob future', (string) $ed->get('/admin/blog/notes/posts?tab=scheduled')->getBody());
        self::assertStringContainsString('Bob gone', (string) $ed->get('/admin/blog/notes/posts?tab=deleted')->getBody());
    }

    /** The controller wired to a generator that knows the Task 7/8 routes, which the real app does not have yet. */
    private function controller(App $app): PostListController
    {
        $c = $app->container();
        $routes = [];
        foreach (['admin.blog.posts' => '/{handle}/posts', 'admin.blog.post.new' => '/{handle}/post/new', 'admin.blog.post.edit' => '/post/{id}', 'admin.blog.post.delete' => '/post/{id}/delete', 'admin.blog.post.undelete' => '/post/{id}/undelete'] as $name => $path) {
            $routes[] = new Route(['GET'], '/admin/blog' . $path, 'x', $name);
        }

        return new PostListController(
            $c->get(BlogRepository::class),
            $c->get(PostRepository::class),
            $c->get(UserRepository::class),
            $c->get(AdminAccess::class),
            $c->get(AdminPage::class),
            new UrlGenerator($routes, ''),
            $c->get(Config::class),
        );
    }

    public function testRowActionsFollowPerPostPermissionsAndMirrorsGetNone(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        $bob = $this->user($app, 'bob', ['Authors']);
        $mine = $this->postAs($app, $this->ada, $this->blog, ['title' => 'Mine', 'content_html' => '<p>x</p>']);
        $theirs = $this->postAs($app, $bob, $this->blog, ['title' => 'Theirs', 'content_html' => '<p>x</p>']);
        $mirror = $this->nativeBlog($app, ['handle' => 'feed', 'mode' => 'mirror', 'source_url' => 'https://example.com/feed.json', 'source_format' => 'jsonfeed']);
        $mirrored = $this->posts($app)->find($this->addPost($app, $mirror, self::postRecord(1)));
        self::assertNotNull($mirrored);
        $controller = $this->controller($app);
        $labels = fn(array $actions): array => array_column($actions, 'label');

        $app->container()->get(Auth::class)->actAs($this->ada);
        self::assertSame(['Edit', 'Delete'], $labels($controller->rowActions($this->blog, $mine, 'published', 1)));
        self::assertSame([], $controller->rowActions($this->blog, $theirs, 'published', 1));
        $delete = $controller->rowActions($this->blog, $mine, 'published', 3)[1];
        self::assertSame(['label' => 'Delete', 'url' => '/admin/blog/post/' . $mine->id . '/delete', 'method' => 'post', 'csrf' => true, 'fields' => ['tab' => 'published', 'page' => '3']], $delete);
        self::assertSame([], $controller->rowActions($mirror, $mirrored, 'published', 1));
        self::assertSame('New post', $controller->headerActions($this->blog)[0]['label']);
        self::assertSame([], $controller->headerActions($mirror));

        $app->container()->get(PostWriter::class)->delete($mine);
        $deleted = $this->posts($app)->find($mine->id);
        self::assertNotNull($deleted);
        self::assertSame(['Undelete'], $labels($controller->rowActions($this->blog, $deleted, 'deleted', 1)));

        $app->container()->get(Auth::class)->actAs($this->user($app, 'ed', ['Editors']));
        self::assertSame(['Edit', 'Delete'], $labels($controller->rowActions($this->blog, $theirs, 'published', 1)));
        self::assertSame([], $controller->rowActions($mirror, $mirrored, 'published', 1));
        self::assertSame([], $controller->headerActions($mirror));

        $app->container()->get(Auth::class)->actAs(null);
        self::assertSame([], $controller->rowActions($this->blog, $theirs, 'published', 1));
        self::assertSame([], $controller->headerActions($this->blog));
    }
}
