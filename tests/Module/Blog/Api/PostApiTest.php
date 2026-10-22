<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Api;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use RuntimeException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Http\Api\JsonBody;
use Xaraya\Module\Blog\Post\PostRepository;

final class PostApiTest extends ApiTestCase
{
    private App $site;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->enableTokens();
        $this->nativeBlog($this->site);
        $this->nativeBlog($this->site, ['handle' => 'other', 'title' => 'Other']);
        $this->token = $this->token();
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed> the created item
     */
    private function create(array $item, string $handle = 'notes'): array
    {
        $response = $this->api('POST', "/api/blogs/{$handle}/posts", $this->token, $item);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return self::json($response);
    }

    public function testAPostedItemAppearsInTheFeed(): void
    {
        $response = $this->api('POST', '/api/blogs/notes/posts', $this->token, ['title' => 'Hello API', 'content_html' => '<p>From the API</p>', 'tags' => ['api']]);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $item = self::json($response);
        $id = substr((string) $item['id'], strlen('http://xar.test/s/'));
        self::assertSame("/api/posts/{$id}", $response->getHeaderLine('Location'));
        self::assertSame("http://xar.test/s/{$id}", $item['url']);
        self::assertSame(['status' => 'published'], $item['_phoenix']);
        self::assertSame(['kind' => 'note', 'tags' => [['name' => 'api', 'slug' => 'api']]], $item['_athenana']);

        $feed = self::json($this->page('/blog/notes/feed.json'));
        self::assertSame([$item['id']], array_column($feed['items'], 'id'));
        unset($item['_phoenix']);
        self::assertEquals($item, $feed['items'][0]);
    }

    public function testEveryStatusOfTheApi(): void
    {
        $item = $this->create(['title' => 'One']);
        $id = substr((string) $item['id'], -26);

        self::assertSame(401, $this->api('POST', '/api/blogs/notes/posts', null, ['title' => 'x'])->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/blogs/notes/posts', $this->token('media:write'), ['title' => 'x'])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/blogs/nope/posts', $this->token, ['title' => 'x'])->getStatusCode());
        self::assertSame(415, $this->api('POST', '/api/blogs/notes/posts', $this->token, 'title=x', 'application/x-www-form-urlencoded')->getStatusCode());
        self::assertSame(400, $this->api('POST', '/api/blogs/notes/posts', $this->token, '{"title":')->getStatusCode());
        self::assertSame(400, $this->api('POST', '/api/blogs/notes/posts', $this->token, '["title"]')->getStatusCode());
        self::assertSame(413, $this->api('POST', '/api/blogs/notes/posts', $this->token, '{"title":"' . str_repeat('x', JsonBody::MAX_BYTES) . '"}')->getStatusCode());

        $this->blogs($this->app())->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $mirror = $this->api('POST', '/api/blogs/src/posts', $this->token, ['title' => 'x']);
        self::assertSame(409, $mirror->getStatusCode());
        self::assertSame("Blog 'src' is a mirror: its posts come from its source", self::json($mirror)['error']['message']);

        self::assertSame(404, $this->api('GET', '/api/posts/' . str_repeat('0', 26), $this->token)->getStatusCode());
        self::assertSame(200, $this->api('GET', "/api/posts/{$id}", $this->token)->getStatusCode());
        self::assertSame('Two', self::json($this->api('PUT', "/api/posts/{$id}", $this->token, ['title' => 'Two']))['title']);
        self::assertSame('Three', self::json($this->api('PATCH', "/api/posts/{$id}", $this->token, ['title' => 'Three'], 'application/merge-patch+json'))['title']);

        $deleted = $this->api('DELETE', "/api/posts/{$id}", $this->token);
        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame('', (string) $deleted->getBody());
        self::assertSame(204, $this->api('DELETE', "/api/posts/{$id}", $this->token)->getStatusCode());
        self::assertSame(['status' => 'deleted'], self::json($this->api('GET', "/api/posts/{$id}", $this->token))['_phoenix']);
        self::assertSame(409, $this->api('PUT', "/api/posts/{$id}", $this->token, ['title' => 'x'])->getStatusCode());
        self::assertSame(404, $this->page("/s/{$id}.json")->getStatusCode());

