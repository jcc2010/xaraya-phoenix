<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Support\SafeUrl;

/** Links from the block config: {"links": [{"label", "route", "params"} or {"label", "url"}]}. */
final class MenuBlock implements Block
{
    public function __construct(private readonly ?LoggerInterface $logger = null) {}

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
                try {
                    $href = $x->url($link['route'], $params);
                } catch (Throwable $e) {
                    $this->logger?->warning("Menu link '{$link['label']}' skipped: {$e->getMessage()}");
                    continue;
                }
            } elseif (is_string($link['url'] ?? null) && SafeUrl::isSafe($link['url'])) {
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
