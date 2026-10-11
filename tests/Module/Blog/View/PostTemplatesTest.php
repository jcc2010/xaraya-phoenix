<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\View;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Xaraya\Kernel\View\View;
use Xaraya\Module\Blog\View\PostView;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\PostViews;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

final class PostTemplatesTest extends BlogTestCase
{
    /** @param array<string, mixed> $overrides */
    private function view(string $engine, array $overrides = []): View
    {
        return $this->app(['themes.paths' => ['themes'], 'app.theme' => 'phoenix', 'app.locale' => 'en', 'view.engine' => $engine, ...$overrides])
            ->container()->get(View::class);
    }

    /** Renders the post partial with both engines, asserts identical normalised HTML and returns it. */
    private function both(PostView $post, bool $full): string
    {
        $php = Html::normalize($this->view('php')->render('blog::partials/post', ['post' => $post, 'full' => $full]));
        $twig = Html::normalize($this->view('twig')->render('blog::partials/post', ['post' => $post, 'full' => $full]));
        self::assertSame($php, $twig);

        return $php;
    }

    public function testEveryTemplateShipsInBothEngines(): void
    {
        $dir = dirname(__DIR__, 4) . '/modules/blog/templates';
        $names = ['php' => [], 'twig' => []];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            $extension = $file->getExtension();
            self::assertContains($extension, ['php', 'twig'], $file->getPathname());
            $names[$extension][] = substr($file->getPathname(), strlen($dir) + 1, -strlen($extension) - 1);
        }
        sort($names['php']);
        sort($names['twig']);
        self::assertSame($names['php'], $names['twig']);
        self::assertContains('partials/post', $names['php']);
    }

    public function testEveryKindAndExtraIsIdenticalInBothEngines(): void
    {
        $posts = PostViews::everyKind();

        $note = $this->both($posts['note'], true);
        foreach ([
            '<article class="post post-note post-pinned"><p class="post-pinned-label">Pinned</p><h1 class="post-title">A &lt;title&gt;</h1>',
            '<div class="post-content"><p>Body &amp; soul</p></div>',
            '<p class="post-song"><span class="song-channel">Rick &amp; Co</span><span class="song-duration">3:33</span></p>',
            '<footer class="post-footer"><p class="post-meta"><a class="post-permalink" href="/s/01m3t19wn8reaww81zg1m47tjq"><time datetime="2026-10-01T09:05:00+00:00">1 Oct 2026</time></a></p>',
            '<ul class="post-tags"><li><a href="/blog/notes/tag/caf%C3%A9" rel="tag">Café</a></li></ul></footer>',
            '<section class="post-mentions"><h2>Mentions</h2><ul><li><a href="https://other.test/a" rel="nofollow noopener ugc">A &quot;reply&quot;</a><span class="mention-date">3 Oct 2026</span></li>',
            '<li><a href="https://other.test/b" rel="nofollow noopener ugc">https://other.test/b</a></li></ul></section></article>',
        ] as $needle) {
            self::assertStringContainsString($needle, $note);
        }
        self::assertStringNotContainsString('post-mentions', $this->both($posts['note'], false), 'mentions show on the post page only');
        self::assertStringContainsString('<h2 class="post-title"><a href="/s/01m3t19wn8reaww81zg1m47tjq">A &lt;title&gt;</a></h2>', $this->both($posts['note'], false));

        $link = $this->both($posts['link'], false);
        self::assertStringContainsString(
            '<article class="post post-link"><h2 class="post-title"><a href="https://example.test/story?a=1&amp;b=2" rel="nofollow noopener">A &lt;title&gt;</a></h2>'
            . '<p class="post-via">via<a href="https://example.test/" rel="nofollow noopener">example.test</a></p>'
            . '<div class="post-comment"><p>My take.</p></div><div class="post-content"><p>The description.</p></div>'
            . '<p class="post-video"><a class="video-link" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" rel="nofollow noopener"><img src="https://cdn.test/t.jpg" alt="" loading="lazy" referrerpolicy="no-referrer"><span class="video-play" aria-hidden="true">▶</span><span class="visually-hidden">Watch the video</span></a></p>',
            $link,
        );
        self::assertStringContainsString('<h1 class="post-title"><a href="https://example.test/story?a=1&amp;b=2"', $this->both($posts['link'], true));

        $highlight = $this->both($posts['highlight'], true);
        self::assertStringContainsString(
            '<h1 class="post-title">A &lt;title&gt;</h1><blockquote class="post-quote"><p>To be &lt;or&gt; not</p></blockquote>'
            . '<p class="post-source">— Someone</p><div class="post-comment"><p>Indeed.</p></div>'
            . '<div class="post-podcast"><img class="podcast-cover" src="https://cdn.test/c.jpg" alt="" loading="lazy" referrerpolicy="no-referrer"><p class="podcast-show">Show</p>'
            . '<p class="podcast-episode">Ep 1</p><audio controls preload="none" src="https://cdn.test/ep.mp3"></audio><p class="podcast-duration">1:02:05</p></div>',
            $highlight,
        );

        $photo = $this->both($posts['photo'], false);
        self::assertStringContainsString(
            '<div class="gallery"><img src="https://cdn.test/a.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" width="800" height="600"><img src="https://cdn.test/b.jpg" alt="" loading="lazy" referrerpolicy="no-referrer"></div>',
            $photo,
        );
        self::assertStringNotContainsString('post-content', $photo);
        $single = $this->both(PostViews::make(['kind' => 'photo', 'images' => [['url' => 'https://cdn.test/a.jpg', 'width' => 8, 'height' => 6]]]), false);
        self::assertStringContainsString('<figure class="post-photo"><img src="https://cdn.test/a.jpg" alt="" loading="lazy" referrerpolicy="no-referrer" width="8" height="6"></figure>', $single);
        $fallback = $this->both(PostViews::make(['kind' => 'photo', 'image' => 'https://cdn.test/only.jpg']), false);
        self::assertStringContainsString('<figure class="post-photo"><img src="https://cdn.test/only.jpg" alt="" loading="lazy" referrerpolicy="no-referrer"></figure>', $fallback);

        $withImages = $posts + ['single photo' => PostViews::make(['kind' => 'photo', 'images' => [['url' => 'https://cdn.test/a.jpg', 'width' => 8, 'height' => 6]]]),
            'fallback photo' => PostViews::make(['kind' => 'photo', 'image' => 'https://cdn.test/only.jpg'])];
        $images = 0;
        foreach ($withImages as $kind => $post) {
            foreach ([true, false] as $full) {
                foreach (['php', 'twig'] as $engine) {
                    $html = Html::normalize($this->view($engine)->render('blog::partials/post', ['post' => $post, 'full' => $full]));
                    $images += substr_count($html, '<img');
                    self::assertSame(substr_count($html, '<img'), substr_count($html, ' referrerpolicy="no-referrer"'), "{$kind} ({$engine}): every image is fetched with no Referer");
                }
            }
        }
        self::assertGreaterThan(0, $images);

        foreach ($posts as $kind => $post) {
            foreach ([true, false] as $full) {
                $html = $this->both($post, $full);
                self::assertStringNotContainsString('<script', $html, $kind);
                self::assertStringNotContainsString('<iframe', $html, $kind);
                self::assertStringNotContainsString('style=', $html, $kind);
            }
        }
    }

    public function testAThemeOverrideInTheOtherEngineIsStillUsed(): void
    {
        $themes = $this->tmp . '/themes';
        Fixtures::theme($themes, 'phpkinds', ['parent' => 'phoenix', 'engine' => 'twig'], [
            'templates/modules/blog/partials/kinds/link.php' => '<p class="custom-link"><?= $x->e($post->title) ?></p>',
        ]);
        Fixtures::theme($themes, 'twigkinds', ['parent' => 'phoenix', 'engine' => 'php'], [
            'templates/modules/blog/partials/kinds/link.twig' => '<p class="custom-link">{{ post.title }}</p>',
        ]);
        $link = PostViews::everyKind()['link'];
        foreach (['phpkinds' => 'twig', 'twigkinds' => 'php'] as $theme => $engine) {
            $html = Html::normalize($this->view($engine, ['themes.paths' => [$themes, 'themes'], 'app.theme' => $theme])
                ->render('blog::partials/post', ['post' => $link, 'full' => false]));
            self::assertStringContainsString('<article class="post post-link"><p class="custom-link">A &lt;title&gt;</p>', $html, "{$theme} with {$engine}");
            self::assertStringNotContainsString('post-via', $html, $theme);
        }
    }
}
