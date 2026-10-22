<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Mail;

use InvalidArgumentException;
use LogicException;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MailException;
use Xaraya\Kernel\Mail\Message;
use Xaraya\Kernel\Mail\SmtpMailer;
use Xaraya\Tests\Support\AppTestCase;

/** Runs SmtpMailer against tests/Support/fake-smtp-server.php on a loopback port. */
final class SmtpMailerTest extends AppTestCase
{
    private const USER = 'mailer';
    private const PASSWORD = 'pa55-w0rd!';

    /** @var resource|null */
    private $process = null;
    private string $transcript = '';

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $options starts the fake server and returns its port */
    private function server(array $options = []): int
    {
        $this->transcript = $this->tmp . '/transcript-' . bin2hex(random_bytes(4)) . '.txt';
        $json = $this->tmp . '/server-' . bin2hex(random_bytes(4)) . '.json';
        if (in_array($options['tls'] ?? 'none', ['starttls', 'ssl'], true)) {
            $options += $this->certificate();
        }
        file_put_contents($json, json_encode(['transcript' => $this->transcript, ...$options], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/fake-smtp-server.php', $json], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;
        $port = (int) fgets($pipes[1]);
        if ($port === 0) {
            self::fail('The fake SMTP server did not start: ' . stream_get_contents($pipes[2]));
        }

        return $port;
    }

    /** Waits for the server to exit and returns what the client sent. */
    private function transcript(): string
    {
        self::assertIsResource($this->process);
        for ($i = 0; $i < 100 && proc_get_status($this->process)['running']; $i++) {
            usleep(20000);
        }
        proc_close($this->process);
        $this->process = null;

        return is_file($this->transcript) ? (string) file_get_contents($this->transcript) : '';
    }

    /** @return array{cert: string, key: string} a self-signed certificate for "localhost" */
    private function certificate(): array
    {
        $cert = $this->tmp . '/cert.pem';
        $key = $this->tmp . '/key.pem';
        if (!is_file($cert)) {
            $pkey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($pkey);
            $csr = openssl_csr_new(['commonName' => 'localhost'], $pkey);
            self::assertNotFalse($csr);
            $x509 = openssl_csr_sign($csr, null, $pkey, 1);
            self::assertNotFalse($x509);
            openssl_x509_export_to_file($x509, $cert);
            openssl_pkey_export_to_file($pkey, $key);
        }

        return ['cert' => $cert, 'key' => $key];
    }

    /** @param array<string, mixed> $overrides */
    private function mailer(int $port, string $encryption = 'none', ?string $user = null, ?string $password = null, float $timeout = 5.0): SmtpMailer
    {
        $trust = $encryption === 'none' ? [] : ['cafile' => $this->certificate()['cert'], 'peer_name' => 'localhost'];

        return new SmtpMailer('127.0.0.1', $port, $encryption, 'site@example.com', $user, $password, 'phoenix.test', $timeout, $trust);
    }

    private static function message(): Message
    {
        return new Message('ada@example.com', 'Réinitialiser', "Hello Ada,\n.leading dot\r\nnaïve line\n\nBye");
    }

    public function testSendsAMessageOverAPlainConnection(): void
    {
        $this->mailer($this->server())->send(self::message());
        $sent = $this->transcript();

        self::assertStringStartsWith("EHLO phoenix.test\r\nMAIL FROM:<site@example.com>\r\nRCPT TO:<ada@example.com>\r\nDATA\r\n", $sent);
        self::assertStringContainsString("\r\nFrom: site@example.com\r\nTo: ada@example.com\r\nSubject: =?UTF-8?Q?R=C3=A9initialiser?=\r\n", $sent);
        self::assertMatchesRegularExpression('/\r\nMessage-ID: <[0-9a-f]{32}@example\.com>\r\n/', $sent);
        self::assertStringContainsString("Content-Transfer-Encoding: quoted-printable\r\n\r\nHello Ada,\r\n..leading dot\r\nna=C3=AFve line\r\n\r\nBye\r\n.\r\nQUIT\r\n", $sent);
        self::assertStringNotContainsString('AUTH', $sent);
    }

    public function testStartTlsThenAuthPlain(): void
    {
        $port = $this->server(['tls' => 'starttls', 'ext' => ['AUTH PLAIN LOGIN'], 'user' => self::USER, 'password' => self::PASSWORD]);
        $this->mailer($port, 'tls', self::USER, self::PASSWORD)->send(self::message());
        $sent = $this->transcript();

        self::assertMatchesRegularExpression('/^EHLO phoenix\.test\r\nSTARTTLS\r\n\[tls\]\r\nEHLO phoenix\.test\r\nAUTH PLAIN \S+\r\nMAIL FROM/', $sent);
        self::assertStringContainsString("\r\n.\r\nQUIT\r\n", $sent);
    }

    public function testImplicitTlsWithAuthLogin(): void
    {
        $port = $this->server(['tls' => 'ssl', 'ext' => ['AUTH LOGIN'], 'user' => self::USER, 'password' => self::PASSWORD]);
        $this->mailer($port, 'ssl', self::USER, self::PASSWORD)->send(self::message());
        $sent = $this->transcript();

        self::assertStringStartsWith("EHLO phoenix.test\r\nAUTH LOGIN\r\n" . base64_encode(self::USER) . "\r\n" . base64_encode(self::PASSWORD) . "\r\nMAIL FROM", $sent);
    }

    public function testRejectedCredentialsNeverAppearInTheError(): void
    {
        $port = $this->server(['tls' => 'starttls', 'ext' => ['AUTH PLAIN'], 'user' => self::USER, 'password' => 'something else']);
        try {
            $this->mailer($port, 'tls', self::USER, self::PASSWORD)->send(self::message());
            self::fail('sent with bad credentials');
        } catch (MailException $e) {
            self::assertStringContainsString('refused AUTH: 535', $e->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            self::assertStringNotContainsString(base64_encode("\0" . self::USER . "\0" . self::PASSWORD), $e->getMessage());
        }
        self::assertStringNotContainsString('MAIL FROM', $this->transcript());
    }

    public function testTlsIsRequiredWhenAsked(): void
    {
        $port = $this->server(['ext' => ['AUTH PLAIN']]);
        try {
            $this->mailer($port, 'tls', self::USER, self::PASSWORD)->send(self::message());
            self::fail('sent without STARTTLS');
        } catch (MailException $e) {
            self::assertStringContainsString('does not offer STARTTLS', $e->getMessage());
        }
        self::assertSame("EHLO phoenix.test\r\n", $this->transcript(), 'no credentials go out in plain text');
    }

    public function testAnUntrustedCertificateFails(): void
    {
        $port = $this->server(['tls' => 'starttls']);
        $mailer = new SmtpMailer('127.0.0.1', $port, 'tls', 'site@example.com', null, null, 'phoenix.test', 5.0, ['peer_name' => 'localhost']);
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('TLS negotiation with the SMTP server 127.0.0.1 failed');
        $mailer->send(self::message());
    }

    public function testARefusedRecipientIsAMailException(): void
    {
        $port = $this->server(['reply' => ['RCPT' => '550 5.1.1 no such user']]);
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('refused RCPT TO: 550 5.1.1 no such user');
        $this->mailer($port)->send(self::message());
    }

    public function testARefusedMessageIsAMailException(): void
    {
        $port = $this->server(['reply' => ['MESSAGE' => '554 spam']]);
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('refused message: 554 spam');
        $this->mailer($port)->send(self::message());
    }

    public function testAMalformedReplyIsAMailException(): void
    {
        $port = $this->server(['reply' => ['EHLO' => 'hello there']]);
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('malformed reply');
        $this->mailer($port)->send(self::message());
    }

    public function testASilentServerTimesOut(): void
    {
        $port = $this->server(['silent' => true]);
        $start = microtime(true);
        try {
            $this->mailer($port, timeout: 1.0)->send(self::message());
            self::fail('no timeout');
        } catch (MailException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        self::assertLessThan(4.0, microtime(true) - $start);
    }

    public function testAClosedPortCannotConnect(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);
        $this->expectException(MailException::class);
        $this->expectExceptionMessage('Cannot connect to the SMTP server 127.0.0.1:');
        $this->mailer((int) substr($name, strrpos($name, ':') + 1))->send(self::message());
    }

    public function testAServerThatHangsUpOnQuitStillSent(): void
    {
        $this->mailer($this->server(['hangup_on_quit' => true]))->send(self::message());
        self::assertStringEndsWith("QUIT\r\n", $this->transcript());
    }

    public function testBadSettingsAreRefusedUpFront(): void
    {
        $bad = [
            static fn() => new SmtpMailer('', 587, 'tls', 'a@example.com'),
            static fn() => new SmtpMailer("mail.example.com\r\n", 587, 'tls', 'a@example.com'),
            static fn() => new SmtpMailer('mail.example.com', 0, 'tls', 'a@example.com'),
            static fn() => new SmtpMailer('mail.example.com', 587, 'starttls', 'a@example.com'),
            static fn() => new SmtpMailer('mail.example.com', 587, 'tls', "a@example.com\r\nBcc: b@example.com"),
            static fn() => new SmtpMailer('mail.example.com', 587, 'tls', 'a@example.com', 'user'),
            static fn() => new SmtpMailer('mail.example.com', 587, 'tls', 'a@example.com', null, 'secret'),
            static fn() => new SmtpMailer('mail.example.com', 587, 'tls', 'a@example.com', helo: "x\r\nRSET"),
            static fn() => new SmtpMailer('mail.example.com', 587, 'tls', 'a@example.com', timeout: 0),
        ];
        foreach ($bad as $i => $make) {
            try {
                $make();
                self::fail("case {$i} was accepted");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only sent over TLS');
        new SmtpMailer('mail.example.com', 25, 'none', 'a@example.com', 'user', 'secret');
    }

    public function testTheSmtpDriverComesFromConfig(): void
    {
        $port = $this->server();
        $mailer = $this->boot([], [
            'app.url' => 'https://blog.example.com',
            'mail.driver' => 'smtp',
            'mail.from' => 'site@example.com',
            'mail.smtp.host' => '127.0.0.1',
            'mail.smtp.port' => (string) $port,
            'mail.smtp.encryption' => 'none',
        ])->container()->get(Mailer::class);
        self::assertInstanceOf(SmtpMailer::class, $mailer);
        $mailer->send(self::message());
        self::assertStringStartsWith("EHLO blog.example.com\r\n", $this->transcript(), 'the HELO name is the app.url host');
    }

    public function testTheSmtpDriverNeedsAHost(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mail.smtp.host');
        $this->boot([], ['mail.driver' => 'smtp'])->container()->get(Mailer::class);
    }
}
