<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Module\Blog\Admin\AdminAccess;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
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

    /**
     * allow(), plus for a token that acts as a user the same item check as the admin: $level is "edit" (replace,
     * patch, Micropub update) or "delete" (delete, undelete). A scope-only token (no user) is not checked further.
     *
     * @throws Forbidden when the token's user may not change $post
     */
    public static function allowPost(ServerRequestInterface $request, Blog $blog, Post $post, Access $access, string $level): Token
    {
        $token = self::allow($request, $blog);
        if ($token->userId !== null && !$access->can($level, 'blog', 'post', AdminAccess::item($post))) {
            throw new Forbidden("This token's user may not {$level} this post");
        }

        return $token;
    }
}
