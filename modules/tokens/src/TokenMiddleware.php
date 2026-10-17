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
use Xaraya\Module\Blog\BlogRepository;

/**
 * Route middleware "token:<scope>[,<scope>…]": requires `Authorization: Bearer <token>` with every listed scope.
 * The token is never read from the query string. On success the request carries the Token as the
 * Token::class attribute. A route with a {handle} parameter also checks the token's blog pin; routes that reach
 * a blog another way (a post id, a Micropub destination) check Token::allowsBlog() themselves.
 */
final class TokenMiddleware implements MiddlewareInterface
{
    /** @param list<string> $args the required scopes */
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly BlogRepository $blogs,
        private readonly LoggerInterface $logger,
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
        foreach ($this->args as $scope) {
            if (!$token->hasScope($scope)) {
                throw TokenError::insufficientScope($scope);
            }
        }
        $handle = $request->getAttribute('handle');
        if (is_string($handle) && $token->blogId !== null) {
            $blog = $this->blogs->find($handle);
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

        return $handler->handle($request->withAttribute(Token::class, $token));
    }
}
