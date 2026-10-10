<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\RecentItemsQuery;

/** The newest items of whichever modules answer a RecentItemsQuery: {"limit", "module", "itemtype"}. */
final class RecentItemsBlock implements Block
{
    public function __construct(private readonly EventDispatcher $events) {}

    public function render(array $config, BlockContext $context): string
    {
        $limit = is_int($config['limit'] ?? null) ? max(1, min(50, $config['limit'])) : 5;
        $module = is_string($config['module'] ?? null) ? $config['module'] : null;
        $itemtype = is_string($config['itemtype'] ?? null) ? $config['itemtype'] : null;
        $items = $this->events->dispatch(new RecentItemsQuery($limit, $module, $itemtype))->items();
        if ($items === []) {
            return '';
        }
        $x = $context->helpers;
        $html = '<ul class="recent-items">';
        foreach ($items as $item) {
            $html .= '<li><a href="' . $x->e($item->url) . '">' . $x->e($item->title) . '</a>';
            if ($item->date !== null) {
                $html .= ' <time datetime="' . $x->e($item->date->format(DATE_ATOM)) . '">' . $x->e($item->date->format('j M Y')) . '</time>';
            }
            $html .= '</li>';
        }

        return $html . '</ul>';
    }
}