        $back = $this->api('POST', "/api/posts/{$id}/undelete", $this->token);
        self::assertSame(200, $back->getStatusCode());
        self::assertSame(['status' => 'published'], self::json($back)['_phoenix']);
        self::assertSame(200, $this->page("/s/{$id}.json")->getStatusCode());
    }

    public function testPatchTakesMergePatchJsonAndFeedJsonBodies(): void
    {
        $id = substr((string) $this->create(['title' => 'One'])['id'], -26);
        foreach (['application/merge-patch+json', 'application/json', 'application/feed+json; charset=utf-8'] as $n => $type) {
            $response = $this->api('PATCH', "/api/posts/{$id}", $this->token, ['title' => "Patched {$n}"], $type);
            self::assertSame(200, $response->getStatusCode(), $type);
            self::assertSame("Patched {$n}", self::json($response)['title'], $type);
        }
        $plain = $this->api('PATCH', "/api/posts/{$id}", $this->token, '{"title":"x"}', 'text/plain');
        self::assertSame(415, $plain->getStatusCode());
        self::assertSame('Send the body as application/merge-patch+json or application/json or application/feed+json', self::json($plain)['error']['message']);
        self::assertSame(415, $this->api('PUT', "/api/posts/{$id}", $this->token, '{"title":"x"}', 'application/merge-patch+json')->getStatusCode());
    }

    public function testABodyThatIsNotAJsonObjectIs400(): void
    {
        $id = substr((string) $this->create(['title' => 'Kept'])['id'], -26);
        $bodies = [
            '' => 'The body is not valid JSON',
            '{"title":' => 'The body is not valid JSON',
            '"Kept"' => 'The body must be a JSON object',
            '42' => 'The body must be a JSON object',
            'null' => 'The body must be a JSON object',
            '[]' => 'The body must be a JSON object',
            '["title"]' => 'The body must be a JSON object',
            str_repeat('{"a":', 100) . '1' . str_repeat('}', 100) => 'The body is not valid JSON',
        ];
        foreach (['PUT' => 'application/json', 'PATCH' => 'application/merge-patch+json'] as $method => $type) {
            foreach ($bodies as $body => $message) {
                $response = $this->api($method, "/api/posts/{$id}", $this->token, (string) $body, $type);
                self::assertSame(400, $response->getStatusCode(), "{$method} {$body}");
                self::assertSame($message, self::json($response)['error']['message'], "{$method} {$body}");
            }
        }
        self::assertSame('Kept', self::json($this->api('GET', "/api/posts/{$id}", $this->token))['title']);
    }

    public function testAnOversizedBodyIs413BeforeItIsRead(): void
    {
        $id = substr((string) $this->create(['title' => 'Small'])['id'], -26);
        self::assertSame(413, $this->api('PUT', "/api/posts/{$id}", $this->token, '{"title":"' . str_repeat('x', JsonBody::MAX_BYTES) . '"}')->getStatusCode());

        // A declared Content-Length over the cap is refused without reading the body.
        $request = (new ServerRequest('PATCH', "/api/posts/{$id}"))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'application/merge-patch+json')
            ->withHeader('Content-Length', (string) (JsonBody::MAX_BYTES + 1))
            ->withBody(Stream::create('{"title":"x"}'));
        $response = $this->app()->handle($request);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame('The body is larger than ' . JsonBody::MAX_BYTES . ' bytes', self::json($response)['error']['message']);
        self::assertSame('Small', self::json($this->api('GET', "/api/posts/{$id}", $this->token))['title']);
    }

    public function testUnknownTopLevelFieldsAre422WithFields(): void
    {
        $response = $this->api('POST', '/api/blogs/notes/posts', $this->token, ['title' => 'x', 'titel' => 'typo', '_ext' => 1]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['status' => 422, 'message' => 'Invalid item', 'fields' => ['_ext' => 'Extension fields must be JSON objects', 'titel' => 'Unknown field']],
            self::json($response)['error'],
        );
        self::assertSame(0, (int) $this->db($this->app())->fetchValue('SELECT COUNT(*) FROM {posts}'));
    }

    public function testAPinnedTokenCannotReachAnotherBlogsPostsById(): void
    {
        $other = $this->create(['title' => 'Not yours'], 'other');
        $id = substr((string) $other['id'], -26);
        $pinned = $this->token('blog:write', ['--blog=notes']);

        self::assertSame(201, $this->api('POST', '/api/blogs/notes/posts', $pinned, ['title' => 'Mine'])->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/blogs/other/posts', $pinned, ['title' => 'x'])->getStatusCode());
        $routes = [
            ['GET', "/api/posts/{$id}", null, 'application/json'],
            ['PUT', "/api/posts/{$id}", ['title' => 'Hijacked'], 'application/json'],
            ['PATCH', "/api/posts/{$id}", ['title' => 'Hijacked'], 'application/merge-patch+json'],
            ['DELETE', "/api/posts/{$id}", null, 'application/json'],
            ['POST', "/api/posts/{$id}/undelete", null, 'application/json'],
        ];
        foreach ($routes as [$method, $uri, $body, $type]) {
            $response = $this->api($method, $uri, $pinned, $body, $type);
            self::assertSame(403, $response->getStatusCode(), "{$method} {$uri}");
            self::assertSame('The token may not write to this blog', self::json($response)['error']['message']);
        }
        $after = self::json($this->api('GET', "/api/posts/{$id}", $this->token));
        self::assertSame(['Not yours', ['status' => 'published']], [$after['title'], $after['_phoenix']]);

        // Undelete is refused too when the other blog's post is deleted.
        self::assertSame(204, $this->api('DELETE', "/api/posts/{$id}", $this->token)->getStatusCode());
        self::assertSame(403, $this->api('POST', "/api/posts/{$id}/undelete", $pinned)->getStatusCode());
        self::assertSame(['status' => 'deleted'], self::json($this->api('GET', "/api/posts/{$id}", $this->token))['_phoenix']);
    }

    public function testAScheduledPostIsHiddenEverywhereButTheApi(): void
    {
        $item = $this->create(['title' => 'Future walrus', 'content_html' => '<p>Soon</p>', 'date_published' => '2099-01-01T09:00:00Z']);
        $id = substr((string) $item['id'], -26);
        self::assertSame(['status' => 'scheduled'], $item['_phoenix']);
        self::assertSame(['status' => 'scheduled'], self::json($this->api('GET', "/api/posts/{$id}", $this->token))['_phoenix']);

        self::assertSame([], self::json($this->page('/blog/notes/feed.json'))['items'] ?? []);
        self::assertStringNotContainsString('walrus', (string) $this->page('/blog/notes/feed.xml')->getBody());
        self::assertSame(404, $this->page("/s/{$id}.json")->getStatusCode());
        self::assertSame(404, $this->page("/s/{$id}")->getStatusCode());
        self::assertStringNotContainsString('walrus', (string) $this->page('/blog/notes')->getBody());
        $search = $this->page('/blog/notes/search?q=walrus');
        self::assertSame(200, $search->getStatusCode());
        self::assertStringNotContainsString('Future walrus', (string) $search->getBody());
    }

    public function testAnEditShowsOnThePostPageAtOnce(): void
    {
        $item = $this->create(['title' => 'Cached', 'content_html' => '<p>Before edit</p>']);
        $id = substr((string) $item['id'], -26);
        self::assertStringContainsString('Before edit', (string) $this->page("/s/{$id}")->getBody());
        $this->api('PATCH', "/api/posts/{$id}", $this->token, ['content_html' => '<p>After edit</p>']);
        $html = (string) $this->page("/s/{$id}")->getBody();
        self::assertStringContainsString('After edit', $html);
        self::assertStringNotContainsString('Before edit', $html);
    }

    public function testTokensNeverReachTheLogs(): void
    {
        $responses = [
            $this->api('POST', '/api/blogs/notes/posts', $this->token . 'x', ['title' => 'x']),
            $this->api('POST', '/api/blogs/notes/posts?access_token=' . $this->token, null, ['title' => 'x']),
            $this->api('GET', '/api/posts/' . str_repeat('z', 26), $this->token),
        ];
        // A listener that throws turns an accepted write into a 500, which the error handler logs with its trace.
        $app = $this->app();
        $app->container()->get(EventDispatcher::class)->listen(ItemCreated::class, static function (): never {
            throw new RuntimeException('listener exploded');
        });
        $request = (new ServerRequest('POST', '/api/blogs/notes/posts?access_token=' . $this->token))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create('{"title":"Logged?"}'));
        $failed = $app->handle($request);
        self::assertSame(500, $failed->getStatusCode());
        $responses[] = $failed;

        $log = implode('', array_map('file_get_contents', glob($this->tmp . '/logs/*') ?: []));
        self::assertStringContainsString('listener exploded', $log, 'the failure was logged');
        self::assertStringContainsString('PostApiController', $log, 'the log holds the trace');
        // The first characters of the secret: a full token, and the 15-character prefix a trace argument shows.
        $secret = substr($this->token, 4, 11);
        self::assertStringNotContainsString($secret, $log);
        foreach ($responses as $response) {
            self::assertStringNotContainsString($secret, (string) $response->getBody());
            self::assertStringNotContainsString($secret, implode("\n", array_map(static fn(array $v): string => implode(',', $v), $response->getHeaders())));
        }
    }

    public function testU0000IsA422AtItsPath(): void
    {
        $cases = [
            '{"title":"a\\u0000b"}' => ['title' => 'Must not contain U+0000'],
            '{"title":"x","_ext":{"a":["ok","b\\u0000"]}}' => ['_ext.a.1' => 'Must not contain U+0000'],
            '{"title":"x","_ext":{"k\\u0000":1}}' => ['_ext.k?' => 'Must not contain U+0000'],
        ];
        foreach ($cases as $body => $fields) {
            $response = $this->api('POST', '/api/blogs/notes/posts', $this->token, $body);
            self::assertSame(422, $response->getStatusCode(), $body . ' ' . $response->getBody());
            self::assertSame($fields, self::json($response)['error']['fields'], $body);
        }
        self::assertSame(0, (int) $this->db($this->app())->fetchValue('SELECT COUNT(*) FROM {posts}'));
    }

    public function testAMirrorBlogIs409WhateverTheBody(): void
    {
        $app = $this->app();
        $mirror = $app->container()->get(BlogRepository::class)->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $id = $this->addPost($app, $mirror, self::postRecord(1));
        foreach ([['POST', '/api/blogs/src/posts'], ['PUT', "/api/posts/{$id}"], ['PATCH', "/api/posts/{$id}"]] as [$method, $uri]) {
            $response = $this->api($method, $uri, $this->token, 'title=x', 'text/plain');
            self::assertSame(409, $response->getStatusCode(), $method . ' ' . $response->getBody());
        }
    }

    public function testTheComputedStatusBeatsAStoredPhoenixField(): void
    {
        $app = $this->app();
        $mirror = $app->container()->get(BlogRepository::class)->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $id = $this->addPost($app, $mirror, self::postRecord(1, ['itemId' => 'https://src.test/posts/1', 'doc' => ['_phoenix' => ['status' => 'published']]]));
        $app->container()->get(PostRepository::class)->tombstone($mirror->id, ['https://src.test/posts/1'], $this->now());
        $response = $this->api('GET', "/api/posts/{$id}", $this->token);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['status' => 'deleted'], self::json($response)['_phoenix']);
    }

    public function testJsonResponsesAreNosniff(): void
    {
        $created = $this->api('POST', '/api/blogs/notes/posts', $this->token, ['title' => 'Sniff']);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('nosniff', $created->getHeaderLine('X-Content-Type-Options'));
        $id = substr((string) self::json($created)['id'], -26);
        self::assertSame('nosniff', $this->api('GET', "/api/posts/{$id}", $this->token)->getHeaderLine('X-Content-Type-Options'));
        $error = $this->api('GET', '/api/posts/' . str_repeat('0', 26), $this->token);
        self::assertSame(404, $error->getStatusCode());
        self::assertSame('nosniff', $error->getHeaderLine('X-Content-Type-Options'));
    }

    public function testErrorsAreJsonEvenForHtmlClients(): void
    {
        $response = $this->app()->handle((new ServerRequest('GET', '/api/posts/' . str_repeat('0', 26)))->withHeader('Accept', 'text/html'));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testTheApiNeedsTheTokensModule(): void
    {
        $this->xar(['module:disable', 'tokens']);
        $response = $this->api('POST', '/api/blogs/notes/posts', $this->token, ['title' => 'x']);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testATokenThatActsAsAUserMakesThatUserTheOwner(): void
    {
        $ada = $this->site->container()->get(UserRepository::class)
            ->create('ada', 'ada@example.com', 'correct horse battery', 'Ada', 'active', ['Authors']);
        $response = $this->api('POST', '/api/blogs/notes/posts', $this->token('blog:write', ['--user=ada']), ['content_html' => '<p>Mine</p>']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringNotContainsString('owner', (string) $response->getBody());
        $id = basename((string) self::json($response)['id']);
        self::assertSame($ada->id, $this->app()->container()->get(PostRepository::class)->find($id)?->ownerId);
    }

    public function testAUserBoundTokenChangesOnlyPostsItsUserMayChangeInTheAdmin(): void
    {
        $users = $this->site->container()->get(UserRepository::class);
        $users->create('ada', 'ada@example.com', 'correct horse battery', 'Ada', 'active', ['Authors']);
        $users->create('bob', 'bob@example.com', 'correct horse battery', 'Bob', 'active', ['Authors']);
        $ada = $this->token('blog:write', ['--user=ada']);
        $bob = $this->token('blog:write', ['--user=bob']);
        $theirs = basename((string) self::json($this->api('POST', '/api/blogs/notes/posts', $bob, ['content_html' => '<p>Bob</p>']))['id']);
        $mine = basename((string) self::json($this->api('POST', '/api/blogs/notes/posts', $ada, ['content_html' => '<p>Ada</p>']))['id']);

        self::assertSame(200, $this->api('GET', "/api/posts/{$theirs}", $ada)->getStatusCode(), 'reading stays open');
        self::assertSame(403, $this->api('PATCH', "/api/posts/{$theirs}", $ada, ['title' => 'Ada was here'])->getStatusCode());
        self::assertSame(403, $this->api('PUT', "/api/posts/{$theirs}", $ada, ['content_html' => '<p>Ada</p>'])->getStatusCode());
        self::assertSame(403, $this->api('DELETE', "/api/posts/{$theirs}", $ada)->getStatusCode());
        self::assertSame(204, $this->api('DELETE', "/api/posts/{$theirs}", $this->token)->getStatusCode(), 'a scope-only token is unchanged');
        $undelete = $this->api('POST', "/api/posts/{$theirs}/undelete", $ada);
        self::assertSame(403, $undelete->getStatusCode());
        self::assertSame('application/json', $undelete->getHeaderLine('Content-Type'));
        $post = $this->app()->container()->get(PostRepository::class)->find($theirs);
        self::assertSame(['<p>Bob</p>', 'deleted'], [$post?->contentHtml, $post?->status]);
        self::assertSame(200, $this->api('POST', "/api/posts/{$theirs}/undelete", $this->token)->getStatusCode());
        self::assertSame(200, $this->api('PATCH', "/api/posts/{$theirs}", $this->token, ['title' => 'Edited'])->getStatusCode());

        self::assertSame(200, $this->api('PATCH', "/api/posts/{$mine}", $ada, ['title' => 'Mine'])->getStatusCode());
        self::assertSame(200, $this->api('PUT', "/api/posts/{$mine}", $ada, ['content_html' => '<p>Still mine</p>'])->getStatusCode());
        self::assertSame(204, $this->api('DELETE', "/api/posts/{$mine}", $ada)->getStatusCode());
        self::assertSame(200, $this->api('POST', "/api/posts/{$mine}/undelete", $ada)->getStatusCode());
    }
}
