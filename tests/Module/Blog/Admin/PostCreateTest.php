<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Module\Blog\Admin\PostForm;
use Xaraya\Tests\Support\Html;

final class PostCreateTest extends AdminTestCase
{
    public function testAnAuthorCreatesAHighlightThatTheyOwn(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $html = $this->both('/admin/blog/notes/posts/new', 'ada');
        self::assertStringContainsString('<label for="post-title">', $html);
        self::assertStringNotContainsString('name="pinned"', $html, 'Authors cannot pin');

        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', [
            'kind' => 'highlight', 'title' => '', 'content' => "<p>Worth\r\nreading</p>", 'quote' => 'To be or not',
            'source_name' => 'Hamlet', 'source_url' => 'https://example.com/hamlet', 'tags' => ' plays, , Plays ,drama',
            'date_published' => '', 'save' => '1',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/notes/posts', $response->getHeaderLine('Location'));
        self::assertPrivateNoStore($response);
        $post = $this->posts($app)->page($blog->id, $this->now(), null, 1)[0];
        self::assertSame($ada->id, $post->ownerId);
        self::assertSame('highlight', $post->kind);
        self::assertSame("<p>Worth\nreading</p>", $post->contentHtml);
        self::assertSame(['plays', 'drama'], array_column($post->tags, 'name'), 'empties dropped; one tag per slug, the first wins');
        self::assertSame('To be or not', $post->doc['_athenana']['quote'] ?? null);
        // assertEquals: MySQL's JSON type stores object keys in its own order.
        self::assertEquals(['name' => 'Hamlet', 'url' => 'https://example.com/hamlet'], $post->doc['_source'] ?? null);
        self::assertStringContainsString('<p class="admin-flash admin-flash-status" role="status">Created “', (string) $browser->get('/admin/blog/notes/posts')->getBody());
    }

    public function testAFutureLocalTimeSchedulesThePostInTheSiteTimezone(): void
    {
        $app = $this->app(['app.timezone' => 'Europe/Paris']);
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>Later</p>', 'date_published' => '2999-07-01T09:30', 'save' => '1']);
        self::assertSame('/admin/blog/notes/posts?tab=scheduled', $response->getHeaderLine('Location'));
        $post = $this->posts($app)->adminPage($blog->id, 'scheduled', '', $this->now(), 20, 0)['posts'][0];
        self::assertSame('2999-07-01T07:30:00+00:00', $post->datePublished->format(DATE_ATOM));
        self::assertStringContainsString('<span class="form-hint" id="post-date-published-hint">Time zone: Europe/Paris. A future time schedules the post.</span>', Html::normalize((string) $browser->get('/admin/blog/notes/posts/new')->getBody()));
    }

