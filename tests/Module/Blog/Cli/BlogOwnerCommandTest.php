<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class BlogOwnerCommandTest extends BlogTestCase
{
    private function user(App $app, string $handle, string $status = 'active'): User
    {
        return $app->container()->get(UserRepository::class)
            ->create($handle, $handle . '@example.com', 'correct horse battery', ucfirst($handle), $status, ['Authors']);
    }

    private function owner(App $app, string $postId): ?string
    {
        return $app->container()->get(PostRepository::class)->find($postId)?->ownerId;
    }

    private function setOwner(App $app, string $postId, ?string $ownerId): void
    {
        $this->db($app)->update('posts', ['owner_id' => $ownerId], ['id' => $postId]);
    }

    /** @return array{0: App, 1: Blog, 2: list<string>} a native blog with three ownerless posts, the second deleted */
    private function blogWithPosts(): array
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $ids = [];
        foreach ([1, 2, 3] as $n) {
            $ids[] = $this->addPost($app, $blog, self::postRecord($n));
        }
        $app->container()->get(PostRepository::class)->setStatus($ids[1], 'deleted', $this->now());

        return [$app, $blog, $ids];
    }

    public function testAssignsOnlyOwnerlessPostsOfEveryStatus(): void
    {
        [$app, , $ids] = $this->blogWithPosts();
        $ada = $this->user($app, 'ada');
        $bob = $this->user($app, 'bob');
        $this->setOwner($app, $ids[2], $bob->id);

        [$code, $out, $err] = $this->xar(['blog:owner', 'notes', '--user=ada'], $app);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("Assigned 2 post(s) in blog 'notes' to 'ada'.", $out);
        self::assertSame($ada->id, $this->owner($app, $ids[0]));
        self::assertSame($ada->id, $this->owner($app, $ids[1]), 'deleted posts are included');
        self::assertSame($bob->id, $this->owner($app, $ids[2]), 'an existing owner is kept');

        [$code, $out] = $this->xar(['blog:owner', 'notes', '--user=ada'], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString("Assigned 0 post(s) in blog 'notes' to 'ada'.", $out);
    }

    public function testOnlyTouchesTheNamedBlog(): void
    {
        [$app, , $ids] = $this->blogWithPosts();
        $other = $this->nativeBlog($app, ['handle' => 'other']);
        $elsewhere = $this->addPost($app, $other, self::postRecord(9, ['itemId' => 'other-9']));
        $this->user($app, 'ada');

        self::assertSame(0, $this->xar(['blog:owner', 'notes', '--user=ada'], $app)[0]);
        self::assertNotNull($this->owner($app, $ids[0]));
        self::assertNull($this->owner($app, $elsewhere));
    }

    public function testAllReassignsEveryPost(): void
    {
        [$app, , $ids] = $this->blogWithPosts();
        $ada = $this->user($app, 'ada');
        $bob = $this->user($app, 'bob');
        $this->setOwner($app, $ids[2], $bob->id);

        [$code, $out] = $this->xar(['blog:owner', 'notes', '--user=ada', '--all'], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString("Assigned 3 post(s) in blog 'notes' to 'ada'.", $out);
        foreach ($ids as $id) {
            self::assertSame($ada->id, $this->owner($app, $id));
        }
    }

    public function testIdsAssignsTheListedPostsEvenWhenOwned(): void
    {
        [$app, , $ids] = $this->blogWithPosts();
        $ada = $this->user($app, 'ada');
        $bob = $this->user($app, 'bob');
        $this->setOwner($app, $ids[2], $bob->id);

        [$code, $out] = $this->xar(['blog:owner', 'notes', '--user=ada', "--ids={$ids[0]}, {$ids[2]}"], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString("Assigned 2 post(s) in blog 'notes' to 'ada'.", $out);
        self::assertSame($ada->id, $this->owner($app, $ids[0]));
        self::assertNull($this->owner($app, $ids[1]));
        self::assertSame($ada->id, $this->owner($app, $ids[2]));
    }

    public function testIdsOutsideTheBlogChangeNothing(): void
    {
        [$app, , $ids] = $this->blogWithPosts();
        $other = $this->nativeBlog($app, ['handle' => 'other']);
        $elsewhere = $this->addPost($app, $other, self::postRecord(9, ['itemId' => 'other-9']));
        $this->user($app, 'ada');

        foreach ([$elsewhere, '01JUNKNOWNPOSTID0000000000'] as $stranger) {
            [$code, , $err] = $this->xar(['blog:owner', 'notes', '--user=ada', "--ids={$ids[0]},{$stranger}"], $app);
            self::assertSame(1, $code);
            self::assertStringContainsString("No post '{$stranger}' in blog 'notes'", $err);
            self::assertNull($this->owner($app, $ids[0]), 'nothing is assigned when any id is wrong');
        }
        self::assertNull($this->owner($app, $elsewhere));
    }

    public function testDryRunChangesNothing(): void
    {
        [$app, , $ids] = $this->blogWithPosts();
        $this->user($app, 'ada');

        [$code, $out] = $this->xar(['blog:owner', 'notes', '--user=ada', '--dry-run'], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString("Would assign 3 post(s) in blog 'notes' to 'ada'.", $out);
        foreach ($ids as $id) {
            self::assertNull($this->owner($app, $id));
        }
    }

    public function testASyncKeepsTheOwner(): void
    {
        $app = $this->enableBlog();
        $this->xar(['blog:create', 'src', '--mode=mirror', '--format=athena', '--source=https://src.test/blog/src/feed.json'], $app);
        $items = FeedFactory::items(2);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages($items)));
        $this->xar(['blog:sync', 'src', '--full'], $app);
        $ada = $this->user($app, 'ada');

        self::assertSame(0, $this->xar(['blog:owner', 'src', '--user=ada'], $app)[0]);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages($items)));
        self::assertSame(0, $this->xar(['blog:sync', 'src', '--full'], $app)[0]);
        $owners = array_column($this->db($app)->select('posts')->all(), 'owner_id');
        self::assertSame([$ada->id, $ada->id], $owners);
    }

    public function testUsageAndLookupErrors(): void
    {
        $app = $this->enableBlog();
        $this->nativeBlog($app);
        $this->user($app, 'gone', 'disabled');
        $cases = [
            [['blog:owner'], 'Usage: xar blog:owner'],
            [['blog:owner', 'notes'], 'Usage: xar blog:owner'],
            [['blog:owner', 'notes', '--user=ada', '--all', '--ids=x'], '--all and --ids cannot be combined'],
            [['blog:owner', 'notes', '--user=ada', '--ids= , '], '--ids needs at least one post id'],
            [['blog:owner', 'ghost', '--user=ada'], "Unknown blog 'ghost'"],
            [['blog:owner', 'notes', '--user=ada'], "Unknown user 'ada'"],
            [['blog:owner', 'notes', '--user=gone'], "User 'gone' is disabled"],
        ];
        foreach ($cases as [$args, $message]) {
            [$code, , $err] = $this->xar($args, $app);
            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err, implode(' ', $args));
        }
    }
}
