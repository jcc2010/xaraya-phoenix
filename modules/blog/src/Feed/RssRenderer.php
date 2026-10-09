<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Support\Text;

final class RssRenderer
{
    public function __construct(private readonly ItemSerializer $items) {}

    /**
     * @param list<Post> $posts
     * @param array<string, string> $media
     */
    public function render(Blog $blog, array $posts, string $selfUrl, string $appUrl, array $media = []): string
    {
        $home = $blog->homePageUrl ?? rtrim($appUrl, '/') . '/blog/' . $blog->handle;
        $x = static fn(string $s): string => htmlspecialchars(self::xmlSafe($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:media="http://search.yahoo.com/mrss/">' . "\n<channel>\n"
            . '<title>' . $x($blog->title) . "</title>\n"
            . '<link>' . $x($home) . "</link>\n"
            . '<description>' . $x(Text::plain($blog->descriptionHtml ?? '')) . "</description>\n"
            . '<language>' . $x($blog->language) . "</language>\n"
            . '<atom:link href="' . $x($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";
        if ($posts !== []) {
            $out .= '<lastBuildDate>' . $posts[0]->datePublished->format(DATE_RSS) . "</lastBuildDate>\n";
        }
        foreach ($posts as $post) {
            $item = $this->items->item($blog, $post, $appUrl, $media);
            $url = $this->items->url($blog, $post, $appUrl);
            $permalink = '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">Permalink</a></p>';
            $out .= "<item>\n"
                . '<title>' . $x($post->title) . "</title>\n"
                . '<link>' . $x($url) . "</link>\n"
                . '<guid isPermaLink="true">' . $x($url) . "</guid>\n"
                . '<description>' . $x(($post->contentHtml ?? '') . $permalink) . "</description>\n"
                . '<pubDate>' . $post->datePublished->format(DATE_RSS) . "</pubDate>\n"
                . '<dc:creator>' . $x($blog->authorName) . "</dc:creator>\n";
            foreach ($post->tags as $tag) {
                $out .= '<category domain="' . $x(rtrim($home, '/') . '/tag/' . $tag['slug']) . '">' . $x($tag['name']) . "</category>\n";
            }
            $image = $item['image'] ?? null;
            if (is_string($image)) {
                $out .= '<media:content url="' . $x($image) . '" medium="image"/>' . "\n";
            }
            $out .= "</item>\n";
        }

        return $out . "</channel>\n</rss>\n";
    }

    public static function xmlSafe(string $value): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    }
}
