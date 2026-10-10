<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

/** One row of xar_blocks. */
final class BlockInstance
{
    /**
     * @param array<string, mixed> $config
     * @param array{routes?: list<string>, roles?: list<string>} $visibility
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $region,
        public readonly ?string $title,
        public readonly array $config,
        public readonly int $sort,
        public readonly array $visibility,
        public readonly bool $enabled,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['region'],
            $row['title'] === null ? null : (string) $row['title'],
            self::json($row['config']),
            (int) $row['sort'],
            self::visibility(self::json($row['visibility'])),
            (bool) $row['enabled'],
        );
    }

    /**
     * @param array<mixed> $value
     * @return array{routes?: list<string>, roles?: list<string>}
     */
    public static function visibility(array $value): array
    {
        $out = [];
        if (isset($value['routes'])) {
            $out['routes'] = self::strings($value['routes']);
        }
        if (isset($value['roles'])) {
            $out['roles'] = self::strings($value['roles']);
        }

        return $out;
    }

    /**
     * A "routes" rule needs the current route name to match one pattern ("*" is a wildcard), so pages without a
     * route (error pages) hide the block. A "roles" rule needs one of the request's roles.
     *
     * @param list<string> $roles
     */
    public function visibleFor(?string $routeName, array $roles): bool
    {
        $routes = $this->visibility['routes'] ?? [];
        if ($routes !== []) {
            if ($routeName === null) {
                return false;
            }
            $matched = false;
            foreach ($routes as $pattern) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/D';
                $matched = $matched || preg_match($regex, $routeName) === 1;
            }
            if (!$matched) {
                return false;
            }
        }
        $wanted = $this->visibility['roles'] ?? [];

        return $wanted === [] || array_intersect($wanted, $roles) !== [];
    }

    /** @return array<string, mixed> */
    private static function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }
}
