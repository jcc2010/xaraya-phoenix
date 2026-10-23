<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

final class PostEditTest extends AdminTestCase
{
    private const CSRF_MESSAGE = 'The form has expired';

    public function testTheFormRoundTripsAndAnUnchangedSaveWritesNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $post = $this->postAs($app, $ada, $blog, [
            'content_html' => "<p>Derived\nfrom here</p>", 'tags' => ['a', 'b'], 'date_published' => '2026-10-01T09:30:15Z',
            '_athenana' => ['kind' => 'note', 'images' => [['url' => 'https://cdn.test/1.png', 'width' => 10, 'height' => 10]]],
        ]);
        $html = $this->both('/admin/blog/post/' . $post->id, 'ada');
        self::assertStringContainsString('id="post-title" name="title" value=""', $html, 'a derived title stays derived');
        self::assertStringContainsString('value="a, b"', $html);
        self::assertStringContainsString('value="2026-10-01T09:30"', $html);
        self::assertStringContainsString('action="/admin/blog/post/' . $post->id . '"', $html);
        self::assertStringContainsString('name="_csrf"', $html);

        $browser = $this->signIn($app, 'ada');
        self::assertPrivateNoStore($browser->get('/admin/blog/post/' . $post->id));
        $fields = ['kind' => 'note', 'title' => '', 'content' => "<p>Derived\r\nfrom here</p>", 'tags' => 'a, b', 'date_published' => '2026-10-01T09:30', 'save' => '1'];
        $response = $browser->post('/admin/blog/post/' . $post->id, $fields);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/post/' . $post->id, $response->getHeaderLine('Location'));
        self::assertPrivateNoStore($response);
        self::assertStringContainsString('No changes.', (string) $browser->get('/admin/blog/post/' . $post->id)->getBody());
        self::assertEquals($post->updatedAt, $this->posts($app)->find($post->id)?->updatedAt);

        $browser->post('/admin/blog/post/' . $post->id, ['title' => 'Now titled', 'tags' => 'b'] + $fields);
        $saved = $this->posts($app)->find($post->id);
        self::assertSame('Now titled', $saved?->title);
        self::assertSame(['b'], array_column($saved->tags, 'name'));
        self::assertSame('2026-10-01T09:30:15+00:00', $saved->datePublished->format(DATE_ATOM), 'seconds survive an unchanged date');
        self::assertSame('https://cdn.test/1.png', $saved->doc['_athenana']['images'][0]['url'] ?? null, 'fields the form does not show survive');
        self::assertSame($ada->id, $saved->ownerId);
        self::assertStringContainsString('Saved “Now titled”.', (string) $browser->get('/admin/blog/post/' . $post->id)->getBody());

