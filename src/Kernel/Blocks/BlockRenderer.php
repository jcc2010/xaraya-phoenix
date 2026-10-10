<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Routing\RouteMatch;
use Xaraya\Kernel\View\Helpers;

/** Renders a theme region: its enabled, visible blocks, each wrapped by the theme's "block" template. */
final class BlockRenderer
{
    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly BlockTypes $types,
        private readonly Access $access,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public function region(string $region, Helpers $x): string
    {
        $match = $x->request()?->getAttribute(RouteMatch::class);
        $routeName = $match instanceof RouteMatch ? $match->route->name : null;
        $params = $match instanceof RouteMatch ? $match->params : [];
        $roles = $this->access->roles();
        $html = '';
        foreach ($this->blocks->forRegion($region) as $block) {
            if (!$block->enabled || !$this->types->has($block->type) || !$block->visibleFor($routeName, $roles)) {
                continue;
            }
            try {
                $inner = $this->types->get($block->type)->render($block->config, new BlockContext($block, $x, $routeName, $params));
            } catch (Throwable $e) {
                if ($this->debug) {
                    throw $e;
                }
                $this->logger->error("Block {$block->id} ({$block->type}) failed: {$e->getMessage()}", ['exception' => $e]);
                continue;
            }
            if ($inner !== '') {
                $html .= $this->wrap($block, $inner, $x);
            }
        }

        return $html;
    }

    private function wrap(BlockInstance $block, string $inner, Helpers $x): string
    {
        $typeClass = 'block-' . (preg_replace('/[^a-z0-9-]+/', '-', $block->type) ?? 'block');
        if ($x->view()->exists('block')) {
            return $x->render('block', [
                'id' => $block->id,
                'type' => $block->type,
                'typeClass' => $typeClass,
                'title' => $block->title,
                'region' => $block->region,
                'content' => $inner,
            ]);
        }
        $title = $block->title === null || $block->title === '' ? '' : '<h2 class="block-title">' . $x->e($block->title) . '</h2>';

        return '<section class="block ' . $x->e($typeClass) . '">' . $title . $inner . "</section>\n";
    }
}
