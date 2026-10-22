<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use Xaraya\Kernel\Auth\PasswordResets;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class UserCommandsTest extends AuthTestCase
{
    public function testCreateWithFlags(): void
    {
        $app = $this->installed();
        [$code, $out, $err] = $this->xar($app, ['user:create', 'ada', '--email=ada@example.com', '--name=Ada Lovelace', '--role=Administrators,Editors', '--password=' . self::PASSWORD]);
        self::assertSame(0, $code, $err);
        self::assertSame("Created user 'ada' (Administrators, Editors).\n", $out);
        self::assertStringContainsString('shell history', $err);
        self::assertStringNotContainsString(self::PASSWORD, $err . $out);
        $ada = $this->users($app)->findByHandle('ada');
        self::assertNotNull($ada);
        self::assertSame('Ada Lovelace', $ada->name);
        self::assertSame(['Administrators', 'Editors'], $this->users($app)->rolesOf($ada));
        self::assertTrue($this->users($app)->verifyPassword($ada, self::PASSWORD));
    }

    public function testCreateAsksForThePasswordTwice(): void
    {
        $app = $this->installed();
        [$code, $out, $err] = $this->xar($app, ['user:create', 'bob', '--email=bob@example.com'], "a typed password\na typed password\n");
        self::assertSame(0, $code);
        self::assertSame('', $err);
        self::assertSame("Password: Password again: Created user 'bob'.\n", $out);
        $bob = $this->users($app)->findByHandle('bob');
        self::assertNotNull($bob);
        self::assertTrue($this->users($app)->verifyPassword($bob, 'a typed password'));
    }

    public function testCreateReadsThePasswordFromStandardInputForScripts(): void
    {
        $app = $this->installed();
        [$code, $out, $err] = $this->xar($app, ['user:create', 'cy', '--email=cy@example.com', '--password-stdin'], "piped in password\n");
        self::assertSame(0, $code, $err);
        self::assertSame('', $err);
        self::assertSame("Created user 'cy'.\n", $out);
        $cy = $this->users($app)->findByHandle('cy');
        self::assertNotNull($cy);
        self::assertTrue($this->users($app)->verifyPassword($cy, 'piped in password'));

        [$code, , $err] = $this->xar($app, ['user:create', 'di', '--email=di@example.com', '--password-stdin']);
        self::assertSame(1, $code);
        self::assertStringContainsString('No password on standard input', $err);
        self::assertNull($this->users($app)->findByHandle('di'));
    }

    public function testCreateRefusesBadInput(): void
    {
        $app = $this->installed();
        $this->user($app, 'ada');
        foreach ([
            [['user:create', 'x'], '', 'Usage: xar user:create'],
            [['user:create', 'bob', '--email=bob@example.com'], '', 'Give --password=<password> or type one'],
            [['user:create', 'bob', '--email=bob@example.com'], "one password\nanother one\n", 'The two passwords are not the same'],
            [['user:create', 'ada', '--email=new@example.com', '--password=' . self::PASSWORD], '', "The handle 'ada' is taken"],
            [['user:create', 'bob', '--email=bob@example.com', '--role=Wizards', '--password=' . self::PASSWORD], '', "Unknown role 'Wizards'"],
            [['user:create', 'bob', '--email=bob@example.com', '--password=short'], '', 'A password is at least 8 characters'],
        ] as [$args, $stdin, $message]) {
            [$code, $out, $err] = $this->xar($app, $args, $stdin);
            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err);
            foreach (['short', self::PASSWORD, 'one password', 'another one'] as $secret) {
                self::assertStringNotContainsString($secret, $out . $err);
            }
        }
        self::assertSame(1, $this->users($app)->count());
    }

    public function testPasswordSetsThePasswordAndEndsSessionsAndResetLinks(): void
    {
        $app = $this->probeApp(['mail.driver' => 'memory']);
        $ada = $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->login('ada', self::PASSWORD);
        $app->container()->get(PasswordResets::class)->send('ada@example.com', new DateTimeImmutable());

        [$code, $out, $err] = $this->xar($app, ['user:password', 'ada'], "a brand new password\na brand new password\n");
        self::assertSame(0, $code, $err);
        self::assertSame("Password: Password again: Changed the password of 'ada' and ended 1 session(s).\n", $out);
        self::assertTrue($this->users($app)->verifyPassword($ada, 'a brand new password'));
        self::assertNull(json_decode((string) $browser->get('/probe/whoami')->getBody(), true)['user']);
        self::assertSame(0, $this->db($app)->select('password_resets')->count());

        [$code, , $err] = $this->xar($app, ['user:password', 'nobody', '--password=' . self::PASSWORD]);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown user 'nobody'", $err);
    }

    public function testPasswordFromStandardInputAndRejectedPasswordChangesNothing(): void
    {
        $app = $this->installed();
        $ada = $this->user($app, 'ada');

        [$code, , $err] = $this->xar($app, ['user:password', 'ada', '--password-stdin'], "tiny\n");
        self::assertSame(1, $code);
        self::assertStringContainsString('A password is at least 8 characters', $err);
        self::assertStringNotContainsString('tiny', $err);
        self::assertTrue($this->users($app)->verifyPassword($ada, self::PASSWORD));

        [$code, $out, $err] = $this->xar($app, ['user:password', 'ada', '--password-stdin'], "from a pipe, fine\n");
        self::assertSame(0, $code, $err);
        self::assertSame("Changed the password of 'ada'.\n", $out);
        self::assertTrue($this->users($app)->verifyPassword($ada, 'from a pipe, fine'));
    }

    public function testHelpListsTheUserCommands(): void
    {
        [, $out] = $this->xar($this->installed(), ['help']);
        self::assertStringContainsString('user:create', $out);
        self::assertStringContainsString('user:password', $out);
    }
}
