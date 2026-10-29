<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

/**
 * Finds and rewrites the `src` of `<img>` tags inside post HTML (content_html, the Athena comment).
 *
 * Works on the markup as a string, so everything but the matched attribute values comes back byte for byte: the
 * sanitiser's cache keys and the feed's parity with the source are untouched for posts without local media.
 */
final class InlineImages
{
    /** An `<img>` tag; quoted values may contain `>`. */
    private const TAG = '#<img(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i';

    /** One attribute, scanned left to right so a quoted value is consumed whole and never searched for `src=`. */
    private const ATTRIBUTE = '#(?<=[\s/"\'])([^\s"\'>/=]+)(\s*=\s*)?(?(2)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))#';

    /** @return list<string> the decoded http(s) src of every `<img>`, in order, without repeats */
    public static function sources(string $html): array
    {
        if (stripos($html, '<img') === false) {
            return [];
        }
        $urls = [];
        preg_match_all(self::TAG, $html, $tags);
        foreach ($tags[0] as $tag) {
            $src = self::src($tag);
            if ($src !== null && preg_match('#^https?://#i', $src[1]) === 1) {
                $urls[$src[1]] = true;
            }
        }

        return array_keys($urls);
    }

    /** @param array<string, string> $media source URL => replacement URL */
    public static function rewrite(string $html, array $media): string
    {
        if ($media === [] || stripos($html, '<img') === false) {
            return $html;
        }

        return (string) preg_replace_callback(self::TAG, static function (array $tag) use ($media): string {
            $src = self::src($tag[0]);
            $replacement = $src === null ? null : ($media[$src[1]] ?? null);
            if ($src === null || $replacement === null) {
                return $tag[0];
            }
            [$offset, , $length] = $src;

            return substr($tag[0], 0, $offset) . 'src="' . htmlspecialchars($replacement, ENT_QUOTES | ENT_HTML5) . '"'
                . substr($tag[0], $offset + $length);
        }, $html);
    }

    /**
     * The tag's first `src` attribute, as browsers read it.
     *
     * @return array{0: int, 1: string, 2: int}|null byte offset of the attribute, its decoded value, its length
     */
    private static function src(string $tag): ?array
    {
        preg_match_all(self::ATTRIBUTE, substr($tag, 0, -1), $attributes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE, 4);
        foreach ($attributes as $m) {
            if (strtolower($m[1][0]) !== 'src') {
                continue;
            }
            if (($m[2][0] ?? '') === '') {
                return null;
            }
            $raw = '';
            foreach ([3, 4, 5] as $group) {
                if (isset($m[$group]) && $m[$group][1] >= 0) {
                    $raw = $m[$group][0];
                }
            }

            return [$m[0][1], trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), strlen($m[0][0])];
        }

        return null;
    }
}
