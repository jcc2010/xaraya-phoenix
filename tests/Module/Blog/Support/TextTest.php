<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Support\Text;

final class TextTest extends TestCase
{
    public function testPlain(): void
    {
        self::assertSame('Hello & welcome to the blog', Text::plain("<p>Hello &amp; <b>welcome</b></p>\n<p>to   the blog</p>"));
        self::assertSame('', Text::plain('<img src="x">'));
    }

    public function testDeriveTitle(): void
    {
        self::assertSame(str_repeat('é', 80), Text::deriveTitle('note', '<p>' . str_repeat('é', 100) . '</p>', null));
        self::assertSame('example.com/a/b', Text::deriveTitle('link', null, 'https://www.example.com/a/b/?x=1'));
        self::assertSame('Photo', Text::deriveTitle('photo', null, null));
        self::assertSame('Untitled', Text::deriveTitle('note', '<p> </p>', null));
    }

    public function testSlug(): void
    {
        self::assertSame('rock-roll', Text::slug('Rock & Roll'));
        self::assertSame('1990s', Text::slug('1990s'));
        self::assertSame(substr(sha1('日本'), 0, 8), Text::slug('日本'));
    }

    public function testCanonicalJsonSortsKeysButNotLists(): void
    {
        self::assertSame(Text::canonicalJson(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]), Text::canonicalJson(['a' => ['x' => 1, 'y' => 2], 'b' => 1]));
        self::assertNotSame(Text::canonicalJson(['l' => [1, 2]]), Text::canonicalJson(['l' => [2, 1]]));
        self::assertSame('{"f":2.0,"u":"é/x"}', Text::canonicalJson(['u' => 'é/x', 'f' => 2.0]));
    }
}
