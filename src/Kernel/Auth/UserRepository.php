<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;

/** xar_users and xar_user_roles. Passwords are hashed with password_hash(PASSWORD_DEFAULT, auth.password_options). */
final class UserRepository
{
    public const HANDLE = '/^[a-z0-9_-]{2,32}$/D';
    public const MIN_PASSWORD = 8;
    /** bcrypt ignores everything after 72 bytes, so longer passwords are refused instead of silently truncated. */
    public const MAX_PASSWORD_BYTES = 72;

    /** @var \Closure(string, string): bool */
    private readonly \Closure $verify;

    /** @param array<string, int|string> $hashOptions password_hash() options, e.g. ['cost' => 12] */
    public function __construct(
        private readonly Connection $db,
        private readonly array $hashOptions = [],
        ?\Closure $verify = null,
    ) {
        // The seam lets tests count password checks; production always uses password_verify().
        $this->verify = $verify ?? static fn(string $password, string $hash): bool => password_verify($password, $hash);
    }

    /**
     * @param list<string> $roles role names; every one must exist
     * @throws InvalidArgumentException for an invalid or taken handle or email, a short, empty or NUL-containing password or an unknown role
     */
    public function create(
        string $handle,
        string $email,
        #[SensitiveParameter]
        string $password,
        string $name = '',
        string $status = 'active',
        array $roles = [],
    ): User {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if (preg_match(self::HANDLE, $handle) !== 1) {
            throw new InvalidArgumentException('A handle is 2 to 32 lower-case letters, digits, "_" or "-"');
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The email address is not valid');
        }
        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A name is at most 255 characters');
        }
        if (!in_array($status, User::STATUSES, true)) {
            throw new InvalidArgumentException('A status is one of: ' . implode(', ', User::STATUSES));
        }
        self::checkPassword($password);
        if ($this->findByHandle($handle) !== null) {
            throw new InvalidArgumentException("The handle '{$handle}' is taken");
        }
        if ($this->db->select('users')->where('email', '=', $email)->first() !== null) {
            throw new InvalidArgumentException('The email address is taken');
        }
        $roleIds = $this->roleIds($roles);
        $id = Ulid::generate();
        $this->db->transaction(function (Connection $db) use ($id, $handle, $name, $email, $password, $status, $roleIds): void {
            $db->insert('users', [
                'id' => $id,
                'handle' => $handle,
                'name' => $name,
                'email' => $email,
                'password_hash' => $this->hash($password),
                'status' => $status,
                'created' => new DateTimeImmutable(),
                'last_login' => null,
            ]);
            foreach ($roleIds as $roleId) {
                $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
            }
        });

        return $this->find($id) ?? throw new RuntimeException('User insert failed');
    }

    public function find(string $id): ?User
    {
        $row = $this->db->select('users')->where('id', '=', $id)->first();

        return $row === null ? null : User::fromRow($row);
    }

    public function findByHandle(string $handle): ?User
    {
        $row = $this->db->select('users')->where('handle', '=', $handle)->first();

        return $row === null ? null : User::fromRow($row);
    }

    /** The user a login form names: an email address when it contains "@", else a handle. Case is ignored. */
    public function findByLogin(string $login): ?User
    {
        $login = mb_strtolower(trim($login));
        if ($login === '' || strlen($login) > 254) {
            return null;
        }
        $row = $this->db->select('users')->where(str_contains($login, '@') ? 'email' : 'handle', '=', $login)->first();

        return $row === null ? null : User::fromRow($row);
    }

    /** Checks the password; when it is right and the hash is outdated (algorithm or options), stores a new hash. */
    public function verifyPassword(User $user, #[SensitiveParameter] string $password): bool
    {
        $hash = (string) $this->db->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$user->id]);
        if (str_contains($password, "\0")) {
            // Still one full check, so a NUL password costs the same as any wrong password (and as verifyNothing()).
            ($this->verify)('', $hash);

            return false;
        }
        if (!($this->verify)($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT, $this->hashOptions)) {
            $this->db->update('users', ['password_hash' => $this->hash($password)], ['id' => $user->id]);
        }

        return true;
    }

    /** Burns the time of one password check, so a login for an unknown user takes as long as a wrong password. */
    public function verifyNothing(#[SensitiveParameter] string $password): void
    {
        static $dummy = [];
        if (str_contains($password, "\0")) {
            $password = '';
        }
        $key = json_encode($this->hashOptions);
        $dummy[$key] ??= password_hash('phoenix-timing-equaliser', PASSWORD_DEFAULT, $this->hashOptions);
        ($this->verify)($password, $dummy[$key]);
    }

    public function setPassword(User $user, #[SensitiveParameter] string $password): void
    {
        self::checkPassword($password);
        $this->db->update('users', ['password_hash' => $this->hash($password)], ['id' => $user->id]);
    }

    public function setStatus(User $user, string $status): void
    {
        if (!in_array($status, User::STATUSES, true)) {
            throw new InvalidArgumentException('A status is one of: ' . implode(', ', User::STATUSES));
        }
        $this->db->update('users', ['status' => $status], ['id' => $user->id]);
    }

    public function recordLogin(User $user, DateTimeImmutable $now): void
    {
        $this->db->update('users', ['last_login' => $now], ['id' => $user->id]);
    }

    /** @return list<string> the role names granted to $user directly (not Anonymous or Members, which are implicit) */
    public function rolesOf(User $user): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.name FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name',
            [$user->id],
        );

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    public function addRole(User $user, string $role): void
    {
        $roleId = $this->roleIds([$role])[0];
        if ($this->db->select('user_roles')->where('user_id', '=', $user->id)->where('role_id', '=', $roleId)->first() === null) {
            $this->db->insert('user_roles', ['user_id' => $user->id, 'role_id' => $roleId]);
        }
    }

    public function count(): int
    {
        return $this->db->hasTable('users') ? $this->db->select('users')->count() : 0;
    }

    public static function checkPassword(#[SensitiveParameter] string $password): void
    {
        if (str_contains($password, "\0")) {
            throw new InvalidArgumentException('A password cannot contain a NUL character');
        }
        if (strlen($password) > self::MAX_PASSWORD_BYTES) {
            throw new InvalidArgumentException('A password is at most ' . self::MAX_PASSWORD_BYTES . ' bytes');
        }
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('A password is at least ' . self::MIN_PASSWORD . ' characters');
        }
    }

    private function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, $this->hashOptions);
    }

    /**
     * @param list<string> $names
     * @return list<int>
     */
    private function roleIds(array $names): array
    {
        $ids = [];
        foreach (array_unique($names) as $name) {
            $id = $this->db->fetchValue('SELECT id FROM {roles} WHERE name = ?', [$name]);
            $ids[] = $id === null ? throw new InvalidArgumentException("Unknown role '{$name}'") : (int) $id;
        }

        return $ids;
    }
}
