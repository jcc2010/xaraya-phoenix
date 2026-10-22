<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\Router;

final class BlogSettingsTest extends AdminTestCase
{
    public function testAnAdministratorEditsSettingsWithValidation(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'root', ['Administrators']);
        $html = $this->both('/admin/blog/notes/settings', 'root');
        self::assertStringContainsString('<code>bin/xar blog:mode notes mirror|native</code>', $html);
        $browser = $this->signIn($app, 'root');
        $fields = ['title' => 'Field notes', 'description_html' => '', 'author_name' => 'Ada', 'author_url' => 'javascript:alert(1)',
            'language' => 'en-GB', 'post_url_pattern' => '', 'media' => 'local'];
        $browser->get('/admin/blog/notes/settings');
        self::assertSame(422, $browser->post('/admin/blog/notes/settings', $fields)->getStatusCode());
        self::assertStringContainsString('Author URL must be an http(s) URL', $browser->body());
        $browser->post('/admin/blog/notes/settings', ['post_url_pattern' => 'https://x.test/p'] + ['author_url' => ''] + $fields);
        self::assertSame(422, $browser->last?->getStatusCode());
        self::assertStringContainsString('Post URL pattern must be an http(s) URL containing {id} exactly once', $browser->body());
        $response = $browser->post('/admin/blog/notes/settings', ['author_url' => 'https://ada.test'] + $fields);
        self::assertSame(303, $response->getStatusCode());
        $blog = $this->blogs($app)->find('notes');
        self::assertSame(['Field notes', null, 'https://ada.test', 'en-GB', 'local', 'native'], [$blog?->title, $blog?->descriptionHtml, $blog?->authorUrl, $blog?->language, $blog?->media, $blog?->mode]);
    }

    public function testOverlongOrMalformedSettingsAreInline422sNever500s(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'root', ['Administrators']);
        $browser = $this->signIn($app, 'root');
        $ok = ['title' => 'Notes', 'description_html' => '', 'author_name' => 'Ada', 'author_url' => '', 'language' => 'en', 'post_url_pattern' => '', 'media' => 'remote'];
        $cases = [
            'title' => [['title' => str_repeat('t', 256)], 'Title must be at most 255 characters'],
            'author_name' => [['author_name' => str_repeat('a', 256)], 'Author name must be at most 255 characters'],
            'author_url' => [['author_url' => 'https://a.test/' . str_repeat('a', 1024)], 'Author URL must be at most 1024 characters'],
            'pattern' => [['post_url_pattern' => 'https://a.test/' . str_repeat('a', 1024) . '/{id}'], 'Post URL pattern must be at most 1024 characters'],
            'language' => [['language' => str_repeat('a', 17)], 'Language must be at most 16 characters'],
            'tag' => [['language' => 'not a tag'], 'Language must be a tag like en or en-GB'],
            'empty title' => [['title' => ' '], 'Title is required'],
            'media' => [['media' => 'bogus'], 'Media must be remote or local'],
        ];
        foreach ($cases as $label => [$change, $message]) {
            $browser->get('/admin/blog/notes/settings');
            self::assertSame(422, $browser->post('/admin/blog/notes/settings', $change + $ok)->getStatusCode(), $label);
            self::assertStringContainsString($message, $browser->body(), $label);
            self::assertStringContainsString('aria-invalid="true"', $browser->body(), $label);
        }
        self::assertSame('Notes', $this->blogs($app)->find('notes')?->title);
    }

    public function testSettingsEscapeEveryValueAndRenderTheSameInBothEnginesWhenRefused(): void
    {
        $app = $this->site();
        $this->nativeBlog($app, ['title' => 'A "quoted" <b>title</b>', 'description_html' => '</textarea><script>alert(1)</script>']);
        $this->user($app, 'root', ['Administrators']);
        $html = $this->both('/admin/blog/notes/settings', 'root');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;', $html);
        self::assertStringContainsString('<textarea id="blog-description-html"', $html);
        $bodies = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'root');
            $browser->get('/admin/blog/notes/settings');
            self::assertSame(422, $browser->post('/admin/blog/notes/settings', ['title' => '"><script>x</script>', 'description_html' => '', 'author_name' => 'A', 'author_url' => 'nope', 'language' => 'en', 'post_url_pattern' => '', 'media' => 'remote'])->getStatusCode());
            $bodies[$engine] = \Xaraya\Tests\Support\Html::normalize(preg_replace('/name="_csrf" value="[^"]*"/', '', $browser->body()) ?? '');
        }
        self::assertSame($bodies['php'], $bodies['twig']);
        self::assertStringNotContainsString('<script>x', $bodies['php']);
        self::assertStringContainsString('&lt;script&gt;x', $bodies['php']);

        $new = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'root');
            $browser->get('/admin/blog/new');
            self::assertSame(422, $browser->post('/admin/blog/new', ['handle' => '"><i>', 'title' => '<i>x</i>'])->getStatusCode());
            $new[$engine] = \Xaraya\Tests\Support\Html::normalize(preg_replace('/name="_csrf" value="[^"]*"/', '', $browser->body()) ?? '');
        }
        self::assertSame($new['php'], $new['twig']);
        self::assertStringNotContainsString('<i>x', $new['php']);
    }

    public function testAMirrorOnlyTakesThePostUrlPatternAndMedia(): void
    {
        $app = $this->site();
        $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'title' => 'Source title', 'author_name' => 'Src', 'language' => 'fr',
            'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena'], $this->now());
        $this->user($app, 'root', ['Administrators']);
        $html = $this->both('/admin/blog/mirror/settings', 'root');
        self::assertStringContainsString('The source supplies the title, description, author and language', $html);
        foreach (['blog-title', 'blog-description-html', 'blog-author-name', 'blog-author-url', 'blog-language'] as $id) {
            self::assertMatchesRegularExpression('~<(input|textarea)[^>]*id="' . $id . '"[^>]* disabled~', $html, $id);
        }
        self::assertDoesNotMatchRegularExpression('~id="blog-post-url-pattern"[^>]* disabled~', $html);
        $browser = $this->signIn($app, 'root');
        $browser->get('/admin/blog/mirror/settings');
        $response = $browser->post('/admin/blog/mirror/settings', ['title' => 'Hijacked', 'description_html' => '<p>x</p>', 'author_name' => 'Evil',
            'author_url' => 'javascript:x', 'language' => 'zz zz', 'post_url_pattern' => 'https://x.test/p/{id}', 'media' => 'local']);
        self::assertSame(303, $response->getStatusCode(), 'synced fields are ignored, even invalid ones');
        $blog = $this->blogs($app)->find('mirror');
        self::assertSame(
            ['Source title', null, 'Src', null, 'fr', 'https://x.test/p/{id}', 'local', 'mirror'],
            [$blog?->title, $blog?->descriptionHtml, $blog?->authorName, $blog?->authorUrl, $blog?->language, $blog?->postUrlPattern, $blog?->media, $blog?->mode],
        );
        $browser->get('/admin/blog/mirror/settings');
        self::assertSame(422, $browser->post('/admin/blog/mirror/settings', ['post_url_pattern' => 'https://x.test/none', 'media' => 'local'])->getStatusCode());
    }

    public function testAnAdministratorCreatesANativeBlog(): void
    {
        $app = $this->site();
        $this->user($app, 'root', ['Administrators']);
        self::assertStringContainsString('<label for="blog-handle">', $this->both('/admin/blog/new', 'root'));
        $browser = $this->signIn($app, 'root');
        $browser->get('/admin/blog/new');
        self::assertSame(422, $browser->post('/admin/blog/new', ['handle' => 'No Caps', 'title' => 'x'])->getStatusCode());
        $response = $browser->post('/admin/blog/new', ['handle' => 'diary', 'title' => 'Diary', 'mode' => 'mirror']);
        self::assertSame('/admin/blog/diary/posts', $response->getHeaderLine('Location'));
        $blog = $this->blogs($app)->find('diary');
        self::assertSame(['native', 'Root'], [$blog?->mode, $blog?->authorName], 'the mode is always native; the author is the user');
        $browser->get('/admin/blog/new');
        self::assertSame(422, $browser->post('/admin/blog/new', ['handle' => 'diary', 'title' => 'Again'])->getStatusCode());
        self::assertStringContainsString("Blog &#039;diary&#039; already exists", $browser->body());
        self::assertSame('Diary', $this->blogs($app)->find('diary')?->title);
    }

    public function testEditorsAndAuthorsCannotManageBlogs(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        foreach (['ed' => 'Editors', 'ada' => 'Authors'] as $handle => $role) {
            $this->user($app, $handle, [$role]);
            $browser = $this->signIn($app, $handle);
            self::assertSame(403, $browser->get('/admin/blog/notes/settings')->getStatusCode(), $handle);
            self::assertSame(403, $browser->get('/admin/blog/new')->getStatusCode(), $handle);
            self::assertStringNotContainsString('/settings"', (string) $browser->get('/admin/blog')->getBody(), $handle);
            $token = (string) $browser->csrfField();
            $fields = ['_csrf' => $token, 'title' => 'Hijacked', 'handle' => 'evil', 'description_html' => '', 'author_name' => 'x', 'author_url' => '', 'language' => 'en', 'post_url_pattern' => '', 'media' => 'remote'];
            self::assertSame(403, $browser->post('/admin/blog/notes/settings', $fields)->getStatusCode(), $handle);
            self::assertSame(403, $browser->post('/admin/blog/new', $fields)->getStatusCode(), $handle);
        }
        self::assertNull($this->blogs($app)->find('evil'));
        self::assertSame('Notes', $this->blogs($app)->find('notes')?->title);
    }

    public function testTheScreensAreNeverCached(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'root', ['Administrators']);
        $browser = $this->signIn($app, 'root');
        foreach (['/admin/blog/notes/settings', '/admin/blog/new'] as $path) {
            self::assertPrivateNoStore($browser->get($path));
            self::assertStringContainsString('name="_csrf"', $browser->body());
        }
        self::assertPrivateNoStore($browser->post('/admin/blog/new', ['handle' => 'X', 'title' => 'x']));
    }

    public function testEveryAdminPostRefusesAMissingOrForeignToken(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena'], $this->now());
        $root = $this->user($app, 'root', ['Administrators']);
        $post = $this->postAs($app, $root, $blog, ['title' => 'Kept', 'content_html' => '<p>x</p>']);
        $paths = [
            '/admin/blog/new', '/admin/blog/notes/posts/new', '/admin/blog/post/' . $post->id,
            '/admin/blog/post/' . $post->id . '/delete', '/admin/blog/post/' . $post->id . '/undelete',
            '/admin/blog/mirror/sync', '/admin/blog/notes/settings',
        ];
        $posts = array_filter(
            $app->container()->get(Router::class)->routes(),
            static fn(Route $r): bool => str_starts_with((string) $r->name, 'admin.blog') && in_array('POST', $r->methods, true),
        );
        self::assertCount(count($paths), $posts, 'every admin POST route is listed here');

        $other = $this->signIn($app, 'root');
        $other->get('/admin/blog/new');
        $foreign = (string) $other->csrfField();
        $browser = $this->signIn($app, 'root');
        $fields = ['handle' => 'evil', 'title' => 'Hijacked', 'kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'];
        foreach ($paths as $path) {
            foreach (['' => 'missing', $foreign => 'foreign'] as $token => $label) {
                self::assertSame(403, $browser->post($path, ['_csrf' => (string) $token] + $fields)->getStatusCode(), "{$path}: {$label}");
            }
        }
        self::assertNull($this->blogs($app)->find('evil'));
        self::assertSame('Kept', $this->posts($app)->find($post->id)?->title);
        self::assertSame('published', $this->posts($app)->find($post->id)?->status);
        self::assertSame('Notes', $this->blogs($app)->find('notes')?->title);
    }
}
