<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Module\Tokens\TokenError;
use Xaraya\Module\Tokens\TokenRepository;
use Xaraya\Tests\Support\Fixtures;

final class TokenUsersTest extends TokensTestCase
{
    /** @param list<string> $roles */
    private function user(string $handle, array $roles = [], string $status = 'active'): User
    {
        return (new UserRepository($this->db($this->app()), ['cost' => 4]))
            ->create($handle, "{$handle}@example.com", 'correct horse battery', '', $status, $roles);
    }

    /** Runs token:blog:write and answers "<user handle or guest> <can delete blog posts: yes/no>". */
    private function identity(string $plaintext, ?User $sessionUser = null): string
    {
        $app = $this->app();
        $auth = $app->container()->get(Auth::class);
        $auth->actAs($sessionUser);
        $middleware = $app->container()->get(MiddlewareRegistry::class)->resolve('token:blog:write');
        $final = new CallableHandler(static fn(): ResponseInterface => new Response(200, [], ($auth->user()->handle ?? 'guest') . ' ' . ($auth->can('delete', 'blog', 'post') ? 'yes' : 'no')));
        $request = (new ServerRequest('POST', '/api/x'))->withHeader('Authorization', 'Bearer ' . $plaintext);

        return (string) (new Pipeline([$middleware], $final))->handle($request)->getBody();
    }

    public function testATokenCanBelongToAUser(): void
    {
        $this->enableTokens();
        $ada = $this->user('ada', ['Editors']);
        [$code, $out, $err] = $this->xar(['token:create', '--name=ci', '--scopes=blog:write', '--user=ada']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("'ci' (blog:write; user ada).", $out);
        $lines = explode("\n", trim($out));
        self::assertSame($ada->id, $this->app()->container()->get(TokenRepository::class)->findByPlaintext((string) end($lines))?->userId);
        [, $list] = $this->xar(['token:list']);
        self::assertMatchesRegularExpression('/ci\s+blog:write\s+\*\s+ada\s/', $list);

        $this->user('dee', [], 'disabled');
        foreach ([['--user=nobody', "Unknown user 'nobody'"], ['--user=dee', "The user 'dee' is not active"]] as [$flag, $message]) {
            [$code, , $err] = $this->xar(['token:create', '--name=x', '--scopes=blog:write', $flag]);
            self::assertSame(1, $code);
            self::assertStringContainsString($message, $err);
        }
    }

    public function testAUsersTokenActsAsThatUserAndAPlainTokenAsAGuest(): void
    {
        $this->enableTokens();
        $this->user('ada', ['Editors']);
        $root = $this->user('root', ['Administrators']);
        self::assertSame('ada yes', $this->identity($this->token('blog:write', ['--user=ada'])));
        self::assertSame('guest no', $this->identity($this->token()));
        self::assertSame('guest no', $this->identity($this->token(), $root), 'a session cookie does not lend its user to a token');
    }

    public function testADisabledOrDeletedUsersTokensStopWorking(): void
    {
        $app = $this->enableTokens();
        $ada = $this->user('ada');
        $plaintext = $this->token('blog:write', ['--user=ada']);
        (new UserRepository($this->db($app)))->setStatus($ada, 'disabled');
        try {
            $this->identity($plaintext);
            self::fail('a disabled user\'s token must be refused');
        } catch (TokenError $e) {
            self::assertSame(401, $e->status());
            self::assertSame('Bearer error="invalid_token"', $e->headers()['WWW-Authenticate']);
        }
        $this->db($app)->delete('users', ['id' => $ada->id]);
        self::assertNull($app->container()->get(TokenRepository::class)->findByPlaintext($plaintext), 'deleted with the user');
    }

    public function testEnabledModulesAddScopes(): void
    {
        $this->enableTokens();
        Fixtures::module($this->tmp . '/extra', 'notes', ['scopes' => ['notes:write']]);
        Fixtures::module($this->tmp . '/extra', 'sloppy', ['scopes' => ['write']]);
        $paths = [dirname(__DIR__, 3) . '/modules', $this->tmp . '/extra'];
        $app = fn(): App => $this->boot($paths, ['blog.locks' => $this->tmp . '/locks']);

        [$code, , $err] = $this->xar(['token:create', '--name=x', '--scopes=notes:write'], $app());
        self::assertSame(1, $code);
        self::assertStringContainsString('Scopes must be one or more of: blog:write, media:write', $err);

        $app()->container()->get(ModuleRegistry::class)->enable('notes');
        self::assertSame(['blog:write', 'media:write', 'notes:write'], $app()->container()->get(TokenRepository::class)->scopes());
        [$code, , $err] = $this->xar(['token:create', '--name=x', '--scopes=notes:write,blog:write'], $app());
        self::assertSame(0, $code, $err);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('sloppy: scopes must be strings like "sloppy:write"');
        $app()->container()->get(ModuleRegistry::class)->enable('sloppy');
    }

    public function testTheMigrationKeepsExistingTokens(): void
    {
        $app = $this->enableTokens();
        $plaintext = $this->token('blog:write,media:write');
        $migration = require dirname(__DIR__, 3) . '/modules/tokens/migrations/2026_10_21_200001_add_user_to_tokens.php';
        $migration->down($this->db($app)->schema());
        self::assertArrayNotHasKey('user_id', $this->db($app)->select('tokens')->first() ?? []);
        $migration->up($this->db($app)->schema());
        $token = $this->app()->container()->get(TokenRepository::class)->findByPlaintext($plaintext);
        self::assertNotNull($token);
        self::assertSame(['blog:write', 'media:write'], $token->scopes);
        self::assertNull($token->userId);
    }
}
