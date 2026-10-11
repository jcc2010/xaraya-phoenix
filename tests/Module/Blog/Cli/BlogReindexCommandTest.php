<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class BlogReindexCommandTest extends BlogTestCase
{
    public function testRebuildsOneBlogOrAll(): void
    {
        $app = $this->enableBlog();
        $mirror = $this->blogs($app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena',
        ], $this->now());
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(250))));
        $app->container()->get(Syncer::class)->sync($mirror, true, $this->now());
        $notes = $this->nativeBlog($app);
        $this->addPost($app, $notes, self::postRecord(1, ['title' => 'Scheduled', 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $notes, self::postRecord(2, ['title' => 'Deleted']));
        $app->container()->get(PostRepository::class)->tombstone($notes->id, ['item-2'], $this->now());

        // An install from before the index existed: the table is empty.
        $this->db($app)->pdo()->exec('DELETE FROM ' . $this->db($app)->table('post_search'));
        self::assertSame(0, $app->container()->get(SearchIndex::class)->search($mirror->id, 'post', 20, 0)['total']);

        [$code, $out, $err] = $this->xar(['blog:reindex', 'src'], $app);
        self::assertSame(0, $code, $err);
        self::assertSame("src: indexed 250 post(s)\n", $out);
        self::assertSame(250, $app->container()->get(SearchIndex::class)->search($mirror->id, 'post', 20, 0)['total']);

        [$code, $out] = $this->xar(['blog:reindex', '--all'], $app);
        self::assertSame(0, $code);
        self::assertSame("notes: indexed 1 post(s)\nsrc: indexed 250 post(s)\n", $out, 'the scheduled post is indexed (search hides it until it is due); the deleted one is not');
        self::assertSame(251, $this->db($app)->select('post_search')->count());
    }

    public function testErrors(): void
    {
        $app = $this->enableBlog();
        [$code, , $err] = $this->xar(['blog:reindex'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar blog:reindex <handle>|--all', $err);

        [$code, , $err] = $this->xar(['blog:reindex', 'nope'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'nope'", $err);
    }
}
