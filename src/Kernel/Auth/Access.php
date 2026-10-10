<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

/** Privilege checks for the current request (kernel spec §4.3). Plan 3 binds the real implementation. */
interface Access
{
    public const LEVELS = [
        'none' => 0, 'overview' => 100, 'read' => 200, 'comment' => 300, 'moderate' => 400,
        'edit' => 500, 'add' => 600, 'delete' => 700, 'admin' => 800,
    ];

    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool;

    /** The signed-in user, or null for a guest. */
    public function user(): ?object;

    /** @return list<string> the current request's role names (block visibility uses them) */
    public function roles(): array;
}
