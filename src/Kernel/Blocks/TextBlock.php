<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;
use Xaraya\Kernel\Support\MiniMarkdown;

/**
 * Admin-written text. NOTE: `format` defaults to "html", which is trusted and output RAW, unescaped.
 * Use `"format": "markdown"` for the safe format: MiniMarkdown escapes everything and allows only safe links.
 */
final class TextBlock implements Block
{
    public function render(array $config, BlockContext $context): string
    {
        $body = $config['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return '';
        }

        return match ($config['format'] ?? 'html') {
            'html' => $body,
            'markdown' => MiniMarkdown::toHtml($body),
            default => throw new InvalidArgumentException('A text block format must be "html" or "markdown"'),
        };
    }
}
