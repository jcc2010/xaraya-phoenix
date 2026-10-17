<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenRepositoryTest extends TokensTestCase
{
    private function repo(): TokenRepository
    {
        return $this->app()->container()->get(TokenRepository::class);
    }

    public function testGeneratesBase62TokensWithThePrefix(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $token = TokenRepository::generate();
            self::assertMatchesRegularExpression('/^xar_[0-9A-Za-z]{40}$/D', $token);
            $seen[$token] = true;
        }
        self::assertCount(50, $seen);
    }

    public function testStoresOnlyTheHashAndFindsByPlaintext(): void
    {
        $app = $this->enableTokens();
        $now = $this->now();
        [$token, $plaintext] = $this->repo()->create('ci', ['blog:write', 'media:write', 'blog:write'], null, null, $now);
        self::assertSame(['blog:write', 'media:write'], $token->scopes);
        self::assertNull($token->blogId);

        $row = $this->db($app)->select('tokens')->where('id', '=', $token->id)->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $plaintext), $row['token_hash']);
        self::assertSame(TokenRepository::hash($plaintext), $row['token_hash']);
        self::assertStringNotContainsString(substr($plaintext, 4), (string) json_encode($row));
        foreach ($row as $value) {
            self::assertNotSame($plaintext, $value);
        }

        self::assertSame($token->id, $this->repo()->findByPlaintext($plaintext)?->id);
        self::assertNull($this->repo()->findByPlaintext('xar_' . str_repeat('A', 40)));
        self::assertNull($this->repo()->findByPlaintext($plaintext . 'x'));
        self::assertNull($this->repo()->findByPlaintext(''));

        // A token differing by a single character is rejected.
        $last = substr($plaintext, -1);
        $other = $last === 'a' ? 'b' : 'a';
        self::assertNull($this->repo()->findByPlaintext(substr($plaintext, 0, -1) . $other));
    }

    public function testValidatesNameScopesAndExpiry(): void
    {
        $this->enableTokens();
        $now = $this->now();
        foreach ([['', ['blog:write'], null], [str_repeat('n', 65), ['blog:write'], null], ['x', [], null], ['x', ['admin'], null], ['x', ['blog:write'], $now]] as [$name, $scopes, $expires]) {
            try {
                $this->repo()->create($name, $scopes, null, $expires, $now);
                self::fail('must be rejected: ' . json_encode([$name, $scopes]));
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame([], $this->repo()->all(), 'nothing is stored for a rejected token');
    }

    public function testTokenRules(): void
    {
        $now = new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('UTC'));
        $pinned = new Token('t', 'n', ['blog:write'], 7, null, $now, $now);
        self::assertTrue($pinned->hasScope('blog:write'));
        self::assertFalse($pinned->hasScope('media:write'));
        self::assertTrue($pinned->allowsBlog(7));
        self::assertFalse($pinned->allowsBlog(8));
        self::assertTrue($pinned->isExpired($now));
        self::assertFalse($pinned->isExpired($now->modify('-1 second')));
        $open = new Token('t', 'n', ['blog:write'], null, null, null, $now);
        self::assertTrue($open->allowsBlog(8));
        self::assertFalse($open->isExpired($now->modify('+100 years')));
    }

    public function testTouchWritesAtMostOnceAMinute(): void
    {
        $this->enableTokens();
        $now = new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('UTC'));
        [$token, $plaintext] = $this->repo()->create('ci', ['blog:write'], null, null, $now);

        // Two requests that loaded the token before either wrote: only one UPDATE matches.
        $first = $this->repo()->findByPlaintext($plaintext);
        $second = $this->repo()->findByPlaintext($plaintext);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertTrue($this->repo()->touch($first, $now));
        self::assertFalse($this->repo()->touch($second, $now->modify('+1 second')));

        $fresh = $this->repo()->findByPlaintext($plaintext);
        self::assertNotNull($fresh);
        self::assertEquals($now, $fresh->lastUsedAt);
        self::assertFalse($this->repo()->touch($fresh, $now->modify('+59 seconds')), 'no query inside the minute');
        self::assertTrue($this->repo()->touch($fresh, $now->modify('+60 seconds')));
    }

    public function testRevokeDeletes(): void
    {
        $this->enableTokens();
        [$token, $plaintext] = $this->repo()->create('ci', ['blog:write'], null, null, $this->now());
        self::assertTrue($this->repo()->revoke($token->id));
        self::assertFalse($this->repo()->revoke($token->id));
        self::assertNull($this->repo()->findByPlaintext($plaintext));
    }
}
