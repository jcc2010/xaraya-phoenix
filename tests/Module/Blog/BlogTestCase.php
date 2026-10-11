<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Tests\Support\AppTestCase;

abstract class BlogTestCase extends AppTestCase
{
    /** @param array<string, mixed> $overrides */
    protected function app(array $overrides = []): App
    {
        return $this->boot([dirname(__DIR__, 3) . '/modules'], [
            'blog.locks' => $this->tmp . '/locks',
            'blog.uploads' => $this->tmp . '/uploads',
            ...$overrides,
        ]);
    }

    protected function enableBlog(): App
    {
        [$code, $out, $err] = $this->xar(['module:enable', 'blog']);
        self::assertSame(0, $code, $out . $err);

        return $this->app();
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    protected function xar(array $args, ?App $app = null): array
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        $err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        $code = (new Application($app ?? $this->app()))->run(['xar', ...$args], new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    protected function db(App $app): Connection
    {
        return $app->container()->get(Connection::class);
    }

    protected function blogs(App $app): BlogRepository
    {
        return $app->container()->get(BlogRepository::class);
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Creates a native blog, "notes" unless $fields says otherwise.
     *
     * @param array<string, mixed> $fields
     */
    protected function nativeBlog(App $app, array $fields = []): Blog
    {
        return $this->blogs($app)->create([
            'handle' => 'notes',
            'mode' => 'native',
            'title' => 'Notes',
            'author_name' => 'Ada',
            'description_html' => '<p>Short <b>notes</b> &amp; links.</p>',
            ...$fields,
        ], $this->now());
    }

    /** Saves a post and dispatches ItemCreated, as sync does; returns the post id. */
    protected function addPost(App $app, Blog $blog, PostRecord $record): string
    {
        $id = $app->container()->get(PostRepository::class)->save($blog->id, $record, $this->now())->id;
        $app->container()->get(EventDispatcher::class)->dispatch(
            new ItemCreated('blog', 'post', $id, ['blog' => $blog->handle, 'item_id' => $record->itemId]),
        );

        return $id;
    }

    /**
     * Post $n of a test blog: "Post {n}", item id "item-{n}", published {n} hours before 1 Oct 2026 12:00 UTC.
     * Keys of $o: date, itemId, kind, title, url, externalUrl, content, image, doc, tags.
     *
     * @param array<string, mixed> $o
     */
    protected static function postRecord(int $n, array $o = []): PostRecord
    {
        $date = $o['date'] ?? (new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC')))->modify("-{$n} hours");
        /** @var list<array{name: string, slug: string}> $tags */
        $tags = $o['tags'] ?? [];
        /** @var array<string, mixed> $doc */
        $doc = $o['doc'] ?? [];

        return new PostRecord(
            (string) ($o['itemId'] ?? "item-{$n}"),
            null,
            (string) ($o['kind'] ?? 'note'),
            (string) ($o['title'] ?? "Post {$n}"),
            isset($o['url']) ? (string) $o['url'] : null,
            isset($o['externalUrl']) ? (string) $o['externalUrl'] : null,
            array_key_exists('content', $o) ? ($o['content'] === null ? null : (string) $o['content']) : "<p>Body {$n}</p>",
            isset($o['image']) ? (string) $o['image'] : null,
            $date instanceof DateTimeImmutable ? $date : new DateTimeImmutable((string) $date, new DateTimeZone('UTC')),
            null,
            $doc,
            $tags,
            null,
        );
    }
}
