<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

/** Links from the block config: {"links": [{"label", "route", "params"} or {"label", "url"}]}. */
final class MenuBlock implements Block
{
    public function render(array $config, BlockContext $context): string
    {
        $x = $context->helpers;
        $current = $context->request()?->getUri()->getPath();
        $items = '';
        foreach ((array) ($config['links'] ?? []) as $link) {
            if (!is_array($link) || !is_string($link['label'] ?? null)) {
                continue;
            }
            if (is_string($link['route'] ?? null)) {
                $params = [];
                foreach ((array) ($link['params'] ?? []) as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_int($value))) {
                        $params[$key] = $value;
                    }
                }
                $href = $x->url($link['route'], $params);
            } elseif (is_string($link['url'] ?? null) && preg_match('#^(?:https?://|/(?![/\\\\])|\#)[^\s\\\\\x00-\x1f\x7f]*$#', $link['url']) === 1) {
                $href = $link['url'];
            } else {
                continue;
            }
            $items .= '<li><a href="' . $x->e($href) . '"' . ($href === $current ? ' aria-current="page"' : '') . '>' . $x->e($link['label']) . '</a></li>';
        }
        if ($items === '') {
            return '';
        }

        return '<nav aria-label="' . $x->e($context->block->title ?? $x->t('Menu')) . '"><ul class="menu">' . $items . '</ul></nav>';
    }
}
