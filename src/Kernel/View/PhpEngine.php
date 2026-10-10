<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

/**
 * Plain PHP templates. Variables arrive directly, helpers as $x. Nothing is escaped automatically:
 * every value must go through $x->e().
 */
final class PhpEngine implements Engine
{
    public function render(string $name, string $path, array $data, Helpers $x): string
    {
        unset($data['this']);
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data, Helpers $x): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($path, $data, $x);

            return (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
