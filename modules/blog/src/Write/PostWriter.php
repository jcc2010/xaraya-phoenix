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
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\AthenaAdapter;

/**
 * The one write path for native posts (JSON API and Micropub). Input is a JSON Feed item; it is mapped to columns,
 * doc and tags exactly as sync maps an Athena item, so ItemSerializer gives the item back. content_html is stored
 * as given (pages sanitise it when they render). Every write bumps updated_at, and item events are dispatched only
 * after the database transaction has committed.
 *
 * Titles, kinds and tags are derived by AthenaAdapter::toRecord(), which takes its title from Text::deriveTitle().
 */
final class PostWriter
{
    /** sprintf() format of the 409 for a write to a mirror blog; the argument is the blog's handle. */
    public const MIRROR_MESSAGE = "Blog '%s' is a mirror: its posts come from its source";

    public function __construct(
        private readonly PostRepository $posts,
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

    /** Throws the 409 for a mirror blog (MIRROR_MESSAGE); every write path starts with it. */
    public static function assertNative(Blog $blog): void
    {
        if ($blog->isMirror()) {
            throw new HttpException(409, sprintf(self::MIRROR_MESSAGE, $blog->handle));
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
        try {
            $r = (new AthenaAdapter())->toRecord($item, $now);
        } catch (InvalidArgumentException $e) {
            throw new ValidationFailed(['item' => $e->getMessage()], 'Invalid item');
        }

        // source_hash stays null: that is what marks a post as native (PostRepository::countLiveNative()).
        return new PostRecord($itemId, $id, $r->kind, $r->title, $url, $r->externalUrl, $r->contentHtml, $r->image, $r->datePublished, $r->dateModified, $r->doc, $r->tags, null);
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
