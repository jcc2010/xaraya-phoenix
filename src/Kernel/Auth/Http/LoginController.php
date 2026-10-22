<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth\Http;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\Throttle;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\View\Page;

/**
 * GET/POST /login and POST /logout; the global VerifyCsrf middleware guards both POSTs (no login CSRF). Attempts
 * are counted per client IP bucket and account (the user id when the login names a user, so a handle and an email
 * share one counter; else the lower-cased login): after 5 failures in 15 minutes that pair is locked out, and the
 * password is not checked. Every failure shows the same message, and every unlocked attempt costs exactly one
 * password check, so neither the page nor its timing tells an unknown login from a wrong password.
 */
final class LoginController extends Controller
{
    public const MAX_FAILURES = 5;
    public const WINDOW = 900;
    public const FAILED = 'The handle, email or password is wrong.';
    public const LOCKED = 'Too many failed attempts. Try again in {minutes} minutes.';
    public const RESET = 'Your password has been changed. Log in with the new one.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Throttle $throttle,
        private readonly ClientIp $ip,
    ) {}

    public function show(ServerRequestInterface $request): Page
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $this->form(self::safeNext($query['next'] ?? null), '', notice: ($query['reset'] ?? null) === '1' ? self::RESET : null);
    }

    public function login(ServerRequestInterface $request): ResponseInterface|Page
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $login = is_string($body['login'] ?? null) ? trim($body['login']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $next = self::safeNext($body['next'] ?? null);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $user = $this->users->findByLogin($login);
        $key = 'login|' . ClientIp::bucket($this->ip->of($request)) . '|' . ($user->id ?? mb_strtolower($login));
        // Count the attempt first, then compare: the increment is atomic, so a concurrent burst cannot slip past
        // the limit. A success clears the counter, so what it holds is the failures plus this attempt.
        if ($this->throttle->hit($key, self::WINDOW, $now) > self::MAX_FAILURES) {
            $retryAfter = max(1, $this->throttle->retryAfter($key, self::MAX_FAILURES, $now));

            return $this->form($next, $login, self::LOCKED, (int) ceil($retryAfter / 60), 429, ['Retry-After' => (string) $retryAfter]);
        }
        if ($user === null) {
            $this->users->verifyNothing($password);
        }
        // A right password on a pending or disabled account is a failure like any other.
        if ($user === null || !$this->users->verifyPassword($user, $password) || !$user->isActive()) {
            return $this->form($next, $login, self::FAILED, status: 422);
        }
        $this->throttle->clear($key);
        self::session($request)->login($user->id);
        $this->users->recordLogin($user, $now);

        return $this->redirect($next, 303);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        self::session($request)->logout();

        return $this->redirect('/', 303);
    }

    /**
     * $next when it is a local path, else "/". A local path starts with one "/" (not "//" or "/\", so no other
     * host and no scheme) and has no backslash or control character (CR, LF, tab, NUL, DEL), both as sent and
     * once percent-decoded.
     */
    public static function safeNext(mixed $next): string
    {
        if (!is_string($next) || strlen($next) > 2000) {
            return '/';
        }
        foreach ([$next, rawurldecode($next)] as $path) {
            if (preg_match('#^/(?![/\\\\])[^\\\\\x00-\x1f\x7f]*$#D', $path) !== 1) {
                return '/';
            }
        }

        return $next;
    }

    public static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);

        return $session instanceof Session ? $session : throw new LogicException('The session middleware did not run');
    }

    /** @param array<string, string> $headers */
    private function form(string $next, string $login, ?string $error = null, int $minutes = 0, int $status = 200, array $headers = [], ?string $notice = null): Page
    {
        return new Page('auth/login', [
            'next' => $next,
            'login' => $login,
            'error' => $error,
            'minutes' => $minutes,
            'notice' => $notice,
        ], 'Log in', status: $status, headers: $headers);
    }
}
