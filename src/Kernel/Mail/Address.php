<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use InvalidArgumentException;

/** @internal Validation shared by the message and the drivers: one plain address, never a header carrier. */
final class Address
{
    public static function assert(string $address, string $what): void
    {
        // FILTER_VALIDATE_EMAIL also accepts quoted local parts; refuse those, whitespace and list separators.
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\s",<>;\x00-\x1f\x7f]/', $address) === 1) {
            throw new InvalidArgumentException("{$what} must be a plain email address");
        }
    }
}
