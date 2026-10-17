<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

/** Spec §8, Plan B definition of done, end to end through the CLI and App::handle. */
final class PlanBAcceptanceTest extends MicropubTestCase
{
    /** @param array<string, mixed>|null $body */
    private function api(string $method, string $uri, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequest($method, $uri))->withHeader('Authorization', 'Bearer ' . $this->token);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody(Stream::create(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app()->handle($request);
    }

    /** @return list<array<string, mixed>> */
    private function feedItems(string $handle): array
    {
        $feed = self::body($this->app()->handle(new ServerRequest('GET', "/blog/{$handle}/feed.json")));

        return $feed['items'] ?? [];
    }

    public function testDone1APostedItemAppearsInTheFeed(): void
    {
        $this->enableTokens();
        $this->xar(['module:enable', 'micropub']);
        [$code, , $err] = $this->xar(['blog:create', 'notes', '--mode=native', '--title=Notes']);
        self::assertSame(0, $code, $err);
        $this->token = $this->token('blog:write', ['--blog=notes']);

        $response = $this->api('POST', '/api/blogs/notes/posts', ['title' => 'Done one', 'content_html' => '<p>It works.</p>']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame([['Done one', '<p>It works.</p>']], array_map(static fn(array $i): array => [$i['title'], $i['content_html']], $this->feedItems('notes')));
    }

    public function testDone2AMicropubClientWritesNotesLinksAndPhotos(): void
    {
        $this->enableMicropub();
        $note = $this->form(['h' => 'entry', 'content' => 'A note'])->getHeaderLine('Location');
        $link = $this->postJson(['type' => ['h-entry'], 'properties' => ['bookmark-of' => ['https://example.com/'], 'name' => ['Example']]])->getHeaderLine('Location');

        $path = $this->tmp . '/p.png';
        file_put_contents($path, (string) base64_decode(MediaStoreTest::PNG, true));
        $media = $this->app()->handle((new ServerRequest('POST', '/micropub/media'))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withUploadedFiles(['file' => new UploadedFile($path, (int) filesize($path), UPLOAD_ERR_OK, 'p.png', 'image/png')]));
        self::assertSame(201, $media->getStatusCode());
        $photo = $this->form(['photo' => $media->getHeaderLine('Location'), 'content' => 'Sunset'])->getHeaderLine('Location');

        $kinds = array_column($this->feedItems('notes'), '_athenana', 'url');
        self::assertSame(['note', 'link', 'photo'], [$kinds[$note]['kind'], $kinds[$link]['kind'], $kinds[$photo]['kind']]);
        self::assertSame($media->getHeaderLine('Location'), array_column($this->feedItems('notes'), 'image', 'url')[$photo]);

        foreach ([$note, $link, $photo] as $url) {
            self::assertSame(204, $this->postJson(['action' => 'update', 'url' => $url, 'replace' => ['content' => ['Edited']]])->getStatusCode());
        }
        self::assertSame(['<p>Edited</p>'], array_values(array_unique(array_column($this->feedItems('notes'), 'content_html'))));

        foreach ([$note, $link, $photo] as $url) {
            self::assertSame(204, $this->form(['action' => 'delete', 'url' => $url])->getStatusCode());
        }
        self::assertSame([], $this->feedItems('notes'));
    }

    public function testDone3TheBlogModeCutOverKeepsEveryIdAndUrl(): void
    {
        $this->enableMicropub();
        [$code, , $err] = $this->xar(['blog:create', 'src', '--mode=mirror', '--format=athena', '--source=https://src.test/blog/src/feed.json', '--post-url=https://www.example.org/post.html?id={id}']);
        self::assertSame(0, $code, $err);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->xar(['blog:sync', 'src', '--full'], $app);
        $before = array_map(static fn(array $i): array => [$i['id'], $i['url']], $this->feedItems('src'));
        self::assertCount(3, $before);
        $single = (string) $this->app()->handle(new ServerRequest('GET', '/s/' . substr((string) $before[1][1], -26) . '.json'))->getBody();

        self::assertSame(409, $this->api('POST', '/api/blogs/src/posts', ['title' => 'Too early'])->getStatusCode(), 'a mirror refuses writes');
        [$code] = $this->xar(['blog:mode', 'src', 'native']);
        self::assertSame(0, $code);

        $created = $this->api('POST', '/api/blogs/src/posts', ['title' => 'First native post', 'date_published' => '2026-10-02T12:00:00Z']);
        self::assertSame(201, $created->getStatusCode());
        $newId = substr((string) self::body($created)['id'], -26);
        $oldId = substr((string) $before[0][1], -26);
        self::assertSame(200, $this->api('PATCH', "/api/posts/{$oldId}", ['title' => 'Edited after cut-over'])->getStatusCode());
        self::assertSame(204, $this->postJson(['action' => 'update', 'url' => $before[2][1], 'replace' => ['name' => ['Edited by Micropub']]])->getStatusCode());

        $after = array_map(static fn(array $i): array => [$i['id'], $i['url']], $this->feedItems('src'));
        self::assertSame(["http://xar.test/s/{$newId}", "https://www.example.org/post.html?id={$newId}"], $after[0]);
        self::assertSame($before, array_slice($after, 1), 'mirrored ids and urls never change');
        self::assertSame(['Edited after cut-over', 'Post 1', 'Edited by Micropub'], array_column(array_slice($this->feedItems('src'), 1), 'title'));
        self::assertSame($single, (string) $this->app()->handle(new ServerRequest('GET', '/s/' . substr((string) $before[1][1], -26) . '.json'))->getBody(), 'an untouched post serves the same bytes');
    }
}
