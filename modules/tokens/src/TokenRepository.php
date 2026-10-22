<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Support\Ulid;

final class TokenRepository
{
    public const PREFIX = 'xar_';
    public const LENGTH = 40;
    /** last_used_at is written at most once in this many seconds per token. */
    public const TOUCH_SECONDS = 60;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly Connection $db, private readonly ModuleRegistry $modules) {}

    /** @return list<string> Token::SCOPES plus the scopes of every enabled module */
    public function scopes(): array
    {
        $scopes = Token::SCOPES;
        foreach ($this->modules->enabled() as $manifest) {
            array_push($scopes, ...$manifest->scopes());
        }

        return array_values(array_unique($scopes));
    }

    /** A new plaintext token: "xar_" and 40 base62 characters from random_bytes, without modulo bias. */
    public static function generate(): string
    {
        $out = '';
        while (strlen($out) < self::LENGTH) {
            foreach (str_split(random_bytes(64)) as $byte) {
                $n = ord($byte);
                // 248 = 4 × 62: bytes 248-255 are dropped so every character is equally likely.
                if ($n < 248 && strlen($out) < self::LENGTH) {
                    $out .= self::ALPHABET[$n % 62];
                }
            }
        }

        return self::PREFIX . $out;
    }

    public static function hash(#[SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function looksLikeToken(#[SensitiveParameter] string $value): bool
    {
        return preg_match('/^xar_[0-9A-Za-z]{40}$/D', $value) === 1;
    }

    /**
     * @param list<string> $scopes
     * @return array{0: Token, 1: string} the stored token and its plaintext, which is never stored
     */
    public function create(string $name, array $scopes, ?int $blogId, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now, ?string $userId = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new InvalidArgumentException('A token needs a name of 1 to 64 characters');
        }
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, $this->scopes()) !== []) {
            throw new InvalidArgumentException('Scopes must be one or more of: ' . implode(', ', $this->scopes()));
        }
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new InvalidArgumentException('A token must expire in the future');
        }
        $plaintext = self::generate();
        $id = Ulid::generate();
        $this->db->insert('tokens', [
            'id' => $id,
            'name' => $name,
            'token_hash' => self::hash($plaintext),
            'scopes' => $scopes,
            'blog_id' => $blogId,
            'user_id' => $userId,
            'last_used_at' => null,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return [$this->find($id) ?? throw new RuntimeException('Token insert failed'), $plaintext];
    }

    /** The token whose plaintext this is, expired or not; null for anything else. */
    public function findByPlaintext(#[SensitiveParameter] string $plaintext): ?Token
    {
        if (!self::looksLikeToken($plaintext)) {
            return null;
        }
        $hash = self::hash($plaintext);
        $row = $this->db->select('tokens')->where('token_hash', '=', $hash)->first();

        return $row !== null && hash_equals((string) $row['token_hash'], $hash) ? Token::fromRow($row) : null;
    }

    public function find(string $id): ?Token
    {
        $row = $this->db->select('tokens')->where('id', '=', $id)->first();

        return $row === null ? null : Token::fromRow($row);
    }

    /** @return list<Token> oldest first */
    public function all(): array
    {
        return array_map(Token::fromRow(...), $this->db->select('tokens')->orderBy('created_at')->orderBy('id')->all());
    }

    public function revoke(string $id): bool
    {
        return $this->db->delete('tokens', ['id' => $id]) > 0;
    }

    /**
     * Records a use. Writes only when the stored last_used_at is a minute old or more, and the conditional
     * UPDATE lets only one of many concurrent requests do it. Returns whether this call wrote.
     */
    public function touch(Token $token, DateTimeImmutable $now): bool
    {
        $threshold = $now->modify('-' . self::TOUCH_SECONDS . ' seconds');
        if ($token->lastUsedAt !== null && $token->lastUsedAt > $threshold) {
            return false;
        }

        return $this->db->query(
            'UPDATE {tokens} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at <= ?)',
            [$now, $token->id, $threshold],
        )->rowCount() > 0;
    }
}
