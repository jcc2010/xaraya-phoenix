<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Write;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Support\Text;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Tests\Module\Blog\BlogTestCase;

abstract class PostWriterTestCase extends BlogTestCase
{
    protected App $app;
    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->blog = $this->nativeBlog($this->app);
    }

    protected function writer(): PostWriter
    {
        return $this->app->container()->get(PostWriter::class);
    }

    /** @return array<string, mixed> the post as the feed serves it */
    protected function served(Post $post): array
    {
        return $this->app->container()->get(ItemSerializer::class)->item($this->blog, $post, 'http://xar.test');
    }

    /**
     * Decoded JSON compared without regard to key order (spec §2.2).
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    protected static function assertSameItem(array $expected, array $actual): void
    {
        self::assertSame(Text::canonicalJson($expected), Text::canonicalJson($actual));
    }

    /** @return array<string, string> the fields of the ValidationFailed that $write throws */
    protected static function fieldErrors(callable $write): array
    {
        try {
            $write();
        } catch (ValidationFailed $e) {
            self::assertSame(422, $e->status());

            return $e->fields();
        }
        self::fail('expected a 422');
    }
}
