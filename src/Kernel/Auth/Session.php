<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * The request's session (the Session::class request attribute). SessionMiddleware loads it from the cookie and
 * saves it after the response when it changed. A session with no user and no data is never stored.
 */
final class Session
{
    /** The cookie value: 32 random bytes in unpadded base64url. */
    public const ID = '/^[A-Za-z0-9_-]{43}$/D';

    private bool $dirty = false;

    private ?string $replaced = null;

    /** @param array<string, mixed> $data */
    private function __construct(
        private ?string $id,
        private ?string $userId,
        private array $data,
        public readonly ?DateTimeImmutable $lastActivity,
    ) {}

    public static function fresh(): self
    {
        return new self(null, null, [], null);
    }

    /** @param array<string, mixed> $data */
    public static function loaded(#[SensitiveParameter] string $id, ?string $userId, array $data, DateTimeImmutable $lastActivity): self
    {
        return new self($id, $userId, $data, $lastActivity);
    }

    public static function newId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** What the sessions table stores instead of the cookie value. */
    public static function hash(#[SensitiveParameter] string $id): string
    {
        return hash('sha256', $id);
    }

    /** The cookie value, or null until a new session is first saved. */
    public function id(): ?string
    {
        return $this->id;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** The CSRF synchronizer token, created on first use. */
    public function token(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token) || $token === '') {
            $this->set('_csrf', $token = self::newId());
        }

        return $token;
    }

    /** Signs $userId in under a new session id, with fresh data (and so a fresh CSRF token). */
    public function login(string $userId): void
    {
        $this->regenerate();
        $this->userId = $userId;
        $this->data = [];
    }

    /** Signs out: the stored session is deleted and the cookie expired. */
    public function logout(): void
    {
        $this->regenerate();
        $this->userId = null;
        $this->data = [];
    }

    /** Gives the session a new id when it is next saved; the old row is deleted. */
    public function regenerate(): void
    {
        if ($this->id !== null) {
            $this->replaced = self::hash($this->id);
        }
        $this->id = null;
        $this->dirty = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function isEmpty(): bool
    {
        return $this->userId === null && $this->data === [];
    }

    /** The hash of a row regenerate() left behind, which the store must delete. */
    public function replaced(): ?string
    {
        return $this->replaced;
    }

    /** @internal SessionStore assigns the id of a newly saved session. */
    public function saved(#[SensitiveParameter] string $id): void
    {
        $this->id = $id;
        $this->dirty = false;
        $this->replaced = null;
    }
}
