<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Support;

final class Text
{
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?>|</p>|</li>|</h[1-6]>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function deriveTitle(string $kind, ?string $contentHtml, ?string $externalUrl): string
    {
        if ($kind === 'link' && $externalUrl !== null) {
            $parts = parse_url($externalUrl);
            $host = (string) preg_replace('/^www\./i', '', (string) ($parts['host'] ?? ''));
            $label = $host . rtrim((string) ($parts['path'] ?? ''), '/');
            if ($label !== '') {
                return $label;
            }
        }
        $plain = $contentHtml === null ? '' : self::plain($contentHtml);
        if ($plain !== '') {
            return mb_substr($plain, 0, 80);
        }

        return match ($kind) {
            'photo' => 'Photo',
            'link' => (string) $externalUrl,
            default => 'Untitled',
        };
    }

    public static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return $slug !== '' ? substr($slug, 0, 128) : substr(sha1($name), 0, 8);
    }

    /** @param array<mixed> $value */
    public static function canonicalJson(array $value): string
    {
        return json_encode(
            self::sortKeys($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
