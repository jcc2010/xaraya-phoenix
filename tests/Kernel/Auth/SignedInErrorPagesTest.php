<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

/** Error pages a signed-in user sees: never cached by others, and their logout form still works. */
final class SignedInErrorPagesTest extends AuthTestCase
{
    private function site(): App
    {
        $app = $this->probeApp();
        $app->container()->get(BlockRepository::class)->create('login', 'header', null);
        $this->user($app, 'ada');

        return $app;
    }

    private function assertSignedInErrorPage(Browser $browser, int $status): void
    {
        self::assertSame($status, $browser->last?->getStatusCode());
        self::assertSame('private, no-store', $browser->last->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Signed in as Ada', $browser->body());
        self::assertNotNull($browser->csrfField(), 'the logout form carries the session token');
        self::assertSame(303, $browser->post('/logout')->getStatusCode(), 'logging out from the error page works');
        self::assertSame([], $browser->cookies, 'signed out');
    }

    public function testAControllerForbiddenRightAfterLogin(): void
    {
        $browser = new Browser($this->site());
        $browser->login('ada', self::PASSWORD);
        // The first page after login is the error page: its token must already be the stored one.
        $browser->get('/probe/forbidden');
        $this->assertSignedInErrorPage($browser, 403);
    }

    public function testACsrfFailure(): void
    {
        $browser = new Browser($this->site());
        $browser->login('ada', self::PASSWORD);
        $browser->get('/');
        $browser->post('/probe/form', ['_csrf' => 'wrong']);
        $this->assertSignedInErrorPage($browser, 403);
    }

    public function testAFailedRequestStillSavesNothing(): void
    {
        $app = $this->site();
        $browser = new Browser($app);
        $browser->login('ada', self::PASSWORD);
        $browser->get('/probe/forbidden');
        self::assertSame([], $browser->last?->getHeader('Set-Cookie'));
        $session = $app->container()->get(SessionStore::class)->load((string) reset($browser->cookies), new \DateTimeImmutable());
        self::assertNotNull($session);
        self::assertSame(['_csrf'], array_keys($session->data()));
    }

    public function testAnErrorBeforeTheSessionLoadsIsPrivateWithACookie(): void
    {
        $browser = new Browser($this->site());
        $browser->login('ada', self::PASSWORD);
        self::assertSame(404, $browser->get('/no/such/page')->getStatusCode());
        self::assertSame('private, no-store', $browser->last?->getHeaderLine('Cache-Control'));
        self::assertSame([], $browser->last->getHeader('Set-Cookie'));
    }

    public function testAnErrorForATokenIdentityIsPrivate(): void
    {
        $app = $this->site();
        $ada = $this->users($app)->findByHandle('ada');
        self::assertNotNull($ada);
        foreach (['text/html', 'application/json'] as $accept) {
            $response = (new Browser($app))->get('/probe/as/' . $ada->id, ['Accept' => $accept]);
            self::assertSame(403, $response->getStatusCode());
            self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'), $accept);
            self::assertSame([], $response->getHeader('Set-Cookie'));
        }
    }

    public function testAnonymousErrorPagesAreUnchanged(): void
    {
        $app = $this->site();
        foreach (['/no/such/page' => 404, '/probe/forbidden' => 403] as $path => $status) {
            $response = (new Browser($app))->get($path);
            self::assertSame($status, $response->getStatusCode());
            self::assertSame([], $response->getHeader('Set-Cookie'), $path);
            self::assertStringNotContainsString('private', $response->getHeaderLine('Cache-Control'), $path);
            self::assertStringNotContainsString('no-store', $response->getHeaderLine('Cache-Control'), $path);
            self::assertStringNotContainsString('_csrf', $response->getBody()->__toString(), $path);
        }
    }
}
