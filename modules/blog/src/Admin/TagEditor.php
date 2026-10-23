<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Admin;

use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Support\Text;
use Xaraya\Module\Blog\Write\ItemValidator;
use Xaraya\Module\Blog\Write\PostWriter;

/**
 * Renames or removes one tag across a blog. Each affected post is patched through PostWriter with its new
 * `_athenana.tags`, so post_tags, the served `tags`, the search index (ItemUpdated) and the feed stay as a single
 * edit leaves them; date_modified is not bumped. Deleted posts are not touched (the writer refuses them) and keep
 * their old tags. Renaming to a slug another tag has merges the two: a post that ends up with the slug twice keeps
 * the first entry by position.
 *
 * There is no surrounding transaction: PostWriter dispatches ItemUpdated as it goes, and item events must not run
 * inside an open transaction (SearchIndexer). Instead every patch is previewed first, so a post the writer would
 * refuse stops the edit before anything is written.
 */
final class TagEditor
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostWriter $writer,
    ) {}

    /**
     * Field errors for a new name and slug (already trimmed); an empty slug is derived from the name.
     *
     * @return array<string, string>
     */
    public static function errors(string $name, string $slug): array
    {
        $errors = [];
        if (!mb_check_encoding($name, 'UTF-8') || preg_match('/\p{Cc}/u', $name) === 1) {
            $errors['name'] = 'Name must be valid UTF-8 without control characters';
        } elseif ($name === '') {
            $errors['name'] = 'Name is required';
        } elseif (mb_strlen($name) > ItemValidator::MAX_TAG) {
            $errors['name'] = 'Name must be at most ' . ItemValidator::MAX_TAG . ' characters';
        }
        if ($slug !== '' && preg_match(ItemValidator::TAG_SLUG, $slug) !== 1) {
            $errors['slug'] = 'Slug must be 1 to 128 characters of a-z, 0-9 and -';
        }

        return $errors;
    }

    /**
     * Gives the tag $slug the name $name and the slug $newSlug (derived from $name when empty) on every
     * non-deleted post of $blog; returns how many posts changed.
     *
     * @throws ValidationFailed for a bad name or slug
     */
    public function rename(Blog $blog, string $slug, string $name, string $newSlug): int
    {
        $name = trim($name);
        $newSlug = trim($newSlug);
        $errors = self::errors($name, $newSlug);
        if ($errors !== []) {
            throw new ValidationFailed($errors, 'Invalid tag');
        }
        $newSlug = $newSlug === '' ? Text::slug($name) : $newSlug;

        return $this->apply($blog, $slug, static fn(array $tag): array => $tag['slug'] === $slug ? ['name' => $name, 'slug' => $newSlug] : $tag);
    }

    /** Takes the tag $slug off every non-deleted post of $blog; returns how many posts changed. */
    public function remove(Blog $blog, string $slug): int
    {
        return $this->apply($blog, $slug, static fn(array $tag): ?array => $tag['slug'] === $slug ? null : $tag);
    }

    /**
     * Maps the tags of every live post carrying $slug through $map (null drops a tag), first slug wins.
     *
     * @param callable(array{name: string, slug: string}): ?array{name: string, slug: string} $map
     */
    private function apply(Blog $blog, string $slug, callable $map): int
    {
        PostWriter::assertNative($blog);
        $posts = mb_check_encoding($slug, 'UTF-8') ? $this->posts->liveWithTag($blog->id, $slug) : [];
        if ($posts === []) {
            throw new NotFound("No tag '{$slug}'");
        }
        $patches = [];
        foreach ($posts as $post) {
            $patches[] = [$post, self::patch($post, $map)];
        }
        foreach ($patches as [$post, $patch]) {
            $this->writer->previewPatch($post, $patch);
        }
        $changed = 0;
        foreach ($patches as [$post, $patch]) {
            $changed += $this->writer->patch($post, $patch) === $post ? 0 : 1;
        }

        return $changed;
    }

    /**
     * The merge patch that gives $post its mapped tags; no tags at all removes `_athenana.tags`, as on a post
     * that never had any.
     *
     * @param callable(array{name: string, slug: string}): ?array{name: string, slug: string} $map
     * @return array<string, mixed>
     */
    private static function patch(Post $post, callable $map): array
    {
        $tags = [];
        foreach ($post->tags as $tag) {
            $tag = $map($tag);
            if ($tag !== null) {
                $tags[$tag['slug']] ??= $tag;
            }
        }

        return ['_athenana' => ['tags' => $tags === [] ? null : array_values($tags)]];
    }
}
