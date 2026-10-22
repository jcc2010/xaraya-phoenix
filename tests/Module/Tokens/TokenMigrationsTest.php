<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Throwable;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenMigrationsTest extends TokensTestCase
{
    private const MIGRATIONS = __DIR__ . '/../../../modules/tokens/migrations/';

    private static function insertsOrphan(Connection $db): bool
    {
        try {
            $db->insert('tokens', [
                'id' => Ulid::generate(),
                'name' => 'orphan',
                'token_hash' => str_repeat('0', 64),
                'scopes' => ['blog:write'],
                'blog_id' => 999_999,
                'created_at' => new \DateTimeImmutable(),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function testAPlanBInstallLosesTheBlogForeignKeyAndKeepsItsTokens(): void
    {
        $app = $this->enableTokens();
        $this->nativeBlog($app);
        $pinned = $this->token('blog:write,media:write', ['--blog=notes', '--expires=30d']);
        $plain = $this->token();
        $db = $this->db($app);
        $rows = $db->select('tokens')->orderBy('id')->all();

        // Rebuild the table as Plan B's create migration made it: a foreign key to blogs and no user_id.
        $db->schema()->drop('tokens');
        $db->schema()->create('tokens', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->string('token_hash', 64)->unique();
            $t->json('scopes');
            $t->int('blog_id')->nullable();
            $t->datetime('last_used_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at');
            $t->foreign('blog_id', 'blogs');
        });
        foreach ($rows as $row) {
            unset($row['user_id']);
            $db->insert('tokens', $row);
        }
        self::assertFalse(self::insertsOrphan($db), 'Plan B pinned tokens to existing blogs');

        foreach (['2026_10_21_200000_drop_blog_foreign_key_from_tokens', '2026_10_21_200001_add_user_to_tokens'] as $name) {
            (require self::MIGRATIONS . $name . '.php')->up($db->schema());
        }

        $tokens = $this->app()->container()->get(TokenRepository::class);
        $token = $tokens->findByPlaintext($pinned);
        self::assertNotNull($token);
        self::assertSame($this->blogs($app)->find('notes')?->id, $token->blogId);
        self::assertSame(['blog:write', 'media:write'], $token->scopes);
        self::assertNotNull($token->expiresAt);
        self::assertNull($token->userId);
        self::assertNull($tokens->findByPlaintext($plain)?->blogId);
        self::assertSame(array_column($rows, 'id'), array_column($db->select('tokens')->orderBy('id')->all(), 'id'));
        self::assertTrue(self::insertsOrphan($db), 'blog_id is a plain column now');
    }

    public function testAFreshInstallHasNoBlogForeignKey(): void
    {
        $this->enableTokens();
        self::assertTrue(self::insertsOrphan($this->db($this->app())));
    }
}
