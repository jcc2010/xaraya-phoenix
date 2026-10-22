<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Auth\Throttle;
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Middleware\ThrottleRequests;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class ThrottleTest extends AuthTestCase
{
    public function testAKeyIsBlockedAfterMaxHitsUntilItsWindowEnds(): void
    {
        $app = $this->installed();
        $throttle = $app->container()->get(Throttle::class);
        $now = new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC'));
        self::assertSame(0, $throttle->retryAfter('k', 3, $now));
        self::assertSame(1, $throttle->hit('k', 900, $now));
        self::assertSame(2, $throttle->hit('k', 900, $now->modify('+1 minute')));
        self::assertSame(0, $throttle->retryAfter('k', 3, $now->modify('+2 minutes')));
        self::assertSame(3, $throttle->hit('k', 900, $now->modify('+2 minutes')));
        self::assertSame(780, $throttle->retryAfter('k', 3, $now->modify('+2 minutes')), 'the window runs from the first hit');
        self::assertSame(0, $throttle->retryAfter('other', 3, $now));

        $later = $now->modify('+15 minutes');
        self::assertSame(0, $throttle->retryAfter('k', 3, $later));
        self::assertSame(1, $throttle->hit('k', 900, $later), 'a new window starts');
        $throttle->clear('k');
        self::assertSame(0, $this->db($app)->select('throttle')->count());
    }

    public function testKeysAreStoredHashed(): void
    {
        $app = $this->installed();
        $app->container()->get(Throttle::class)->hit('login|198.51.100.4|ada', 60, new DateTimeImmutable());
        self::assertSame(hash('sha256', 'login|198.51.100.4|ada'), $this->db($app)->fetchValue('SELECT ' . $this->db($app)->ident('key') . ' FROM {throttle}'));
    }

    public function testTheMiddlewareAnswers429PerClientIp(): void
    {
        $app = $this->probeApp();
        $client = new Browser($app, '198.51.100.4');
        self::assertSame(200, $client->get('/probe/limited')->getStatusCode());
        self::assertSame(200, $client->get('/probe/limited')->getStatusCode());
        $limited = $client->get('/probe/limited');
        self::assertSame(429, $limited->getStatusCode());
        self::assertGreaterThan(0, (int) $limited->getHeaderLine('Retry-After'));
        self::assertLessThanOrEqual(60, (int) $limited->getHeaderLine('Retry-After'));
        self::assertSame(200, (new Browser($app, '198.51.100.5'))->get('/probe/limited')->getStatusCode());

        $spoofed = new Browser($app, '198.51.100.4');
        self::assertSame(429, $spoofed->get('/probe/limited', ['X-Forwarded-For' => '192.0.2.99'])->getStatusCode(), 'X-Forwarded-For is not trusted by default');
    }

    public function testClientIpBelievesXForwardedForOnlyFromTrustedProxies(): void
    {
        $request = static fn(string $remote, string $xff = ''): ServerRequest => new ServerRequest('GET', '/', $xff === '' ? [] : ['X-Forwarded-For' => $xff], null, '1.1', ['REMOTE_ADDR' => $remote]);
        $direct = new ClientIp();
        self::assertSame('203.0.113.1', $direct->of($request('203.0.113.1', '192.0.2.9')));
        self::assertSame('', $direct->of(new ServerRequest('GET', '/')));

        $proxied = new ClientIp(['10.0.0.1', '10.0.0.2']);
        self::assertSame('192.0.2.9', $proxied->of($request('10.0.0.1', '192.0.2.9')));
        self::assertSame('192.0.2.9', $proxied->of($request('10.0.0.1', '6.6.6.6, 192.0.2.9, 10.0.0.2')), 'the client cannot prepend a fake hop');
        self::assertSame('10.0.0.1', $proxied->of($request('10.0.0.1', 'garbage')));
        self::assertSame('203.0.113.1', $proxied->of($request('203.0.113.1', '192.0.2.9')));
    }

    public function testIpv6IsBucketedBySlash64AndIpv4PerAddress(): void
    {
        self::assertSame(ClientIp::bucket('2001:db8:1:2::5'), ClientIp::bucket('2001:db8:1:2:ffff::1'));
        self::assertNotSame(ClientIp::bucket('2001:db8:1:2::5'), ClientIp::bucket('2001:db8:1:3::5'));
        self::assertSame('198.51.100.4', ClientIp::bucket('198.51.100.4'));
        self::assertNotSame(ClientIp::bucket('198.51.100.4'), ClientIp::bucket('198.51.100.5'));
        self::assertSame('198.51.100.4', ClientIp::bucket('::ffff:198.51.100.4'));
        self::assertSame('', ClientIp::bucket(''));
        self::assertSame('not-an-ip', ClientIp::bucket('not-an-ip'));
    }

    public function testTheMiddlewareSharesOneBucketAcrossAnIpv6Slash64(): void
    {
        $app = $this->probeApp();
        self::assertSame(200, (new Browser($app, '2001:db8:1:2::5'))->get('/probe/limited')->getStatusCode());
        self::assertSame(200, (new Browser($app, '2001:db8:1:2:ffff::1'))->get('/probe/limited')->getStatusCode());
        self::assertSame(429, (new Browser($app, '2001:db8:1:2:abcd::9'))->get('/probe/limited')->getStatusCode());
        self::assertSame(200, (new Browser($app, '2001:db8:1:3::5'))->get('/probe/limited')->getStatusCode());
    }

    public function testClientIpIgnoresMalformedAndStopsAtTheFirstUntrustedHop(): void
    {
        $request = static fn(string $xff): ServerRequest => new ServerRequest('GET', '/', ['X-Forwarded-For' => $xff], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']);
        $ip = new ClientIp(['10.0.0.1', '10.0.0.2']);
        self::assertSame('192.0.2.9', $ip->of($request('1.1.1.1, 192.0.2.9, 10.0.0.2')));
        self::assertSame('10.0.0.1', $ip->of($request('192.0.2.9, junk, 10.0.0.2')), 'a malformed hop ends the walk');
        self::assertSame('10.0.0.1', $ip->of($request('10.0.0.2')));
        self::assertSame('10.0.0.1', $ip->of(new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1'])));
    }

    public function testAMalformedSpecIsAProgrammingError(): void
    {
        $app = $this->installed();
        foreach ([[], ['x'], ['x', '5'], ['x', 'five', '60'], ['x', '0', '60'], ['Bad Key', '5', '60']] as $args) {
            try {
                new ThrottleRequests($app->container()->get(Throttle::class), new ClientIp(), $args);
                self::fail('accepted ' . implode(',', $args));
            } catch (LogicException $e) {
                self::assertStringContainsString('<key>,<max>,<seconds>', $e->getMessage());
            }
        }
    }
}
