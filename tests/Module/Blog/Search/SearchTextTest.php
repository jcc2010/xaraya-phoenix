<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Search;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Search\SearchText;

final class SearchTextTest extends TestCase
{
    public function testNormalizeFoldsCaseAccentsAndPunctuation(): void
    {
        self::assertSame('creme brulee', SearchText::normalize('  Crème   Brûlée!! '));
        self::assertSame('strasse aesir', SearchText::normalize('Straße — Æsir'));
        self::assertSame('100 cotton x', SearchText::normalize('100% _cotton_ !x'));
        self::assertSame('text', SearchText::normalize('日本語 text'), 'scripts with no ASCII form are dropped');
        self::assertSame('', SearchText::normalize('%_!'));
        self::assertSame('bad utf', SearchText::normalize("bad \xff utf"));
    }

    public function testTermsAreDistinctAtLeastTwoCharactersAndAtMostEight(): void
    {
        self::assertSame(['red', 'apple'], SearchText::terms('Red a APPLE red'));
        self::assertSame([], SearchText::terms('% _ ! a'));
        self::assertSame(['cd', 'ef', 'gh', 'ij', 'kl', 'mn', 'op', 'qr'], SearchText::terms('a cd ef gh ij kl mn op qr st uv'));
    }

    public function testSnippetCentresOnTheFirstMatchAndMarksEveryMatch(): void
    {
        $plain = str_repeat('lorem ipsum dolor ', 20) . 'the Crème brûlée recipe ' . str_repeat('sit amet consectetur ', 20);
        $snippet = SearchText::snippet($plain, SearchText::terms('creme BRULEE'));
        self::assertStringStartsWith('…', $snippet);
        self::assertStringEndsWith('…', $snippet);
        self::assertStringContainsString('the <mark>Crème</mark> <mark>brûlée</mark> recipe', $snippet);
        $text = strip_tags($snippet);
        self::assertLessThanOrEqual(162, mb_strlen($text), '160 characters plus the two ellipses');
        self::assertStringStartsWith('…ipsum', $text, 'cut at a word boundary');
        self::assertStringEndsWith(' sit…', $text);
    }

    public function testSnippetWithoutAMatchStartsAtTheBeginning(): void
    {
        self::assertSame('short text', SearchText::snippet('short text', ['zz']));
        self::assertSame('', SearchText::snippet('', ['zz']));
        self::assertSame(str_repeat('word ', 31) . 'word…', SearchText::snippet(str_repeat('word ', 60), ['zz']));
    }

    public function testSnippetEscapesHostileText(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;<mark>Café</mark>&quot;)&lt;/script&gt; &amp; a <mark>cafe</mark>',
            SearchText::snippet('<script>alert("Café")</script> & a cafe', ['cafe']),
        );
    }

    public function testBodyCollectsEverySearchableField(): void
    {
        $post = Post::fromRow([
            'id' => '01m3t19wn8reaww81zg1m47tjq',
            'blog_id' => 1,
            'item_id' => 'x',
            'kind' => 'link',
            'title' => 'The Title',
            'external_url' => 'https://www.Example.test/path',
            'content_html' => '<p>Body <b>text</b></p>',
            'date_published' => '2026-10-01 12:00:00',
            'status' => 'published',
            'doc' => json_encode([
                '_source' => ['name' => 'Source Name'],
                '_athenana' => [
                    'quote' => 'A quote',
                    'comment' => '<p>My comment</p>',
                    'podcast' => ['show' => 'Show', 'episode' => 'Episode'],
                    'song' => ['channel' => 'Channel'],
                ],
            ]),
        ], [['name' => 'Tag Name', 'slug' => 'tag-name']]);
        self::assertSame(
            'the title body text a quote my comment tag name source name www example test show episode channel',
            SearchText::body($post),
        );
    }
}
