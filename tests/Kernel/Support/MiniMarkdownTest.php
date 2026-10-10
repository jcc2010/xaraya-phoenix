<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Support\MiniMarkdown;

final class MiniMarkdownTest extends TestCase
{
    public function testParagraphsAndLineBreaks(): void
    {
        self::assertSame("<p>a<br>\nb</p>\n<p>c</p>", MiniMarkdown::toHtml("a\nb\n\nc"));
        self::assertSame('', MiniMarkdown::toHtml("  \n "));
    }

    public function testBulletLists(): void
    {
        self::assertSame('<ul><li>one</li><li><strong>two</strong></li></ul>', MiniMarkdown::toHtml("- one\n- **two**"));
        self::assertSame("<p>- a<br>\nb</p>", MiniMarkdown::toHtml("- a\nb"), 'a list needs every line to be an item');
    }

    public function testRawHtmlIsEscaped(): void
    {
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', MiniMarkdown::toHtml('<script>alert(1)</script>'));
    }

    public function testOnlySafeLinkSchemes(): void
    {
        self::assertSame(
            '<p><a href="https://x.test/a?b=1&amp;c=2">ok</a> [bad](javascript:alert(1)) [rel](//evil.test) <a href="/local">local</a></p>',
            MiniMarkdown::toHtml('[ok](https://x.test/a?b=1&c=2) [bad](javascript:alert(1)) [rel](//evil.test) [local](/local)'),
        );
        self::assertSame('<p><a href="/a&quot;b">x</a></p>', MiniMarkdown::toHtml('[x](/a"b)'));
    }

    public function testHostileInputsNeverProduceUnsafeMarkup(): void
    {
        $hostile = [
            '[x](javascript:alert(1))',
            '[x](JaVaScRiPt:alert(1))',
            '[x](&#106;avascript:alert(1))',
            '[x](javascript&#58;alert(1))',
            '[x](data:text/html,<script>alert(1)</script>)',
            '[x](vbscript:msgbox)',
            '[x](/\\evil.test)',
            '[x](//evil.test)',
            "[x](/ok\tonmouseover=alert(1))",
            '[x](/a" onmouseover="alert(1))',
            "[x](/a'onmouseover='alert(1))",
            '[<img src=x onerror=alert(1)>](/a)',
            '`<script>`',
            '**<b onclick=x>**',
            '<a href="javascript:alert(1)">x</a>',
        ];
        foreach ($hostile as $input) {
            $html = MiniMarkdown::toHtml($input);
            self::assertDoesNotMatchRegularExpression('/<(?!\/?(?:p|br|ul|li|strong|em|code|a)[ >])/i', $html, $input);
            self::assertDoesNotMatchRegularExpression('/<a [^>]*href="(?!https?:\/\/|mailto:|#|\/(?![\/\\\\]))/i', $html, $input);
            self::assertDoesNotMatchRegularExpression('/<a [^>]*href="[^"]*[\s\'<>]/', $html, $input);
            self::assertStringNotContainsString('<a ', preg_replace('/<a href="(?:https?:\/\/|mailto:|#|\/)[^"\s<>\']*">/', '', $html) ?? '', $input);
        }
        self::assertSame('<p>[x](/\\evil.test)</p>', MiniMarkdown::toHtml('[x](/\\evil.test)'));
        self::assertSame('<p>[x](&amp;#106;avascript:alert(1))</p>', MiniMarkdown::toHtml('[x](&#106;avascript:alert(1))'));
    }

    public function testEmphasisIsLinearTimeAndInputIsCapped(): void
    {
        foreach (['*a ', '**a '] as $unit) {
            $start = microtime(true);
            MiniMarkdown::toHtml(str_repeat($unit, 50000));
            self::assertLessThan(1.0, microtime(true) - $start, $unit);
        }
        self::assertSame('<p><strong>a <em>b</em> c</strong> <em>a <strong>b</strong> c</em></p>', MiniMarkdown::toHtml('**a *b* c** *a **b** c*'));
        $html = MiniMarkdown::toHtml(str_repeat('x', 100000) . 'TAIL');
        self::assertStringNotContainsString('TAIL', $html);
        self::assertSame(100000 + 7, strlen($html));
    }

    public function testControlCharactersNeverFormLinks(): void
    {
        self::assertStringNotContainsString('<a ', MiniMarkdown::toHtml("[x](/\x00/evil)"));
        self::assertStringNotContainsString('<a ', MiniMarkdown::toHtml("[x](/a\x7fb)"));
    }

    public function testCodeProtectsEmphasis(): void
    {
        self::assertSame('<p><code>**x**</code> and <em>y</em> and 2 * 3 * 4</p>', MiniMarkdown::toHtml('`**x**` and *y* and 2 * 3 * 4'));
    }
}
