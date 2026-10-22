<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Write;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;

final class PostOwnerTest extends PostWriterTestCase
{
    private function actAs(?User $user): void
    {
        $this->app->container()->get(Auth::class)->actAs($user);
    }

    private function author(string $handle): User
    {
        return $this->app->container()->get(UserRepository::class)
            ->create($handle, $handle . '@example.com', 'correct horse battery', ucfirst($handle), 'active', ['Authors']);
    }

    private function find(string $id): ?Post
    {
        return $this->app->container()->get(PostRepository::class)->find($id);
    }

    public function testCreateRecordsTheActingUserAndLaterWritesKeepIt(): void
    {
        $ada = $this->author('ada');
        $this->actAs($ada);
        $post = $this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>']);
        self::assertSame($ada->id, $post->ownerId);
        $this->actAs(null);
        $post = $this->writer()->patch($post, ['title' => 'Edited']);
        $post = $this->writer()->delete($post);
        $post = $this->writer()->undelete($post);
        self::assertSame($ada->id, $post->ownerId, 'patch, delete and undelete keep the owner');
    }

    public function testAGuestWriteAndASyncedPostHaveNoOwner(): void
    {
        self::assertNull($this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>'])->ownerId);
        $id = $this->addPost($this->app, $this->blog, self::postRecord(1));
        self::assertNull($this->find($id)?->ownerId);
    }

    public function testDeletingTheUserKeepsThePostWithoutAnOwner(): void
    {
        $ada = $this->author('ada');
        $this->actAs($ada);
        $post = $this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>']);
        $this->db($this->app)->delete('users', ['id' => $ada->id]);
        $reloaded = $this->find($post->id);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->ownerId);
    }

    public function testTheOwnerNeverReachesTheServedItemFeedsOrRss(): void
    {
        $ada = $this->author('ada');
        $this->actAs($ada);
        $post = $this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>', 'tags' => ['x']]);
        $this->actAs(null);
        self::assertArrayNotHasKey('owner_id', $this->served($post));
        foreach (['/blog/notes/feed.json', '/blog/notes/feed.xml', '/s/' . $post->id . '.json'] as $uri) {
            $response = $this->app->handle(new ServerRequest('GET', $uri));
            self::assertSame(200, $response->getStatusCode(), $uri);
            self::assertStringNotContainsString($ada->id, (string) $response->getBody(), $uri);
            self::assertStringNotContainsString('owner_id', (string) $response->getBody(), $uri);
        }
    }

    public function testTheAuthorsOwnGrantNowMatchesTheirPosts(): void
    {
        $ada = $this->author('ada');
        $bob = $this->author('bob');
        $this->actAs($bob);
        $bobs = $this->writer()->create($this->blog, ['content_html' => '<p>Bob</p>']);
        $this->actAs($ada);
        $adas = $this->writer()->create($this->blog, ['content_html' => '<p>Ada</p>']);
        $auth = $this->app->container()->get(Auth::class);
        self::assertTrue($auth->can('delete', 'blog', 'post', ['id' => $adas->id, 'owner_id' => $adas->ownerId]));
        self::assertFalse($auth->can('edit', 'blog', 'post', ['id' => $bobs->id, 'owner_id' => $bobs->ownerId]));
    }
}
