<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\SyncLock;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

final class MirrorTest extends AdminTestCase
{
    private const SOURCE = 'https://src.test/blog/src/feed.json';

    private function mirror(App $app, string $source = self::SOURCE): Blog
    {
        return $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => $source, 'source_format' => 'athena'], $this->now());
    }

    /** The session's CSRF token, from a page every signed-in user can open. */
    private function token(Browser $browser): string
    {
        $browser->get('/login');
        self::assertNotNull($token = $browser->csrfField());

        return $token;
    }

    public function testMirrorPostsCannotBeWrittenFromTheAdmin(): void
    {
        $app = $this->site();
        $blog = $this->mirror($app);
        $id = $this->addPost($app, $blog, self::postRecord(1, ['title' => 'Synced']));
        $this->user($app, 'root', ['Administrators']);
        $list = self::main($this->both('/admin/blog/mirror/posts', 'root'));
        self::assertStringContainsString('<p class="admin-mirror-note">Mirror of https://src.test/blog/src/feed.json; edits come from the source.</p>', $list);
        foreach (['/admin/blog/post/', '/posts/new', '/delete"'] as $needle) {
            self::assertStringNotContainsString($needle, $list, $needle);
        }
        $browser = $this->signIn($app, 'root');
        self::assertSame(409, $browser->get('/admin/blog/mirror/posts/new')->getStatusCode());
        self::assertSame(409, $browser->get('/admin/blog/post/' . $id)->getStatusCode());
        foreach (['/admin/blog/post/' . $id, '/admin/blog/post/' . $id . '/delete', '/admin/blog/post/' . $id . '/undelete', '/admin/blog/mirror/posts/new'] as $path) {
            $browser->get('/admin/blog/mirror/posts');
            self::assertSame(409, $browser->post($path, ['kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'])->getStatusCode(), $path);
        }
        self::assertSame('Synced', $this->posts($app)->find($id)?->title);
        self::assertSame('published', $this->posts($app)->find($id)?->status);
    }

    public function testTheSourceUrlIsEscapedInBothEngines(): void
    {
        $app = $this->site();
        $this->mirror($app, 'https://src.test/feed.json?a=1&b=<script>alert(1)</script>');
        $this->user($app, 'root', ['Administrators']);
        $list = self::main($this->both('/admin/blog/mirror/posts', 'root'));
        self::assertStringNotContainsString('<script>', $list);
        self::assertStringContainsString('&lt;script&gt;', $list);
    }

    public function testSyncNowReportsItsCountsAndOnlyAdministratorsMayRunIt(): void
    {
        $app = $this->site();
        $blog = $this->mirror($app);
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->user($app, 'ed', ['Editors']);
        $editor = $this->signIn($app, 'ed');
        self::assertStringNotContainsString('Sync now', (string) $editor->get('/admin/blog/mirror/posts')->getBody());
        self::assertSame(403, $editor->post('/admin/blog/mirror/sync', ['_csrf' => $this->token($editor)])->getStatusCode());
        self::assertSame(0, $this->posts($app)->countLive($blog->id));

        $this->user($app, 'root', ['Administrators']);
        $root = $this->signIn($app, 'root');
        $list = (string) $root->get('/admin/blog/mirror/posts')->getBody();
        self::assertMatchesRegularExpression('~<form[^>]*method="post"[^>]*action="/admin/blog/mirror/sync"[^>]*>.*?name="_csrf".*?Sync now.*?</form>~s', $list);
        $response = $root->post('/admin/blog/mirror/sync');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/mirror/posts', $response->getHeaderLine('Location'));
        self::assertStringContainsString('mirror: 1 page(s), 3 created, 0 updated', (string) $root->get('/admin/blog/mirror/posts')->getBody());
        self::assertSame(3, $this->posts($app)->countLive($blog->id));
    }

    public function testAuthorsAndMembersAreForbiddenToSyncWithAValidToken(): void
    {
        $app = $this->site();
        $blog = $this->mirror($app);
        $this->nativeBlog($app);
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        foreach (['ada' => 'Authors', 'mia' => 'Members'] as $handle => $role) {
            $this->user($app, $handle, [$role]);
            $browser = $this->signIn($app, $handle);
            self::assertSame(403, $browser->post('/admin/blog/mirror/sync', ['_csrf' => $this->token($browser)])->getStatusCode(), $handle);
        }
        self::assertSame(0, $this->posts($app)->countLive($blog->id));
    }

    public function testAFailedFetchIsAnErrorFlash(): void
    {
        $app = $this->site();
        $this->mirror($app);
        $app->container()->instance(HttpFetcher::class, new FixtureFetcher());
        $this->user($app, 'root', ['Administrators']);
        $root = $this->signIn($app, 'root');
        $root->get('/admin/blog/mirror/posts');
        $response = $root->post('/admin/blog/mirror/sync');
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('<p class="admin-flash admin-flash-error" role="alert">Sync failed:', Html::normalize((string) $root->get('/admin/blog/mirror/posts')->getBody()));
    }

    public function testANativeBlogHasNoSyncButtonAndAnswers409(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'root', ['Administrators']);
        $root = $this->signIn($app, 'root');
        self::assertStringNotContainsString('Sync now', (string) $root->get('/admin/blog/notes/posts')->getBody());
        $token = $this->token($root);
        self::assertSame(409, $root->post('/admin/blog/notes/sync', ['_csrf' => $token])->getStatusCode());
        self::assertSame(404, $root->post('/admin/blog/nope/sync', ['_csrf' => $token])->getStatusCode());
    }

    public function testAHostileNextUrlIsAnErrorFlashNotA500(): void
    {
        $app = $this->site();
        $this->mirror($app);
        $page = FeedFactory::pages(FeedFactory::items(2))[self::SOURCE];
        $page['next_url'] = 'ftp://x/feed';
        $app->container()->instance(HttpFetcher::class, (new FixtureFetcher())->on(self::SOURCE, FixtureFetcher::json($page)));
        $this->user($app, 'root', ['Administrators']);
        $root = $this->signIn($app, 'root');
        $root->get('/admin/blog/mirror/posts');
        self::assertSame(303, $root->post('/admin/blog/mirror/sync')->getStatusCode());
        self::assertStringContainsString('admin-flash-error" role="alert">Sync failed:', Html::normalize((string) $root->get('/admin/blog/mirror/posts')->getBody()));
    }

    public function testAHeldLockIsAnAlreadyRunningFlash(): void
    {
        $app = $this->site();
        $this->mirror($app);
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->user($app, 'root', ['Administrators']);
        $root = $this->signIn($app, 'root');
        $root->get('/admin/blog/mirror/posts');
        $lock = new SyncLock($this->tmp . '/locks');
        self::assertTrue($lock->acquire('mirror'));
        try {
            $response = $root->post('/admin/blog/mirror/sync');
        } finally {
            $lock->release('mirror');
        }
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('mirror: already running, skipped', (string) $root->get('/admin/blog/mirror/posts')->getBody());
    }
}
