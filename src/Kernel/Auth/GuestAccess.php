<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use InvalidArgumentException;

/**
 * Kernel Plan 2 has no users: every request is an anonymous guest who may read. Plan 3 replaces the Access
 * binding with real users, roles and grants.
 */
final class GuestAccess implements Access
{
    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool
    {
        $required = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown access level '{$level}'");

        return $required <= self::LEVELS['read'];
    }

    public function user(): ?object
    {
        return null;
    }

    public function roles(): array
    {
        return ['Anonymous'];
    }
}
