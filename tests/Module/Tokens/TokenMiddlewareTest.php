<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenMiddlewareTest extends TokensTestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableTokens();
        $this->nativeBlog($this->app);
        $this->nativeBlog($this->app, ['handle' => 'other', 'title' => 'Other']);
    }

    private function send(ServerRequestInterface $request, string $spec = 'token:blog:write'): ResponseInterface
    {
        $middleware = $this->app()->container()->get(MiddlewareRegistry::class)->resolve($spec);
        $final = new CallableHandler(static function (ServerRequestInterface $r): ResponseInterface {
            $token = $r->getAttribute(Token::class);

            return new Response(200, ['X-Token' => $token instanceof Token ? $token->id : 'none']);
        });

        return (new Pipeline([$middleware], $final))->handle($request);
    }

    private static function bearer(string $token, string $uri = '/api/x'): ServerRequestInterface
    {
        return (new ServerRequest('POST', $uri))->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function rejects(ServerRequestInterface $request, int $status, string $error, string $challenge, string $spec = 'token:blog:write'): void
    {
        try {
            $this->send($request, $spec);
            self::fail("expected {$status} {$error}");
        } catch (TokenError $e) {
            self::assertSame($status, $e->status());
            self::assertSame($error, $e->error);
            self::assertSame($challenge, $e->headers()['WWW-Authenticate']);
        }
    }

    public function testAcceptsAValidTokenAndSetsTheAttribute(): void
    {
        $plaintext = $this->token('blog:write,media:write');
        $id = $this->app->container()->get(TokenRepository::class)->findByPlaintext($plaintext)?->id;
        self::assertSame($id, $this->send(self::bearer($plaintext))->getHeaderLine('X-Token'));
        self::assertSame($id, $this->send(self::bearer($plaintext), 'token:blog:write,media:write')->getHeaderLine('X-Token'));
        self::assertSame($id, $this->send((new ServerRequest('GET', '/x'))->withHeader('Authorization', 'bearer  ' . $plaintext))->getHeaderLine('X-Token'));
    }

    public function testMissingMalformedUnknownAndExpiredTokensAre401(): void
    {
        $this->rejects(new ServerRequest('POST', '/api/x'), 401, 'unauthorized', 'Bearer');
        $this->rejects((new ServerRequest('POST', '/api/x'))->withHeader('Authorization', 'Basic dTpw'), 401, 'unauthorized', 'Bearer error="invalid_token"');
        $this->rejects(self::bearer('xar_' . str_repeat('a', 40)), 401, 'unauthorized', 'Bearer error="invalid_token"');

        $plaintext = $this->token('blog:write', ['--expires=1d']);
        $this->db($this->app)->update('tokens', ['expires_at' => $this->now()->modify('-1 second')], ['token_hash' => hash('sha256', $plaintext)]);
        $this->rejects(self::bearer($plaintext), 401, 'unauthorized', 'Bearer error="invalid_token"');
    }

    public function testATokenInTheQueryStringIsIgnored(): void
    {
        $plaintext = $this->token();
        $this->rejects(new ServerRequest('POST', '/api/x?access_token=' . $plaintext), 401, 'unauthorized', 'Bearer');
    }

    public function testAMissingScopeIs403(): void
    {
        $this->rejects(self::bearer($this->token('media:write')), 403, 'insufficient_scope', 'Bearer error="insufficient_scope", scope="blog:write"');
        $this->rejects(self::bearer($this->token('blog:write')), 403, 'insufficient_scope', 'Bearer error="insufficient_scope", scope="media:write"', 'token:blog:write,media:write');
    }

    public function testAPinnedTokenOnlyPassesForItsOwnHandle(): void
    {
        $plaintext = $this->token('blog:write', ['--blog=notes']);
        self::assertSame(200, $this->send(self::bearer($plaintext)->withAttribute('handle', 'notes'))->getStatusCode());
        $this->rejects(self::bearer($plaintext)->withAttribute('handle', 'other'), 403, 'forbidden', 'Bearer error="insufficient_scope"');
        self::assertSame(200, $this->send(self::bearer($this->token())->withAttribute('handle', 'other'))->getStatusCode(), 'an unpinned token writes anywhere');
    }

    public function testRecordsUseAtMostOnceAMinute(): void
    {
        $plaintext = $this->token();
        $repo = $this->app->container()->get(TokenRepository::class);
        self::assertNull($repo->findByPlaintext($plaintext)?->lastUsedAt);
        $this->send(self::bearer($plaintext));
        $used = $repo->findByPlaintext($plaintext)?->lastUsedAt;
        self::assertNotNull($used);

        $this->db($this->app)->update('tokens', ['last_used_at' => $used->modify('-30 seconds')], ['token_hash' => hash('sha256', $plaintext)]);
        $this->send(self::bearer($plaintext));
        self::assertEquals($used->modify('-30 seconds'), $repo->findByPlaintext($plaintext)?->lastUsedAt, 'a use inside the minute writes nothing');
    }

    public function testTheAliasNeedsAScope(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("needs a scope");
        $this->send(new ServerRequest('GET', '/'), 'token');
    }
}
