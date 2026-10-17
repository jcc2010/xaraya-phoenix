<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;

final class MicropubQueryTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    public function testConfigListsTheNativeBlogsTheTokenMayUse(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        $this->blogs($this->app())->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $response = $this->query(['q' => 'config']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'media-endpoint' => 'http://xar.test/micropub/media',
            'destination' => [['uid' => 'notes', 'name' => 'Notes'], ['uid' => 'other', 'name' => 'Other']],
            'syndicate-to' => [],
        ], self::body($response));
        self::assertSame([['uid' => 'other', 'name' => 'Other']], self::body($this->query(['q' => 'config'], $this->token('blog:write', ['--blog=other'])))['destination']);
    }

    public function testSyndicateToIsEmpty(): void
    {
        self::assertSame(['syndicate-to' => []], self::body($this->query(['q' => 'syndicate-to'])));
    }

    public function testSourceReturnsTheEntryOrTheAskedProperties(): void
    {
        $note = $this->form(['content' => 'A note', 'category' => ['x'], 'published' => '2026-10-01T10:00:00Z'])->getHeaderLine('Location');
        self::assertSame(['type' => ['h-entry'], 'properties' => [
            'content' => [['html' => '<p>A note</p>', 'value' => 'A note']],
            'category' => ['x'],
            'published' => ['2026-10-01T10:00:00+00:00'],
        ]], self::body($this->query(['q' => 'source', 'url' => $note])));

        $link = $this->form(['name' => 'Named', 'bookmark-of' => 'https://example.com/', 'photo' => ['https://img.test/a.jpg', 'https://img.test/b.jpg']])->getHeaderLine('Location');
        $properties = self::body($this->query(['q' => 'source', 'url' => $link]))['properties'];
        self::assertSame(['Named'], $properties['name']);
        self::assertSame(['https://example.com/'], $properties['bookmark-of']);
        self::assertSame(['https://img.test/a.jpg', 'https://img.test/b.jpg'], $properties['photo']);

        $response = $this->app()->handle((new ServerRequest('GET', '/micropub?q=source&url=' . rawurlencode($link) . '&properties[]=name&properties[]=photo'))->withHeader('Authorization', 'Bearer ' . $this->token));
        self::assertSame(['properties' => ['name' => ['Named'], 'photo' => ['https://img.test/a.jpg', 'https://img.test/b.jpg']]], self::body($response));
    }

    public function testSourceWithNoMatchingPropertiesIsAnEmptyObject(): void
    {
        $note = $this->form(['content' => 'A note'])->getHeaderLine('Location');
        $response = $this->app()->handle((new ServerRequest('GET', '/micropub?q=source&url=' . rawurlencode($note) . '&properties[]=photo'))->withHeader('Authorization', 'Bearer ' . $this->token));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"properties":{}}', (string) $response->getBody());
    }

    public function testAUrlWithU0000OrBadUtf8NamesNoPost(): void
    {
        foreach (["https://src.test/a\0b", "https://src.test/a\xffb"] as $url) {
            self::assertMicropubError($this->query(['q' => 'source', 'url' => $url]), 400, 'invalid_request', 'No post at ' . mb_scrub($url, 'UTF-8'));
        }
        self::assertMicropubError($this->form(['action' => 'delete', 'url' => "https://src.test/a\0b"]), 400, 'invalid_request', "No post at https://src.test/a\0b");
    }

    public function testAGetIgnoresAFormBodyToken(): void
    {
        $response = $this->app()->handle((new ServerRequest('GET', '/micropub?q=config'))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody(Stream::create('access_token=' . $this->token)));
        self::assertMicropubError($response, 401, 'unauthorized', 'A bearer token is required');
    }

    public function testSourceHonoursTheTokensBlogPin(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        $note = $this->form(['content' => 'In other', 'mp-destination' => 'other'])->getHeaderLine('Location');
        self::assertSame(200, $this->query(['q' => 'source', 'url' => $note], $this->token('blog:write', ['--blog=other']))->getStatusCode());
        self::assertMicropubError($this->query(['q' => 'source', 'url' => $note], $this->token('blog:write', ['--blog=notes'])), 403, 'forbidden');
    }

    public function testSourceOfADeletedPostIsA400(): void
    {
        $note = $this->form(['content' => 'Soon gone'])->getHeaderLine('Location');
        self::assertSame(204, $this->postJson(['action' => 'delete', 'url' => $note])->getStatusCode());
        self::assertMicropubError($this->query(['q' => 'source', 'url' => $note]), 400, 'invalid_request', 'No post at ' . $note);
    }

    public function testBadQueriesAre400AndATokenIsRequired(): void
    {
        self::assertMicropubError($this->query(['q' => 'source']), 400, 'invalid_request', 'q=source needs a url');
        self::assertMicropubError($this->query(['q' => 'source', 'url' => 'https://nowhere.test/']), 400, 'invalid_request', 'No post at https://nowhere.test/');
        self::assertMicropubError($this->query(['q' => 'channels']), 400, 'invalid_request', "Unknown query 'channels'");
        self::assertMicropubError($this->query([]), 400, 'invalid_request', 'Send q=config, q=source or q=syndicate-to');
        self::assertMicropubError($this->app()->handle(new ServerRequest('GET', '/micropub?q=config')), 401, 'unauthorized', 'A bearer token is required');
        self::assertMicropubError($this->app()->handle(new ServerRequest('GET', '/micropub?q=config&access_token=' . $this->token)), 401, 'unauthorized');
    }
}
