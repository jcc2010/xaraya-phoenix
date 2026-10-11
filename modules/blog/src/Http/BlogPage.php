<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Support\Text;

/** What every blog page shares: the blog lookup, the <head> (title, meta, feeds, canonical, lang), blog.css and caching. */
final class BlogPage
{
    public const PAGE_SIZE = 20;

    /** @var array<int, string> blog id => sanitised description_html, for the life of this instance (one request) */
    private array $descriptions = [];

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly UrlGenerator $urls,
        private readonly AssetUrls $assets,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function blog(string $handle): Blog
    {
        return $this->blogs->find($handle) ?? throw new NotFound("No blog '{$handle}'");
    }

    /**
     * @param array<string, mixed> $data body template variables; "blog" is added
     * @param string $name the page name: "<name> · <blog title>"; '' for the blog home
     * @param ?string $description plain text; null uses the blog's description
     */
    public function page(
        Blog $blog,
        string $template,
        array $data,
        string $name,
        string $canonical,
        ?string $description = null,
        string $ogType = 'website',
        ?string $image = null,
    ): Page {
        $description ??= Text::plain($this->descriptionHtml($blog));
        $meta = [];
        if ($description !== '') {
            $meta['description'] = $description;
        }
        $meta['og:title'] = $name === '' ? $blog->title : $name;
        $meta['og:type'] = $ogType;
        $meta['og:url'] = $canonical;
        if ($image !== null) {
            $meta['og:image'] = $image;
        }

        return new Page(
            $template,
            ['blog' => $this->summary($blog), ...$data],
            title: $name === '' ? $blog->title : $name . ' · ' . $blog->title,
            meta: $meta,
            feeds: [
                ['type' => 'application/feed+json', 'title' => $blog->title, 'href' => $this->urls->generate('blog.feed.json', ['handle' => $blog->handle], true)],
                ['type' => 'application/rss+xml', 'title' => $blog->title, 'href' => $this->urls->generate('blog.feed.xml', ['handle' => $blog->handle], true)],
            ],
            canonical: $canonical,
            lang: $blog->language,
            styles: [$this->assets->url('module/blog/blog.css')],
            headers: ['Cache-Control' => 'public, max-age=60'],
        );
    }

    /** @return array{handle: string, title: string, description: string} */
    public function summary(Blog $blog): array
    {
        return ['handle' => $blog->handle, 'title' => $blog->title, 'description' => Text::plain($this->descriptionHtml($blog))];
    }

    /** The blog's description_html, sanitised (so a <script>'s text never reaches the plain-text description either). */
    public function descriptionHtml(Blog $blog): string
    {
        return $this->descriptions[$blog->id] ??= $this->sanitizer->clean($blog->descriptionHtml ?? '');
    }
}
