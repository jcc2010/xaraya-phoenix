<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use InvalidArgumentException;

/**
 * The current request's identity and privilege checks (kernel spec §4.3). The Auth middleware (or a bearer token)
 * calls actAs(); everyone has Anonymous, and a signed-in user also has Members and their own roles.
 */
final class Auth implements Access
{
    private ?User $user = null;

    /** @var list<string>|null */
    private ?array $roles = null;

    /** @var list<array{module: string, component: string, instance: string, level: int}>|null */
    private ?array $grants = null;

    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly UserRepository $users, private readonly Grants $grantStore) {}

    /** Makes $user (null: a guest) the identity of the current request. Only an active user is accepted. */
    public function actAs(?User $user): void
    {
        $this->user = $user !== null && $user->isActive() ? $user : null;
        $this->roles = null;
        $this->grants = null;
        $this->memo = [];
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function roles(): array
    {
        if ($this->roles === null) {
            $this->roles = $this->user === null
                ? ['Anonymous']
                : array_values(array_unique(['Anonymous', 'Members', ...$this->users->rolesOf($this->user)]));
        }

        return $this->roles;
    }

    /**
     * True when the highest level among the matching grants of the request's roles (and their ancestors) is at
     * least $level. Without an $item every instance matches: the route-level check of §3.3 asks whether the user
     * has the level on some instance, and the controller checks the item itself.
     */
    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool
    {
        $required = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown access level '{$level}'");
        $id = self::field($item, 'id');
        $owner = self::field($item, 'owner_id');
        $key = implode("\0", [$required, $module, $component, $item === null ? '' : 'i', $id ?? '', $owner ?? '']);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $this->grants ??= $this->grantStore->forRoles($this->roles());
        $best = 0;
        foreach ($this->grants as $grant) {
            if (
                ($grant['module'] === '*' || $grant['module'] === $module)
                && ($grant['component'] === '*' || $grant['component'] === $component)
                && $this->instanceMatches($grant['instance'], $item, $id, $owner)
            ) {
                $best = max($best, $grant['level']);
            }
        }

        return $this->memo[$key] = $best >= $required;
    }

    private function instanceMatches(string $instance, mixed $item, ?string $id, ?string $owner): bool
    {
        return match (true) {
            $instance === '*', $item === null => true,
            $instance === 'own' => $this->user !== null && $owner === $this->user->id,
            default => $id !== null && $id === $instance,
        };
    }

    /** $item's id or owner_id: a scalar item is its own id; arrays and objects are read by key or property. */
    private static function field(mixed $item, string $name): ?string
    {
        $value = match (true) {
            is_array($item) => $item[$name] ?? null,
            is_object($item) => $item->{$name} ?? null,
            $name === 'id' => $item,
            default => null,
        };

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
