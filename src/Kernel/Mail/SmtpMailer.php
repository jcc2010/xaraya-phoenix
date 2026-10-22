<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * The "smtp" driver: a minimal SMTP client (RFC 5321) over one connection per message.
 *
 * Encryption is "tls" (STARTTLS, required: a server that doesn't offer it is an error), "ssl" (TLS from the first
 * byte, usually port 465) or "none" (plain text, for a local relay such as Mailpit). Certificates are always
 * verified. Credentials are sent only over TLS, with AUTH PLAIN or LOGIN. The body goes out quoted-printable, so it
 * never needs the server's 8BITMIME. Error messages carry the server's reply, never the credentials.
 */
final class SmtpMailer implements Mailer
{
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** @var resource|null */
    private $socket = null;
    private float $deadline = 0.0;

    /** @param array<string, mixed> $tls extra `ssl` stream-context options (tests pass a cafile and peer_name) */
    private readonly string $host;

    public function __construct(
        string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $from,
        private readonly ?string $username = null,
        #[SensitiveParameter]
        private readonly ?string $password = null,
        private readonly string $helo = 'localhost',
        private readonly float $timeout = 10.0,
        private readonly array $tls = [],
    ) {
        $this->host = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        if ($this->host === '' || preg_match('/[\s\/\[\]\x00-\x1f\x7f]/', $this->host) === 1) {
            throw new InvalidArgumentException('mail.smtp.host must be a host name or IP address');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('mail.smtp.port must be between 1 and 65535');
        }
        if (!in_array($encryption, self::ENCRYPTIONS, true)) {
            throw new InvalidArgumentException('mail.smtp.encryption must be "tls", "ssl" or "none"');
        }
        Address::assert($from, 'mail.from');
        if (preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*$/', $helo) !== 1
            && preg_match('/^\[(?:[0-9.]+|IPv6:[0-9A-Fa-f:.]+)\]$/', $helo) !== 1) {
            throw new InvalidArgumentException('The SMTP HELO name must be a host name or an address literal');
        }
        if (($username ?? '') === '' xor ($password ?? '') === '') {
            throw new InvalidArgumentException('mail.smtp.username and mail.smtp.password go together');
        }
        if (($username ?? '') !== '' && $encryption === 'none') {
            throw new LogicException('SMTP credentials are only sent over TLS; set mail.smtp.encryption to "tls" or "ssl"');
        }
        if ($timeout <= 0) {
            throw new InvalidArgumentException('mail.smtp.timeout must be positive');
        }
    }

