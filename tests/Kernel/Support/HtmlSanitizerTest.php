<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Tests\Support\AppTestCase;

final class HtmlSanitizerTest extends AppTestCase
{
    private function clean(string $html): string
    {
        return (new HtmlSanitizer('xar.test'))->clean($html);
    }

    /** @return array<string, array{string, string}> */
    public static function vectors(): array
    {
        return [
            'script' => ['<script>alert(1)</script><p>ok</p>', '<p>ok</p>'],
            'img onerror' => ['<img src="/x.png" onerror="alert(1)">', '<img src="/x.png" referrerpolicy="no-referrer">'],
            'img referrerpolicy forced' => ['<img src="/x.png" referrerpolicy="unsafe-url">', '<img src="/x.png" referrerpolicy="no-referrer">'],
            'img referrerpolicy first' => ['<img referrerpolicy="origin" src="/x.png" alt="a">', '<img src="/x.png" alt="a" referrerpolicy="no-referrer">'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'entity-encoded javascript' => ['<a href="&#106;avascript:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'tab-split javascript' => ['<a href="java&#x09;script:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'space-led javascript' => ['<a href=" javascript:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'upper-case javascript' => ['<a href="JAVASCRIPT:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'svg onload' => ['<svg onload="alert(1)"><circle/></svg>after', 'after'],
            'data href' => ['<a href="data:text/html,xx">d</a>', '<a rel="nofollow noopener ugc">d</a>'],
            'data img' => ['<img src="data:image/png;base64,AAAA"><p>z</p>', '<p>z</p>'],
            'vbscript cite' => ['<blockquote cite="vbscript:x"><p>q</p></blockquote>', '<blockquote><p>q</p></blockquote>'],
            'style, class and id' => ['<p style="color:red" class="c" id="i">s</p>', '<p>s</p>'],
            'iframe and form' => ['<iframe src="https://x.test"></iframe><form><input name="a"><button>b</button></form>', ''],
            'dropped with content' => ['<noscript><p>n</p></noscript><template><p>t</p></template><math><mi>x</mi></math><object>o</object>', ''],
            'nested and broken' => ['<p>unclosed <b>bold <i>it</p> tail', '<p>unclosed <b>bold <i>it</i></b></p> tail'],
            'stray end tags' => ['</p></div><p>stray</p>', '<p>stray</p>'],
            'comment' => ['<!-- secret --><p>c</p>', '<p>c</p>'],
            'h1 demoted' => ['<h1>T</h1>', '<h2>T</h2>'],
            'unknown unwrapped' => ['<custom-el><b>kept</b></custom-el><table><tr><td>cell</td></tr></table>', '<b>kept</b>cell'],
            'allowed attributes' => [
                '<img src="/x.png" width="10" height="20px" alt="a" title="t"><q cite="https://c.test">q</q>',
                '<img src="/x.png" width="10" alt="a" referrerpolicy="no-referrer"><q cite="https://c.test">q</q>',
            ],
            'escaped text stays escaped' => ['<p>a &lt;b&gt; &amp; c</p>', '<p>a &lt;b&gt; &amp; c</p>'],
            'query string ampersand' => ['<a href="/s?a=1&amp;b=2">q</a>', '<a href="/s?a=1&amp;b=2" rel="nofollow noopener ugc">q</a>'],
            'empty' => ['  ', ''],
            'empty img src' => ['<img src=""><img src="  "><p>i</p>', '<p>i</p>'],
            'meta windows-1252' => ['<p>ok é</p><meta http-equiv="content-type" content="text/html;charset=windows-1252"><p>é</p>', '<p>ok é</p><p>é</p>'],
            'meta utf-7' => ['<meta charset="UTF-7"><p>a é +ADw-b</p>', '<p>a é +ADw-b</p>'],
        ];
    }

    #[DataProvider('vectors')]
    public function testVector(string $input, string $expected): void
    {
        $clean = $this->clean($input);
        self::assertSame($expected, $clean);
        self::assertSame($clean, $this->clean($clean), 'clean() must be idempotent');
    }

    public function testLinksGetRelAndExternalLinksOpenInANewTab(): void
    {
        self::assertSame(
            '<a href="https://ext.test/a" rel="nofollow noopener ugc" target="_blank">e</a>'
            . '<a href="/local" rel="nofollow noopener ugc">l</a>'
            . '<a href="mailto:a@b.test" rel="nofollow noopener ugc">m</a>'
            . '<a href="https://XAR.test/x" rel="nofollow noopener ugc">s</a>',
            $this->clean('<a href="https://ext.test/a" target="_self" rel="me">e</a><a href="/local">l</a><a href="mailto:a@b.test">m</a><a href="https://XAR.test/x">s</a>'),
        );
        self::assertSame('<img src="/x.png" referrerpolicy="no-referrer">', $this->clean('<img src="/x.png" href="mailto:a@b.test">'));
        self::assertSame('<blockquote>q</blockquote>', $this->clean('<blockquote cite="mailto:a@b.test">q</blockquote>'), 'mailto: is for href only');
        self::assertSame(
            '<a href="https://xar.test/" rel="nofollow noopener ugc" target="_blank">a</a>',
            (new HtmlSanitizer())->clean('<a href="https://xar.test/">a</a>'),
            'with no site host every absolute link is external',
        );
    }

    public function testExternalLinkWithLeadingWhitespaceIsIdempotent(): void
    {
        $clean = $this->clean("<a href=\" https://ext.test/\">x</a><a href=\"https://ex&#x09;t.test/\">y</a>");
        self::assertSame(
            '<a href="https://ext.test/" rel="nofollow noopener ugc" target="_blank">x</a>'
            . '<a href="https://ex%09t.test/" rel="nofollow noopener ugc" target="_blank">y</a>',
            $clean,
        );
        self::assertSame($clean, $this->clean($clean));
    }

    public function testUtf8RoundTrips(): void
    {
        $html = '<p>Café — naïve “quotes” 日本語 😀</p>';
        self::assertSame($html, $this->clean($html));
    }

    public function testBrokenUtf8AndNulBytesComeBackAsValidUtf8(): void
    {
        $clean = $this->clean("<p>bad \xff\xfe bytes\0 here</p>");
        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertStringStartsWith('<p>bad ', $clean);
        self::assertStringEndsWith(' bytes here</p>', $clean);
        self::assertSame($clean, $this->clean($clean));
    }

    public function testHugeNestingIsCutAndStaysSafe(): void
    {
        $html = str_repeat('<span>', 5000) . 'deep<script>alert(1)</script>' . str_repeat('</span>', 5000) . '<p>after</p>';
        $clean = $this->clean($html);
        self::assertStringNotContainsString('script', $clean);
        self::assertLessThanOrEqual(HtmlSanitizer::MAX_DEPTH, substr_count($clean, '<span>'));
        self::assertSame($clean, $this->clean($clean));
    }

    public function testIsSafeUrl(): void
    {
        foreach (['https://a.test/x', 'http://a.test', '/root', 'relative/path', '#frag', '?q=1', '//cdn.test/x'] as $url) {
            self::assertTrue(HtmlSanitizer::isSafeUrl($url), $url);
        }
        foreach (['javascript:x', ' JavaScript:x', "java\nscript:x", 'data:x', 'vbscript:x', 'file:///etc/passwd', 'mailto:a@b.test'] as $url) {
            self::assertFalse(HtmlSanitizer::isSafeUrl($url), $url);
        }
        self::assertTrue(HtmlSanitizer::isSafeUrl('mailto:a@b.test', true));
        foreach (["javascript:alert(1)\xff", "javascript:alert(1)\xc0", "\xffjavascript:x"] as $url) {
            self::assertFalse(HtmlSanitizer::isSafeUrl($url), bin2hex($url));
        }
    }

    public function testTheSiteHostIsExposedForCacheKeys(): void
    {
        self::assertSame('xar.test', (new HtmlSanitizer('xar.test'))->siteHost());
        self::assertNull((new HtmlSanitizer())->siteHost());
    }

    public function testTheAppBindsItWithTheSiteHost(): void
    {
        $app = $this->boot([], ['app.url' => 'https://blog.example.test/sub']);
        self::assertSame(
            '<a href="https://BLOG.example.test/a" rel="nofollow noopener ugc">x</a>',
            $app->container()->get(HtmlSanitizer::class)->clean('<a href="https://BLOG.example.test/a">x</a>'),
        );
    }
}
