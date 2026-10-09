<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Media\MediaLocalizer;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Post\Post;

final class MediaUrls
{
    public function __construct(private readonly MediaStore $store, private readonly UrlGenerator $urls) {}

    /**
     * @param list<Post> $posts
     * @return array<string, string> source media URL => absolute local URL
     */
    public function map(Blog $blog, array $posts): array
    {
        if ($blog->media !== 'local' || $posts === []) {
            return [];
        }
        $sources = [];
        foreach ($posts as $post) {
            array_push($sources, ...MediaLocalizer::urls($post));
        }
        $map = [];
        foreach ($this->store->lookup($blog->id, array_values(array_unique($sources))) as $url => $media) {
            $map[$url] = $this->urls->generate('blog.media', ['id' => $media['id'], 'ext' => $media['ext']], true);
        }

        return $map;
    }
}
