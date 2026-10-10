<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

interface Engine
{
    /** @param array<string, mixed> $data */
    public function render(string $name, string $path, array $data, Helpers $x): string;
}
