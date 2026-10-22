<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class SessionTest extends AuthTestCase
{
    /** @return array{0: string, 1: list<string>} the cookie value and its attributes */
    private static function cookie(Browser $browser): array
    {
        $line = $browser->last?->getHeaderLine('Set-Cookie') ?? '';
        self::assertMatchesRegularExpression('/^xar_session=[A-Za-z0-9_-]{43};/', $line);
        $parts = array_map('trim', explode(';', $line));

        return [substr((string) array_shift($parts), strlen('xar_session=')), $parts];
    }

    private function rows(App $app): int
    {
        return $this->db($app)->select('sessions')->count();
    }

    public function testAPageThatDoesNotUseTheSessionSetsNoCookieAndStoresNothing(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $response = $browser->get('/probe/plain');
        self::assertSame('plain', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('Cache-Control'));
        self::assertSame(0, $this->rows($app));
    }

    public function testAChangedSessionIsStoredByTheHashOfItsCookie(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        self::assertSame('1', (string) $browser->get('/probe/count')->getBody());
        [$id, $attributes] = self::cookie($browser);
        self::assertSame(['Path=/', 'HttpOnly', 'SameSite=Lax'], $attributes);
        self::assertSame('private, no-cache', $browser->last?->getHeaderLine('Cache-Control'), 'a session page is never shared-cached');

        $row = $this->db($app)->select('sessions')->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $id), $row['id']);
        self::assertStringNotContainsString($id, (string) json_encode($row));

        self::assertSame('2', (string) $browser->get('/probe/count')->getBody());
        self::assertSame(1, $this->rows($app));
        self::assertSame($id, $browser->cookies['xar_session'], 'the id only changes at login and logout');
    }

    public function testTheCookieIsSecureExceptInDebugModeOverPlainHttp(): void
    {
        $cases = [
            [true, 'http://xar.test', false],
            [true, 'https://xar.test', true],
            [false, 'http://xar.test', true],
            [false, 'https://xar.test', true],
        ];
        foreach ($cases as [$debug, $origin, $secure]) {
            $browser = new Browser($this->probeApp(['app.debug' => $debug]), origin: $origin);
            $browser->get('/probe/count');
            [, $attributes] = self::cookie($browser);
            self::assertContains('HttpOnly', $attributes);
            self::assertContains('SameSite=Lax', $attributes);
            self::assertSame($secure, in_array('Secure', $attributes, true), ($debug ? 'debug ' : '') . $origin);
        }
    }

    public function testLoginGivesANewIdAndTheOldOneStopsWorking(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $planted = $browser->cookies['xar_session'];

        $browser->post('/probe/login/' . $ada->id);
        $fresh = $browser->cookies['xar_session'];
        self::assertNotSame($planted, $fresh);
        self::assertSame(['user' => $ada->id, 'n' => null], json_decode((string) $browser->get('/probe/session')->getBody(), true));

        $attacker = new Browser($app);
        $attacker->cookies['xar_session'] = $planted;
        self::assertSame(['user' => null, 'n' => null], json_decode((string) $attacker->get('/probe/session')->getBody(), true));
        self::assertSame(1, $this->rows($app));
    }

    public function testLogoutDeletesTheSessionAndExpiresTheCookie(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $ada->id);
        $id = $browser->cookies['xar_session'];
        $response = $browser->post('/probe/logout');
        self::assertSame('xar_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(0, $this->rows($app));
        self::assertArrayNotHasKey('xar_session', $browser->cookies);
        self::assertNull($app->container()->get(SessionStore::class)->load($id, new DateTimeImmutable()));
    }

    public function testExpiredUnknownAndMalformedCookiesStartAFreshSession(): void
    {
        $app = $this->probeApp(['session.lifetime' => 1]);
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $this->db($app)->query('UPDATE {sessions} SET expires_at = ?', [new DateTimeImmutable('-1 second')]);
        self::assertSame('1', (string) $browser->get('/probe/count')->getBody());

        foreach (['junk', str_repeat('A', 43), "x'; DROP TABLE sessions; --"] as $cookie) {
            $other = new Browser($app);
            $other->cookies['xar_session'] = $cookie;
            self::assertSame('1', (string) $other->get('/probe/count')->getBody());
        }
    }

    public function testExpiredSessionsAreCollected(): void
    {
        $app = $this->probeApp();
        (new Browser($app))->get('/probe/count');
        $this->db($app)->query('UPDATE {sessions} SET expires_at = ?', [new DateTimeImmutable('-1 second')]);
        (new Browser($this->app(['session.gc_probability' => 1], [self::PROBE])))->get('/probe/count');
        self::assertSame(1, $this->rows($app), 'the expired row went; the new one stays');
    }

    public function testAnUnchangedSessionIsTouchedAtMostOnceAMinute(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $activity = fn(): string => (string) $this->db($app)->fetchValue('SELECT last_activity FROM {sessions}');
        $old = (new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db($app)->query('UPDATE {sessions} SET last_activity = ?', [$old]);
        $browser->get('/probe/session');
        self::assertSame($old, $activity(), 'not within a minute');
        $older = (new DateTimeImmutable('-90 seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db($app)->query('UPDATE {sessions} SET last_activity = ?', [$older]);
        $browser->get('/probe/session');
        self::assertGreaterThan($older, $activity());
        self::assertFalse($browser->last?->hasHeader('Set-Cookie'));
    }

    public function testAPlantedWellFormedIdIsNeverAdopted(): void
    {
        $app = $this->probeApp();
        $planted = str_repeat('A', 43);
        $browser = new Browser($app);
        $browser->cookies['xar_session'] = $planted;
        $browser->get('/probe/count');
        [$id] = self::cookie($browser);
        self::assertNotSame($planted, $id, 'the server picks every id; a client-chosen one is ignored');
        self::assertNull($this->db($app)->fetchValue('SELECT id FROM {sessions} WHERE id = ?', [hash('sha256', $planted)]));
    }

    public function testNonStringAndOversizedCookiesStartAFreshSession(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        foreach ([['x'], str_repeat('A', 44), str_repeat('A', 4096), "AAAA\0" . str_repeat('A', 38)] as $cookie) {
            $response = $app->handle($browser->request('GET', '/probe/count')->withCookieParams(['xar_session' => $cookie]));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('1', (string) $response->getBody());
        }
    }

    public function testIdleSessionsExpireServerSideWhateverTheCookieSays(): void
    {
        $app = $this->probeApp(['session.lifetime' => 5]);
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $id = $browser->cookies['xar_session'];
        $store = $app->container()->get(SessionStore::class);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        self::assertNotNull($store->load($id, $now->modify('+4 minutes')));
        self::assertNull($store->load($id, $now->modify('+5 minutes +1 second')), 'idle past session.lifetime');
    }

    public function testNewIdsAreRandom32ByteValues(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $id = Session::newId();
            self::assertMatchesRegularExpression(Session::ID, $id);
            self::assertSame(32, strlen((string) base64_decode(strtr($id, '-_', '+/'), true)));
            $ids[$id] = true;
        }
        self::assertCount(200, $ids);
        self::assertSame(hash('sha256', $id), Session::hash($id));
    }

    public function testNeitherTheSessionIdNorItsDataReachTheLog(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $id = $browser->cookies['xar_session'];
        self::assertSame(500, $browser->get('/probe/boom')->getStatusCode());

        $log = implode('', array_map('file_get_contents', glob($this->tmp . '/logs/*.log') ?: []));
        self::assertStringContainsString('probe boom', $log);
        self::assertStringNotContainsString($id, $log);
        self::assertStringNotContainsString(hash('sha256', $id), $log);
        self::assertStringNotContainsString('session-secret-value', $log);
    }

    public function testAWriteNeverResurrectsASessionEndedByAnotherRequest(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $ada->id);
        $response = $browser->get('/probe/race');
        self::assertSame('raced', (string) $response->getBody());
        self::assertSame(0, $this->rows($app), 'deleteForUser sticks');
        self::assertSame(['xar_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax'], $response->getHeader('Set-Cookie'));
        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertArrayNotHasKey('xar_session', $browser->cookies);
    }

    public function testStoreSaveOnlyUpdatesRowsThatStillExist(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $store = $app->container()->get(SessionStore::class);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $session = Session::fresh();
        $session->login($ada->id);
        self::assertTrue($store->save($session, $now, 3600));
        $id = (string) $session->id();
        self::assertTrue($store->save($store->load($id, $now) ?? self::fail('loads'), $now, 3600), 'an unchanged update in the same second still counts as stored');

        foreach (['set' => fn(Session $s) => $s->set('n', 1), 'regenerate' => fn(Session $s) => $s->regenerate()] as $change => $apply) {
            $loaded = $store->load($id, $now) ?? self::fail('loads');
            self::assertSame(1, $store->deleteForUser($ada->id));
            $apply($loaded);
            self::assertFalse($store->save($loaded, $now, 3600), $change);
            self::assertSame(0, $this->rows($app), $change);
            $session = Session::fresh();
            $session->login($ada->id);
            $store->save($session, $now, 3600);
            $id = (string) $session->id();
        }

        $loaded = $store->load($id, $now) ?? self::fail('loads');
        $store->deleteForUser($ada->id);
        $loaded->login($ada->id);
        self::assertTrue($store->save($loaded, $now, 3600), 'a login is a new authentication, so it may start a new row');
        self::assertSame(1, $this->rows($app));
    }

    public function testNothingIsStoredOrSentBeforeTheSessionsTableExists(): void
    {
        $app = $this->app();
        $store = $app->container()->get(SessionStore::class);
        $middleware = new SessionMiddleware($store, $app->container()->get(Config::class));
        $browser = new Browser($app);
        $response = $middleware->process($browser->request('GET', '/'), new CallableHandler(static function (ServerRequestInterface $r): Response {
            $session = $r->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $session);
            $session->token();

            return new Response(200, [], 'ok');
        }));
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($this->db($app)->hasTable('sessions'));
    }

    public function testEmptyingASessionDeletesItsRowAndExpiresAPrivateCookie(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $id = $browser->cookies['xar_session'];
        $response = $browser->get('/probe/forget');
        self::assertSame('xar_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'), 'a shared cache must never replay a Set-Cookie');
        self::assertSame(0, $this->rows($app));
        self::assertNull($app->container()->get(SessionStore::class)->load($id, new DateTimeImmutable()));
    }

    public function testAnUnchangedSessionStillMakesTheResponsePrivate(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        self::assertSame('public, max-age=60', $browser->get('/probe/shared')->getHeaderLine('Cache-Control'), 'no session, no override');
        $browser->get('/probe/count');
        $response = $browser->get('/probe/shared');
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
    }

    public function testASessionEndsAtItsAbsoluteLifetimeWhateverItsActivity(): void
    {
        $app = $this->probeApp(['session.max_lifetime' => 3600]);
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $id = $browser->cookies['xar_session'];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = new DateTimeImmutable((string) $this->db($app)->fetchValue('SELECT expires_at FROM {sessions}'), new DateTimeZone('UTC'));
        self::assertLessThanOrEqual($now->modify('+3601 seconds'), $expires, 'the 120-minute idle expiry is capped at the absolute lifetime');

        // Active a moment ago, but created over an hour ago: dead, in SQL, whatever expires_at says.
        $this->db($app)->query('UPDATE {sessions} SET created_at = ?, last_activity = ?, expires_at = ?', [
            $now->modify('-3601 seconds'), $now->modify('-5 seconds'), $now->modify('+1 hour'),
        ]);
        self::assertNull($app->container()->get(SessionStore::class)->load($id, $now));
        self::assertSame('1', (string) $browser->get('/probe/count')->getBody());
        self::assertNotSame($id, $browser->cookies['xar_session']);
    }

    public function testRegenerateKeepsTheAbsoluteLifetimeAndLoginStartsANewOne(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $store = $app->container()->get(SessionStore::class);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $created = fn(): string => (string) $this->db($app)->fetchValue('SELECT created_at FROM {sessions}');

        $session = Session::fresh();
        $session->set('n', 1);
        $store->save($session, $now->modify('-30 minutes'), 7200);
        $start = $created();

        $session = $store->load((string) $session->id(), $now) ?? self::fail('loads');
        $session->regenerate();
        $store->save($session, $now, 7200);
        self::assertSame($start, $created(), 'regenerate never extends a session');

        $session->login($ada->id);
        $store->save($session, $now, 7200);
        self::assertSame($now->format('Y-m-d H:i:s'), $created(), 'login starts a new absolute lifetime');
        self::assertSame(1, $this->rows($app));
    }

    public function testSessionTokens(): void
    {
        $session = Session::fresh();
        self::assertTrue($session->isEmpty());
        $token = $session->token();
        self::assertMatchesRegularExpression(Session::ID, $token);
        self::assertSame($token, $session->token());
        $session->login('01J0000000000000000000000A');
        self::assertNotSame($token, $session->token(), 'login starts a new CSRF token');
    }

    public function testFlashMessagesAreKeptUntilTakenOnce(): void
    {
        $session = Session::fresh();
        self::assertSame([], $session->takeFlash());
        $session->flash('Created “<b>x</b>”.');
        $session->flash('Sync failed', 'error');
        self::assertTrue($session->isDirty());
        self::assertSame([
            ['kind' => 'status', 'message' => 'Created “<b>x</b>”.'],
            ['kind' => 'error', 'message' => 'Sync failed'],
        ], $session->takeFlash());
        self::assertSame([], $session->takeFlash());
        $this->expectException(\InvalidArgumentException::class);
        $session->flash('x', 'warning');
    }

    public function testFlashBeforeLoginIsDiscardedAndAFrozenSessionNeverSavesIt(): void
    {
        $session = Session::fresh();
        $session->flash('before');
        $session->login('u1');
        self::assertSame([], $session->takeFlash(), 'login starts with fresh data');

        $app = $this->app();
        $store = $app->container()->get(SessionStore::class);
        $middleware = new SessionMiddleware($store, $app->container()->get(Config::class));
        $browser = new Browser($app);
        $response = $middleware->process($browser->request('GET', '/'), new CallableHandler(static function (ServerRequestInterface $r): Response {
            $s = $r->getAttribute(Session::class);
            self::assertInstanceOf(Session::class, $s);
            $s->freeze();
            $s->flash('lost');

            return new Response(200, [], 'ok');
        }));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($this->db($app)->hasTable('sessions') && $this->rows($app) > 0);
    }
}
