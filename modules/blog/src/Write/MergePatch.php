<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Write;

/**
 * JSON Merge Patch (RFC 7396) over decoded JSON arrays. A patch member set to null removes the member; an object
 * merges recursively; anything else (a scalar or a list) replaces. An empty array counts as an empty object.
 */
final class MergePatch
{
    public static function apply(mixed $target, mixed $patch): mixed
    {
        if (!self::isObject($patch)) {
            return $patch;
        }
        /** @var array<array-key, mixed> $patch */
        $result = self::isObject($target) && is_array($target) ? $target : [];
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($result[$key]);
            } else {
                $result[$key] = self::apply($result[$key] ?? null, $value);
            }
        }

        return $result;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
