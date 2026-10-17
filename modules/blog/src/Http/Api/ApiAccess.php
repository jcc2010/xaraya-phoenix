<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/** The blog-pin check for routes that reach a blog without a {handle} parameter (a post id, a Micropub destination). */
final class ApiAccess
{
    /** Returns the request's token when it may write to $blog; throws 401 without a token, 403 for another blog's pin. */
    public static function allow(ServerRequestInterface $request, Blog $blog): Token
    {
        $token = Token::fromRequest($request);
        if (!$token->allowsBlog($blog->id)) {
            throw TokenError::wrongBlog();
        }

        return $token;
    }
}
