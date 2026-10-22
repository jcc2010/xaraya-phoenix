<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use DateTimeImmutable;

/**
 * The "log" driver: writes each message to <mail.path>/<UTC time>-<random>.eml (mode 0600) instead of sending it.
 *
 * Development only: the whole message, body included, lands on disk, so a password-reset token is readable by
 * anyone who can read that directory. Use the "mail" driver (or a real transport) in production.
 */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $dir, private readonly string $from)
    {
        Address::assert($from, 'mail.from');
    }

    public function send(Message $message): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new MailException("Cannot create the mail directory {$this->dir}");
        }
        $file = $this->dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $content = "From: {$this->from}\nTo: {$message->to}\nSubject: {$message->subject}\n"
            . 'Date: ' . (new DateTimeImmutable())->format(DATE_RFC2822) . "\nContent-Type: text/plain; charset=utf-8\n\n"
            . $message->text . "\n";
        if (@file_put_contents($file, $content) !== strlen($content) || !@chmod($file, 0600)) {
            throw new MailException("Cannot write the message to {$file}");
        }
    }
}
