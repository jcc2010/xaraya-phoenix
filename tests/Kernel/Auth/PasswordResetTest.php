<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use LogicException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\PasswordResets;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MailException;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Kernel\Mail\Message;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

final class PasswordResetTest extends AuthTestCase
{
    /** @param array<string, mixed> $overrides */
    private function mailApp(array $overrides = []): App
    {
        return $this->probeApp(['mail.driver' => 'memory', ...$overrides]);
    }

    private function mailer(App $app): MemoryMailer
    {
        $mailer = $app->container()->get(Mailer::class);
        self::assertInstanceOf(MemoryMailer::class, $mailer);

        return $mailer;
    }

    private function ask(App $app, string $email, string $ip = '203.0.113.7'): Browser
    {
        $browser = new Browser($app, $ip);
        $browser->get('/password/forgot');
        $browser->post('/password/forgot', ['email' => $email]);

        return $browser;
    }

    /** The path of the link in the last mail. */
    private function link(App $app): string
    {
        $sent = $this->mailer($app)->sent;
        self::assertNotSame([], $sent);
        self::assertSame(1, preg_match('#http://xar\.test(/password/reset/[A-Za-z0-9_-]{43})\n#', end($sent)->text, $m));

        return $m[1];
    }

    public function testTheAnswerIsTheSameWhetherOrNotTheAccountExists(): void
    {
        $app = $this->mailApp();
        $this->user($app, 'ada');
        $this->user($app, 'dee', [], 'disabled');
        $this->user($app, 'pat', [], 'pending');
        $pages = [];
        foreach (['ada@example.com', 'nobody@example.com', 'dee@example.com', 'pat@example.com', 'not an email'] as $email) {
            $browser = $this->ask($app, $email);
            $pages[$email] = self::answer($browser);
        }

        // The mail transport fails for an active account: logged (without the link), same answer.
        $failing = $this->mailApp();
        $failing->container()->instance(Mailer::class, new class implements Mailer {
            public function send(Message $message): void
            {
                throw new MailException('mail() did not accept the message');
            }
        });
        $log = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
            }
        };
        $failing->container()->instance(LoggerInterface::class, $log);
        $pages['mail failure'] = self::answer($this->ask($failing, 'ada@example.com', '198.51.100.99'));

        self::assertCount(1, array_unique($pages), 'byte-identical: ' . implode(', ', array_keys($pages)));
        self::assertStringContainsString('If an account uses that address, we have sent it a link to choose a new password.', $pages['nobody@example.com']);
        $token = (string) $this->db($app)->fetchValue('SELECT token_hash FROM {password_resets}');
        self::assertCount(1, $log->lines);
        self::assertStringContainsString('Could not send a password reset email', $log->lines[0]);
        self::assertStringNotContainsString('/password/reset', $log->lines[0]);
        self::assertStringNotContainsString($token, $log->lines[0]);

        $sent = $this->mailer($app)->sent;
        self::assertCount(1, $sent, 'only the active account gets mail');
        self::assertSame('ada@example.com', $sent[0]->to);
        self::assertSame('Reset your password', $sent[0]->subject);
        self::assertStringContainsString('of the account ada.', $sent[0]->text);
        self::assertStringContainsString('The link works once, for 60 minutes.', $sent[0]->text);
    }

    /** The status, the body exactly as sent, and the headers apart from the session cookie. */
    private static function answer(Browser $browser): string
    {
        $response = $browser->last;
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        $headers = $response->withoutHeader('Set-Cookie')->getHeaders();
        ksort($headers);

        return $response->getStatusCode() . "\n" . json_encode($headers) . "\n" . $response->getBody();
    }

    public function testTheLinkSetsANewPasswordOnceAndSignsTheUserOutEverywhere(): void
    {
        $app = $this->mailApp();
        $ada = $this->user($app, 'ada');
        $elsewhere = new Browser($app);
        $elsewhere->login('ada', self::PASSWORD);
        $this->ask($app, 'ADA@example.com');
        $link = $this->link($app);
        $token = substr($link, strlen('/password/reset/'));
        self::assertSame(hash('sha256', $token), $this->db($app)->fetchValue('SELECT token_hash FROM {password_resets}'));

        $browser = new Browser($app);
        $browser->get($link);
        self::assertStringContainsString('<label for="password">New password</label>', $browser->body());
        self::assertSame(422, $browser->post($link, ['password' => 'a new password', 'password_confirmation' => 'something else'])->getStatusCode());
        self::assertStringContainsString('The two passwords are not the same.', $browser->body());
        self::assertSame(422, $browser->post($link, ['password' => 'short', 'password_confirmation' => 'short'])->getStatusCode());
        self::assertStringContainsString('A password is at least 8 characters', $browser->body());

        $done = $browser->post($link, ['password' => 'a new password', 'password_confirmation' => 'a new password']);
        self::assertSame(303, $done->getStatusCode());
        self::assertSame('/login?reset=1', $done->getHeaderLine('Location'));
        self::assertTrue($this->users($app)->verifyPassword($ada, 'a new password'));
        self::assertFalse($this->users($app)->verifyPassword($ada, self::PASSWORD));
        self::assertNull(json_decode((string) $elsewhere->get('/probe/whoami')->getBody(), true)['user'], 'other sessions end');
        self::assertSame(0, $this->db($app)->select('password_resets')->count());

        $again = new Browser($app);
        $again->get($link);
        self::assertStringContainsString('This link has expired or has already been used.', $again->body());
        $again->get('/password/forgot');
        self::assertSame(200, $again->post($link, ['password' => 'third password', 'password_confirmation' => 'third password'])->getStatusCode());
        self::assertStringContainsString('This link has expired or has already been used.', $again->body());
        self::assertTrue($this->users($app)->verifyPassword($ada, 'a new password'));
    }

    public function testAnExpiredOrReplacedLinkIsRefused(): void
    {
        $app = $this->mailApp();
        $ada = $this->user($app, 'ada');
        $this->ask($app, 'ada@example.com');
        $first = $this->link($app);
        $this->ask($app, 'ada@example.com');
        $second = $this->link($app);
        $resets = $app->container()->get(PasswordResets::class);
        $now = new DateTimeImmutable();
        self::assertNull($resets->find(substr($first, 16), $now), 'asking again replaces the link');
        self::assertSame($ada->id, $resets->find(substr($second, 16), $now)?->id);

        $this->db($app)->query('UPDATE {password_resets} SET expires_at = ?', [new DateTimeImmutable('-1 second')]);
        self::assertNull($resets->reset(substr($second, 16), 'a new password', $now));
        self::assertTrue($this->users($app)->verifyPassword($ada, self::PASSWORD));
        self::assertNull($resets->find('not-a-token', $now));
    }

    public function testRequestsAreThrottledPerIp(): void
    {
        $app = $this->mailApp();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $this->ask($app, "x{$i}@example.com", '198.51.100.20')->last?->getStatusCode());
        }
        self::assertSame(429, $this->ask($app, 'x@example.com', '198.51.100.20')->last?->getStatusCode());
        self::assertSame(200, $this->ask($app, 'x@example.com', '198.51.100.21')->last?->getStatusCode());
    }

    public function testLinksMustBeAbsolute(): void
    {
        $app = $this->mailApp(['app.url' => '']);
        $this->user($app, 'ada');
        $this->expectException(LogicException::class);
        $app->container()->get(PasswordResets::class)->send('ada@example.com', new DateTimeImmutable());
    }

    public function testTheFormsAreTheSameInBothEngines(): void
    {
        $pages = [];
        $this->user($this->mailApp(), 'ada');
        foreach (['php', 'twig'] as $engine) {
            $app = $this->mailApp(['view.engine' => $engine]);
            $browser = $this->ask($app, 'ada@example.com');
            $sent = Html::normalize($browser->body());
            $browser->get('/password/forgot');
            $form = Html::normalize((string) preg_replace('/value="[A-Za-z0-9_-]{43}"/', 'value="T"', $browser->body()));
            $browser->get($this->link($app));
            $reset = Html::normalize((string) preg_replace(['/value="[A-Za-z0-9_-]{43}"/', '#/password/reset/[A-Za-z0-9_-]{43}#'], ['value="T"', '/password/reset/L'], $browser->body()));
            $pages[$engine] = [$sent, $form, $reset];
        }
        self::assertSame($pages['php'], $pages['twig']);
        self::assertStringContainsString('<label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required>', $pages['php'][1]);
        self::assertStringContainsString('<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="password-hint">', $pages['php'][2]);
    }

    public function testAnUnusablePasswordIsRefusedWithoutSpendingTheLink(): void
    {
        $app = $this->mailApp();
        $ada = $this->user($app, 'ada');
        $this->ask($app, 'ada@example.com');
        $link = $this->link($app);
        $browser = new Browser($app);
        $browser->get($link);
        $cases = [
            '' => 'A password is at least 8 characters',
            "long enough\0but NUL" => 'A password cannot contain a NUL character',
            str_repeat('x', 73) => 'A password is at most 72 bytes',
        ];
        foreach ($cases as $password => $message) {
            $response = $browser->post($link, ['password' => (string) $password, 'password_confirmation' => (string) $password]);
            self::assertSame(422, $response->getStatusCode(), $message);
            self::assertStringContainsString($message, $browser->body());
        }
        self::assertSame(1, $this->db($app)->select('password_resets')->count(), 'the link still works');
        self::assertTrue($this->users($app)->verifyPassword($ada, self::PASSWORD));
    }

    public function testTheLinkComesFromAppUrlNotTheRequestHost(): void
    {
        $app = $this->mailApp();
        $this->user($app, 'ada');
        $browser = new Browser($app, '203.0.113.7', 'http://evil.example');
        $browser->get('/password/forgot');
        $browser->post('/password/forgot', ['email' => 'ada@example.com'], ['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example']);
        self::assertStringNotContainsString('evil.example', $this->mailer($app)->sent[0]->text);
        $this->link($app);
    }

    public function testTheResetPageNeverLeaksItsTokenThroughReferer(): void
    {
        $app = $this->mailApp();
        $this->user($app, 'ada');
        $this->ask($app, 'ada@example.com');
        $link = $this->link($app);
        $browser = new Browser($app);
        foreach ([$browser->get($link), $browser->post($link, ['password' => 'x', 'password_confirmation' => 'y']), (new Browser($app))->get('/password/reset/' . str_repeat('a', 43))] as $response) {
            self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
            self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testTheResetFormNeedsTheCsrfToken(): void
    {
        $app = $this->mailApp();
        $ada = $this->user($app, 'ada');
        $this->ask($app, 'ada@example.com');
        $link = $this->link($app);
        $browser = new Browser($app);
        $browser->get($link);
        $response = $browser->post($link, ['_csrf' => 'forged', 'password' => 'a new password', 'password_confirmation' => 'a new password']);
        self::assertSame(403, $response->getStatusCode());
        self::assertTrue($this->users($app)->verifyPassword($ada, self::PASSWORD));
        self::assertSame(1, $this->db($app)->select('password_resets')->count());
    }
}
