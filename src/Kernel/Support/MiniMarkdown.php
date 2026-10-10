<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Support;

/**
 * A deliberately tiny, safe Markdown subset for text blocks: paragraphs (single newlines become <br>),
 * "- " bullet lists, `code`, **strong**, *em* and [text](url) links whose URL is http(s), mailto,
 * root-relative or a #fragment. Everything is HTML-escaped first, so raw HTML shows as text.
 */
final class MiniMarkdown
{
    public static function toHtml(string $markdown): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        if ($text === '') {
            return '';
        }
        $html = [];
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $block) {
            $lines = array_map('trim', explode("\n", trim($block)));
            $items = array_filter($lines, static fn(string $line): bool => str_starts_with($line, '- '));
            if (count($items) === count($lines)) {
                $html[] = '<ul>' . implode('', array_map(static fn(string $line): string => '<li>' . self::inline(substr($line, 2)) . '</li>', $lines)) . '</ul>';
            } else {
                $html[] = '<p>' . implode("<br>\n", array_map(self::inline(...), $lines)) . '</p>';
            }
        }

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $parts = preg_split('/(`[^`]+`)/', $escaped, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$escaped];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= '<code>' . substr($part, 1, -1) . '</code>';
                continue;
            }
            $part = preg_replace('/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|#|\/(?![\/\\\\]))[^\s()*\\\\]*)\)/', '<a href="$2">$1</a>', $part) ?? $part;
            $part = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $part) ?? $part;
            $part = preg_replace('/\*(?=\S)(.+?)(?<=\S)\*/', '<em>$1</em>', $part) ?? $part;
            $out .= $part;
        }

        return $out;
    }
}
