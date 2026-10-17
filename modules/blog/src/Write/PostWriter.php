<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Write;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\AthenaAdapter;
use Xaraya\Module\Blog\Support\Text;

/**
 * The one write path for native posts (JSON API and Micropub). Input is a JSON Feed item; it is mapped to columns,
 * doc and tags exactly as sync maps an Athena item, so ItemSerializer gives the item back. content_html is stored
 * as given (pages sanitise it when they render). Every write bumps updated_at, and item events are dispatched only
 * after the database transaction has committed.
 *
 * Titles, kinds and tags are derived by AthenaAdapter::toRecord(), which takes its title from Text::deriveTitle().
 * `tags` (names) and `_athenana.tags` ({name, slug}) are reconciled first: see reconcileTags().
 */
final class PostWriter
{
    /** sprintf() format of the 409 for a write to a mirror blog; the argument is the blog's handle. */
    public const MIRROR_MESSAGE = "Blog '%s' is a mirror: its posts come from its source";

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly ItemSerializer $serializer,
        private readonly EventDispatcher $events,
        private readonly Config $config,
    ) {}

    /** @param array<array-key, mixed> $item */
    public function create(Blog $blog, array $item, ?DateTimeImmutable $now = null): Post
    {
        $now ??= self::now();
        self::assertNative($blog);
        $item = self::withoutReserved($item);
        $errors = (new ItemValidator())->errors($item);
        foreach (['id', 'url'] as $field) {
            if (array_key_exists($field, $item)) {
                $errors[$field] = 'Is assigned by the server; leave it out';
            }
        }
        self::check($errors);
        $item['date_published'] ??= $now->format(DATE_ATOM);
        $id = Ulid::generate();
        $itemId = $this->appUrl() . '/s/' . $id;
        $this->posts->insert($blog->id, $id, $this->record($item, $itemId, $id, null, $now), $now);
        $this->events->dispatch(new ItemCreated('blog', 'post', $id, ['blog' => $blog->handle, 'item_id' => $itemId]));

        return $this->posts->find($id) ?? throw new RuntimeException("Post {$id} vanished after insert");
    }

    /**
     * Replaces the post with $item. `id` and `url` may be sent back unchanged (a GET body round-trips) but never
     * changed. A missing date_published keeps the current one.
     *
     * @param array<array-key, mixed> $item
     */
    public function replace(Post $post, array $item, ?DateTimeImmutable $now = null): Post
    {
        $now ??= self::now();
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        self::assertLive($post);
        $item = self::withoutReserved($item);
        $errors = (new ItemValidator())->errors($item);
        if (array_key_exists('id', $item) && $item['id'] !== $post->itemId) {
            $errors['id'] = 'Cannot be changed';
        }
        if (array_key_exists('url', $item) && $item['url'] !== $this->serializer->url($blog, $post, $this->appUrl())) {
            $errors['url'] = 'Is assigned by the server; leave it out or send it unchanged';
        }
        self::check($errors);
        $item['date_published'] ??= $post->datePublished->format(DATE_ATOM);
        $this->posts->rewrite($post, $this->record($item, $post->itemId, $post->id, $post->url, $now), $now);
        $this->events->dispatch(new ItemUpdated('blog', 'post', $post->id, ['blog' => $blog->handle, 'item_id' => $post->itemId]));

        return $this->reload($post);
    }

    /**
     * Applies a JSON Merge Patch (RFC 7396) to the item as the feed serves it, then replaces. A derived title
     * follows the content. Patching only `tags` rebuilds the tag list from the new names (reusing the slugs of the
     * names that stay); patching only `_athenana.tags` moves the served `tags` with it.
     *
     * @param array<array-key, mixed> $partial
     */
    public function patch(Post $post, array $partial, ?DateTimeImmutable $now = null): Post
    {
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        self::assertLive($post);
        if (array_key_exists('date_published', $partial) && $partial['date_published'] === null) {
            self::check(['date_published' => 'Cannot be removed']);
        }
        $current = $this->serializer->item($blog, $post, $this->appUrl());
        // A title that was only derived from the content (§3.1) is derived again from the patched content.
        $text = is_string($post->doc['content_text'] ?? null) ? htmlspecialchars($post->doc['content_text']) : null;
        if (!array_key_exists('title', $partial) && $post->title === Text::deriveTitle($post->kind, $post->contentHtml ?? $text, $post->externalUrl)) {
            unset($current['title']);
        }
        $patchesTags = array_key_exists('tags', $partial);
        $patchesAthenaTags = is_array($partial['_athenana'] ?? null) && array_key_exists('tags', $partial['_athenana']);
        if ($patchesTags && !$patchesAthenaTags && $partial['tags'] === null && is_array($current['_athenana'] ?? null)) {
            unset($current['_athenana']['tags']); // removing the names removes the tags
        }
        if ($patchesAthenaTags && !$patchesTags) {
            unset($current['tags']); // the served names follow the patched {name, slug} list
        }
        $merged = MergePatch::apply($current, $partial);

        return $this->replace($post, is_array($merged) ? $merged : [], $now);
    }

    /** Tombstones the post (status deleted). Deleting a deleted post changes nothing and fires no event. */
    public function delete(Post $post, ?DateTimeImmutable $now = null): Post
    {
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        if ($this->posts->setStatus($post->id, 'deleted', $now ?? self::now())) {
            $this->events->dispatch(new ItemDeleted('blog', 'post', $post->id, ['blog' => $blog->handle]));
        }

        return $this->reload($post);
    }

    /** Brings a tombstoned post back, as a resurrecting sync does (ItemUpdated). */
    public function undelete(Post $post, ?DateTimeImmutable $now = null): Post
    {
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        if ($this->posts->setStatus($post->id, 'published', $now ?? self::now())) {
            $this->events->dispatch(new ItemUpdated('blog', 'post', $post->id, ['blog' => $blog->handle, 'item_id' => $post->itemId]));
        }

        return $this->reload($post);
    }

    /** Throws the 409 for a mirror blog (MIRROR_MESSAGE); every write path starts with it. */
    public static function assertNative(Blog $blog): void
    {
        if ($blog->isMirror()) {
            throw new HttpException(409, sprintf(self::MIRROR_MESSAGE, $blog->handle));
        }
    }

    private function blogOf(Post $post): Blog
    {
        return $this->blogs->findById($post->blogId) ?? throw new RuntimeException("Blog {$post->blogId} no longer exists");
    }

    private function reload(Post $post): Post
    {
        return $this->posts->find($post->id) ?? throw new RuntimeException("Post {$post->id} vanished");
    }

    private static function assertLive(Post $post): void
    {
        if ($post->status === 'deleted') {
            throw new HttpException(409, 'The post is deleted; undelete it first');
        }
    }

    /**
     * Drops `_phoenix` (the API's read-only status flag) and top-level nulls, which mean "absent".
     *
     * @param array<array-key, mixed> $item
     * @return array<string, mixed>
     */
    private static function withoutReserved(array $item): array
    {
        unset($item['_phoenix']);
        $out = [];
        foreach ($item as $key => $value) {
            if ($value !== null) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, string> $errors */
    private static function check(array $errors): void
    {
        if ($errors !== []) {
            ksort($errors);

            throw new ValidationFailed($errors, 'Invalid item');
        }
    }

    /** @param array<string, mixed> $item a validated item with date_published set */
    private function record(array $item, string $itemId, string $id, ?string $url, DateTimeImmutable $now): PostRecord
    {
        unset($item['url']);
        $item['id'] = $itemId;
        $item = self::reconcileTags($item);
        try {
            $r = (new AthenaAdapter())->toRecord($item, $now);
        } catch (InvalidArgumentException $e) {
            throw new ValidationFailed(['item' => $e->getMessage()], 'Invalid item');
        }

        // source_hash stays null: that is what marks a post as native (PostRepository::countLiveNative()).
        return new PostRecord($itemId, $id, $r->kind, $r->title, $url, $r->externalUrl, $r->contentHtml, $r->image, $r->datePublished, $r->dateModified, $r->doc, $r->tags, null);
    }

    /**
     * AthenaAdapter takes the tags from `_athenana.tags` whenever it is there, but a served item carries both it and
     * `tags`, and a client editing the body edits `tags`. So when the `tags` names differ from the `_athenana.tags`
     * names, `_athenana.tags` is rebuilt from `tags`, keeping the slug of every name it already had. When the names
     * agree (or either field is absent), `_athenana.tags` stands, so a slug-only edit is kept.
     *
     * @param array<string, mixed> $item a validated item
     * @return array<string, mixed>
     */
    private static function reconcileTags(array $item): array
    {
        $athenana = $item['_athenana'] ?? null;
        if (!is_array($item['tags'] ?? null) || !is_array($athenana) || !is_array($athenana['tags'] ?? null)) {
            return $item;
        }
        $names = [];
        $slugs = [];
        foreach ($athenana['tags'] as $tag) {
            if (is_array($tag) && is_string($tag['name'] ?? null)) {
                $names[] = $tag['name'];
                if (is_string($tag['slug'] ?? null)) {
                    $slugs[$tag['name']] ??= $tag['slug'];
                }
            }
        }
        if ($item['tags'] === $names) {
            return $item;
        }
        $tags = [];
        foreach ($item['tags'] as $name) {
            $name = trim((string) $name);
            $tags[] = ['name' => $name, 'slug' => $slugs[$name] ?? Text::slug($name)];
        }
        $athenana['tags'] = $tags;
        $item['_athenana'] = $athenana;

        return $item;
    }

    private function appUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
