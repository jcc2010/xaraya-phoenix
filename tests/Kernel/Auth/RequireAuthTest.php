<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use LogicException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Http\Middleware\RequireAuth;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class RequireAuthTest extends AuthTestCase
{
    /** @param list<string> $roles */
    private function signedIn(App $app, string $handle, array $roles = []): Browser
    {
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $this->user($app, $handle, $roles)->id);

        return $browser;
    }

    public function testAGuestIsSentToTheLoginPageFromAnHtmlGet(): void
    {
        $response = (new Browser($this->probeApp()))->get('/probe/members?tab=a&b=1');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?next=%2Fprobe%2Fmembers%3Ftab%3Da%26b%3D1', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheLoginRedirectIsAlwaysALocalPath(): void
    {
        $guest = new Browser($this->probeApp());
        // A path that begins with a second slash must not turn the redirect into a protocol-relative URL.
        foreach (['/probe/members?next=//evil.example', '/probe/members?u=http://evil.example'] as $path) {
            $location = $guest->get($path)->getHeaderLine('Location');
            self::assertStringStartsWith('/login?next=%2Fprobe%2Fmembers', $location);
            self::assertStringNotContainsString('://', $location);
            self::assertStringNotContainsString('//', $location, 'the next value is fully percent-encoded');
        }
    }

    public function testAGuestGetsAJson401OnJsonRequestsAndA401OnOtherMethods(): void
    {
        $guest = new Browser($this->probeApp());
        foreach ([$guest->get('/probe/members.json'), $guest->get('/probe/members', ['Accept' => 'application/json'])] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame(['error' => ['status' => 401, 'message' => 'Log in to continue']], json_decode((string) $response->getBody(), true));
        }
        $guest->get('/probe/form');
        self::assertSame(401, $guest->post('/probe/members')->getStatusCode());
    }

    public function testASignedInUserPassesAuth(): void
    {
        $app = $this->probeApp();
        $member = $this->signedIn($app, 'mia');
        self::assertSame('mia', (string) $member->get('/probe/members')->getBody());
        self::assertSame('mia', (string) $member->get('/probe/members.json')->getBody());
    }

    public function testALevelCheckIs403ForMembersAnd200ForAdministrators(): void
    {
        $app = $this->probeApp();
        $denied = $this->signedIn($app, 'mia')->get('/probe/admin');
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('nosniff', $denied->getHeaderLine('X-Content-Type-Options'), 'secureHtml still wraps the error');
        self::assertSame('root', (string) $this->signedIn($app, 'root', ['Administrators'])->get('/probe/admin')->getBody());
        self::assertSame(302, (new Browser($app))->get('/probe/admin')->getStatusCode(), 'a guest is asked to log in, not refused');
    }

    public function testASignedInUserWhoFailsGetsAJson403OnJsonRequests(): void
    {
        $response = $this->signedIn($this->probeApp(), 'mia')->get('/probe/admin', ['Accept' => 'application/json']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(403, json_decode((string) $response->getBody(), true)['error']['status']);
    }

    public function testALevelAnonymousHoldsLetsGuestsIn(): void
    {
        self::assertSame(200, (new Browser($this->probeApp()))->get('/probe/public')->getStatusCode());
    }

    public function testAnOwnGrantPassesTheRouteButTheControllerChecksTheItem(): void
    {
        $app = $this->probeApp();
        $app->container()->get(Grants::class)->grant('Members', 'probe', 'note', 'own', 'delete');
        $member = new Browser($app);
        $user = $this->user($app, 'mia');
        $member->post('/probe/login/' . $user->id);

        // The route middleware asks about the whole component, so an own-only grant passes it...
        self::assertSame(200, $member->get('/probe/notes')->getStatusCode());
        // ...and the controller's instance check refuses another user's item but allows the user's own.
        self::assertSame(403, $member->get('/probe/notes/999999')->getStatusCode());
        self::assertSame('deleted', (string) $member->get('/probe/notes/' . $user->id)->getBody());
    }

    public function testAMalformedSpecIsAProgrammingError(): void
    {
        $auth = $this->installed()->container()->get(Auth::class);
        foreach ([['blog'], ['blog.post'], ['blog.post.superuser'], ['blog.post.none'], ['.post.read'], ['a.b.c.read']] as $args) {
            try {
                new RequireAuth($auth, $args);
                self::fail('accepted ' . $args[0]);
            } catch (LogicException $e) {
                self::assertStringContainsString('<module>.<component>.<level>', $e->getMessage());
            }
        }
    }

    public function testTheAliasResolvesAndRejectsABadSpecWhenTheStackIsBuilt(): void
    {
        $registry = $this->installed()->container()->get(MiddlewareRegistry::class);
        self::assertInstanceOf(RequireAuth::class, $registry->resolve('auth'));
        self::assertInstanceOf(RequireAuth::class, $registry->resolve('auth:blog.post.delete'));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("not 'blog.post.superuser'");
        $registry->stack(['auth:blog.post.superuser']);
    }
}
