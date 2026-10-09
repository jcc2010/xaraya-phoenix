<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Xaraya\Tests\Module\Blog\BlogTestCase;

final class BlogCommandsTest extends BlogTestCase
{
    public function testCreateAndList(): void
    {
        $app = $this->enableBlog();
        [$code, $out] = $this->xar([
            'blog:create', 'wyome', '--mode=mirror', '--format=athena',
            '--source=https://athenana.com/blog/wyome/feed.json',
            '--post-url=https://www.wyome.com/blog/post.html?id={id}',
        ], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString("Created mirror blog 'wyome'.", $out);

        [$code, $out] = $this->xar(['blog:create', 'notes', '--mode=native', '--title=My Notes'], $app);
        self::assertSame(0, $code);

        [$code, $out] = $this->xar(['blog:list'], $app);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/notes\s+native\s+-\s+0\s+never/', $out);
        self::assertMatchesRegularExpression('#wyome\s+mirror\s+https://athenana\.com/blog/wyome/feed\.json\s+0\s+never#', $out);
    }

    public function testErrors(): void
    {
        $app = $this->enableBlog();
        [$code, , $err] = $this->xar(['blog:create'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar blog:create <handle>', $err);

        [$code, , $err] = $this->xar(['blog:create', 'x!', '--mode=native'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Handle must match', $err);
    }
}
