<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;

final class MicropubUpdateTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    /** @param array<string, mixed> $extra */
    private function note(string $content = 'First draft', array $extra = []): string
    {
        return $this->form(['content' => $content, 'category' => ['a'], ...$extra])->getHeaderLine('Location');
    }

    private function post(string $url): Post
    {
        $post = $this->app()->container()->get(PostRepository::class)->find(substr($url, -26));
        self::assertNotNull($post);

        return $post;
    }

    /** @param array<string, mixed> $operations */
    private function update(string $url, array $operations, ?string $token = null): ResponseInterface
    {
        return $this->postJson(['action' => 'update', 'url' => $url, ...$operations], $token);
    }

    private function rawJson(string $body): ResponseInterface
    {
        return $this->app()->handle((new ServerRequest('POST', '/micropub'))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create($body)));
    }

    /**
     * Creates a post through the JSON API (in "notes"); returns its URL.
     *
     * @param array<string, mixed> $item
     */
    private function apiPost(array $item): string
    {
        $response = $this->app()->handle((new ServerRequest('POST', '/api/blogs/notes/posts'))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $url = self::body($response)['url'] ?? null;
        self::assertIsString($url);

        return $url;
    }

    public function testReplaceRewritesOnlyThatProperty(): void
    {
        $url = $this->note();
        $response = $this->update($url, ['replace' => ['content' => ['Second draft']]]);
        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $post = $this->post($url);
        self::assertSame(['<p>Second draft</p>', 'Second draft'], [$post->contentHtml, $post->title]);
        self::assertSame([['name' => 'a', 'slug' => 'a']], $post->tags);
        self::assertNotNull($post->dateModified);
    }

    public function testAddAndDeleteValuesAndProperties(): void
    {
        $url = $this->note();
        $this->update($url, ['add' => ['category' => ['b', 'c']]]);
        self::assertSame(['a', 'b', 'c'], array_column($this->post($url)->tags, 'name'));
        $this->update($url, ['delete' => ['category' => ['a', 'c']]]);
        self::assertSame(['b'], array_column($this->post($url)->tags, 'name'));
        $this->update($url, ['delete' => ['category']]);
        self::assertSame([], $this->post($url)->tags);
    }

    public function testPhotosAndBookmarksChangeTheKind(): void
    {
        $url = $this->note();
        $this->update($url, ['add' => ['photo' => ['https://img.test/p.jpg']]]);
        $post = $this->post($url);
        self::assertSame(['photo', 'https://img.test/p.jpg'], [$post->kind, $post->image]);
        $this->update($url, ['replace' => ['bookmark-of' => ['https://example.com/']], 'delete' => ['photo']]);
        $post = $this->post($url);
        self::assertSame(['link', 'https://example.com/', null], [$post->kind, $post->externalUrl, $post->image]);
        self::assertArrayNotHasKey('images', $post->doc['_athenana'] ?? []);
        $this->update($url, ['delete' => ['bookmark-of']]);
        self::assertSame('note', $this->post($url)->kind);
    }

    public function testANameCanBeSetAndRemoved(): void
    {
        $url = $this->note('Body text');
        $this->update($url, ['replace' => ['name' => ['A title']]]);
        self::assertSame('A title', $this->post($url)->title);
        $this->update($url, ['delete' => ['name']]);
        self::assertSame('Body text', $this->post($url)->title);
    }

    public function testDeleteAndUndelete(): void
    {
        $url = $this->note('Gone soon');
        self::assertSame(204, $this->form(['action' => 'delete', 'url' => $url])->getStatusCode());
        self::assertSame('deleted', $this->post($url)->status);
        self::assertSame(404, $this->app()->handle(new ServerRequest('GET', '/s/' . substr($url, -26) . '.json'))->getStatusCode());
        self::assertSame(204, $this->postJson(['action' => 'undelete', 'url' => $url])->getStatusCode());
        self::assertSame('published', $this->post($url)->status);
    }

    public function testPhotoUpdatesKeepTheSizesOfImagesThatStay(): void
    {
        // assertEquals for the images: MySQL's JSON column does not keep object key order (list order is still checked).
        $url = $this->apiPost(['content_html' => '<p>Pics</p>', 'image' => 'https://img.test/a.jpg', '_athenana' => ['kind' => 'photo', 'images' => [
            ['url' => 'https://img.test/a.jpg', 'width' => 800, 'height' => 600],
            ['url' => 'https://img.test/b.jpg', 'width' => 640, 'height' => 480],
        ]]]);
        self::assertSame(204, $this->update($url, ['add' => ['photo' => ['https://img.test/c.jpg']]])->getStatusCode());
        self::assertEquals([
            ['url' => 'https://img.test/a.jpg', 'width' => 800, 'height' => 600],
            ['url' => 'https://img.test/b.jpg', 'width' => 640, 'height' => 480],
            ['url' => 'https://img.test/c.jpg'],
        ], $this->post($url)->doc['_athenana']['images'] ?? null);
        self::assertSame(204, $this->update($url, ['delete' => ['photo' => ['https://img.test/a.jpg']]])->getStatusCode());
        $post = $this->post($url);
        self::assertEquals([['url' => 'https://img.test/b.jpg', 'width' => 640, 'height' => 480], ['url' => 'https://img.test/c.jpg']], $post->doc['_athenana']['images'] ?? null);
        self::assertSame('https://img.test/b.jpg', $post->image);
    }

    public function testContentUpdatesClearContentText(): void
    {
        $url = $this->apiPost(['title' => 'Plain', 'content_text' => 'Old text', 'content_html' => '<p>Old text</p>']);
        self::assertSame(204, $this->update($url, ['replace' => ['content' => ['New text']]])->getStatusCode());
        $post = $this->post($url);
        self::assertSame('<p>New text</p>', $post->contentHtml);
        self::assertArrayNotHasKey('content_text', $post->doc);

        $url = $this->apiPost(['title' => 'Text only', 'content_text' => 'Just text']);
        self::assertSame(204, $this->update($url, ['delete' => ['content']])->getStatusCode());
        $post = $this->post($url);
        self::assertNull($post->contentHtml);
        self::assertArrayNotHasKey('content_text', $post->doc);
    }

    public function testAKindMicropubDoesNotModelIsKept(): void
    {
        $url = $this->apiPost(['content_html' => '<p>A quote</p>', '_athenana' => ['kind' => 'highlight']]);
        self::assertSame(204, $this->update($url, ['replace' => ['bookmark-of' => ['https://example.com/source']]])->getStatusCode());
        $post = $this->post($url);
        self::assertSame(['highlight', 'https://example.com/source'], [$post->kind, $post->externalUrl]);
        self::assertSame(204, $this->update($url, ['add' => ['photo' => ['https://img.test/q.jpg']]])->getStatusCode());
        self::assertSame('highlight', $this->post($url)->kind);
    }

    public function testFieldsMicropubDoesNotModelSurviveAnUpdate(): void
    {
        $url = $this->apiPost([
            'content_html' => '<p>Episode</p>',
            'attachments' => [['url' => 'https://cdn.test/e.mp3', 'mime_type' => 'audio/mpeg']],
            '_video' => ['provider' => 'youtube', 'id' => 'abc'],
            '_athenana' => ['podcast' => ['duration' => 1800, 'episode' => 7]],
        ]);
        self::assertSame(204, $this->update($url, ['replace' => ['content' => ['Episode 7']], 'add' => ['category' => ['pods']]])->getStatusCode());
        $post = $this->post($url);
        self::assertSame('<p>Episode 7</p>', $post->contentHtml);
        // assertEquals: MySQL's JSON column does not keep object key order.
        self::assertEquals([['url' => 'https://cdn.test/e.mp3', 'mime_type' => 'audio/mpeg']], $post->doc['attachments'] ?? null);
        self::assertEquals(['provider' => 'youtube', 'id' => 'abc'], $post->doc['_video'] ?? null);
        self::assertEquals(['duration' => 1800, 'episode' => 7], $post->doc['_athenana']['podcast'] ?? null);
    }

    public function testDeleteAndUndeleteAreIdempotent(): void
    {
        $url = $this->note('Twice');
        $this->form(['action' => 'delete', 'url' => $url]);
        $app = $this->app(); // app() boots a new App each call: the listener lives on this one
        $events = [];
        $app->container()->get(EventDispatcher::class)->listen(ItemEvent::class, static function (ItemEvent $e) use (&$events): void {
            $events[] = $e::class;
        });
        $send = fn(string $action): int => $app->handle((new ServerRequest('POST', '/micropub'))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode(['action' => $action, 'url' => $url], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))))->getStatusCode();
        self::assertSame(204, $send('delete'));
        self::assertSame('deleted', $this->post($url)->status);
        self::assertSame(204, $send('undelete'));
        self::assertSame(204, $send('undelete'));
        self::assertSame('published', $this->post($url)->status);
        self::assertSame([ItemUpdated::class], $events, 'only the real undelete fires an event');
    }

    public function testUpdatingADeletedPostIs400(): void
    {
        $url = $this->note('Deleted');
        $this->form(['action' => 'delete', 'url' => $url]);
        self::assertMicropubError($this->update($url, ['replace' => ['content' => ['Revived?']]]), 400, 'invalid_request', 'The post is deleted; undelete it first');
        self::assertSame('<p>Deleted</p>', $this->post($url)->contentHtml);
        self::assertSame('deleted', $this->post($url)->status);
    }

    public function testMirrorPostsCannotBeChanged(): void
    {
        $app = $this->app();
        $mirror = $app->container()->get(BlogRepository::class)->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $id = $this->addPost($app, $mirror, self::postRecord(1, ['itemId' => 'https://src.test/posts/1']));
        self::assertMicropubError($this->form(['action' => 'delete', 'url' => 'http://xar.test/s/' . $id]), 400, 'invalid_request', "Blog 'src' is a mirror: its posts come from its source");
        self::assertSame('published', $this->post($id)->status);
    }

    public function testBadUpdatesAre400(): void
    {
        $url = $this->note();
        self::assertMicropubError($this->update('http://xar.test/s/' . str_repeat('0', 26), ['replace' => ['content' => ['x']]]), 400, 'invalid_request', 'No post at http://xar.test/s/' . str_repeat('0', 26));
        self::assertMicropubError($this->postJson(['action' => 'update', 'replace' => ['content' => ['x']]]), 400, 'invalid_request', 'action=update needs a url');
        self::assertMicropubError($this->form(['action' => 'update', 'url' => $url, 'content' => 'x']), 400, 'invalid_request', 'Send updates as JSON');
        self::assertMicropubError($this->update($url, ['replace' => ['content' => 'x']]), 400, 'invalid_request', 'replace.content must be a list of values');
        self::assertMicropubError($this->update($url, ['replace' => [['content']]]), 400, 'invalid_request', 'replace must be an object of properties');
        self::assertMicropubError($this->update($url, ['delete' => ['published']]), 400, 'invalid_request', 'date_published: Cannot be removed');
        self::assertSame('<p>First draft</p>', $this->post($url)->contentHtml);
    }

    public function testInvalidUtf8Is400(): void
    {
        $url = $this->note();
        $bad = "\xC3\x28";
        self::assertMicropubError($this->rawJson('{"action":"update","url":"' . $url . $bad . '","replace":{"content":["x"]}}'), 400, 'invalid_request', 'The body is not valid JSON');
        self::assertMicropubError($this->rawJson('{"action":"update","url":"' . $url . '","replace":{"content":["x' . $bad . '"]}}'), 400, 'invalid_request', 'The body is not valid JSON');
        self::assertMicropubError($this->rawJson('{"action":"update","url":"' . $url . '","replace":{"content":["\ud800"]}}'), 400, 'invalid_request', 'The body is not valid JSON');
        self::assertMicropubError($this->form(['action' => 'delete', 'url' => $url . $bad]), 400, 'invalid_request', 'url must be valid UTF-8');
        self::assertMicropubError($this->form(['action' => 'update', 'url' => $url, 'content' => $bad]), 400, 'invalid_request', 'content must be valid UTF-8');
        self::assertSame(['<p>First draft</p>', 'published'], [$this->post($url)->contentHtml, $this->post($url)->status]);
    }

    public function testPostUrlPatternsAndItemIdsLocatePosts(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'site', 'post_url_pattern' => 'https://www.example.org/post.html?id={id}']);
        $url = $this->form(['content' => 'Patterned', 'mp-destination' => 'site'])->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^https://www\.example\.org/post\.html\?id=[0-9a-z]{26}$#', $url);
        self::assertSame(204, $this->update($url, ['replace' => ['content' => ['Found by pattern']]])->getStatusCode());
        $post = $this->post($url);
        self::assertSame('<p>Found by pattern</p>', $post->contentHtml);
        self::assertSame(204, $this->update($post->itemId, ['replace' => ['content' => ['Found by item id']]])->getStatusCode());
        self::assertSame('<p>Found by item id</p>', $this->post($url)->contentHtml);
    }

    /**
     * A post that kept its source item id after a cut-over is found by that id, even when the id has the shape of
     * a post URL pattern or of {app.url}/s/{id} but names no post there.
     */
    public function testSourceItemIdsLocatePosts(): void
    {
        $app = $this->app();
        $site = $this->nativeBlog($app, ['handle' => 'site', 'post_url_pattern' => 'https://www.example.org/post.html?id={id}']);
        $byPattern = 'https://www.example.org/post.html?id=' . Ulid::generate();
        $bySlash = 'http://xar.test/s/' . Ulid::generate();
        $patternId = $this->addPost($app, $site, self::postRecord(1, ['itemId' => $byPattern]));
        $slashId = $this->addPost($app, $site, self::postRecord(2, ['itemId' => $bySlash]));
        foreach ([$byPattern => $patternId, $bySlash => $slashId] as $itemId => $id) {
            $response = $this->update($itemId, ['replace' => ['content' => ['Found by source id']]]);
            self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
            self::assertSame('<p>Found by source id</p>', $this->post($id)->contentHtml);
            self::assertSame(204, $this->form(['action' => 'delete', 'url' => $itemId])->getStatusCode());
            self::assertSame('deleted', $this->post($id)->status);
        }
    }

    public function testAPinnedTokenCannotTouchAnotherBlog(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        $url = $this->form(['content' => 'Theirs', 'mp-destination' => 'other'])->getHeaderLine('Location');
        $pinned = $this->token('blog:write', ['--blog=notes']);
        self::assertMicropubError($this->update($url, ['replace' => ['content' => ['Mine now']]], $pinned), 403, 'forbidden');
        self::assertMicropubError($this->form(['action' => 'delete', 'url' => $url], $pinned), 403, 'forbidden');
        self::assertSame(['<p>Theirs</p>', 'published'], [$this->post($url)->contentHtml, $this->post($url)->status]);
        $this->form(['action' => 'delete', 'url' => $url]);
        self::assertMicropubError($this->postJson(['action' => 'undelete', 'url' => $url], $pinned), 403, 'forbidden');
        self::assertSame('deleted', $this->post($url)->status);
    }
}
