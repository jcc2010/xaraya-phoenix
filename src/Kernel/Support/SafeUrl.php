<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Support;

/** The link allowlist shared by blocks: http(s)://, a single-slash root-relative path, or a #fragment; no whitespace, backslashes or control characters. */
final class SafeUrl
{
    public static function isSafe(string $url): bool
    {
        return preg_match('#^(?:https?://|/(?![/\\\\])|\#)[^\s\\\\\x00-\x1f\x7f]*$#D', $url) === 1;
    }
}
