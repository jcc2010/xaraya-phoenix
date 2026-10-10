<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

final class Html
{
    /** Collapses whitespace runs and removes whitespace next to tags, for engine-parity comparisons. */
    public static function normalize(string $html): string
    {
        $html = preg_replace('/\s+/u', ' ', $html) ?? $html;
        $html = preg_replace('/\s*(<[^>]*>)\s*/u', '$1', $html) ?? $html;

        return trim($html);
    }
}
