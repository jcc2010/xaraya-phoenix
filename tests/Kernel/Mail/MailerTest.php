<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Mail;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Xaraya\Kernel\Mail\LogMailer;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MailException;
use Xaraya\Kernel\Mail\MailFunctionMailer;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Kernel\Mail\Message;
use Xaraya\Tests\Support\AppTestCase;

final class MailerTest extends AppTestCase
{
    public function testAMessageCannotSmuggleHeaders(): void
    {
        $bad = [
            ['not-an-address', 'Hi'],
            ["a@example.com\r\nBcc: x@example.com", 'Hi'],
            ["a@example.com\n", 'Hi'],
            ['"a,b"@example.com', 'Hi'],
            ['a@example.com', "Hi\r\nBcc: x@example.com"],
            ['a@example.com', "Hi\nthere"],
            ['a@example.com', "Hi\rthere"],
        ];
        foreach ($bad as [$to, $subject]) {
            try {
                new Message($to, $subject, 'body');
                self::fail('accepted ' . json_encode([$to, $subject]));
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        self::assertSame('ada@example.com', (new Message('ada@example.com', 'Hi', "multi\nline"))->to);
    }

    public function testTheDriversRefuseAFromThatCarriesHeaders(): void
    {
        foreach (["a@example.com\r\nBcc: x@example.com", 'nope', ''] as $from) {
            foreach ([fn() => new LogMailer($this->tmp, $from), fn() => new MailFunctionMailer($from)] as $make) {
                try {
                    $make();
                    self::fail('accepted from ' . json_encode($from));
                } catch (InvalidArgumentException $e) {
                    self::assertStringContainsString('mail.from', $e->getMessage());
                }
            }
        }
    }

    public function testTheLogDriverWritesAPrivateEmlFile(): void
    {
        $dir = $this->tmp . '/mail';
        (new LogMailer($dir, 'site@example.com'))->send(new Message('ada@example.com', 'Réinitialiser', "Line one\nhttps://xar.test/x"));
        $files = glob($dir . '/*.eml') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[0-9a-f]{8}\.eml$/', basename($files[0]));
        self::assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
        $eml = (string) file_get_contents($files[0]);
        self::assertStringStartsWith("From: site@example.com\nTo: ada@example.com\nSubject: Réinitialiser\nDate: ", $eml);
        self::assertStringEndsWith("Content-Type: text/plain; charset=utf-8\n\nLine one\nhttps://xar.test/x\n", $eml);
    }

    public function testTheLogDriverReportsAnUnwritableDirectoryAsAMailException(): void
    {
        file_put_contents($this->tmp . '/file', 'x');
        $this->expectException(MailException::class);
        (new LogMailer($this->tmp . '/file/sub', 'site@example.com'))->send(new Message('ada@example.com', 'x', 'y'));
    }

    public function testAFailedLogWriteLeavesNoFileBehind(): void
    {
        $dir = $this->tmp . '/ro';
        mkdir($dir, 0500);
        if (is_writable($dir)) {
            chmod($dir, 0700);
            self::markTestSkipped('The directory stays writable (running as root ignores the mode)');
        }
        try {
            (new LogMailer($dir, 'site@example.com'))->send(new Message('ada@example.com', 'x', 'y'));
            self::fail('expected MailException');
        } catch (MailException) {
            self::assertSame([], glob($dir . '/*') ?: []);
        } finally {
            chmod($dir, 0700);
        }
    }

    public function testTheMailDriverHandsAnEncodedMessageToMail(): void
    {
        $calls = [];
        $mailer = new MailFunctionMailer('site@example.com', static function (string $to, string $subject, string $body, array $headers) use (&$calls): bool {
            $calls[] = [$to, $subject, $body, $headers];

            return true;
        });
        $mailer->send(new Message('ada@example.com', 'Réinitialiser', "one\ntwo\rthree"));
        self::assertSame([[
            'ada@example.com',
            mb_encode_mimeheader('Réinitialiser', 'UTF-8', 'Q'),
            "one\r\ntwo\r\nthree",
            ['From' => 'site@example.com', 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=utf-8', 'Content-Transfer-Encoding' => '8bit'],
        ]], $calls);
        foreach ($calls[0][3] as $name => $value) {
            self::assertDoesNotMatchRegularExpression('/[\r\n]/', $name . $value);
        }
        self::assertDoesNotMatchRegularExpression('/[\r\n]/', $calls[0][1]);
    }

    public function testAFailingMailDriverThrowsATypedExceptionTheCallerCanCatch(): void
    {
        $message = new Message('ada@example.com', 'x', 'y');
        try {
            (new MailFunctionMailer('site@example.com', static fn(): bool => false))->send($message);
            self::fail('expected MailException');
        } catch (MailException $e) {
            self::assertInstanceOf(RuntimeException::class, $e);
        }
        $this->expectException(MailException::class);
        (new MailFunctionMailer('site@example.com', static fn(): bool => throw new \ErrorException('smtp down')))->send($message);
    }

    public function testTheDriverComesFromConfig(): void
    {
        self::assertInstanceOf(LogMailer::class, $this->boot()->container()->get(Mailer::class));
        self::assertInstanceOf(MailFunctionMailer::class, $this->boot([], ['mail.driver' => 'mail', 'mail.from' => 'site@example.com'])->container()->get(Mailer::class));
        $memory = $this->boot([], ['mail.driver' => 'memory'])->container()->get(Mailer::class);
        self::assertInstanceOf(MemoryMailer::class, $memory);
        $memory->send(new Message('ada@example.com', 'x', 'y'));
        self::assertCount(1, $memory->sent);
        self::assertSame('ada@example.com', $memory->sent[0]->to);
        $this->expectException(LogicException::class);
        $this->boot([], ['mail.driver' => 'pigeon'])->container()->get(Mailer::class);
    }

    public function testTheDefaultFromIsAValidAddressDerivedFromTheAppUrl(): void
    {
        $from = function (string $url, string $configured = ''): string {
            $mailer = $this->boot([], ['mail.driver' => 'mail', 'mail.from' => $configured, 'app.url' => $url])->container()->get(Mailer::class);
            $property = new \ReflectionProperty($mailer, 'from');

            return (string) $property->getValue($mailer);
        };
        self::assertSame('phoenix@blog.example.com', $from('https://blog.example.com/xaraya'));
        self::assertSame('phoenix@example.invalid', $from('http://localhost:8080'));
        self::assertSame('phoenix@example.invalid', $from(''));
        self::assertSame('phoenix@example.invalid', $from('http://127.0.0.1:8000'));
        self::assertSame('phoenix@example.invalid', $from('http://[::1]:8000'));
        self::assertSame('me@site.org', $from('https://blog.example.com', 'me@site.org'));
        foreach (['https://blog.example.com', 'http://localhost:8080', 'http://127.0.0.1:8000', 'http://[::1]:8000'] as $url) {
            self::assertNotFalse(filter_var($from($url), FILTER_VALIDATE_EMAIL));
        }
    }
}
