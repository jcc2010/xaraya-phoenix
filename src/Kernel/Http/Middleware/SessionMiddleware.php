<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Exception\RequestFailed;

/**
 * Global middleware: loads the session named by the cookie (session.cookie, default "xar_session") into the
 * Session::class request attribute, and after the response saves it, renews the cookie or expires it. The cookie
 * is HttpOnly and SameSite=Lax, and Secure unless app.debug is on and the request came over plain HTTP.
 * A response that used a session or sets its cookie is marked Cache-Control: private, no-cache. A frozen session
 * (a bearer-token request) is left exactly as it was: nothing is saved or touched and no cookie is sent.
 * A request that throws saves nothing either; ErrorHandler renders its page with the stored session (RequestFailed).
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionStore $store, private readonly Config $config) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $name = self::cookieName($this->config);
        $cookie = $request->getCookieParams()[$name] ?? null;
        $session = (is_string($cookie) ? $this->store->load($cookie, $now) : null) ?? Session::fresh();
        $inner = $request->withAttribute(Session::class, $session);
        try {
            $response = $handler->handle($inner);
        } catch (Throwable $e) {
            // Nothing is saved, touched or sent for a failed request. ErrorHandler renders the page outside this
            // middleware: a stored session goes with it, so the page's forms (logout) carry the stored token.
            throw $session->id() !== null && !$session->isFrozen() ? new RequestFailed($inner, $e) : $e;
        }
        if ($session->isFrozen()) {
            return $response;
        }

        $lifetime = 60 * max(1, (int) $this->config->get('session.lifetime', 120));
        $expire = false;
        $sent = false;
        if ($session->isDirty() && $session->isEmpty()) {
            $this->store->forget($session);
            $expire = is_string($cookie);
        } elseif ($session->isDirty()) {
            if ($this->store->save($session, $now, $lifetime)) {
                $response = $response->withAddedHeader('Set-Cookie', $this->cookie($request, $name, (string) $session->id(), null));
                $sent = true;
                $this->maybeCollectGarbage($now);
            } else {
                // Ended by another request meanwhile (or no sessions table yet): never re-issue it.
                $expire = is_string($cookie);
            }
        } else {
            $this->store->touch($session, $now, $lifetime);
        }
        if ($expire) {
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($request, $name, '', 0));
        }
        // Any response that used a session or sets a cookie must never be replayed by a shared cache.
        if (($sent || $expire || $session->id() !== null) && !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) {
            $response = $response->withHeader('Cache-Control', 'private, no-cache');
        }

        return $response;
    }

    /** The session cookie's name: session.cookie when it is a safe token, else "xar_session". */
    public static function cookieName(Config $config): string
    {
        $name = (string) $config->get('session.cookie', 'xar_session');

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) === 1 ? $name : 'xar_session';
    }

    private function cookie(ServerRequestInterface $request, string $name, string $value, ?int $maxAge): string
    {
        $secure = $request->getUri()->getScheme() === 'https' || $this->config->get('app.debug', false) !== true;

        return $name . '=' . $value . '; Path=/' . ($maxAge === null ? '' : '; Max-Age=' . $maxAge)
            . '; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : '');
    }

    private function maybeCollectGarbage(DateTimeImmutable $now): void
    {
        $probability = (float) $this->config->get('session.gc_probability', 0.01);
        if ($probability > 0 && random_int(1, 1_000_000) <= (int) ($probability * 1_000_000)) {
            $this->store->gc($now);
        }
    }
}
