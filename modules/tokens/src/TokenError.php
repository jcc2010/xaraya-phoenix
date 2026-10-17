<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use Xaraya\Kernel\Http\Exception\HttpException;

/**
 * A rejected bearer token. $error uses the Micropub/OAuth codes: unauthorized (401), insufficient_scope (403)
 * or forbidden (403). Messages never contain the token.
 */
final class TokenError extends HttpException
{
    private function __construct(int $status, public readonly string $error, string $message, string $challenge)
    {
        parent::__construct($status, $message, ['WWW-Authenticate' => $challenge]);
    }

    public static function missing(): self
    {
        return new self(401, 'unauthorized', 'A bearer token is required', 'Bearer');
    }

    public static function invalid(): self
    {
        return new self(401, 'unauthorized', 'The token is invalid or expired', 'Bearer error="invalid_token"');
    }

    public static function insufficientScope(string $scope): self
    {
        return new self(403, 'insufficient_scope', "The token lacks the {$scope} scope", 'Bearer error="insufficient_scope", scope="' . $scope . '"');
    }

    public static function wrongBlog(): self
    {
        return new self(403, 'forbidden', 'The token may not write to this blog', 'Bearer error="insufficient_scope"');
    }
}
