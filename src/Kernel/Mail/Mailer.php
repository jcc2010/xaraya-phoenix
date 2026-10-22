<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

/** Sends mail (config mail.driver: "log" by default, or "mail" for PHP's mail()). */
interface Mailer
{
    /**
     * A caller that must not fail the request (a password reset, which answers identically either way) catches
     * MailException, logs it and carries on.
     *
     * @throws MailException when the message could not be handed over
     */
    public function send(Message $message): void;
}
