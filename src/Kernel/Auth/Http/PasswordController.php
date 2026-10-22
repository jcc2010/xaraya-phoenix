<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth\Http;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Xaraya\Kernel\Auth\PasswordResets;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Mail\MailException;
use Xaraya\Kernel\View\Page;

/**
 * GET/POST /password/forgot and /password/reset/{token}. The forgot page answers byte for byte the same whoever is
 * asked for, and when the mail cannot be sent (the failure is logged, never the link). The reset page carries its
 * token in the URL, so it is sent with Referrer-Policy: no-referrer and Cache-Control: no-store.
 */
final class PasswordController extends Controller
{
    private const RESET_HEADERS = ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store'];

    public function __construct(private readonly PasswordResets $resets, private readonly LoggerInterface $logger) {}

    public function forgotForm(): Page
    {
        return new Page('auth/forgot', ['sent' => false], 'Forgot your password?');
    }

    public function forgot(ServerRequestInterface $request): Page
    {
        $body = $request->getParsedBody();
        $email = is_array($body) && is_string($body['email'] ?? null) ? trim($body['email']) : '';
        try {
            $this->resets->send($email, self::now());
        } catch (MailException $e) {
            // A mail failure must not reveal that the account exists; it is logged for the administrator. The
            // exception's message never holds the link (the drivers do not echo the body).
            $this->logger->error('Could not send a password reset email: {reason}', ['reason' => $e->getMessage()]);
        }

        return new Page('auth/forgot', ['sent' => true], 'Forgot your password?');
    }

    /** @param array<string, string> $params */
    public function resetForm(ServerRequestInterface $request, array $params): Page
    {
        return $this->form($params['token'], $this->resets->find($params['token'], self::now()) !== null);
    }

    /** @param array<string, string> $params */
    public function reset(ServerRequestInterface $request, array $params): Page|ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($password !== ($body['password_confirmation'] ?? null)) {
            return $this->form($params['token'], true, 'The two passwords are not the same.');
        }
        try {
            $user = $this->resets->reset($params['token'], $password, self::now());
        } catch (InvalidArgumentException $e) {
            // Empty, short, over 72 bytes or containing NUL: a 422 with the reason, and the link still works.
            return $this->form($params['token'], true, $e->getMessage());
        }

        return $user === null ? $this->form($params['token'], false) : $this->redirect('/login?reset=1', 303);
    }

    private function form(string $token, bool $valid, ?string $error = null): Page
    {
        return new Page(
            'auth/reset',
            ['token' => $token, 'valid' => $valid, 'error' => $error],
            'Choose a new password',
            status: $error === null ? 200 : 422,
            headers: self::RESET_HEADERS,
        );
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
