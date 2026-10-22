<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Middleware\Authenticate;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class AuthenticateTest extends AuthTestCase
{
    /** @return array{user: ?string, roles: list<string>} */
    private static function whoami(Browser $browser): array
    {
        return json_decode((string) $browser->get('/probe/whoami')->getBody(), true);
    }

    public function testAGuestIsAnonymous(): void
    {
        self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami(new Browser($this->probeApp())));
    }

    public function testTheSessionUserIsTheIdentity(): void
    {
        $app = $this->probeApp();
        $ed = $this->user($app, 'ed', ['Editors']);
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $ed->id);
        self::assertSame(['user' => 'ed', 'roles' => ['Anonymous', 'Members', 'Editors']], self::whoami($browser));
        self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami(new Browser($app)), 'the next request starts as a guest');
    }

    public function testADisabledOrPendingUserIsSignedOutOfTheirSession(): void
    {
        $app = $this->probeApp();
        foreach (['disabled', 'pending'] as $i => $status) {
            $user = $this->user($app, "user{$i}");
            $browser = new Browser($app);
            $browser->post('/probe/login/' . $user->id);
            $this->users($app)->setStatus($user, $status);
            self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami($browser), $status);
            self::assertStringContainsString('Max-Age=0', $browser->last?->getHeaderLine('Set-Cookie') ?? '');
            self::assertSame(0, $this->db($app)->select('sessions')->where('user_id', '=', $user->id)->count());
            self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami($browser), 'and stays a guest afterwards');
        }
    }

    public function testADeletedUserIsAGuest(): void
    {
        $app = $this->probeApp();
        $user = $this->user($app, 'gone');
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $user->id);
        $this->db($app)->delete('users', ['id' => $user->id]);
        self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami($browser));
    }

    public function testASessionNamingAMissingUserIsSignedOut(): void
    {
        // The foreign key normally removes such a session with its user, so hand the middleware one directly.
        $app = $this->probeApp();
        $auth = $app->container()->get(Auth::class);
        $auth->actAs($this->user($app, 'stale'));
        $session = Session::loaded(Session::newId(), 'no-such-user', ['n' => 1], new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $middleware = new Authenticate($auth, $this->users($app));
        $seen = null;
        $middleware->process(
            (new ServerRequest('GET', '/'))->withAttribute(Session::class, $session),
            new CallableHandler(static function () use (&$seen, $auth): ResponseInterface {
                $seen = $auth->user();

                return new Response(204);
            }),
        );
        self::assertNull($seen);
        self::assertNull($session->userId());
        self::assertTrue($session->isDirty());
        self::assertSame([], $session->data());
    }

    public function testRequestsThatMissEveryRouteStartAsAGuest(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $this->user($app, 'ada')->id);
        self::whoami($browser);
        self::assertSame('ada', $app->container()->get(Auth::class)->user()?->handle);
        self::assertSame(404, $app->handle(new ServerRequest('GET', '/no/such/page'))->getStatusCode());
        self::assertNull($app->container()->get(Auth::class)->user());
    }
}
