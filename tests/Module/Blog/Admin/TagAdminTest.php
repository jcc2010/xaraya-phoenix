<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Tests\Support\Html;

final class TagAdminTest extends AdminTestCase
{
    private const MIRROR = 'https://src.test/blog/src/feed.json';

    /** @param list<string> $tags */
    private function tagged(App $app, Blog $blog, string $title, array $tags): Post
    {
        return $this->postAs($app, null, $blog, ['title' => $title, 'content_html' => '<p>' . $title . '</p>', 'tags' => $tags]);
    }

    /** @return list<array{name: string, slug: string}> */
    private function tagsOf(App $app, Post $post): array
    {
        return $this->posts($app)->find($post->id)->tags ?? [];
    }

    private function mirror(App $app): Blog
    {
        return $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => self::MIRROR, 'source_format' => 'athena'], $this->now());
    }

    public function testEditorsAndAdministratorsSeeEveryTagSortedBySlugWithCounts(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->tagged($app, $blog, 'One', ['Zebra', 'PHP']);
        $this->tagged($app, $blog, 'Two', ['php']);
        $this->postAs($app, null, $blog, ['title' => 'Later', 'content_html' => '<p>x</p>', 'tags' => ['Soon'], 'date_published' => '2099-01-01T00:00:00Z']);
        $app->container()->get(PostWriter::class)->delete($this->tagged($app, $blog, 'Gone', ['Ghost', 'PHP']));
        $this->user($app, 'root', ['Administrators']);
        $this->user($app, 'eve', ['Editors']);
        foreach (['root', 'eve'] as $handle) {
            $html = self::main($this->both('/admin/blog/notes/tags', $handle));
            preg_match_all('~<tr id="tag-([^"]+)">~', $html, $m);
            self::assertSame(['php', 'soon', 'zebra'], $m[1], $handle . ': sorted by slug; deleted posts do not count, scheduled ones do');
            self::assertMatchesRegularExpression('~<tr id="tag-php">\s*<td><a href="/blog/notes/tag/php">PHP</a></td>\s*<td><code>php</code></td>\s*<td>2</td>~', $html);
            self::assertStringContainsString('action="/admin/blog/notes/tags/php/rename"', $html);
            self::assertStringContainsString('action="/admin/blog/notes/tags/php/remove"', $html);
            self::assertStringNotContainsString('ghost', $html);
        }
    }

    public function testAuthorsAreRefusedAndOnlyEditAllSeesTheLinks(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->tagged($app, $blog, 'One', ['PHP']);
        $this->user($app, 'ada', ['Authors']);
        $this->user($app, 'eve', ['Editors']);
        $ada = $this->signIn($app, 'ada');
        $response = $ada->get('/admin/blog/notes/tags');
        self::assertSame(403, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        $ada->get('/admin/blog/notes/posts');
        $token = (string) $ada->csrfField();
        self::assertSame(403, $ada->post('/admin/blog/notes/tags/php/rename', ['_csrf' => $token, 'name' => 'Evil', 'slug' => ''])->getStatusCode());
        self::assertSame(403, $ada->post('/admin/blog/notes/tags/php/remove', ['_csrf' => $token])->getStatusCode());
        self::assertSame([['name' => 'PHP', 'slug' => 'php']], $this->tagsOf($app, $post));
        self::assertStringNotContainsString('/tags"', (string) $ada->get('/admin/blog')->getBody());
        self::assertStringNotContainsString('/tags"', (string) $ada->get('/admin/blog/notes/posts')->getBody());

        $eve = $this->signIn($app, 'eve');
        self::assertStringContainsString('href="/admin/blog/notes/tags"', (string) $eve->get('/admin/blog')->getBody());
        self::assertStringContainsString('href="/admin/blog/notes/tags"', (string) $eve->get('/admin/blog/notes/posts')->getBody());
        self::assertSame(404, $eve->get('/admin/blog/nope/tags')->getStatusCode());
    }

    public function testRenamingKeepsOrChangesOrDerivesTheSlug(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $one = $this->tagged($app, $blog, 'One', ['Web', 'PHP', 'Zebra']);
        $two = $this->tagged($app, $blog, 'Two', ['PHP']);
        $this->user($app, 'eve', ['Editors']);
        $browser = $this->signIn($app, 'eve');
        $date = $this->posts($app)->find($one->id)?->dateModified;

        $browser->get('/admin/blog/notes/tags');
        $response = $browser->post('/admin/blog/notes/tags/php/rename', ['name' => '  PHP 8  ', 'slug' => 'php']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/notes/tags', $response->getHeaderLine('Location'));
        self::assertPrivateNoStore($response);
        self::assertSame([['name' => 'Web', 'slug' => 'web'], ['name' => 'PHP 8', 'slug' => 'php'], ['name' => 'Zebra', 'slug' => 'zebra']], $this->tagsOf($app, $one));
        self::assertSame([['name' => 'PHP 8', 'slug' => 'php']], $this->tagsOf($app, $two));
        self::assertStringContainsString('Renamed tag “PHP” to “PHP 8” on 2 posts.', (string) $browser->get('/admin/blog/notes/tags')->getBody());
        self::assertEquals($date, $this->posts($app)->find($one->id)?->dateModified, 'date_modified is not bumped');

        $browser->post('/admin/blog/notes/tags/php/rename', ['name' => 'PHP 8', 'slug' => 'php-8']);
        self::assertSame([['name' => 'PHP 8', 'slug' => 'php-8']], $this->tagsOf($app, $two));

        $browser->get('/admin/blog/notes/tags');
        self::assertSame(303, $browser->post('/admin/blog/notes/tags/php-8/rename', ['name' => 'Hypertext Preprocessor', 'slug' => ''])->getStatusCode());
        self::assertSame([['name' => 'Hypertext Preprocessor', 'slug' => 'hypertext-preprocessor']], $this->tagsOf($app, $two));
        self::assertSame('hypertext-preprocessor', $this->tagsOf($app, $one)[1]['slug'], 'the position is kept');
    }

    public function testRenamingToAnExistingSlugMergesKeepingTheFirstEntry(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $both = $this->tagged($app, $blog, 'Both', ['JavaScript', 'JS', 'Web']);
        $reversed = $this->tagged($app, $blog, 'Reversed', ['JS', 'JavaScript']);
        $old = $this->tagged($app, $blog, 'Old', ['JS']);
        $this->user($app, 'root', ['Administrators']);
        $browser = $this->signIn($app, 'root');
        $browser->get('/admin/blog/notes/tags');
        self::assertSame(303, $browser->post('/admin/blog/notes/tags/js/rename', ['name' => 'JavaScript', 'slug' => 'javascript'])->getStatusCode());
        self::assertSame([['name' => 'JavaScript', 'slug' => 'javascript'], ['name' => 'Web', 'slug' => 'web']], $this->tagsOf($app, $both));
        self::assertSame([['name' => 'JavaScript', 'slug' => 'javascript']], $this->tagsOf($app, $reversed));
        self::assertSame([['name' => 'JavaScript', 'slug' => 'javascript']], $this->tagsOf($app, $old));
        $html = self::main((string) $browser->get('/admin/blog/notes/tags')->getBody());
        self::assertStringNotContainsString('id="tag-js"', $html);
        self::assertMatchesRegularExpression('~<tr id="tag-javascript">.*?<td>3</td>~s', $html);
    }

    public function testRemovingATagLeavesDeletedPostsAlone(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $writer = $app->container()->get(PostWriter::class);
        $one = $this->tagged($app, $blog, 'One', ['PHP', 'Web']);
        $only = $this->tagged($app, $blog, 'Only', ['PHP']);
        $dead = $writer->delete($this->tagged($app, $blog, 'Dead', ['PHP']));
        $this->user($app, 'eve', ['Editors']);
        $browser = $this->signIn($app, 'eve');
        $browser->get('/admin/blog/notes/tags');
        $response = $browser->post('/admin/blog/notes/tags/php/remove');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/notes/tags', $response->getHeaderLine('Location'));
        self::assertSame([['name' => 'Web', 'slug' => 'web']], $this->tagsOf($app, $one));
        self::assertSame([], $this->tagsOf($app, $only));
        self::assertSame([['name' => 'PHP', 'slug' => 'php']], $this->tagsOf($app, $dead), 'deleted posts keep their tags');
        self::assertSame('deleted', $this->posts($app)->find($dead->id)?->status);
        self::assertStringContainsString('Removed tag “PHP” from 2 posts.', (string) $browser->get('/admin/blog/notes/tags')->getBody());
        self::assertSame(404, $browser->post('/admin/blog/notes/tags/php/remove')->getStatusCode(), 'only the deleted post has it now');

        $browser->get('/admin/blog/notes/tags');
        $browser->post('/admin/blog/notes/tags/web/rename', ['name' => 'WWW', 'slug' => '']);
        self::assertStringContainsString('Renamed tag “Web” to “WWW” on 1 post.', (string) $browser->get('/admin/blog/notes/tags')->getBody());
    }

    public function testTheFeedAndSearchFollowARename(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->tagged($app, $blog, 'Kept', ['Gardening', 'Old']);
        $this->user($app, 'eve', ['Editors']);
        $browser = $this->signIn($app, 'eve');
        self::assertStringNotContainsString('Kept', self::main((string) $browser->get('/blog/notes/search?q=horticulture')->getBody()));
        $browser->get('/admin/blog/notes/tags');
        $browser->post('/admin/blog/notes/tags/gardening/rename', ['name' => 'Horticulture', 'slug' => '']);
        $browser->get('/admin/blog/notes/tags');
        $browser->post('/admin/blog/notes/tags/old/remove');

        $feed = json_decode((string) $browser->get('/blog/notes/feed.json')->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($feed);
        $item = $feed['items'][0];
        self::assertSame(['Horticulture'], $item['tags']);
        self::assertSame([['name' => 'Horticulture', 'slug' => 'horticulture']], $item['_athenana']['tags']);
        self::assertStringContainsString('Kept', self::main((string) $browser->get('/blog/notes/search?q=horticulture')->getBody()));
        self::assertSame(200, $browser->get('/blog/notes/tag/horticulture')->getStatusCode());
        self::assertSame($post->id, $this->posts($app)->find($post->id)?->id);
    }

    public function testInvalidNamesAndSlugsAreInline422s(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->tagged($app, $blog, 'One', ['PHP']);
        $this->user($app, 'eve', ['Editors']);
        $browser = $this->signIn($app, 'eve');
        $cases = [
            'empty' => [['name' => '   ', 'slug' => ''], 'Name is required'],
            'long' => [['name' => str_repeat('é', 129), 'slug' => 'x'], 'Name must be at most 128 characters'],
            'control' => [['name' => "a\tb", 'slug' => ''], 'Name must be valid UTF-8 without control characters'],
            'utf8' => [['name' => "a\xffb", 'slug' => ''], 'Name must be valid UTF-8 without control characters'],
            'slug' => [['name' => 'PHP', 'slug' => 'Not OK'], 'Slug must be 1 to 128 characters of a-z, 0-9 and -'],
            'long slug' => [['name' => 'PHP', 'slug' => str_repeat('a', 129)], 'Slug must be 1 to 128 characters of a-z, 0-9 and -'],
        ];
        foreach ($cases as $label => [$fields, $message]) {
            $browser->get('/admin/blog/notes/tags');
            $response = $browser->post('/admin/blog/notes/tags/php/rename', $fields);
            self::assertSame(422, $response->getStatusCode(), $label);
            self::assertPrivateNoStore($response);
            self::assertStringContainsString($message, $browser->body(), $label);
            self::assertStringContainsString('aria-invalid="true"', $browser->body(), $label);
        }
        self::assertSame(303, $browser->post('/admin/blog/notes/tags/php/rename', ['name' => str_repeat('é', 128), 'slug' => 'php'])->getStatusCode(), '128 characters fit');
        self::assertSame(str_repeat('é', 128), $this->tagsOf($app, $post)[0]['name']);
    }

    public function testUnknownTagsAre404s(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->tagged($app, $blog, 'One', ['PHP']);
        $this->user($app, 'eve', ['Editors']);
        $browser = $this->signIn($app, 'eve');
        $browser->get('/admin/blog/notes/tags');
        self::assertSame(404, $browser->post('/admin/blog/notes/tags/nope/rename', ['name' => 'X', 'slug' => ''])->getStatusCode());
        $browser->get('/admin/blog/notes/tags');
        self::assertSame(404, $browser->post('/admin/blog/notes/tags/nope/remove')->getStatusCode());
        $browser->get('/admin/blog/notes/tags');
        self::assertSame(404, $browser->post('/admin/blog/notes/tags/%FF/remove')->getStatusCode());
    }

    public function testAMirrorsTagsAreReadOnly(): void
    {
        $app = $this->site();
        $blog = $this->mirror($app);
        $id = $this->addPost($app, $blog, self::postRecord(1, ['tags' => [['name' => 'Synced', 'slug' => 'synced']]]));
        $this->user($app, 'root', ['Administrators']);
        $html = self::main($this->both('/admin/blog/mirror/tags', 'root'));
        self::assertStringContainsString('<p class="admin-mirror-note">Tags come from the source feed; edit them there.</p>', $html);
        self::assertStringContainsString('<tr id="tag-synced">', $html);
        self::assertStringNotContainsString('<form', $html);
        $browser = $this->signIn($app, 'root');
        foreach (['rename', 'remove'] as $action) {
            $browser->get('/admin/blog/mirror/posts'); // the tags screen of a mirror has no form to take a token from
            self::assertSame(409, $browser->post('/admin/blog/mirror/tags/synced/' . $action, ['name' => 'Hijacked', 'slug' => ''])->getStatusCode(), $action);
        }
        self::assertSame([['name' => 'Synced', 'slug' => 'synced']], $this->posts($app)->find($id)?->tags);
    }

    public function testHostileNamesAreEscapedAndTheRefusedFormMatchesInBothEngines(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->tagged($app, $blog, 'One', ['<script>alert(1)</script>']);
        $this->user($app, 'eve', ['Editors']);
        $html = self::main($this->both('/admin/blog/notes/tags', 'eve'));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('<tr id="tag-script-alert-1-script">', $html);

        $bodies = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = $this->signIn($this->site($engine), 'eve');
            $browser->get('/admin/blog/notes/tags');
            self::assertSame(422, $browser->post('/admin/blog/notes/tags/script-alert-1-script/rename', ['name' => '"><i>x</i>', 'slug' => 'Bad Slug'])->getStatusCode(), $engine);
            $bodies[$engine] = Html::normalize(preg_replace('/name="_csrf" value="[^"]*"/', '', $browser->body()) ?? '');
        }
        self::assertSame($bodies['php'], $bodies['twig']);
        self::assertStringNotContainsString('<i>x', $bodies['php']);
        self::assertStringContainsString('value="&quot;&gt;&lt;i&gt;x&lt;/i&gt;"', $bodies['php']);
        self::assertStringContainsString('value="Bad Slug"', $bodies['php']);
    }

    public function testABlogWithoutTagsSaysSo(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'eve', ['Editors']);
        self::assertStringContainsString('<p class="admin-empty">No tags.</p>', self::main($this->both('/admin/blog/notes/tags', 'eve')));
    }
}