        $browser->post('/admin/blog/post/' . $post->id, ['title' => 'Now titled', 'tags' => 'b', 'date_published' => '2026-10-02T08:00'] + $fields);
        self::assertSame('2026-10-02T08:00:00+00:00', $this->posts($app)->find($post->id)?->datePublished->format(DATE_ATOM), 'a changed date is saved');
    }

    public function testPreviewShowsSanitisedHtmlAndSavesNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $post = $this->postAs($app, $ada, $blog, ['title' => 'Before', 'content_html' => '<p>Before</p>']);
        $events = [];
        $app->container()->get(EventDispatcher::class)->listen(ItemEvent::class, function (ItemEvent $e) use (&$events): void {
            $events[] = $e::class;
        });
        $evil = '<p>Shown</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">j</a>';
        $browser = $this->signIn($app, 'ada');
        foreach (['/admin/blog/post/' . $post->id, '/admin/blog/notes/posts/new'] as $path) {
            $browser->get($path);
            $response = $browser->post($path, ['kind' => 'note', 'title' => 'After', 'content' => $evil, 'preview' => '1']);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertPrivateNoStore($response);
            $html = (string) $response->getBody();
            $preview = (string) strstr($html, '<section class="admin-preview"');
            self::assertStringContainsString('<p>Shown</p>', $preview, $path);
            self::assertStringContainsString('<h2 class="post-title">After</h2>', $preview, $path);
            self::assertSame(1, substr_count(self::main($html), '<h1'), "{$path}: the preview's title is not a second <h1>");
            self::assertStringNotContainsString('post-permalink', $preview, "{$path}: the preview links nowhere that would lose the input");
            foreach (['<script', 'onerror', 'javascript:'] as $needle) {
                self::assertStringNotContainsString($needle, $preview, "{$path}: {$needle}");
            }
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the textarea keeps the raw HTML, escaped');
            self::assertStringContainsString('name="_csrf"', $html);
        }
        $after = $this->posts($app)->find($post->id);
        self::assertSame('Before', $after?->title);
        self::assertEquals($post->updatedAt, $after?->updatedAt, 'updated_at is untouched');
        self::assertSame(1, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
        self::assertSame([], $events, 'a preview fires no item event');
        self::assertSame([], glob($this->tmp . '/cache/sanitized/*') ?: [], 'the preview is not cached');
    }

    public function testThePreviewRendersTheSameInBothEngines(): void
    {
        $ada = $this->user($this->site(), 'ada', ['Authors']);
        $post = $this->postAs($this->site(), $ada, $this->nativeBlog($this->site()), ['title' => 'Before', 'content_html' => '<p>Before</p>', 'tags' => ['x']]);
        $html = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'ada');
            $browser->get('/admin/blog/post/' . $post->id);
            $response = $browser->post('/admin/blog/post/' . $post->id, [
                'kind' => 'highlight', 'title' => '"><b>t</b>', 'content' => '<p>Hi</p><script>x</script>', 'quote' => 'Q', 'tags' => 'x, y', 'preview' => '1',
            ]);
            self::assertSame(200, $response->getStatusCode(), $engine);
            // The preview is dated "now", which can tick over a second between the two engines: mask it.
            $html[$engine] = Html::normalize(preg_replace(['/name="_csrf" value="[^"]*"/', '/datetime="[^"]*"/'], ['name="_csrf" value=""', 'datetime=""'], $browser->body()) ?? '');
        }
        self::assertSame($html['php'], $html['twig']);
        $preview = (string) strstr(self::main($html['php']), '<section class="admin-preview"');
        self::assertStringContainsString('<h2 id="preview-heading">Preview</h2>', $preview);
        self::assertStringContainsString('&quot;&gt;&lt;b&gt;t&lt;/b&gt;', $preview, 'the title is escaped');
        self::assertStringNotContainsString('<script', $preview);
        self::assertStringContainsString('<h2 class="post-title">&quot;&gt;&lt;b&gt;t&lt;/b&gt;</h2>', $preview);
        self::assertStringNotContainsString('post-permalink', $preview);
        self::assertStringNotContainsString('href="/s/', $preview);
    }

    public function testAPreviewOfALinkWithoutAUrlLinksNowhere(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $html = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'ada');
            $browser->get('/admin/blog/notes/posts/new');
            $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'link', 'title' => 'Somewhere', 'content' => '<p>c</p>', 'date_published' => '2026-10-01T09:30', 'preview' => '1']);
            self::assertSame(200, $response->getStatusCode(), $engine);
            $html[$engine] = Html::normalize(preg_replace('/name="_csrf" value="[^"]*"/', 'name="_csrf" value=""', $browser->body()) ?? '');
        }
        self::assertSame($html['php'], $html['twig']);
        $preview = (string) strstr(self::main($html['php']), '<section class="admin-preview"');
        self::assertStringContainsString('<h2 class="post-title">Somewhere</h2>', $preview);
        self::assertStringNotContainsString('href="/s/', $preview);
        self::assertStringNotContainsString('post-permalink', $preview);
    }

    public function testALeadingNewlineSurvivesTheTextareaAndAnUnchangedSaveWritesNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $post = $this->postAs($app, $ada, $blog, ['title' => 'Lead', 'content_html' => "\n<p>Body</p>"]);
        self::assertSame("\n<p>Body</p>", $this->posts($app)->find($post->id)?->contentHtml, 'the writer keeps the newline');
        $this->both('/admin/blog/post/' . $post->id, 'ada');
        foreach (['php', 'twig'] as $engine) {
            $raw = (string) $this->signIn($this->site($engine), 'ada')->get('/admin/blog/post/' . $post->id)->getBody();
            self::assertStringContainsString('rows="12">' . "\n\n&lt;p&gt;Body&lt;/p&gt;</textarea>", $raw, $engine);
        }

        $browser = $this->signIn($app, 'ada');
        $page = (string) $browser->get('/admin/blog/post/' . $post->id)->getBody();
        $fields = ['kind' => 'note', 'title' => 'Lead', 'content' => self::textarea($page, 'post-content'), 'quote' => self::textarea($page, 'post-quote'), 'date_published' => $post->datePublished->format('Y-m-d\\TH:i'), 'save' => '1'];
        self::assertSame("\r\n<p>Body</p>", $fields['content'], 'what a browser submits');
        self::assertSame(303, $browser->post('/admin/blog/post/' . $post->id, $fields)->getStatusCode());
        self::assertStringContainsString('No changes.', (string) $browser->get('/admin/blog/post/' . $post->id)->getBody());
        $after = $this->posts($app)->find($post->id);
        self::assertSame("\n<p>Body</p>", $after?->contentHtml);
        self::assertEquals($post->updatedAt, $after?->updatedAt);
    }

    /**
     * The value a browser submits for a textarea: HTML parsing drops one newline straight after the start tag
     * (HTML spec, "in body" insertion mode), and form submission normalises line breaks to CRLF.
     */
    private static function textarea(string $html, string $id): string
    {
        self::assertSame(1, preg_match('~<textarea id="' . preg_quote($id, '~') . '"[^>]*>(.*?)</textarea>~s', $html, $m), $id);
        $value = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_starts_with($value, "\n") ? substr($value, 1) : $value;

        return (string) preg_replace('~\r\n|\r|\n~', "\r\n", $value);
    }

    public function testAnAuthorCannotOpenPreviewOrSaveSomeoneElsesPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $bobs = $this->postAs($app, $this->user($app, 'bob', ['Authors']), $blog, ['title' => 'Bob’s', 'content_html' => '<p>b</p>']);
        $browser = $this->signIn($app, 'ada');
        $refused = $browser->get('/admin/blog/post/' . $bobs->id);
        self::assertSame(403, $refused->getStatusCode());
        self::assertStringNotContainsString('Bob’s', (string) $refused->getBody(), 'the refusal shows nothing of the post');
        foreach (['save', 'preview'] as $button) {
            $token = $this->token($browser);
            $response = $browser->post('/admin/blog/post/' . $bobs->id, ['_csrf' => $token, 'kind' => 'note', 'title' => 'Hijacked', 'content' => '<p>x</p>', $button => '1']);
            self::assertSame(403, $response->getStatusCode(), $button);
            self::assertPrivateNoStore($response);
            $body = (string) $response->getBody();
            self::assertStringNotContainsString(self::CSRF_MESSAGE, $body, 'refused by the ownership check, not the CSRF check');
            self::assertStringNotContainsString('Hijacked', $body);
            self::assertStringNotContainsString('<p>x</p>', $body);
        }
        // The same token is accepted on the user's own post, so the 403s above were not a token problem.
        $own = $this->postAs($app, $ada, $blog, ['title' => 'Ada’s', 'content_html' => '<p>a</p>']);
        $response = $browser->post('/admin/blog/post/' . $own->id, ['_csrf' => $this->token($browser), 'kind' => 'note', 'title' => 'Mine', 'content' => '<p>a</p>', 'save' => '1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('Bob’s', $this->posts($app)->find($bobs->id)?->title);
        $list = (string) $browser->get('/admin/blog/notes/posts')->getBody();
        self::assertStringNotContainsString('/admin/blog/post/' . $bobs->id, $list, 'no Edit action on someone else’s post');
        self::assertStringContainsString('<a href="/admin/blog/post/' . $own->id . '">Edit</a>', $list);
    }

    public function testAnEditorMayEditAnyPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $bobs = $this->postAs($app, $this->user($app, 'bob', ['Authors']), $blog, ['title' => 'Bob’s', 'content_html' => '<p>b</p>']);
        $this->user($app, 'ed', ['Editors']);
        $browser = $this->signIn($app, 'ed');
        $browser->get('/admin/blog/post/' . $bobs->id);
        $browser->post('/admin/blog/post/' . $bobs->id, ['kind' => 'note', 'title' => 'Edited', 'content' => '<p>b</p>', 'save' => '1']);
        $saved = $this->posts($app)->find($bobs->id);
        self::assertSame('Edited', $saved?->title);
        self::assertSame($bobs->ownerId, $saved->ownerId, 'editing keeps the owner');
    }

    public function testADeletedPostCannotBeEditedAndAnUnknownIdIs404(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ed = $this->user($app, 'ed', ['Editors']);
        $post = $app->container()->get(PostWriter::class)->delete($this->postAs($app, $ed, $blog, ['content_html' => '<p>x</p>']));
        $browser = $this->signIn($app, 'ed');
        $response = $browser->get('/admin/blog/post/' . $post->id);
        self::assertSame(409, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        $token = $this->token($browser);
        self::assertSame(409, $browser->post('/admin/blog/post/' . $post->id, ['_csrf' => $token, 'kind' => 'note', 'content' => '<p>y</p>', 'save' => '1'])->getStatusCode());
        self::assertSame('deleted', $this->posts($app)->find($post->id)?->status);
        self::assertSame(404, $browser->get('/admin/blog/post/01m3t19wn8reaww81zg1m47tjq')->getStatusCode());
    }

    public function testAMirrorPostHasNoEditPage(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $mirror = $this->nativeBlog($app, ['handle' => 'feed', 'mode' => 'mirror', 'source_url' => 'https://example.com/feed.json', 'source_format' => 'jsonfeed']);
        $id = $this->addPost($app, $mirror, self::postRecord(1));
        $this->user($app, 'ed', ['Editors']);
        $browser = $this->signIn($app, 'ed');
        $response = $browser->get('/admin/blog/post/' . $id);
        self::assertSame(409, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        $token = $this->token($browser);
        foreach (['save', 'preview'] as $button) {
            $response = $browser->post('/admin/blog/post/' . $id, ['_csrf' => $token, 'kind' => 'note', 'title' => 'Changed', 'content' => '<p>y</p>', $button => '1']);
            self::assertSame(409, $response->getStatusCode(), $button);
        }
        self::assertSame('Post 1', $this->posts($app)->find($id)?->title);
        self::assertStringNotContainsString('/admin/blog/post/', self::main((string) $browser->get('/admin/blog/feed/posts')->getBody()));
    }

    public function testAnEditorPinsAndUnpinsOnlyThisPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ed = $this->user($app, 'ed', ['Editors']);
        $first = $this->postAs($app, $ed, $blog, ['title' => 'First', 'content_html' => '<p>1</p>']);
        $second = $this->postAs($app, $ed, $blog, ['title' => 'Second', 'content_html' => '<p>2</p>']);
        $browser = $this->signIn($app, 'ed');
        $fields = ['kind' => 'note', 'content' => '<p>1</p>', 'title' => 'First', 'save' => '1'];

        self::assertStringNotContainsString('name="pinned" type="checkbox" value="1" checked', (string) $browser->get('/admin/blog/post/' . $first->id)->getBody());
        $browser->post('/admin/blog/post/' . $first->id, $fields + ['pinned' => '1']);
        self::assertSame($first->itemId, $this->blogs($app)->find('notes')?->pinnedItemId);
        self::assertStringContainsString('Saved “First”.', (string) $browser->get('/admin/blog/post/' . $first->id)->getBody(), 'a pin change is a change');
        self::assertStringContainsString('id="post-pinned" name="pinned" type="checkbox" value="1" checked', Html::normalize($browser->body()));

        // Saving another post with the box unticked leaves the blog's pin alone.
        $browser->get('/admin/blog/post/' . $second->id);
        $browser->post('/admin/blog/post/' . $second->id, ['content' => '<p>2</p>', 'title' => 'Second'] + $fields);
        self::assertSame($first->itemId, $this->blogs($app)->find('notes')?->pinnedItemId);

        $browser->get('/admin/blog/post/' . $first->id);
        $browser->post('/admin/blog/post/' . $first->id, $fields);
        self::assertNull($this->blogs($app)->find('notes')?->pinnedItemId);
    }

    public function testAnAuthorNeitherSeesNorChangesThePin(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $mine = $this->postAs($app, $ada, $blog, ['title' => 'Mine', 'content_html' => '<p>m</p>']);
        $this->blogs($app)->update($blog, ['pinned_item_id' => $mine->itemId], $this->now());
        $browser = $this->signIn($app, 'ada');
        self::assertStringNotContainsString('name="pinned"', (string) $browser->get('/admin/blog/post/' . $mine->id)->getBody());
        $browser->post('/admin/blog/post/' . $mine->id, ['kind' => 'note', 'title' => 'Mine', 'content' => '<p>m</p>', 'save' => '1']);
        self::assertSame($mine->itemId, $this->blogs($app)->find('notes')?->pinnedItemId, 'no box sent does not unpin');

        $other = $this->postAs($app, $ada, $blog, ['title' => 'Other', 'content_html' => '<p>o</p>']);
        $browser->get('/admin/blog/post/' . $other->id);
        $browser->post('/admin/blog/post/' . $other->id, ['kind' => 'note', 'title' => 'Other', 'content' => '<p>o</p>', 'pinned' => '1', 'save' => '1']);
        self::assertSame($mine->itemId, $this->blogs($app)->find('notes')?->pinnedItemId, 'an Author’s pinned field is ignored');
    }

    public function testFormAndItemErrorsAreShownTogetherAndNothingIsSaved(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $post = $this->postAs($app, $ada, $blog, ['title' => 'Before', 'content_html' => '<p>Before</p>']);
        $browser = $this->signIn($app, 'ada');
        $fields = [
            'kind' => 'essay', 'title' => 'After', 'content' => '<p>After</p>', 'date_published' => 'tomorrow',
            'source_name' => "Ham\0let", 'source_url' => 'https://example.com/hamlet', 'tags' => "ok, a\0b", 'save' => '1',
        ];
        foreach (['/admin/blog/post/' . $post->id, '/admin/blog/notes/posts/new'] as $path) {
            $browser->get($path);
            $response = $browser->post($path, $fields);
            self::assertSame(422, $response->getStatusCode(), $path);
            self::assertPrivateNoStore($response);
            $html = Html::normalize($browser->body());
            self::assertStringContainsString('id="post-kind-error" role="alert">Choose note, link, photo or highlight', $html, "{$path}: the form's message wins");
            self::assertStringNotContainsString('Must be one of:', $html, $path);
            self::assertStringContainsString('id="post-date-published-error" role="alert">Use a date and time like', $html, $path);
            self::assertStringContainsString('id="post-source-name-error" role="alert">Must not contain U+0000', $html, "{$path}: _source.name maps to source_name");
            self::assertStringContainsString('id="post-tags-error" role="alert">Must not contain U+0000', $html, "{$path}: tags.1 maps to tags");
            self::assertStringNotContainsString('class="form-error"', $html, "{$path}: every error has a field");
            self::assertStringNotContainsString('admin-preview', $html, $path);
        }
        self::assertSame('Before', $this->posts($app)->find($post->id)?->title);
        self::assertSame(1, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);

        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'source_name' => 'S', 'source_url' => "https://exa\0mple.com/", 'save' => '1']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('id="post-source-url-error" role="alert">Must not contain U+0000', Html::normalize($browser->body()), '_source.url maps to source_url');
        self::assertSame(1, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    /** A valid CSRF token for the signed-in session, read from a page with a form. */
    private function token(Browser $browser): string
    {
        $browser->get('/admin/blog/notes/posts/new');
        $token = $browser->csrfField();
        self::assertNotNull($token);

        return $token;
    }
}
