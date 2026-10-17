<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Xaraya\Module\Tokens\TokenRepository;

final class TokenCommandsTest extends TokensTestCase
{
    public function testTokensNeedTheBlogModule(): void
    {
        [$code, , $err] = $this->xar(['module:enable', 'tokens']);
        self::assertSame(1, $code);
        self::assertStringContainsString("requires 'blog'", $err);
    }

    public function testCreatePrintsTheTokenOnceAndStoresItsHash(): void
    {
        $app = $this->enableTokens();
        $this->nativeBlog($app);
        [$code, $out, $err] = $this->xar(['token:create', '--name=phone', '--scopes=blog:write,media:write', '--blog=notes', '--expires=30d']);
        self::assertSame(0, $code, $err);
        $lines = explode("\n", trim($out));
        self::assertCount(3, $lines);
        self::assertMatchesRegularExpression("/^Created token [0-9a-z]{26} 'phone' \\(blog:write, media:write; blog notes; expires \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d UTC\\)\\.$/", $lines[0]);
        self::assertSame('Copy it now; it is not shown again:', $lines[1]);
        self::assertMatchesRegularExpression('/^xar_[0-9A-Za-z]{40}$/D', $lines[2]);

        $token = $this->app()->container()->get(TokenRepository::class)->findByPlaintext($lines[2]);
        self::assertNotNull($token);
        self::assertSame($this->blogs($this->app())->find('notes')?->id, $token->blogId);
        self::assertNotNull($token->expiresAt);
        self::assertEqualsWithDelta($this->now()->modify('+30 days')->getTimestamp(), $token->expiresAt->getTimestamp(), 5);

        [$code, $list] = $this->xar(['token:list']);
        self::assertSame(0, $code);
        self::assertStringContainsString($token->id, $list);
        self::assertStringContainsString('phone', $list);
        self::assertStringContainsString('notes', $list);
        self::assertStringNotContainsString($lines[2], $list);
    }

    public function testCreateRejectsBadInput(): void
    {
        $this->enableTokens();
        foreach ([
            [['--name=x'], 'Usage'],
            [['--name=x', '--scopes=admin'], 'Scopes must be'],
            [['--name=x', '--scopes=blog:write', '--blog=nope'], "Unknown blog 'nope'"],
            [['--name=x', '--scopes=blog:write', '--expires=tomorrow'], '--expires must be'],
            [['--name=x', '--scopes=blog:write', '--expires=0d'], '--expires must be'],
        ] as [$args, $message]) {
            [$code, , $err] = $this->xar(['token:create', ...$args]);
            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err);
        }
        self::assertSame([], $this->app()->container()->get(TokenRepository::class)->all());
    }

    public function testRevoke(): void
    {
        $this->enableTokens();
        $plaintext = $this->token();
        $repo = $this->app()->container()->get(TokenRepository::class);
        $id = $repo->findByPlaintext($plaintext)?->id;
        self::assertNotNull($id);

        [$code, $out] = $this->xar(['token:revoke', $id]);
        self::assertSame(0, $code);
        self::assertSame("Revoked token {$id}.\n", $out);
        self::assertNull($repo->findByPlaintext($plaintext));

        [$code, , $err] = $this->xar(['token:revoke', $id]);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown token '{$id}'", $err);
    }
}
