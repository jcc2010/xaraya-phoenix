<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Write\PostWriter;

final class PostActionsTest extends AdminTestCase
{
    public function testTheListShowsEditDeleteUndeleteAndNewPostControls(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ed = $this->user($app, 'ed', ['Editors']);
        $live = $this->postAs($app, $ed, $blog, ['title' => 'Live', 'content_html' => '<p>x</p>']);
        $dead = $app->container()->get(PostWriter::class)->delete($this->postAs($app, $ed, $blog, ['title' => 'Dead', 'content_html' => '<p>x</p>']));

        $published = self::main($this->both('/admin/blog/notes/posts', 'ed'));
        self::assertStringContainsString('href="/admin/blog/post/' . $live->id . '"', $published);
        self::assertStringContainsString('>Edit</a>', $published);
        self::assertMatchesRegularExpression('~<form[^>]*method="post"[^>]*action="/admin/blog/post/' . $live->id . '/delete"[^>]*>.*?name="_csrf".*?</form>~s', $published);
        self::assertStringContainsString('New post', $published);

        $deleted = self::main($this->both('/admin/blog/notes/posts?tab=deleted', 'ed'));
        self::assertMatchesRegularExpression('~<form[^>]*method="post"[^>]*action="/admin/blog/post/' . $dead->id . '/undelete"[^>]*>.*?name="_csrf".*?</form>~s', $deleted);
        self::assertStringNotContainsString('/delete"', $deleted);
    }

    public function testAnAuthorDeletesAndUndeletesTheirOwnPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->postAs($app, $this->user($app, 'ada', ['Authors']), $blog, ['title' => 'Mine', 'content_html' => '<p>x</p>']);
        $browser = $this->signIn($app, 'ada');
        self::assertStringContainsString('action="/admin/blog/post/' . $post->id . '/delete"', $this->both('/admin/blog/notes/posts', 'ada'));
        $browser->get('/admin/blog/notes/posts');
        $response = $browser->post('/admin/blog/post/' . $post->id . '/delete', ['tab' => 'published', 'page' => '1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/notes/posts', $response->getHeaderLine('Location'));
        self::assertPrivateNoStore($response);
        self::assertSame('deleted', $this->posts($app)->find($post->id)?->status);
        self::assertStringContainsString('Deleted “Mine”.', (string) $browser->get('/admin/blog/notes/posts?tab=deleted')->getBody());

        $response = $browser->post('/admin/blog/post/' . $post->id . '/undelete', ['tab' => 'deleted', 'page' => '2']);
        self::assertSame('/admin/blog/notes/posts?tab=deleted&page=2', $response->getHeaderLine('Location'));
        self::assertSame('published', $this->posts($app)->find($post->id)?->status);
        self::assertStringContainsString('Restored “Mine”.', (string) $browser->get('/admin/blog/notes/posts')->getBody());
    }

    public function testTheRedirectIgnoresAnythingButAKnownTabAndAPageNumber(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->postAs($app, $this->user($app, 'ed', ['Editors']), $blog, ['content_html' => '<p>x</p>']);
        $browser = $this->signIn($app, 'ed');
        foreach ([
            ['tab' => 'https://evil.test/', 'page' => '//evil.test', 'next' => 'https://evil.test/'],
            ['tab' => ['x'], 'page' => ['1']],
            ['tab' => 'published', 'page' => '0'],
            ['tab' => 'scheduled', 'page' => '0002'],
            ['tab' => 'scheduled', 'page' => '1234567'],
        ] as $fields) {
            $browser->get('/admin/blog/notes/posts');
            $response = $browser->post('/admin/blog/post/' . $post->id . '/undelete', $fields); // @phpstan-ignore argument.type
            self::assertSame(303, $response->getStatusCode());
            self::assertSame(($fields['tab'] ?? '') === 'scheduled' ? '/admin/blog/notes/posts?tab=scheduled' : '/admin/blog/notes/posts', $response->getHeaderLine('Location'));
        }
    }

    public function testAnAuthorCannotDeleteOrUndeleteSomeoneElsesPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $bob = $this->user($app, 'bob', ['Authors']);
        $live = $this->postAs($app, $bob, $blog, ['content_html' => '<p>live</p>']);
        $dead = $app->container()->get(PostWriter::class)->delete($this->postAs($app, $bob, $blog, ['content_html' => '<p>dead</p>']));
        $browser = $this->signIn($app, 'ada');
        foreach ([[$live, 'delete', 'published'], [$dead, 'undelete', 'deleted']] as [$post, $action, $status]) {
            $browser->get('/admin/blog/notes/posts/new'); // a page with a form: the list has none for posts she may not delete
            self::assertNotNull($token = $browser->csrfField());
            $response = $browser->post("/admin/blog/post/{$post->id}/{$action}", ['_csrf' => $token]);
            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertStringContainsString('Forbidden', (string) $response->getBody(), $action);
            self::assertSame($status, $this->posts($app)->find($post->id)?->status, $action);
        }
        self::assertStringNotContainsString('/delete"', (string) $browser->get('/admin/blog/notes/posts')->getBody());
    }

    public function testGetIsNotAllowedAndAnUnknownPostIsNotFound(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->postAs($app, $this->user($app, 'ed', ['Editors']), $blog, ['content_html' => '<p>x</p>']);
        $browser = $this->signIn($app, 'ed');
        foreach (['delete', 'undelete'] as $action) {
            self::assertSame(405, $browser->get("/admin/blog/post/{$post->id}/{$action}")->getStatusCode(), $action);
        }
        self::assertSame('published', $this->posts($app)->find($post->id)?->status);
        $browser->get('/admin/blog/notes/posts');
        self::assertSame(404, $browser->post('/admin/blog/post/01m3t19wn8reaww81zg1m47tjq/delete')->getStatusCode());
    }

    public function testAMirrorPostCannotBeDeleted(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $mirror = $this->nativeBlog($app, ['handle' => 'feed', 'mode' => 'mirror', 'source_url' => 'https://example.com/feed.json', 'source_format' => 'jsonfeed']);
        $id = $this->addPost($app, $mirror, self::postRecord(1));
        $this->user($app, 'ed', ['Editors']);
        $browser = $this->signIn($app, 'ed');
        $browser->get('/admin/blog/notes/posts/new');
        self::assertSame(409, $browser->post('/admin/blog/post/' . $id . '/delete')->getStatusCode());
        self::assertSame('published', $this->posts($app)->find($id)?->status);
    }

    public function testDeletingThePinnedPostClearsOnlyItsOwnPin(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ed = $this->user($app, 'ed', ['Editors']);
        $pinned = $this->postAs($app, $ed, $blog, ['title' => 'Pinned', 'content_html' => '<p>x</p>']);
        $other = $this->postAs($app, $ed, $blog, ['title' => 'Other', 'content_html' => '<p>x</p>']);
        $blogs = $app->container()->get(BlogRepository::class);
        $blogs->update($blog, ['pinned_item_id' => $pinned->itemId], new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $browser = $this->signIn($app, 'ed');
        $browser->get('/admin/blog/notes/posts');
        self::assertSame(303, $browser->post('/admin/blog/post/' . $other->id . '/delete')->getStatusCode());
        $browser->get('/admin/blog/notes/posts?tab=deleted');
        self::assertSame($pinned->itemId, $blogs->find('notes')?->pinnedItemId, 'another post leaves the pin alone');
        $browser->post('/admin/blog/post/' . $pinned->id . '/delete');
        self::assertNull($blogs->find('notes')?->pinnedItemId);
    }
}
