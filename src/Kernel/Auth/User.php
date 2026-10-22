<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;

/** One row of xar_users, without its password hash (templates get this object through user()). */
final class User
{
    public const STATUSES = ['active', 'pending', 'disabled'];

    public function __construct(
        public readonly string $id,
        public readonly string $handle,
        public readonly string $name,
        public readonly string $email,
        public readonly string $status,
        public readonly DateTimeImmutable $created,
        public readonly ?DateTimeImmutable $lastLogin = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');

        return new self(
            (string) $row['id'],
            (string) $row['handle'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['status'],
            new DateTimeImmutable((string) $row['created'], $utc),
            isset($row['last_login']) ? new DateTimeImmutable((string) $row['last_login'], $utc) : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The name to show: the display name, or the handle when it is empty. */
    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->handle;
    }
}
