<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Config\Config;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;

/**
 * Finds the post a Micropub `url` names: Phoenix's own {app.url}/s/{id}[.json], a blog's post_url_pattern, or the
 * item id itself (a post mirrored before a cut-over keeps its source id). A URL that has the shape of the first two
 * but names no post there is still tried as an item id.
 */
final class PostLocator
{
    private const ULID = '([0-9a-hjkmnp-tv-z]{26})';

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly Config $config,
    ) {}

    public function find(string $url): ?Post
    {
        // Postgres text holds neither U+0000 nor bytes that are not UTF-8: no post has such an id, so do not ask.
        if (str_contains($url, "\0") || !mb_check_encoding($url, 'UTF-8')) {
            return null;
        }
        $app = preg_quote(rtrim((string) $this->config->get('app.url', ''), '/'), '#');
        if (preg_match('#^' . $app . '/s/' . self::ULID . '(?:\.json)?$#D', $url, $m) === 1) {
            $post = $this->posts->find($m[1]);
            if ($post !== null) {
                return $post;
            }
        }
        foreach ($this->blogs->all() as $blog) {
            if ($blog->postUrlPattern !== null) {
                $pattern = str_replace(preg_quote('{id}', '#'), self::ULID, preg_quote($blog->postUrlPattern, '#'));
                if (preg_match('#^' . $pattern . '$#D', $url, $m) === 1) {
                    $post = $this->posts->find($m[1]);
                    if ($post !== null && $post->blogId === $blog->id) {
                        return $post;
                    }
                }
            }
            $post = $this->posts->findByItemId($blog->id, $url);
            if ($post !== null) {
                return $post;
            }
        }

        return null;
    }
}
