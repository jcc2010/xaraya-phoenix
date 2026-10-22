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
        try {
            $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        } catch (\Throwable $e) {
            throw new MailException('Cannot name the message file: ' . $e->getMessage(), 0, $e);
        }
        $file = $this->dir . '/' . $name;
        $content = "From: {$this->from}\nTo: {$message->to}\nSubject: {$message->subject}\n"
            . 'Date: ' . (new DateTimeImmutable())->format(DATE_RFC2822) . "\nContent-Type: text/plain; charset=utf-8\n\n"
            . $message->text . "\n";
        // The file is created 0600 (umask 0177) so no token byte is ever on disk with wider permissions.
        $old = umask(0177);
        try {
            $handle = @fopen($file, 'xb');
        } finally {
            umask($old);
        }
        if ($handle === false) {
            throw new MailException("Cannot write the message to {$file}");
        }
        $written = @fwrite($handle, $content);
        $closed = @fclose($handle);
        if ($written !== strlen($content) || !$closed) {
            @unlink($file);
            throw new MailException("Cannot write the message to {$file}");
        }
    }
}
