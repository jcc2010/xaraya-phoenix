<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/** Picks the blog a Micropub request writes to: mp-destination, else the token's blog, else the only native blog. */
final class Destination
{
    public function __construct(private readonly BlogRepository $blogs) {}

    /** @return list<Blog> the native blogs this token may write to (q=config's destinations) */
    public function choices(Token $token): array
    {
        return array_values(array_filter(
            $this->blogs->all(),
            static fn(Blog $blog): bool => !$blog->isMirror() && $token->allowsBlog($blog->id),
        ));
    }

    public function choose(?string $uid, Token $token): Blog
    {
        if ($uid !== null) {
            $blog = $this->blogs->find($uid);
            if ($blog === null || $blog->isMirror()) {
                throw new HttpException(400, "Unknown destination '{$uid}'");
            }
            if (!$token->allowsBlog($blog->id)) {
                throw TokenError::wrongBlog();
            }

            return $blog;
        }
        if ($token->blogId !== null) {
            $blog = $this->blogs->findById($token->blogId);
            if ($blog !== null && !$blog->isMirror()) {
                return $blog;
            }
        }
        $choices = $this->choices($token);
        if (count($choices) === 1) {
            return $choices[0];
        }
        throw new HttpException(400, 'Choose a blog with mp-destination (q=config lists them)');
    }
}
