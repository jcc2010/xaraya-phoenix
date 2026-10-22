<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

/** The "memory" driver, for tests: keeps every message in $sent. */
final class MemoryMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];

    public function send(Message $message): void
    {
        $this->sent[] = $message;
    }
}
