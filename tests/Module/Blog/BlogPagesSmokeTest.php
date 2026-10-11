<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use DOMDocument;
use LibXMLError;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\Http\BlogPageTestCase;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

/** Definition of done: the recorded Athena blog (150 posts) browsed end to end in both engines. */
final class BlogPagesSmokeTest extends BlogPageTestCase
{
    private const SOURCE = 'https://athenana.com/blog/wyome/feed.json';
    private const DIR = __DIR__ . '/../../fixtures/athena';

    /** libxml2's HTML 4 parser reports every HTML5 element (main, article, time, ...) as unknown: code 801. */
    private const HTML5_TAG_UNKNOWN = 801;

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::DIR . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private function mirrorAthena(): void
    {
        $pages = [self::fixture('page-1'), self::fixture('page-2'), self::fixture('page-3')];
        $app = $this->enableBlog();
        [$code, $out, $err] = $this->xar([
            'blog:create', 'wyome', '--mode=mirror', '--format=athena', '--source=' . self::SOURCE,
            '--post-url=https://www.wyome.com/blog/post.html?id={id}',
        ], $app);
        self::assertSame(0, $code, $out . $err);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, (new FixtureFetcher())
            ->on(self::SOURCE, FixtureFetcher::json($pages[0]))
            ->on((string) $pages[0]['next_url'], FixtureFetcher::json($pages[1]))
            ->on((string) $pages[1]['next_url'], FixtureFetcher::json($pages[2])));
        $blog = $this->blogs($app)->find('wyome') ?? self::fail('no blog');
        self::assertSame(150, $app->container()->get(Syncer::class)->sync($blog, true, $this->now())->created);
        [$code, $out] = $this->xar(['blog:reindex', '--all'], $this->app());
        self::assertSame(0, $code);
        self::assertSame("wyome: indexed 150 post(s)\n", $out);
    }

    /**
     * The id of the first post (by id) that $match accepts.
     *
     * @param callable(Post): bool $match
     */
    private function postId(callable $match): string
    {
        $app = $this->app();
        $blog = $this->blogs($app)->find('wyome') ?? self::fail('no blog');
        foreach ($app->container()->get(PostRepository::class)->publishedAfter($blog->id, null, 200) as $post) {
            if ($match($post)) {
                return $post->id;
            }
        }
        self::fail('no matching post');
    }

    private static function assertParses(string $html, string $uri): void
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML($html);
            $errors = array_values(array_filter(libxml_get_errors(), static fn(LibXMLError $e): bool => $e->code !== self::HTML5_TAG_UNKNOWN));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        self::assertSame([], array_map(static fn(LibXMLError $e): string => "line {$e->line}: " . trim($e->message), $errors), $uri);
    }

    public function testEveryPageOfTheMirroredAthenaBlogRenders(): void
    {
        $this->mirrorAthena();
        $uris = [
            '/blog/wyome',
            '/s/' . $this->postId(static fn(Post $p): bool => $p->kind === 'note'),
            '/s/' . $this->postId(static fn(Post $p): bool => $p->kind === 'link' && isset($p->doc['_source'])),
            '/s/' . $this->postId(static fn(Post $p): bool => isset($p->doc['_video'])),
            '/s/' . $this->postId(static fn(Post $p): bool => isset($p->doc['_athenana']['song'])),
            '/blog/wyome/tag/travel',
            '/blog/wyome/tags',
            '/blog/wyome/about',
            '/blog/wyome/search?q=visa',
        ];
        preg_match('#<a class="pager-next" rel="next" href="([^"]+)"#', (string) $this->get('/blog/wyome')->getBody(), $next);
        $uris[] = html_entity_decode($next[1] ?? self::fail('no second page'));
        foreach ($uris as $uri) {
            [$response, $html] = $this->both($uri);
            self::assertSecureHtml($response);
            self::assertParses((string) $response->getBody(), $uri);
            self::assertStringNotContainsString('<script', $html, $uri);
            self::assertStringNotContainsString('<iframe', $html, $uri);
            self::assertStringNotContainsString(' style=', $html, $uri);
        }
        $home = self::main($this->both('/blog/wyome')[1]);
        self::assertSame(20, substr_count($home, '<article '));
        self::assertStringContainsString('<a class="post-permalink" href="https://www.wyome.com/blog/post.html?id=', $home, 'drop-in: cards link the canonical post page');
        self::assertStringContainsString('<p class="post-via">via<a href=', $home);
        self::assertStringContainsString('<a href="https://athenana.com/blog/wyome/subscribe">Subscribe by email</a>', self::main($this->both('/blog/wyome/about')[1]));
        self::assertMatchesRegularExpression('#<p class="search-count">[1-9]\d* results?</p>#', self::main($this->both('/blog/wyome/search?q=visa')[1]));
        self::assertStringContainsString('<p class="post-video"><a class="video-link" href="https://www.youtube.com/watch?v=', $this->both('/s/' . $this->postId(static fn(Post $p): bool => isset($p->doc['_video'])))[1]);
    }

    public function testAThemeOverridesTheLinkPartialInEitherEngineWithoutModuleChanges(): void
    {
        $this->mirrorAthena();
        Fixtures::theme($this->tmp . '/themes', 'custom', ['parent' => 'phoenix', 'engine' => 'php'], [
            'templates/modules/blog/partials/kinds/link.php' => '<p class="custom-link"><?= $x->e($post->title) ?></p>',
        ]);
        foreach (['php', 'twig'] as $engine) {
            $app = $this->site($engine, ['themes.paths' => [$this->tmp . '/themes', 'themes'], 'app.theme' => 'custom']);
            $html = Html::normalize((string) $app->handle(new ServerRequest('GET', '/blog/wyome'))->getBody());
            self::assertStringContainsString('<article class="post post-link"><p class="custom-link">', $html, $engine);
            self::assertStringNotContainsString('post-via', $html, $engine);
        }
    }
}
