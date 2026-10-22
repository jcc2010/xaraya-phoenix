<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\View;

use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\View\PostViewFactory;
use Xaraya\Tests\Support\AppTestCase;

final class PostViewFactoryTest extends AppTestCase
{
    private const ID = '01m3t19wn8reaww81zg1m47tjq';

    private function factory(): PostViewFactory
    {
        // The two routes the factory links to, as modules/blog/src/Routes.php declares them (Task 8).
        $noop = static fn(): null => null;
        $urls = new UrlGenerator([
            new Route(['GET'], '/s/{id:[0-9a-hjkmnp-tv-z]{26}}', $noop, 'blog.post'),
            new Route(['GET'], '/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', $noop, 'blog.tag'),
        ], 'http://xar.test');

        return new PostViewFactory(new HtmlSanitizer('xar.test'), new Cache($this->tmp . '/cache'), $urls, new ItemSerializer(), 'http://xar.test');
    }

    /** @param array<string, mixed> $row */
    private static function blog(array $row = []): Blog
    {
        return Blog::fromRow(['id' => 1, 'handle' => 'notes', 'title' => 'Notes', 'author_name' => 'Ada', 'mode' => 'native', ...$row]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $doc
     * @param list<array{name: string, slug: string}> $tags
     */
    private static function post(array $row = [], array $doc = [], array $tags = []): Post
    {
        return Post::fromRow([
            'id' => self::ID,
            'blog_id' => 1,
            'item_id' => 'item-1',
            'kind' => 'note',
            'title' => 'A title',
            'content_html' => '<p>Hello <script>alert(1)</script><b>world</b></p>',
            'date_published' => '2026-10-01 09:05:00',
            'updated_at' => '2026-10-02 10:00:00',
            'status' => 'published',
            'doc' => json_encode($doc, JSON_THROW_ON_ERROR),
            ...$row,
        ], $tags);
    }

    public function testANoteIsSanitisedDatedAndLinked(): void
    {
        $view = $this->factory()->make(self::blog(), self::post([], [], [
            ['name' => 'Café', 'slug' => 'café'],
            ['name' => 'Odd', 'slug' => 'a/b'],
        ]));
        self::assertSame('note', $view->kind);
        self::assertSame('A title', $view->title);
        self::assertSame('<p>Hello <b>world</b></p>', $view->contentHtml);
        self::assertSame('Hello world', $view->excerpt);
        self::assertSame('/s/' . self::ID, $view->url);
        self::assertSame('http://xar.test/s/' . self::ID, $view->canonicalUrl);
        self::assertSame('2026-10-01T09:05:00+00:00', $view->publishedIso);
        self::assertSame('1 Oct 2026', $view->publishedDisplay);
        self::assertSame([['name' => 'Café', 'slug' => 'café', 'url' => '/blog/notes/tag/caf%C3%A9']], $view->tags, 'a slug with "/" has no route and is left out');
        self::assertNull($view->source);
        self::assertNull($view->commentHtml);
        self::assertSame([], $view->images);
        self::assertNull($view->video);
        self::assertNull($view->podcast);
        self::assertNull($view->song);
        self::assertSame([], $view->mentions);
        self::assertFalse($view->pinned);
    }

    public function testExcerptIsTheFirst160CharactersOfPlainText(): void
    {
        $view = $this->factory()->make(self::blog(), self::post(['content_html' => '<p>' . str_repeat('é', 200) . '</p>']));
        self::assertSame(str_repeat('é', 160), $view->excerpt);
    }

    public function testALinkSplitsTheOwnersCommentFromTheDescription(): void
    {
        $doc = [
            '_source' => ['name' => 'example.test', 'url' => 'https://example.test/story'],
            '_athenana' => ['comment' => '<p>My take.</p>'],
        ];
        $view = $this->factory()->make(self::blog(), self::post([
            'kind' => 'link',
            'external_url' => 'https://example.test/story',
            'content_html' => "<p>My take.</p>\n<p>The description.</p>",
        ], $doc));
        self::assertSame('link', $view->kind);
        self::assertSame('https://example.test/story', $view->externalUrl);
        self::assertSame('<p>My take.</p>', $view->commentHtml);
        self::assertSame('<p>The description.</p>', $view->contentHtml);
        self::assertSame(['name' => 'example.test', 'url' => 'https://example.test/story'], $view->source);

        $kept = $this->factory()->make(self::blog(), self::post(['kind' => 'link', 'content_html' => '<p>Other text.</p>'], $doc));
        self::assertSame('<p>Other text.</p>', $kept->contentHtml, 'a comment that is not a prefix leaves the content alone');
    }

    public function testAHighlightKeepsItsQuoteAndDropsUnsafeUrls(): void
    {
        $view = $this->factory()->make(self::blog(), self::post([
            'kind' => 'highlight',
            'content_html' => null,
            'external_url' => 'javascript:alert(1)',
        ], [
            '_source' => ['name' => 'Someone', 'url' => 'javascript:alert(1)'],
            '_athenana' => ['quote' => 'To be <or> not', 'comment' => '<p onclick="x()">Yes</p>'],
        ]));
        self::assertSame('To be <or> not', $view->quote, 'plain text: templates escape it');
        self::assertSame('To be <or> not', $view->excerpt);
        self::assertSame(['name' => 'Someone', 'url' => null], $view->source);
        self::assertNull($view->externalUrl);
        self::assertSame('<p>Yes</p>', $view->commentHtml);
    }

    public function testMediaGoesThroughTheMediaMap(): void
    {
        $media = [
            'https://cdn.test/a.jpg' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta1.jpg',
            'https://cdn.test/cover.jpg' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta2.jpg',
        ];
        $view = $this->factory()->make(self::blog(), self::post(['kind' => 'photo', 'image' => 'https://cdn.test/a.jpg'], [
            '_athenana' => [
                'images' => [
                    ['url' => 'https://cdn.test/a.jpg', 'width' => 800, 'height' => 600],
                    ['url' => 'https://cdn.test/b.jpg', 'width' => '9', 'height' => null],
                    ['url' => 'data:image/png;base64,AAAA'],
                    'junk',
                ],
                'podcast' => ['show' => 'Show', 'episode' => 'Ep 1', 'duration_seconds' => 3725, 'audio_url' => 'https://cdn.test/ep.mp3', 'cover' => 'https://cdn.test/cover.jpg'],
            ],
        ]), $media);
        self::assertSame('http://xar.test/media/01m3t19wn8reaww81zg1m47ta1.jpg', $view->image);
        self::assertSame([
            ['url' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta1.jpg', 'width' => 800, 'height' => 600],
            ['url' => 'https://cdn.test/b.jpg', 'width' => null, 'height' => null],
        ], $view->images);
        self::assertSame([
            'show' => 'Show',
            'episode' => 'Ep 1',
            'durationDisplay' => '1:02:05',
            'audioUrl' => 'https://cdn.test/ep.mp3',
            'cover' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta2.jpg',
        ], $view->podcast);
    }

    public function testVideoSongAndMentions(): void
    {
        $view = $this->factory()->make(self::blog(), self::post([], [
            '_video' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'thumbnail' => 'https://cdn.test/t.jpg', 'source' => 'https://youtu.be/dQw4w9WgXcQ'],
            '_athenana' => [
                'song' => ['channel' => 'Rick Astley', 'duration_seconds' => 213],
                'mentions' => [
                    ['source' => 'https://other.test/post', 'title' => 'A reply', 'date' => '2026-10-03T08:00:00+00:00'],
                    ['source' => 'https://other.test/untitled'],
                    ['source' => 'javascript:alert(1)', 'title' => 'Bad'],
                ],
            ],
        ]));
        self::assertSame([
            'provider' => 'youtube',
            'id' => 'dQw4w9WgXcQ',
            'thumbnail' => 'https://cdn.test/t.jpg',
            'watchUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ], $view->video);
        self::assertSame(['channel' => 'Rick Astley', 'durationDisplay' => '3:33'], $view->song);
        self::assertSame([
            ['source' => 'https://other.test/post', 'title' => 'A reply', 'date' => '3 Oct 2026'],
            ['source' => 'https://other.test/untitled', 'title' => 'https://other.test/untitled', 'date' => null],
        ], $view->mentions);

        foreach ([['provider' => 'vimeo', 'id' => '123456'], ['provider' => 'youtube', 'id' => '"><script>']] as $video) {
            self::assertNull($this->factory()->make(self::blog(), self::post([], ['_video' => $video]))->video);
        }
    }

    public function testTheMirrorPatternDrivesLinksAndThePinnedFlagIsOptIn(): void
    {
        $mirror = self::blog([
            'mode' => 'mirror',
            'source_url' => 'https://src.test/feed.json',
            'source_format' => 'athena',
            'post_url_pattern' => 'https://www.example.test/post?id={id}',
            'pinned_item_id' => 'item-1',
        ]);
        $view = $this->factory()->make($mirror, self::post());
        self::assertSame('https://www.example.test/post?id=' . self::ID, $view->url);
        self::assertSame('https://www.example.test/post?id=' . self::ID, $view->canonicalUrl);
        self::assertFalse($view->pinned, 'a pinned item id alone never marks a card');
        self::assertTrue($this->factory()->make($mirror, self::post(), [], true)->pinned);

        $native = self::blog(['post_url_pattern' => 'https://www.example.test/post?id={id}']);
        $view = $this->factory()->make($native, self::post());
        self::assertSame('/s/' . self::ID, $view->url, 'only a mirror links to its pattern');
        self::assertSame('https://www.example.test/post?id=' . self::ID, $view->canonicalUrl, 'the canonical is always ItemSerializer::url()');
    }

    public function testAnUnknownKindRendersAsANote(): void
    {
        self::assertSame('note', $this->factory()->make(self::blog(), self::post(['kind' => '../../x']))->kind);
    }

    public function testSanitisedHtmlIsCachedByPostUpdateAndContent(): void
    {
        $post = self::post();
        $cache = new Cache($this->tmp . '/cache');
        $key = PostViewFactory::cacheKey($post, 'content', (string) $post->contentHtml, 'xar.test');
        $prefix = 'sanitized/v' . HtmlSanitizer::VERSION . '-' . substr(sha1('xar.test'), 0, 8) . '/';
        self::assertStringStartsWith($prefix . self::ID . '-20261002100000-content-', $key);
        self::assertFalse($cache->has($key));
        $this->factory()->make(self::blog(), $post);
        self::assertSame('<p>Hello <b>world</b></p>', $cache->get($key));

        $cache->set($key, '<p>from the cache</p>');
        self::assertSame('<p>from the cache</p>', $this->factory()->make(self::blog(), $post)->contentHtml);
        $edited = self::post(['content_html' => '<p>Edited</p>']);
        self::assertSame('<p>Edited</p>', $this->factory()->make(self::blog(), $edited)->contentHtml, 'new content, new key');
    }

    public function testAnUncachedViewWritesNothingToTheCache(): void
    {
        $view = $this->factory()->make(self::blog(), self::post(), cached: false);
        self::assertSame('<p>Hello <b>world</b></p>', $view->contentHtml);
        self::assertSame([], glob($this->tmp . '/cache/*') ?: []);
    }

    public function testAnUncachedViewIgnoresACachedEntry(): void
    {
        $post = self::post();
        (new Cache($this->tmp . '/cache'))->set(PostViewFactory::cacheKey($post, 'content', (string) $post->contentHtml, 'xar.test'), '<p>stale</p>');
        self::assertSame('<p>Hello <b>world</b></p>', $this->factory()->make(self::blog(), $post, cached: false)->contentHtml);
    }

    public function testTheCacheKeyChangesWithTheSanitiserVersionAndSiteHost(): void
    {
        $post = self::post();
        $key = PostViewFactory::cacheKey($post, 'content', 'x', 'xar.test');
        self::assertSame($key, PostViewFactory::cacheKey($post, 'content', 'x', 'XAR.test'), 'hosts compare case-insensitively');
        self::assertNotSame($key, PostViewFactory::cacheKey($post, 'content', 'x', 'other.test'), 'target=_blank depends on the host');
        self::assertNotSame($key, PostViewFactory::cacheKey($post, 'content', 'x', null));
        self::assertNotSame($key, PostViewFactory::cacheKey($post, 'content', 'x', 'xar.test', HtmlSanitizer::VERSION + 1), 'a new sanitiser version misses old entries');
    }

    public function testGarbledContentStillGivesValidUtf8(): void
    {
        $html = "<p>bad \xff bytes</p>" . str_repeat('<div>', 3000) . '<script>x</script>' . str_repeat('</div>', 3000);
        $view = $this->factory()->make(self::blog(), self::post(['content_html' => $html]));
        self::assertTrue(mb_check_encoding($view->contentHtml, 'UTF-8'));
        self::assertTrue(mb_check_encoding($view->excerpt, 'UTF-8'));
        self::assertStringNotContainsString('<script', $view->contentHtml);
    }

    public function testPlainTextIsTheSanitisedContentOrTheQuote(): void
    {
        self::assertSame('Hello world', $this->factory()->plainText(self::post()));
        self::assertSame('Q', $this->factory()->plainText(self::post(['content_html' => null], ['_athenana' => ['quote' => 'Q']])));
        self::assertSame('', $this->factory()->plainText(self::post(['content_html' => '<script>x</script>'])));
    }

    public function testDurations(): void
    {
        self::assertSame('0:59', PostViewFactory::duration(59));
        self::assertSame('1:00:00', PostViewFactory::duration(3600));
        self::assertSame('2:05', PostViewFactory::duration(124.6));
        self::assertNull(PostViewFactory::duration(0));
        self::assertNull(PostViewFactory::duration('60'));
    }

    public function testAnUnsafeFeedUrlFallsBackToTheLocalCanonical(): void
    {
        $mirror = self::blog(['mode' => 'mirror', 'source_url' => 'https://src.test/feed.json', 'source_format' => 'athena']);
        $view = $this->factory()->make($mirror, self::post(['url' => 'javascript:alert(1)']));
        self::assertSame('http://xar.test/s/' . self::ID, $view->canonicalUrl);
        $ok = $this->factory()->make($mirror, self::post(['url' => 'https://src.test/p/1']));
        self::assertSame('https://src.test/p/1', $ok->canonicalUrl);
    }

    public function testMentionDatesMustBeTimestamps(): void
    {
        $mentions = [];
        foreach (['now', '+1 week', 'tomorrow', '2026-10-03T08:00:00Z', '2026-10-03T08:00:00.5+02:00'] as $i => $date) {
            $mentions[] = ['source' => 'https://other.test/' . $i, 'date' => $date];
        }
        $view = $this->factory()->make(self::blog(), self::post([], ['_athenana' => ['mentions' => $mentions]]));
        self::assertSame([null, null, null, '3 Oct 2026', '3 Oct 2026'], array_column($view->mentions, 'date'));
    }

    public function testOverlongTagSlugsAreNotLinked(): void
    {
        $slug = str_repeat('a', 129);
        $view = $this->factory()->make(self::blog(), self::post([], [], [['name' => 'Long', 'slug' => $slug], ['name' => 'Ok', 'slug' => str_repeat('a', 128)]]));
        self::assertSame(['Ok'], array_column($view->tags, 'name'));
    }
}
