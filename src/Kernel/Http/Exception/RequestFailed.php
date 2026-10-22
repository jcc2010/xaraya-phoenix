<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Carries a failure out of SessionMiddleware together with the request it handed on (the one with the Session
 * attribute), so ErrorHandler renders the error page with the user's session: csrf() then prints the stored token.
 * It has no status of its own; ErrorHandler answers for getPrevious().
 */
final class RequestFailed extends RuntimeException
{
    public function __construct(public readonly ServerRequestInterface $request, Throwable $cause)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }

    public function cause(): Throwable
    {
        return $this->getPrevious() ?? $this;
    }
}
