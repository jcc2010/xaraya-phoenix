<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Db\Connection;

final class GreetingCountBlock implements Block
{
    public function __construct(private readonly Connection $db) {}

    public function render(array $config, BlockContext $context): string
    {
        $x = $context->helpers;

        return '<p class="greeting-count">' . $x->e($x->t('Greetings so far: {count}', ['count' => $this->db->select('greetings')->count()])) . '</p>';
    }
}