    public function testErrorsAreShownInlineAndNothingIsSaved(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', [
            'kind' => 'link', 'content' => '', 'external_url' => 'ftp://nope', 'date_published' => 'tomorrow', 'save' => '1',
        ]);
        self::assertSame(422, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<span class="field-error" id="post-date-published-error" role="alert">Use a date and time like 2026-10-22T09:30</span>', $html);
        self::assertStringContainsString('id="post-date-published" name="date_published" type="datetime-local" value="tomorrow" aria-invalid="true" aria-describedby="post-date-published-hint post-date-published-error"', $html);
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);

        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'link', 'content' => '', 'external_url' => 'ftp://nope', 'save' => '1']);
        self::assertStringContainsString('id="post-external-url-error" role="alert">Must be an absolute http(s) URL', Html::normalize($browser->body()));
        self::assertStringContainsString('id="post-external-url" name="external_url" type="url" value="ftp://nope" aria-invalid="true" aria-describedby="post-external-url-error"', Html::normalize($browser->body()));
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testMalformedDatesAndKindsAreInlineErrorsNeverA500(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        foreach (['2026-02-30T10:00', '2026-13-01T00:00', '2026-10-22 09:30', '0000-01-01T00:00', '99999-01-01T00:00', '2026-10-22T09:30+02:00'] as $date) {
            $browser->get('/admin/blog/notes/posts/new');
            $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'date_published' => $date, 'save' => '1']);
            self::assertSame(422, $response->getStatusCode(), $date);
            self::assertStringContainsString('id="post-date-published-error" role="alert">', $browser->body(), $date);
        }
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'essay', 'content' => '<p>x</p>', 'source_url' => 'https://example.com', 'save' => '1']);
        self::assertSame(422, $response->getStatusCode());
        $html = Html::normalize($browser->body());
        self::assertStringContainsString('id="post-kind-error" role="alert">Choose note, link, photo or highlight', $html);
        self::assertStringContainsString('id="post-source-name-error" role="alert">Give the source a name', $html);
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testTheFormAndIts422StateRenderTheSameInBothEngines(): void
    {
        $this->nativeBlog($this->site());
        $this->user($this->site(), 'ed', ['Editors']);
        $html = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'ed');
            $browser->get('/admin/blog/notes/posts/new');
            $response = $browser->post('/admin/blog/notes/posts/new', [
                'kind' => 'highlight', 'title' => '"><script>alert(1)</script>', 'content' => '</textarea><script>x</script>',
                'quote' => 'q', 'source_url' => 'nope', 'tags' => str_repeat('t', 129) . ', ok', 'date_published' => 'soon', 'pinned' => '1', 'save' => '1',
            ]);
            self::assertSame(422, $response->getStatusCode(), $engine);
            $html[$engine] = Html::normalize(preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value=""', $browser->body()) ?? '');
        }
        self::assertSame($html['php'], $html['twig']);
        $main = self::main($html['php']);
        self::assertStringNotContainsString('<script>', $main);
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $main);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;x&lt;/script&gt;</textarea>', $main);
        self::assertStringContainsString('id="post-pinned" name="pinned" type="checkbox" value="1" checked', $main);
        self::assertStringContainsString('<option value="highlight" selected>Highlight</option>', $main);

        // A failure PostWriter reports (not the form's own checks) is mapped back to its field too.
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'tags' => str_repeat('t', 129), 'save' => '1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('id="post-tags-error" role="alert">Must be a list of tag names', Html::normalize($browser->body()));
        self::assertStringContainsString('aria-invalid="true" aria-describedby="post-tags-error"', Html::normalize($browser->body()));
    }

    public function testTheFlashMessageEscapesTheTitle(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'title' => '<img src=x onerror=alert(1)>', 'content' => '<p>x</p>', 'save' => '1']);
        $html = (string) $browser->get('/admin/blog/notes/posts')->getBody();
        self::assertStringContainsString('Created “&lt;img src=x onerror=alert(1)&gt;”.', $html);
        self::assertStringNotContainsString('<img src=x', $html);

        $browser->get('/admin/blog/notes/posts/new');
        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'title' => '"><script>alert(1)</script>', 'content' => '<p>x</p>', 'save' => '1']);
        $html = (string) $browser->get('/admin/blog/notes/posts')->getBody();
        self::assertStringContainsString('Created “&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;”.', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function testAnEditorMayPinTheNewPost(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'ed', ['Editors']);
        self::assertStringContainsString('name="pinned"', $this->both('/admin/blog/notes/posts/new', 'ed'));
        $browser = $this->signIn($app, 'ed');
        $browser->get('/admin/blog/notes/posts/new');
        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>Top</p>', 'pinned' => '1', 'save' => '1']);
        $blog = $this->blogs($app)->find('notes');
        $post = $this->posts($app)->page((int) $blog?->id, $this->now(), null, 1)[0];
        self::assertSame($post->itemId, $blog?->pinnedItemId);
    }

    public function testAnAuthorsPinnedFieldIsIgnored(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>Top</p>', 'pinned' => '1', 'save' => '1']);
        self::assertSame(303, $response->getStatusCode());
        $blog = $this->blogs($app)->find('notes');
        self::assertCount(1, $this->posts($app)->page((int) $blog?->id, $this->now(), null, 1));
        self::assertNull($blog?->pinnedItemId);
    }

    public function testAMemberCannotOpenTheForm(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'mia');
        self::assertSame(403, $this->signIn($app, 'mia')->get('/admin/blog/notes/posts/new')->getStatusCode());
    }

    public function testAPostWithoutAValidTokenIsRefusedAndSavesNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        self::assertStringContainsString('name="_csrf"', $browser->body());
        self::assertSame(403, $browser->post('/admin/blog/notes/posts/new', ['_csrf' => 'forged', 'kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'])->getStatusCode());
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
        self::assertSame(404, $browser->get('/admin/blog/nope/posts/new')->getStatusCode());
    }

    public function testAMirrorBlogHasNoNewPostFormOrLink(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $mirror = $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena'], $this->now());
        $this->user($app, 'ed', ['Editors']);
        $browser = $this->signIn($app, 'ed');

        $dashboard = self::main((string) $browser->get('/admin/blog')->getBody());
        self::assertStringContainsString('<a href="/admin/blog/notes/posts/new">New post</a>', $dashboard);
        self::assertStringNotContainsString('/admin/blog/mirror/posts/new', $dashboard);
        self::assertStringContainsString('<a href="/admin/blog/notes/posts/new">New post</a>', self::main((string) $browser->get('/admin/blog/notes/posts')->getBody()));
        self::assertStringNotContainsString('/posts/new', self::main((string) $browser->get('/admin/blog/mirror/posts')->getBody()));

        $response = $browser->get('/admin/blog/mirror/posts/new');
        self::assertSame(409, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/mirror/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'save' => '1']);
        self::assertSame(409, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        self::assertSame(0, $this->posts($app)->adminCounts($mirror->id, $this->now())['published']);
    }

    public function testTheFormMapsInputToAJsonFeedItem(): void
    {
        $form = PostForm::fromInput([
            'kind' => ' link ', 'title' => ['x'], 'content' => "a\r\nb\rc", 'external_url' => ' https://example.com/a ',
            'tags' => 'b, a,,b , c', 'date_published' => '2026-10-22T09:30:15', 'pinned' => '1',
        ]);
        self::assertSame('', $form->title);
        self::assertSame("a\nb\nc", $form->content);
        self::assertSame([], $form->check('UTC'));
        self::assertSame([
            '_athenana' => ['kind' => 'link'],
            'content_html' => "a\nb\nc",
            'external_url' => 'https://example.com/a',
            'tags' => ['b', 'a', 'c'],
            'date_published' => '2026-10-22T07:30:15+00:00',
        ], $form->toItem('Europe/Paris'));
        self::assertTrue($form->values()['pinned']);
        self::assertSame(['_athenana' => ['kind' => 'note']], PostForm::blank()->toItem('UTC'));
        self::assertSame('tags', PostForm::field('_athenana.tags.3.name'));
        self::assertSame('source_name', PostForm::field('_source'));
        self::assertNull(PostForm::field('item'));
    }
}
