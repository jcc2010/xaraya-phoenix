<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Http\LoginController;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

final class LoginTest extends AuthTestCase
{
    private static function whoami(Browser $browser): ?string
    {
        return json_decode((string) $browser->get('/probe/whoami')->getBody(), true)['user'];
    }

    private static function page(Browser $browser): string
    {
        return Html::normalize((string) preg_replace('/value="[A-Za-z0-9_-]{43}"/', 'value="TOKEN"', $browser->body()));
    }

    private static function assertFailed(Browser $browser, ResponseInterface $response, string $label): void
    {
        self::assertSame(422, $response->getStatusCode(), $label);
        self::assertStringContainsString('<p class="form-error" id="login-error" role="alert">' . LoginController::FAILED . '</p>', Html::normalize($browser->body()), $label);
        self::assertNull(self::whoami($browser), $label);
    }

    public function testTheLoginPageIsTheSameAccessibleFormInBothEngines(): void
    {
        $pages = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = new Browser($this->installed(['view.engine' => $engine]));
            $response = $browser->get('/login?next=/hello');
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString("form-action 'self'", $response->getHeaderLine('Content-Security-Policy'));
            self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
            $pages[$engine] = self::page($browser);
        }
        self::assertSame($pages['php'], $pages['twig']);
        $html = $pages['php'];
        self::assertStringContainsString('<title>Log in</title>', $html);
        self::assertStringContainsString('<form class="auth-form" method="post" action="/login"><input type="hidden" name="_csrf" value="TOKEN"><input type="hidden" name="next" value="/hello">', $html);
        self::assertStringContainsString('<label for="login">Handle or email</label><input id="login" name="login" value="" autocomplete="username" autocapitalize="none" spellcheck="false" required>', $html);
        self::assertStringContainsString('<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>', $html);
    }

    public function testAUserLogsInAndOut(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada', ['Administrators']);
        $browser = new Browser($app);
        $browser->get('/login');
        $anonymous = $browser->cookies['xar_session'];

        $response = $browser->login('ada', self::PASSWORD);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertNotSame($anonymous, $browser->cookies['xar_session'], 'login gives the session a new id');
        self::assertSame('ada', self::whoami($browser));
        self::assertNotNull($this->users($app)->find($ada->id)?->lastLogin);

        $planted = new Browser($app);
        $planted->cookies['xar_session'] = $anonymous;
        self::assertNull(self::whoami($planted), 'the pre-login id is dead');

        $browser->get('/login');
        $out = $browser->post('/logout');
        self::assertSame(303, $out->getStatusCode());
        self::assertSame('/', $out->getHeaderLine('Location'));
        self::assertStringContainsString('Max-Age=0', $out->getHeaderLine('Set-Cookie'));
        self::assertNull(self::whoami($browser));
    }

    public function testLoginAndLogoutNeedTheCsrfToken(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $browser = new Browser($app);
        self::assertSame(403, $browser->post('/login', ['login' => 'ada', 'password' => self::PASSWORD])->getStatusCode(), 'no session, no token');
        $browser->get('/login');
        self::assertSame(403, $browser->post('/login', ['login' => 'ada', 'password' => self::PASSWORD, '_csrf' => ''])->getStatusCode(), 'a session but no token');
        $other = new Browser($app);
        $other->get('/login');
        $browser->get('/login');
        $foreign = ['login' => 'ada', 'password' => self::PASSWORD, '_csrf' => (string) $other->csrfField()];
        self::assertSame(403, $browser->post('/login', $foreign)->getStatusCode(), 'a token from another session is refused');
        self::assertNull(self::whoami($browser));

        $browser->login('ada', self::PASSWORD);
        self::assertSame(403, $browser->post('/logout', ['_csrf' => 'x'])->getStatusCode());
        self::assertSame('ada', self::whoami($browser));
    }

    public function testEveryFailureShowsTheSameMessage(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $this->user($app, 'dee', [], 'disabled');
        $this->user($app, 'pip', [], 'pending');
        foreach ([['ada', 'wrong password'], ['nobody', self::PASSWORD], ['dee', self::PASSWORD], ['pip', self::PASSWORD], ['', '']] as [$login, $password]) {
            $browser = new Browser($app);
            $response = $browser->login($login, $password);
            self::assertStringContainsString('aria-invalid="true" aria-describedby="login-error"', $browser->body());
            self::assertFailed($browser, $response, $login);
        }
        $browser = new Browser($app);
        $browser->login('<script>alert(1)</script>', 'x');
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $browser->body());
    }

    public function testANulOrOverLongPasswordIsAnOrdinaryFailedLogin(): void
    {
        $app = $this->probeApp();
        $long = str_repeat('a', UserRepository::MAX_PASSWORD_BYTES);
        $this->user($app, 'ada');
        $this->user($app, 'max', [], 'active', $long);
        foreach ([['ada', "x\0y"], ['ada', self::PASSWORD . "\0"], ['nobody', "x\0y"], ['ada', str_repeat('p', 100_000)], ['max', $long . 'b']] as $i => [$login, $password]) {
            $browser = new Browser($app, "198.51.100.{$i}");
            self::assertFailed($browser, $browser->login($login, $password), "case {$i}");
        }
        $browser = new Browser($app);
        self::assertSame(303, $browser->login('max', $long)->getStatusCode(), 'a 72-byte password still works');
    }

    public function testFiveFailuresLockAnAccountForAnIpWhetherItIsNamedByHandleOrEmail(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $attacker = new Browser($app, '198.51.100.9');
        foreach (['ada', 'ADA@example.com', 'ada', 'ada@example.com', 'Ada'] as $login) {
            self::assertSame(422, $attacker->login($login, 'guess')->getStatusCode());
        }
        $locked = $attacker->login('ada@example.com', self::PASSWORD);
        self::assertSame(429, $locked->getStatusCode());
        self::assertGreaterThan(840, (int) $locked->getHeaderLine('Retry-After'));
        self::assertStringContainsString('Too many failed attempts. Try again in 15 minutes.', $attacker->body());
        self::assertNull(self::whoami($attacker));
        self::assertSame(429, $attacker->login('ada', self::PASSWORD)->getStatusCode(), 'the handle shares the lock');

        $elsewhere = new Browser($app, '198.51.100.10');
        self::assertSame(303, $elsewhere->login('ada', self::PASSWORD)->getStatusCode(), 'another IP is not locked out');

        $this->db($app)->query('UPDATE {throttle} SET reset_at = ?', [new DateTimeImmutable('-1 second')]);
        self::assertSame(303, $attacker->login('ada', self::PASSWORD)->getStatusCode(), 'the lock ends with its window');
        self::assertSame(0, $this->db($app)->select('throttle')->where('reset_at', '>', new DateTimeImmutable())->count(), 'a success clears the counter');
    }

    public function testAnUnknownLoginIsCountedByItsLowerCasedNameAndAnIpv6ClientByItsSlash64(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        foreach (['Nobody', 'NOBODY', 'nobody', ' nobody ', 'NoBody'] as $i => $login) {
            $browser = new Browser($app, '2001:db8:1:2::' . dechex($i + 1));
            self::assertSame(422, $browser->login($login, 'guess')->getStatusCode(), $login);
        }
        $same64 = new Browser($app, '2001:0db8:0001:0002:ffff::9');
        self::assertSame(429, $same64->login('nobody', 'guess')->getStatusCode(), 'the same /64, in another notation, shares the counter');
        $other64 = new Browser($app, '2001:db8:1:3::1');
        self::assertSame(422, $other64->login('nobody', 'guess')->getStatusCode(), 'another /64 does not');
        self::assertSame(303, $same64->login('ada', self::PASSWORD)->getStatusCode(), 'another account from the same /64 is not locked');
    }

    public function testAnUnknownLoginNeverSharesAUsersCounter(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $attacker = new Browser($app, '198.51.100.11');
        // Ada's id is not a login, so it is an unknown name: it must not count against Ada's own counter.
        $name = mb_strtolower($ada->id);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $attacker->login($name, 'guess')->getStatusCode());
        }
        self::assertSame(429, $attacker->login($name, 'guess')->getStatusCode());
        self::assertSame(303, $attacker->login('ada', self::PASSWORD)->getStatusCode(), 'Ada is not locked out');
    }

    /** Swaps in a UserRepository whose password checks are counted. */
    private function countingApp(int &$checks): App
    {
        $app = $this->probeApp();
        $app->container()->set(UserRepository::class, static function (Container $c) use (&$checks): UserRepository {
            return new UserRepository($c->get(Connection::class), ['cost' => 4], static function (string $password, string $hash) use (&$checks): bool {
                $checks++;

                return password_verify($password, $hash);
            });
        });

        return $app;
    }

    public function testEveryLoginAttemptCostsExactlyOnePasswordCheckUnlessItIsLocked(): void
    {
        $checks = 0;
        $app = $this->countingApp($checks);
        $this->user($app, 'ada');
        $cases = [
            'an unknown login' => ['nobody', 'wrong password', 422],
            'an unknown email' => ['nobody@example.com', 'wrong password', 422],
            'a wrong password' => ['ada', 'wrong password', 422],
            'a NUL password' => ['ada', "a\0b", 422],
            'a NUL password for an unknown login' => ['nobody', "a\0b", 422],
            'an over-long password' => ['ada', str_repeat('x', 200), 422],
            'an empty login' => ['', '', 422],
            'the right password' => ['ada', self::PASSWORD, 303],
        ];
        $ip = 0;
        foreach ($cases as $label => [$login, $password, $status]) {
            $browser = new Browser($app, '198.51.100.' . ++$ip);
            $browser->get('/login');
            $checks = 0;
            self::assertSame($status, $browser->post('/login', ['login' => $login, 'password' => $password, 'next' => '/'])->getStatusCode(), $label);
            self::assertSame(1, $checks, "{$label} costs exactly one password check");
        }

        $attacker = new Browser($app, '198.51.100.200');
        for ($i = 0; $i < LoginController::MAX_FAILURES; $i++) {
            $attacker->login('ada', 'guess');
        }
        $checks = 0;
        self::assertSame(429, $attacker->login('ada', self::PASSWORD)->getStatusCode());
        self::assertSame(0, $checks, 'a locked attempt never checks the password');
    }

    public function testNextOnlyLeadsToALocalPath(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $cases = [
            '/blog/notes?page=2' => '/blog/notes?page=2',
            '/' => '/',
            '/a%20b' => '/a%20b',
            '//evil.example/' => '/',
            '/\\evil.example' => '/',
            '/\\evil.com' => '/',
            '\\\\evil.com' => '/',
            '%2F%2Fevil.com' => '/',
            '/%2F/evil.com' => '/',
            '/%5Cevil.com' => '/',
            'https://evil.example/' => '/',
            'https:evil.com' => '/',
            'javascript:alert(1)' => '/',
            'evil.com' => '/',
            "/\tevil" => '/',
            "/a\r\nSet-Cookie: x=1" => '/',
            '/a%0d%0aSet-Cookie:%20x=1' => '/',
            '/a%00b' => '/',
            "/a\x7fb" => '/',
            '' => '/',
        ];
        foreach ($cases as $next => $expected) {
            self::assertSame($expected, LoginController::safeNext($next), json_encode($next) ?: '');
            $browser = new Browser($app);
            self::assertSame($expected, $browser->login('ada', self::PASSWORD, $next)->getHeaderLine('Location'), json_encode($next) ?: '');
        }
        self::assertSame('/', LoginController::safeNext(['/x']));
        self::assertSame('/', LoginController::safeNext(null));
        self::assertSame('/', LoginController::safeNext('/' . str_repeat('a', 2000)));

        $browser = new Browser($app);
        $browser->get('/login');
        $direct = $browser->post('/login', ['login' => 'ada', 'password' => self::PASSWORD, 'next' => "//evil.example/\r\n"]);
        self::assertSame('/', $direct->getHeaderLine('Location'), 'the POSTed next is checked too, not only the page');
    }

    public function testLoginUpgradesAnOutdatedPasswordHash(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $stronger = $this->app(['auth.password_options' => ['cost' => 5], 'session.gc_probability' => 0], [self::PROBE]);
        self::assertSame(303, (new Browser($stronger))->login('ada', self::PASSWORD)->getStatusCode());
        self::assertStringStartsWith('$2y$05$', (string) $this->db($app)->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$ada->id]));
    }

    public function testTheNoticeAfterAPasswordReset(): void
    {
        $browser = new Browser($this->installed());
        $browser->get('/login?reset=1');
        self::assertStringContainsString('<p class="form-notice" role="status">Your password has been changed. Log in with the new one.</p>', Html::normalize($browser->body()));
        $browser->get('/login');
        self::assertStringNotContainsString('form-notice', $browser->body());
    }
}
