<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use Closure;

/** The "mail" driver: PHP's mail(), so the server's sendmail or MTA delivers. */
final class MailFunctionMailer implements Mailer
{
    /** @param (Closure(string, string, string, array<string, string>): bool)|null $mail replaces mail() in tests */
    public function __construct(private readonly string $from, private readonly ?Closure $mail = null)
    {
        Address::assert($from, 'mail.from');
    }

    public function send(Message $message): void
    {
        $headers = [
            'From' => $this->from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        $subject = mb_encode_mimeheader($message->subject, 'UTF-8', 'Q');
        // Normalise every line ending (including a lone CR) to CRLF.
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $message->text) ?? $message->text;
        try {
            $sent = $this->mail !== null ? ($this->mail)($message->to, $subject, $body, $headers) : mail($message->to, $subject, $body, $headers);
        } catch (\Throwable $e) {
            throw new MailException('mail() failed: ' . $e->getMessage(), 0, $e);
        }
        if (!$sent) {
            throw new MailException('mail() did not accept the message');
        }
    }
}
