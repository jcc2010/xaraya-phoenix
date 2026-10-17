<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ServerRequestInterface;

/** A stored API token. The plaintext is never kept: only its sha256 is in xar_tokens. */
final class Token
{
    public const SCOPES = ['blog:write', 'media:write'];

    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly ?int $blogId,
        public readonly ?DateTimeImmutable $lastUsedAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');
        $date = static fn(string $key): ?DateTimeImmutable => isset($row[$key]) && $row[$key] !== ''
            ? new DateTimeImmutable((string) $row[$key], $utc)
            : null;
        $scopes = is_string($row['scopes'] ?? null) ? json_decode($row['scopes'], true) : null;

        return new self(
            (string) $row['id'],
            (string) $row['name'],
            is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            isset($row['blog_id']) ? (int) $row['blog_id'] : null,
            $date('last_used_at'),
            $date('expires_at'),
            $date('created_at') ?? new DateTimeImmutable('@0'),
        );
    }

    /**
     * The token TokenMiddleware put on the request: the one way controllers read it. Throws the 401 when the
     * route ran without the `token` middleware or the attribute is missing.
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $token = $request->getAttribute(self::class);

        return $token instanceof self ? $token : throw TokenError::missing();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** A token with no blog may write to every blog; a pinned token only to its own. */
    public function allowsBlog(int $blogId): bool
    {
        return $this->blogId === null || $this->blogId === $blogId;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
