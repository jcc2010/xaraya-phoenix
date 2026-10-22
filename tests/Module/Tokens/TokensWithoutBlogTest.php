<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

/**
 * The tokens module on a site without the blog module, and what a token request may not do to sessions.
 * The fixture module "apiprobe" declares the apiprobe:write scope and token-protected routes.
 */
final class TokensWithoutBlogTest extends AuthTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/tokens';

    /** @return list<string> */
    private static function paths(): array
    {
        return [dirname(__DIR__, 3) . '/modules', self::FIXTURES];
    }

    private function tokensApp(): App
    {
        $overrides = ['session.gc_probability' => 0];
        $modules = $this->installed($overrides, self::paths())->container()->get(ModuleRegistry::class);
        $modules->enable('tokens');
        $modules->enable('apiprobe');
        $app = $this->app($overrides, self::paths());
        self::assertFalse($app->container()->get(ModuleRegistry::class)->isEnabled('blog'));

        return $app;
    }

    /** @param list<string> $extra */
    private function token(App $app, array $extra = []): string
    {
        [$code, $out, $err] = $this->xar($app, ['token:create', '--name=ci', '--scopes=apiprobe:write', ...$extra]);
        self::assertSame(0, $code, $out . $err);
        $lines = explode("\n", trim($out));

        return (string) end($lines);
    }

    /** @param array<string, string> $headers */
    private static function bearer(Browser $browser, string $method, string $path, string $token, array $headers = []): ResponseInterface
    {
        return $browser->send($browser->request($method, $path, ['Authorization' => 'Bearer ' . $token, ...$headers]));
    }

    public function testAFreshInstallWithoutTheBlogCreatesTokensAndAuthenticatesWithThem(): void
    {
        $app = $this->tokensApp();
        $this->user($app, 'ada', ['Administrators']);
        [$code, $out, $err] = $this->xar($app, ['token:create', '--name=ci', '--scopes=apiprobe:write', '--user=ada']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("'ci' (apiprobe:write; user ada).", $out);
        $lines = explode("\n", trim($out));
        $plaintext = (string) end($lines);

        [$code, $list, $err] = $this->xar($app, ['token:list']);
        self::assertSame(0, $code, $err);
        self::assertMatchesRegularExpression('/ci\s+apiprobe:write\s+\*\s+ada\s/', $list);

        [$code, , $err] = $this->xar($app, ['token:create', '--name=x', '--scopes=apiprobe:write', '--blog=notes']);
        self::assertSame(1, $code);
        self::assertStringContainsString('--blog needs the blog module', $err);

        $browser = new Browser($app);
        $response = self::bearer($browser, 'GET', '/apiprobe/whoami', $plaintext);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ada admin', (string) $response->getBody());
        self::assertSame('guest not-admin', (string) self::bearer($browser, 'GET', '/apiprobe/whoami', $this->token($app))->getBody());
        self::assertSame(401, $browser->get('/apiprobe/whoami')->getStatusCode());
        self::assertSame(401, self::bearer($browser, 'GET', '/apiprobe/whoami', 'xar_' . str_repeat('a', 40))->getStatusCode());
    }

    public function testATokenRequestNeverMintsOrSavesASession(): void
    {
        $app = $this->tokensApp();
        $this->user($app, 'ada', ['Administrators']);
        $plaintext = $this->token($app, ['--user=ada']);

        // A user's token renders the login block (a logout form with csrf()) and tries to write the session.
        $browser = new Browser($app);
        $response = self::bearer($browser, 'POST', '/apiprobe/page', $plaintext);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('csrf[]', (string) $response->getBody());
        self::assertStringContainsString('logout-form', (string) $response->getBody());
        self::assertStringNotContainsString('name="_csrf"', (string) $response->getBody());
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(0, $this->db($app)->select('sessions')->count());

        // Nor does it touch, renew or write a browser session that came along with it.
        $root = $this->user($app, 'root', ['Administrators']);
        $session = $this->signIn($app, $browser, $root->id);
        $before = $this->db($app)->select('sessions')->first();
        $response = self::bearer($browser, 'POST', '/apiprobe/page', $plaintext);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame($session, $browser->cookies['xar_session'] ?? null);
        self::assertSame($before, $this->db($app)->select('sessions')->first());

        // Even a session the request would end (its user was disabled) is left for the next browser request.
        $this->users($app)->setStatus($root, 'disabled');
        $response = self::bearer($browser, 'POST', '/apiprobe/page', $plaintext);
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertSame($before, $this->db($app)->select('sessions')->first());

        // A response that already says no-store keeps its own Cache-Control.
        self::assertSame('no-store', self::bearer($browser, 'GET', '/apiprobe/cached', $plaintext)->getHeaderLine('Cache-Control'));
    }

    /** Signs $userId in through the login form and returns the session cookie's value. */
    private function signIn(App $app, Browser $browser, string $userId): string
    {
        $user = $this->users($app)->find($userId);
        self::assertNotNull($user);
        $response = $browser->login($user->handle, self::PASSWORD);
        self::assertSame(303, $response->getStatusCode(), $browser->body());
        $cookie = $browser->cookies['xar_session'] ?? null;
        self::assertIsString($cookie);

        return $cookie;
    }
}
