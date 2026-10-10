<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;

/** One row of the blocks table. */
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
        // Fail closed: visibility that cannot be decoded or has the wrong shape hides the block.
        $enabled = (bool) $row['enabled'];
        $decoded = is_string($row['visibility'] ?? null) ? json_decode($row['visibility'], true) : null;
        try {
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('visibility is not a JSON object');
            }
            $visibility = self::checkedVisibility($decoded);
        } catch (InvalidArgumentException) {
            $visibility = [];
            $enabled = false;
        }

        return new self(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['region'],
            $row['title'] === null ? null : (string) $row['title'],
            self::json($row['config']),
            (int) $row['sort'],
            $visibility,
            $enabled,
        );
    }

    public static function isValidType(string $type): bool
    {
        return strlen($type) <= 128 && preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)*$/D', $type) === 1;
    }

    public static function isValidRegion(string $region): bool
    {
        return strlen($region) <= 64 && preg_match('/^[a-z][a-z0-9_-]*$/D', $region) === 1;
    }

    /**
     * Validates a visibility array: a present "routes" or "roles" must be a list of non-empty strings.
     * Unknown keys are dropped.
     *
     * @param array<mixed> $value
     * @return array{routes?: list<string>, roles?: list<string>}
     * @throws InvalidArgumentException
     */
    public static function checkedVisibility(array $value): array
    {
        $out = [];
        foreach (['routes', 'roles'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $rule = $value[$key];
            if (!is_array($rule) || !array_is_list($rule)) {
                throw new InvalidArgumentException("Block visibility \"{$key}\" must be a list of strings");
            }
            foreach ($rule as $item) {
                if (!is_string($item) || $item === '') {
                    throw new InvalidArgumentException("Block visibility \"{$key}\" must be a list of non-empty strings");
                }
            }
            $out[$key] = $rule;
        }

        return $out;
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
