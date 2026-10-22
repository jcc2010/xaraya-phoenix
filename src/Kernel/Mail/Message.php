<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use InvalidArgumentException;

/** A plain-text email to one address. The recipient and subject can never carry extra headers. */
final class Message
{
    public function __construct(public readonly string $to, public readonly string $subject, public readonly string $text)
    {
        Address::assert($to, 'The recipient');
        if (preg_match('/[\x00-\x1f\x7f]/', $subject) === 1) {
            throw new InvalidArgumentException('A subject is one line');
        }
    }
}
