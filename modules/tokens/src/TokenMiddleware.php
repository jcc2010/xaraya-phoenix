<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\UserRepository;

/**
 * Route middleware "token:<scope>[,<scope>…]": requires `Authorization: Bearer <token>` with every listed scope.
 * The token is never read from the query string. On success the request carries the Token as the
 * Token::class attribute. A route with a {handle} parameter also checks the token's blog pin; routes that reach
 * a blog another way (a post id, a Micropub destination) check Token::allowsBlog() themselves. Without the blog
 * module there is no pin to check.
 * The request's identity becomes the token's user (a guest for a token without one); a token whose user is
 * disabled, pending or deleted is refused like an unknown token. A token request never uses a session: the
 * session is frozen and hidden from the route (csrf() renders nothing, no cookie is set), and the response is
 * Cache-Control: private, no-store unless it already says no-store.
 */
final class TokenMiddleware implements MiddlewareInterface
{
    /** @param list<string> $args the required scopes */
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly OptionalBlogs $blogs,
        private readonly LoggerInterface $logger,
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly array $args = [],
    ) {
        if ($args === []) {
            throw new LogicException("The token middleware needs a scope, as in 'token:blog:write'");
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            throw TokenError::missing();
        }
        if (preg_match('/^Bearer +(\S+) *$/iD', $header, $m) !== 1) {
            throw TokenError::invalid();
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = $this->tokens->findByPlaintext($m[1]);
        if ($token === null || $token->isExpired($now)) {
            throw TokenError::invalid();
        }
        $user = $token->userId === null ? null : $this->users->find($token->userId);
        if ($token->userId !== null && ($user === null || !$user->isActive())) {
            throw TokenError::invalid();
        }
        foreach ($this->args as $scope) {
            if (!$token->hasScope($scope)) {
                throw TokenError::insufficientScope($scope);
            }
        }
        $handle = $request->getAttribute('handle');
        $blogs = is_string($handle) && $token->blogId !== null ? $this->blogs->repository() : null;
        if ($blogs !== null) {
            $blog = $blogs->find((string) $handle);
            if ($blog !== null && !$token->allowsBlog($blog->id)) {
                throw TokenError::wrongBlog();
            }
        }
        try {
            $this->tokens->touch($token, $now);
        } catch (Throwable $e) {
            // Recording the use must never fail the request. Log the token's id, never its value.
            $this->logger->warning('Could not record the use of token {id}: {reason}', ['id' => $token->id, 'reason' => $e->getMessage()]);
        }

        // The token decides the identity, whatever session cookie came with it, and the session stays untouched.
        $this->auth->actAs($user);
        $session = $request->getAttribute(Session::class);
        if ($session instanceof Session) {
            $session->freeze();
        }
        $response = $handler->handle($request->withoutAttribute(Session::class)->withAttribute(Token::class, $token));

        return str_contains($response->getHeaderLine('Cache-Control'), 'no-store')
            ? $response
            : $response->withHeader('Cache-Control', 'private, no-store');
    }
}
