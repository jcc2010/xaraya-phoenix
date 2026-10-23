<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use RuntimeException;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Http\Api\JsonBody;
use Xaraya\Module\Micropub\Destination;
use Xaraya\Module\Micropub\Mf2Mapper;
use Xaraya\Module\Tokens\Token;

final class MicropubCreateTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    public function testAFormNoteBecomesAPost(): void
    {
        $post = $this->created($this->form(['h' => 'entry', 'content' => "Hello <world>\nline two\n\nSecond", 'category' => ['indieweb', 'Micro Pub']]));
        self::assertSame('note', $post->kind);
        self::assertSame('<p>Hello &lt;world&gt;<br>' . "\n" . 'line two</p><p>Second</p>', $post->contentHtml);
        self::assertSame([['name' => 'indieweb', 'slug' => 'indieweb'], ['name' => 'Micro Pub', 'slug' => 'micro-pub']], $post->tags);
        self::assertSame('Hello <world> line two Second', $post->title);
    }

    public function testAJsonLinkBecomesALinkPost(): void
    {
        $post = $this->created($this->postJson(['type' => ['h-entry'], 'properties' => [
            'bookmark-of' => ['https://example.com/article'],
            'name' => ['A good read'],
            'content' => [['html' => '<p>Worth it.</p>']],
            'published' => ['2026-10-10T09:00:00+02:00'],
        ]]));
        self::assertSame(['link', 'https://example.com/article', 'A good read', '<p>Worth it.</p>'], [$post->kind, $post->externalUrl, $post->title, $post->contentHtml]);
        self::assertSame('2026-10-10 07:00:00', $post->datePublished->format('Y-m-d H:i:s'));
    }

    public function testJsonPhotosFillImageAndImages(): void
    {
        $post = $this->created($this->postJson(['type' => ['h-entry'], 'properties' => [
            'photo' => ['https://img.test/1.jpg', ['value' => 'https://img.test/2.jpg', 'alt' => 'Two']],
        ]]));
        self::assertSame(['photo', 'https://img.test/1.jpg', 'Photo'], [$post->kind, $post->image, $post->title]);
        self::assertSame([['url' => 'https://img.test/1.jpg'], ['url' => 'https://img.test/2.jpg']], $post->doc['_athenana']['images'] ?? null);
    }

    public function testFormPhotoListAndHtmlContent(): void
    {
        $post = $this->created($this->form(['photo' => ['https://img.test/a.jpg'], 'content' => ['html' => '<p><b>Hi</b></p>']]));
        self::assertSame(['photo', '<p><b>Hi</b></p>'], [$post->kind, $post->contentHtml]);
    }

    public function testTheTokenMayComeAsAFormField(): void
    {
        $post = $this->created($this->form(['content' => 'Via field', 'access_token' => $this->token], ''));
        self::assertSame('<p>Via field</p>', $post->contentHtml);
        self::assertMicropubError($this->form(['content' => 'Both', 'access_token' => $this->token]), 400, 'invalid_request', 'Send the token in the Authorization header or in access_token, not both');
        self::assertMicropubError($this->form(['content' => 'Query'], '', '/micropub?access_token=' . $this->token), 401, 'unauthorized');
        self::assertMicropubError($this->form(['content' => 'Bad', 'access_token' => 'nope'], ''), 401, 'unauthorized', 'The token is invalid or expired');
    }

    public function testTheTokenNeverAppearsInAResponseAPostOrALog(): void
    {
        $bad = 'not-a-real-token-' . bin2hex(random_bytes(8));
        $responses = [
            $this->form(['content' => 'Field', 'access_token' => $this->token], ''),
            $this->form(['content' => 'Both', 'access_token' => $this->token]),
            $this->form(['content' => 'Bad', 'access_token' => $bad], ''),
            $this->form(['h' => 'card', 'access_token' => $this->token], ''),
        ];
        // A listener that throws is logged with its trace (the create itself still succeeds).
        $app = $this->app();
        $app->container()->get(EventDispatcher::class)->listen(ItemCreated::class, static function (): never {
            throw new RuntimeException('listener exploded');
        });
        $request = (new ServerRequest('POST', '/micropub'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody(Stream::create(http_build_query(['content' => 'Logged?', 'access_token' => $this->token])))
            ->withParsedBody(['content' => 'Logged?', 'access_token' => $this->token]);
        $created = $app->handle($request);
        self::assertSame(201, $created->getStatusCode());
        $responses[] = $created;

        $log = implode('', array_map('file_get_contents', glob($this->tmp . '/logs/*') ?: []));
        self::assertStringContainsString('listener exploded', $log, 'the failure was logged');
        self::assertStringContainsString('MicropubController', $log, 'the log holds the trace');
        $posts = json_encode($this->db($app)->select('posts')->all(), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Logged?', $posts, 'the posts were stored');
        // The first characters of each secret: a full token, and the 15-character prefix a trace argument shows.
        foreach ([substr($this->token, 4, 11), substr($bad, 4, 11)] as $secret) {
            self::assertStringNotContainsString($secret, $log);
            self::assertStringNotContainsString($secret, $posts);
            foreach ($responses as $response) {
                $headers = implode("\n", array_map(static fn(array $v): string => implode(',', $v), $response->getHeaders()));
                self::assertStringNotContainsString($secret, $headers . (string) $response->getBody());
            }
        }
    }

    public function testInvalidUtf8IsA400(): void
    {
        self::assertMicropubError($this->form(['content' => 'x', 'mp-destination' => "no\xff"]), 400, 'invalid_request', 'mp-destination must be valid UTF-8');
        self::assertMicropubError($this->form(['action' => "x\xff", 'url' => 'http://xar.test/s/x']), 400, 'invalid_request', 'action must be valid UTF-8');
        self::assertMicropubError($this->form(['content' => "bad\xff", 'name' => 'Named']), 400, 'invalid_request', 'content must be valid UTF-8');
        self::assertMicropubError($this->form(['category' => ['ok', "bad\xff"], 'content' => 'x']), 400, 'invalid_request', 'category must be valid UTF-8');
        self::assertMicropubError($this->form(['content' => 'x', 'mp-destination' => 'Not A Handle']), 400, 'invalid_request', "Unknown destination 'Not A Handle'");
        try {
            Mf2Mapper::textToHtml("bad\xff");
            self::fail('textToHtml accepted invalid UTF-8');
        } catch (HttpException $e) {
            self::assertSame([400, 'content must be valid UTF-8'], [$e->status(), $e->getMessage()]);
        }
        // The backstop: input that reaches an error message unchecked is scrubbed, never a 500.
        try {
            $this->app()->container()->get(Destination::class)->choose("no\xff", Token::fromRow(['id' => 't', 'name' => 't', 'scopes' => '["blog:write"]']));
            self::fail('choose accepted an invalid handle');
        } catch (HttpException $e) {
            self::assertSame("Unknown destination 'no?'", $e->getMessage());
        }
    }

    public function testU0000IsA400(): void
    {
        $response = $this->app()->handle((new ServerRequest('POST', '/micropub'))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody(Stream::create('h=entry&content=x%00y')));
        self::assertMicropubError($response, 400, 'invalid_request', 'content_html: Must not contain U+0000');
        self::assertMicropubError($this->postJson(['type' => ['h-entry'], 'properties' => ['name' => ["a\0"], 'content' => ['x']]]), 400, 'invalid_request', 'title: Must not contain U+0000');
        self::assertSame(0, (int) $this->db($this->app())->fetchValue('SELECT COUNT(*) FROM {posts}'));
        // A leading U+0000 is whitespace to trim(), so plain-text content drops it rather than failing.
        self::assertSame('<p>x</p>', $this->created($this->form(['content' => "\0x"]))->contentHtml);
    }

    public function testAnUploadedFileIsRejected(): void
    {
        $request = (new ServerRequest('POST', '/micropub'))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withParsedBody(['content' => 'With a photo'])
            ->withUploadedFiles(['photo' => new UploadedFile(Stream::create('GIF89a'), 6, UPLOAD_ERR_OK, 'a.gif', 'image/gif')]);
        self::assertMicropubError($this->app()->handle($request), 400, 'invalid_request', 'Upload files to the media endpoint');
    }

    public function testANonStringJsonDestinationIs400(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        foreach ([[5], [''], []] as $destination) {
            $response = $this->postJson(['type' => ['h-entry'], 'properties' => ['content' => ['x'], 'mp-destination' => $destination]]);
            self::assertMicropubError($response, 400, 'invalid_request', 'mp-destination must be a string');
        }
    }

    public function testAnOversizedJsonBodyIs400(): void
    {
        $response = $this->postJson(['type' => ['h-entry'], 'properties' => ['content' => [str_repeat('a', JsonBody::MAX_BYTES)]]]);
        self::assertMicropubError($response, 400, 'invalid_request', 'The body is larger than ' . JsonBody::MAX_BYTES . ' bytes');
    }

    public function testScopeErrorsUseMicropubCodes(): void
    {
        $response = $this->form(['content' => 'x'], $this->token('media:write'));
        self::assertMicropubError($response, 403, 'insufficient_scope', 'The token lacks the blog:write scope');
        self::assertSame('Bearer error="insufficient_scope", scope="blog:write"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testTheDestinationIsChosenAndPinsAreKept(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        self::assertMicropubError($this->form(['content' => 'Where?']), 400, 'invalid_request', 'Choose a blog with mp-destination (q=config lists them)');
        self::assertSame($this->blogs($this->app())->find('other')?->id, $this->created($this->form(['content' => 'x', 'mp-destination' => 'other']))->blogId);
        self::assertSame($this->blogs($this->app())->find('other')?->id, $this->created($this->postJson(['type' => ['h-entry'], 'properties' => ['content' => ['x'], 'mp-destination' => ['other']]]))->blogId);
        self::assertMicropubError($this->form(['content' => 'x', 'mp-destination' => 'nope']), 400, 'invalid_request', "Unknown destination 'nope'");

        $pinned = $this->token('blog:write', ['--blog=notes']);
        self::assertSame($this->blogs($this->app())->find('notes')?->id, $this->created($this->form(['content' => 'pinned'], $pinned))->blogId);
        self::assertMicropubError($this->form(['content' => 'x', 'mp-destination' => 'other'], $pinned), 403, 'forbidden');
    }

    public function testInvalidRequestsAre400(): void
    {
        self::assertMicropubError($this->form(['h' => 'card', 'name' => 'Me']), 400, 'invalid_request', 'Only h-entry posts are supported');
        self::assertMicropubError($this->form(['content' => 'x', 'published' => 'yesterday']), 400, 'invalid_request', 'date_published: Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00');
        self::assertMicropubError($this->form(['bookmark-of' => 'javascript:alert(1)']), 400, 'invalid_request', 'external_url: Must be an absolute http(s) URL of at most 2048 bytes');
        self::assertMicropubError($this->postJson(['type' => ['h-entry'], 'properties' => ['content' => [5]]]), 400, 'invalid_request', 'content must be text or {"html": "…"}');
        self::assertMicropubError($this->postJson(['type' => ['h-entry'], 'properties' => ['content' => 'not a list']]), 400, 'invalid_request', 'Property content must be a list of values');
        self::assertMicropubError($this->form([]), 400, 'invalid_request', 'content_html: An item needs content_html, content_text, a title, an external_url or an image');
        self::assertMicropubError($this->form(['action' => 'explode', 'url' => 'http://xar.test/s/x']), 400, 'invalid_request', "Unknown action 'explode'");
        $xml = $this->app()->handle((new ServerRequest('POST', '/micropub'))->withHeader('Authorization', 'Bearer ' . $this->token)->withHeader('Content-Type', 'text/xml')->withBody(Stream::create('<x/>')));
        self::assertMicropubError($xml, 400, 'invalid_request');
    }

    public function testFromItemKeepsANameOnlyWhenTheTitleIsNotDerived(): void
    {
        $item = ['title' => 'Hi', 'content_html' => '<p>Hi</p>', 'tags' => ['a'], '_athenana' => ['kind' => 'note']];
        self::assertSame(['content' => [['html' => '<p>Hi</p>', 'value' => 'Hi']], 'category' => ['a']], (new Mf2Mapper())->fromItem($item, true));
        self::assertSame(['Hi'], (new Mf2Mapper())->fromItem($item, false)['name'] ?? null);
    }

    public function testTextToHtml(): void
    {
        self::assertSame('<p>a &amp; b</p>', Mf2Mapper::textToHtml(" a & b \n"));
        self::assertSame('', Mf2Mapper::textToHtml("\n\n"));
    }

    public function testAUserBoundTokenNeedsAddToCreate(): void
    {
        $this->app()->container()->get(UserRepository::class)->create('mo', 'mo@example.com', 'correct horse battery', 'Mo', 'active', []);
        self::assertMicropubError($this->form(['h' => 'entry', 'content' => 'Hi'], $this->token('blog:write', ['--user=mo'])), 403, 'forbidden');
    }
}