    public function send(Message $message): void
    {
        $this->deadline = microtime(true) + $this->timeout;
        $this->connect();
        try {
            $this->expect(220, 'greeting');
            $extensions = $this->ehlo();
            if ($this->encryption === 'tls') {
                if (!array_key_exists('STARTTLS', $extensions)) {
                    throw new MailException("The SMTP server {$this->host} does not offer STARTTLS");
                }
                $this->command('STARTTLS', 220, 'STARTTLS');
                // Bytes already buffered after the 220 arrived in plain text; reading them after the handshake would
                // let an on-path attacker inject replies (CVE-2011-0411 style), so refuse.
                if (stream_get_meta_data($this->socket())['unread_bytes'] > 0) {
                    throw new MailException("The SMTP server {$this->host} sent data after STARTTLS before the TLS handshake");
                }
                $this->startTls();
                $extensions = $this->ehlo();
            }
            if (($this->username ?? '') !== '') {
                $this->authenticate($extensions);
            }
            $this->command("MAIL FROM:<{$this->from}>", 250, 'MAIL FROM');
            $this->command("RCPT TO:<{$message->to}>", [250, 251], 'RCPT TO');
            $this->command('DATA', 354, 'DATA');
            $this->write($this->data($message));
            $this->expect(250, 'message');
            try {
                $this->command('QUIT', 221, 'QUIT');
            } catch (MailException) {
                // The message is already accepted; a server that drops the line on QUIT changes nothing.
            }
        } finally {
            if (is_resource($this->socket)) {
                @fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[{$this->host}]" : $this->host;
        $target = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $this->port;
        $context = stream_context_create(['ssl' => $this->sslOptions()]);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client($target, $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException("Cannot connect to the SMTP server {$this->host}:{$this->port}: " . ($error !== '' ? $error : 'connection failed'));
        }
        $this->socket = $socket;
    }

    /** @return array<string, mixed> */
    private function sslOptions(): array
    {
        return [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $this->host,
            'SNI_enabled' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            ...$this->tls,
        ];
    }

    private function startTls(): void
    {
        $socket = $this->socket();
        foreach ($this->sslOptions() as $option => $value) {
            stream_context_set_option($socket, 'ssl', $option, $value);
        }
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        stream_set_timeout($socket, $this->remaining());
        if (@stream_socket_enable_crypto($socket, true, $method) !== true) {
            throw new MailException("TLS negotiation with the SMTP server {$this->host} failed");
        }
    }

    /** @return array<string, string> the EHLO keywords (upper case) and their parameters */
    private function ehlo(): array
    {
        $lines = $this->command("EHLO {$this->helo}", 250, 'EHLO');
        $extensions = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', trim($line), 2);
            $extensions[strtoupper($parts[0])] = $parts[1] ?? '';
        }

        return $extensions;
    }

    /** @param array<string, string> $extensions */
    private function authenticate(array $extensions): void
    {
        $mechanisms = preg_split('/\s+/', strtoupper($extensions['AUTH'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $user = (string) $this->username;
        $password = (string) $this->password;
        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0{$user}\0{$password}"), 235, 'AUTH');
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN', 334, 'AUTH');
            $this->command(base64_encode($user), 334, 'AUTH');
            $this->command(base64_encode($password), 235, 'AUTH');
        } else {
            throw new MailException("The SMTP server {$this->host} offers no supported AUTH mechanism (PLAIN, LOGIN)");
        }
    }

    /** The DATA payload: headers, a quoted-printable body with CRLF line ends and dot-stuffing, and the end marker. */
    private function data(Message $message): string
    {
        $domain = substr($this->from, strrpos($this->from, '@') + 1);
        $headers = [
            'Date' => date(DATE_RFC2822),
            'From' => $this->from,
            'To' => $message->to,
            'Subject' => mb_encode_mimeheader($message->subject, 'UTF-8', 'Q'),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ];
        $head = '';
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        $body = quoted_printable_encode(preg_replace('/\r\n|\r|\n/', "\r\n", $message->text) ?? $message->text);
        $body = preg_replace('/^\./m', '..', $body) ?? $body;

        return $head . "\r\n" . $body . (str_ends_with($body, "\r\n") || $body === '' ? '' : "\r\n") . ".\r\n";
    }

    /**
     * Sends one command line and checks the reply code; $label names the step in errors, so a credential never
     * appears in one.
     *
     * @param int|list<int> $codes
     * @return list<string> the reply lines, without their codes
     */
    private function command(#[SensitiveParameter] string $line, int|array $codes, string $label): array
    {
        $this->write($line . "\r\n");

        return $this->expect($codes, $label);
    }

    /**
     * @param int|list<int> $codes
     * @return list<string>
     */
    private function expect(int|array $codes, string $label): array
    {
        [$code, $lines] = $this->reply();
        if (!in_array($code, (array) $codes, true)) {
            throw new MailException("The SMTP server {$this->host} refused {$label}: {$code} " . mb_scrub(implode(' ', $lines), 'UTF-8'));
        }

        return $lines;
    }

    /** @return array{0: int, 1: list<string>} */
    private function reply(): array
    {
        $socket = $this->socket();
        $lines = [];
        $code = 0;
        do {
            stream_set_timeout($socket, $this->remaining());
            $line = fgets($socket, 65536);
            if ($line === false) {
                $timedOut = stream_get_meta_data($socket)['timed_out'];
                throw new MailException($timedOut ? "The SMTP server {$this->host} timed out" : "The SMTP server {$this->host} closed the connection");
            }
            // A reply line is "<code> text", "<code>-text" (more lines follow) or a bare "<code>" (RFC 5321 4.2).
            if (preg_match('/^(\d{3})(?:([ -])(.*?))?\r?\n$/s', $line, $m) !== 1 || ($lines !== [] && (int) $m[1] !== $code)) {
                throw new MailException("The SMTP server {$this->host} sent a malformed reply");
            }
            $code = (int) $m[1];
            $lines[] = $m[3] ?? '';
            if (count($lines) > 100) {
                throw new MailException("The SMTP server {$this->host} sent too long a reply");
            }
        } while (($m[2] ?? ' ') === '-');

        return [$code, $lines];
    }

    private function write(#[SensitiveParameter] string $data): void
    {
        $socket = $this->socket();
        while ($data !== '') {
            stream_set_timeout($socket, $this->remaining());
            $written = @fwrite($socket, $data);
            if ($written === false || $written === 0) {
                throw new MailException("Cannot write to the SMTP server {$this->host}");
            }
            $data = substr($data, $written);
        }
    }

    /**
     * Whole seconds left before the deadline (at least 1, so a slow last step still gets a chance to fail cleanly).
     * The deadline bounds each read and write, not a server that trickles bytes, and DNS lookup isn't covered.
     */
    private function remaining(): int
    {
        $left = $this->deadline - microtime(true);
        if ($left <= 0) {
            throw new MailException("The SMTP server {$this->host} timed out");
        }

        return max(1, (int) ceil($left));
    }

    /** @return resource */
    private function socket()
    {
        return $this->socket ?? throw new LogicException('Not connected');
    }
}
