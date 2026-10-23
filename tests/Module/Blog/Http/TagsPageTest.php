<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Module\Blog\Post\PostRepository;

final class TagsPageTest extends BlogPageTestCase
{
    public function testTheCloudCountsLivePostsAndSortsByName(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $tag = static fn(string $name): array => ['name' => $name, 'slug' => strtolower($name)];
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => [$tag('Travel'), $tag('Food')]]));
        $this->addPost($app, $blog, self::postRecord(2, ['tags' => [$tag('Travel')]]));
        $this->addPost($app, $blog, self::postRecord(3, ['tags' => [$tag('Travel'), $tag('Art')]]));
        $this->addPost($app, $blog, self::postRecord(4, ['tags' => [$tag('Hidden'), $tag('Travel')], 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(5, ['tags' => [$tag('Gone'), $tag('Food')]]));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-5'], $this->now());
        $other = $this->nativeBlog($app, ['handle' => 'other']);
        $this->addPost($app, $other, self::postRecord(6, ['tags' => [$tag('Elsewhere')]]));

        [$response, $html] = $this->both('/blog/notes/tags');
        self::assertSecureHtml($response);
        self::assertStringContainsString('<title>Tags · Notes</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/tags">', $html);
        self::assertStringContainsString(
            '<h1 class="blog-title">Tags</h1></header><ul class="tag-cloud">'
            . '<li class="tag-step-1"><a href="/blog/notes/tag/art" rel="tag">Art</a><span class="tag-count">1</span></li>'
            . '<li class="tag-step-1"><a href="/blog/notes/tag/food" rel="tag">Food</a><span class="tag-count">1</span></li>'
            . '<li class="tag-step-5"><a href="/blog/notes/tag/travel" rel="tag">Travel</a><span class="tag-count">3</span></li></ul>',
            self::main($html),
        );
        foreach (['Hidden', 'Gone', 'Elsewhere'] as $absent) {
            self::assertStringNotContainsString($absent, self::main($html));
        }
    }

    public function testASlugSpelledTwoWaysShowsItsSmallestNameOnEveryDatabase(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => [['name' => 'php', 'slug' => 'php']]]));
        $this->addPost($app, $blog, self::postRecord(2, ['tags' => [['name' => 'PHP', 'slug' => 'php']]]));
        $this->addPost($app, $blog, self::postRecord(3, ['tags' => [['name' => 'Php', 'slug' => 'php'], ['name' => 'Ten', 'slug' => '10'], ['name' => 'Nine', 'slug' => '9']]]));

        $counts = $app->container()->get(PostRepository::class)->tagCounts($blog->id, $this->now());
        self::assertSame([
            ['name' => 'PHP', 'slug' => 'php', 'count' => 3],
            ['name' => 'Ten', 'slug' => '10', 'count' => 1],
            ['name' => 'Nine', 'slug' => '9', 'count' => 1],
        ], $counts, 'byte order, not the collation: "PHP" < "Php" < "php", and slug "10" before "9"');
        self::assertSame([['name' => 'PHP', 'slug' => 'php', 'count' => 3]], $app->container()->get(PostRepository::class)->tagCounts($blog->id, $this->now(), 1));
    }

    public function testTagsNoRouteCanMatchAreLeftOutOfTheCloud(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $long = str_repeat('é', 65); // 65 characters fit the column, but it is 130 bytes
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => [
            ['name' => 'Fine', 'slug' => 'fine'],
            ['name' => 'Slashed', 'slug' => 'a/b'],
            ['name' => 'Long', 'slug' => $long],
        ]]));

        [, $html] = $this->both('/blog/notes/tags');
        self::assertStringContainsString('<a href="/blog/notes/tag/fine" rel="tag">Fine</a>', self::main($html));
        self::assertStringNotContainsString('Slashed', self::main($html));
        self::assertStringNotContainsString('Long', self::main($html));
        self::assertStringNotContainsString('a/b', self::main($html));
    }

    public function testABlogWithNoTags(): void
    {
        $app = $this->enableBlog();
        $this->nativeBlog($app);
        [, $html] = $this->both('/blog/notes/tags');
        self::assertStringContainsString('<p class="blog-empty">No tags yet.</p>', self::main($html));
        $this->both('/blog/nope/tags', 404);
    }
}
