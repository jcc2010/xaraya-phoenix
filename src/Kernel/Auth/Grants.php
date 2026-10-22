<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\Manifest;

/** xar_roles and xar_grants: single-inheritance roles and the module · component · instance grants they hold. */
final class Grants
{
    /** @var array<string, array{id: int, parent: ?int}>|null role name => id and parent id */
    private ?array $roles = null;

    public function __construct(private readonly Connection $db) {}

    /** Creates the role when it is missing; an existing role keeps its parent. Returns its id. */
    public function ensureRole(string $name, ?string $parent = null): int
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException("Invalid role name '{$name}'");
        }
        $existing = $this->roles()[$name] ?? null;
        if ($existing !== null) {
            return $existing['id'];
        }
        $parentId = $parent === null ? null : ($this->roles()[$parent]['id'] ?? throw new InvalidArgumentException("Unknown role '{$parent}'"));
        $this->db->insert('roles', ['name' => $name, 'parent_id' => $parentId]);
        $this->roles = null;

        return $this->roles()[$name]['id'] ?? throw new InvalidArgumentException("Role '{$name}' was not created");
    }

    /** Sets (or clears) a role's parent. Cycles are allowed in the table; forRoles() stops at the first repeat. */
    public function setParent(string $role, ?string $parent): void
    {
        $id = $this->roles()[$role]['id'] ?? throw new InvalidArgumentException("Unknown role '{$role}'");
        $parentId = $parent === null ? null : ($this->roles()[$parent]['id'] ?? throw new InvalidArgumentException("Unknown role '{$parent}'"));
        $this->db->update('roles', ['parent_id' => $parentId], ['id' => $id]);
        $this->roles = null;
    }

    /** Adds a grant unless the role already has one for that module, component and instance (its level is kept). */
    public function grant(string $role, string $module, string $component, string $instance, string $level): void
    {
        $value = Access::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown access level '{$level}'");
        foreach (['module' => $module, 'component' => $component, 'instance' => $instance] as $part => $text) {
            if (!self::isValidPart($text)) {
                throw new InvalidArgumentException("Invalid grant {$part} '{$text}'");
            }
        }
        $roleId = $this->ensureRole($role);
        $exists = $this->db->select('grants')
            ->where('role_id', '=', $roleId)
            ->where('module', '=', $module)
            ->where('component', '=', $component)
            ->where('instance', '=', $instance)
            ->first();
        if ($exists === null) {
            $this->db->insert('grants', ['role_id' => $roleId, 'module' => $module, 'component' => $component, 'instance' => $instance, 'level' => $value]);
        }
    }

    /**
     * Seeds a module's default grants on enable: its manifest "grants", plus Editors · <module> · * · * delete when the
     * manifest sets "content": true. Existing grants are kept, so re-enabling restores only grants that were deleted.
     */
    public function seed(Manifest $manifest): void
    {
        $this->db->transaction(function () use ($manifest): void {
            foreach ($manifest->grants() as $grant) {
                $this->grant($grant['role'], $manifest->name, $grant['component'], $grant['instance'], $grant['level']);
            }
            if ($manifest->isContent()) {
                $this->grant('Editors', $manifest->name, '*', '*', 'delete');
            }
        });
    }

    /**
     * The grants of these roles and of all their ancestors. A parent cycle is cut at the first role seen twice.
     *
     * @param list<string> $roleNames
     * @return list<array{module: string, component: string, instance: string, level: int}>
     */
    public function forRoles(array $roleNames): array
    {
        if (!$this->db->hasTable('grants')) {
            return [];
        }
        $roles = $this->roles();
        $byId = [];
        foreach ($roles as $role) {
            $byId[$role['id']] = $role;
        }
        $ids = [];
        foreach ($roleNames as $name) {
            for ($id = $roles[$name]['id'] ?? null; $id !== null && !isset($ids[$id]); $id = $byId[$id]['parent'] ?? null) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->select('grants')->columns('module', 'component', 'instance', 'level')->whereIn('role_id', array_keys($ids))->all();

        return array_map(static fn(array $row): array => [
            'module' => (string) $row['module'],
            'component' => (string) $row['component'],
            'instance' => (string) $row['instance'],
            'level' => (int) $row['level'],
        ], $rows);
    }

    /** A module, component or instance: "*", "own", an item id, or a lower-case name. */
    public static function isValidPart(string $value): bool
    {
        return preg_match('/^(?:\*|[A-Za-z0-9][A-Za-z0-9_.:-]{0,63})$/D', $value) === 1;
    }

    /** @return array<string, array{id: int, parent: ?int}> */
    private function roles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        $roles = [];
        foreach ($this->db->select('roles')->columns('id', 'name', 'parent_id')->all() as $row) {
            $roles[(string) $row['name']] = ['id' => (int) $row['id'], 'parent' => $row['parent_id'] === null ? null : (int) $row['parent_id']];
        }

        return $this->roles = $roles;
    }
}
