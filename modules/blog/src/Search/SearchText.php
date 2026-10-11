<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use Normalizer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Support\Text;

/**
 * Search normalisation: lower-case ASCII letters and digits separated by single spaces. The same per-character
 * fold builds the index body, the query terms and the snippet highlighting, so the three always agree.
 */
final class SearchText
{
    public const MAX_TERMS = 8;
    public const MIN_TERM_LENGTH = 2;

    /** @var array<string, string> folded forms of multi-byte characters */
    private static array $folds = [];

    public static function normalize(string $text): string
    {
        return self::fold($text)[0];
    }

    /** @return list<string> distinct terms of at least 2 characters, at most 8, in query order */
    public static function terms(string $query): array
    {
        $terms = [];
        foreach (explode(' ', self::normalize($query)) as $term) {
            if (strlen($term) >= self::MIN_TERM_LENGTH && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /** The normalised text a post is found by. */
    public static function body(Post $post): string
    {
        $doc = $post->doc;
        $athenana = is_array($doc['_athenana'] ?? null) ? $doc['_athenana'] : [];
        $parts = [$post->title, Text::plain($post->contentHtml ?? '')];
        $parts[] = self::string($athenana, 'quote');
        $parts[] = Text::plain(self::string($athenana, 'comment'));
        foreach ($post->tags as $tag) {
            $parts[] = $tag['name'];
        }
        $source = is_array($doc['_source'] ?? null) ? $doc['_source'] : [];
        $parts[] = self::string($source, 'name');
        $host = $post->externalUrl === null ? null : parse_url($post->externalUrl, PHP_URL_HOST);
        $parts[] = is_string($host) ? $host : '';
        $podcast = is_array($athenana['podcast'] ?? null) ? $athenana['podcast'] : [];
        $parts[] = self::string($podcast, 'show');
        $parts[] = self::string($podcast, 'episode');
        $song = is_array($athenana['song'] ?? null) ? $athenana['song'] : [];
        $parts[] = self::string($song, 'channel');

        return self::normalize(implode(' ', $parts));
    }

    /**
     * A plain-text window of about $width characters around the first match, cut at word boundaries with "…",
     * HTML-escaped, with every term match wrapped in <mark>. Returns HTML.
     *
     * @param list<string> $terms normalised terms, as terms() returns them
     */
    public static function snippet(string $plain, array $terms, int $width = 160): string
    {
        $plain = mb_scrub($plain, 'UTF-8');
        $chars = mb_str_split($plain);
        $count = count($chars);
        $width = max(1, $width);
        [$normal, $map] = self::fold($plain);
        $first = null;
        foreach ($terms as $term) {
            $pos = $term === '' ? false : strpos($normal, $term);
            if ($pos !== false && ($first === null || $map[$pos] < $first)) {
                $first = $map[$pos];
            }
        }
        $start = $first === null ? 0 : max(0, $first - intdiv($width, 3));
        $end = min($count, $start + $width);
        $start = max(0, min($start, $end - $width));
        if ($start > 0) {
            // Begin at a word start, but never after the match.
            $limit = $first ?? $end;
            while ($start < $limit && !self::isSpace($chars[$start - 1])) {
                $start++;
            }
        }
        if ($end < $count && !self::isSpace($chars[$end])) {
            // End at a word end, but never inside the match.
            for ($k = $end - 1; $k > ($first ?? $start); $k--) {
                if (self::isSpace($chars[$k])) {
                    $end = $k;
                    break;
                }
            }
        }
        $cutStart = $start > 0;
        $cutEnd = $end < $count;
        while ($start < $end && self::isSpace($chars[$start])) {
            $start++;
        }
        while ($end > $start && self::isSpace($chars[$end - 1])) {
            $end--;
        }
        $window = array_slice($chars, $start, $end - $start);
        [$windowNormal, $windowMap] = self::fold(implode('', $window));
        $marked = array_fill(0, count($window), false);
        foreach ($terms as $term) {
            $offset = 0;
            while ($term !== '' && ($pos = strpos($windowNormal, $term, $offset)) !== false) {
                for ($k = $windowMap[$pos]; $k <= $windowMap[$pos + strlen($term) - 1]; $k++) {
                    $marked[$k] = true;
                }
                $offset = $pos + 1;
            }
        }
        $html = $cutStart ? '…' : '';
        $open = false;
        foreach ($window as $k => $char) {
            if ($marked[$k] !== $open) {
                $html .= $open ? '</mark>' : '<mark>';
                $open = !$open;
            }
            $html .= htmlspecialchars($char, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html . ($open ? '</mark>' : '') . ($cutEnd ? '…' : '');
    }

    /**
     * Folds $text character by character and collapses spaces.
     *
     * @return array{0: string, 1: list<int>} the normalised text, and for each of its bytes the index of the
     *                                        source character it came from
     */
    private static function fold(string $text): array
    {
        $out = '';
        $map = [];
        $space = true; // true drops leading spaces
        foreach (mb_str_split(mb_scrub($text, 'UTF-8')) as $index => $char) {
            foreach (str_split(self::foldChar($char)) as $byte) {
                if ($byte === ' ') {
                    if ($space) {
                        continue;
                    }
                    $space = true;
                } else {
                    $space = false;
                }
                $out .= $byte;
                $map[] = $index;
            }
        }
        if ($space && $out !== '') {
            $out = substr($out, 0, -1);
            array_pop($map);
        }

        return [$out, $map];
    }

    /** One character as lower-case ASCII letters and digits: "É" => "e", "ß" => "ss", "-" => " ", "日" => "". */
    private static function foldChar(string $char): string
    {
        if (strlen($char) === 1) {
            $lower = strtolower($char);

            return ctype_alnum($lower) ? $lower : ' ';
        }
        if (isset(self::$folds[$char])) {
            return self::$folds[$char];
        }
        $ascii = mb_strtolower($char, 'UTF-8');
        if (class_exists(Normalizer::class)) {
            // Accents first: macOS iconv transliterates "é" as "'e".
            $decomposed = Normalizer::normalize($ascii, Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $ascii = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        if (preg_match('/[^\x00-\x7f]/', $ascii) === 1) {
            $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii);
            $ascii = is_string($translit) ? $translit : '';
        }

        return self::$folds[$char] = (string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii));
    }

    private static function isSpace(string $char): bool
    {
        return preg_match('/^\s$/u', $char) === 1;
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
