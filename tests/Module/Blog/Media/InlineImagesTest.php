<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Media;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Media\InlineImages;

final class InlineImagesTest extends TestCase
{
    public function testSourcesFindsEveryQuotingStyleOnceAndDecodesEntities(): void
    {
        $html = '<p>a</p><img src="https://a.test/1.png" alt=""><IMG alt=x SRC=\'https://a.test/2.png?x=1&amp;y=2\'>'
            . '<img src=https://a.test/3.png><img src="https://a.test/1.png"><img src="/local.png"><img src="data:image/png;base64,AA">'
            . '<img data-src="https://a.test/lazy.png"><p>src="https://a.test/text.png"</p>';

        self::assertSame(
            ['https://a.test/1.png', 'https://a.test/2.png?x=1&y=2', 'https://a.test/3.png'],
            InlineImages::sources($html),
        );
        self::assertSame([], InlineImages::sources('<p>no images</p>'));
    }

    public function testRewriteReplacesOnlyMappedSrcValues(): void
    {
        $html = '<p>x</p><img src="https://a.test/1.png" alt="one" /><img alt="two" src=\'https://a.test/2.png?x=1&amp;y=2\'>'
            . '<img src="https://a.test/kept.png"><a href="https://a.test/1.png">link</a>';
        $media = [
            'https://a.test/1.png' => 'https://me.test/media/A.png',
            'https://a.test/2.png?x=1&y=2' => 'https://me.test/media/B.png?"<',
        ];

        self::assertSame(
            '<p>x</p><img src="https://me.test/media/A.png" alt="one" /><img alt="two" src="https://me.test/media/B.png?&quot;&lt;">'
            . '<img src="https://a.test/kept.png"><a href="https://a.test/1.png">link</a>',
            InlineImages::rewrite($html, $media),
        );
        self::assertSame($html, InlineImages::rewrite($html, []));
    }

    public function testSrcInsideAnotherAttributeIsNotTheSrc(): void
    {
        $html = '<img alt=" src=http://a.test/x.jpg" src="http://b.test/y.jpg"><img title=\'x src="http://a.test/x.jpg"\' src="http://c.test/z.jpg">';
        self::assertSame(['http://b.test/y.jpg', 'http://c.test/z.jpg'], InlineImages::sources($html));
        self::assertSame(
            '<img alt=" src=http://a.test/x.jpg" src="L1"><img title=\'x src="http://a.test/x.jpg"\' src="http://c.test/z.jpg">',
            InlineImages::rewrite($html, ['http://b.test/y.jpg' => 'L1', 'http://a.test/x.jpg' => 'BAD']),
        );
    }

    public function testQuotedGreaterThanAndSlashSeparatedSrc(): void
    {
        $html = '<img alt="a>b" src="http://a.test/1.png"><img/src="http://a.test/2.png"><img src="http://a.test/3.png"/>';
        self::assertSame(['http://a.test/1.png', 'http://a.test/2.png', 'http://a.test/3.png'], InlineImages::sources($html));
        self::assertSame(
            '<img alt="a>b" src="L1"><img/src="L2"><img src="L3"/>',
            InlineImages::rewrite($html, ['http://a.test/1.png' => 'L1', 'http://a.test/2.png' => 'L2', 'http://a.test/3.png' => 'L3']),
        );
        self::assertSame([], InlineImages::sources('<imgx src="http://a.test/1.png"><img src><img data-src="http://a.test/4.png">'));
    }
}
