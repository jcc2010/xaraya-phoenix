<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use InvalidArgumentException;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Tests\Support\AuthTestCase;

final class UserRepositoryTest extends AuthTestCase
{
    public function testTheMigrationSeedsTheRolesAndTheirRoleWideGrants(): void
    {
        $db = $this->db($this->installed());
        $roles = array_column($db->select('roles')->columns('name')->orderBy('id')->all(), 'name');
        self::assertSame(['Administrators', 'Editors', 'Authors', 'Members', 'Anonymous'], $roles);
        $grants = $db->fetchAll('SELECT r.name, g.module, g.component, g.instance, g.level FROM {grants} g JOIN {roles} r ON r.id = g.role_id ORDER BY g.level DESC');
        self::assertEquals([
            ['name' => 'Administrators', 'module' => '*', 'component' => '*', 'instance' => '*', 'level' => 800],
            ['name' => 'Anonymous', 'module' => '*', 'component' => '*', 'instance' => '*', 'level' => 200],
        ], $grants);
    }

    public function testCreatesAUserWithItsRolesAndALowerCaseEmail(): void
    {
        $app = $this->installed();
        $user = $this->users($app)->create('ada', ' Ada@Example.COM ', self::PASSWORD, 'Ada Lovelace', 'active', ['Editors', 'Authors']);
        self::assertSame('ada', $user->handle);
        self::assertSame('ada@example.com', $user->email);
        self::assertSame('Ada Lovelace', $user->displayName());
        self::assertTrue($user->isActive());
        self::assertNull($user->lastLogin);
        self::assertSame(['Authors', 'Editors'], $this->users($app)->rolesOf($user));

        $hash = (string) $this->db($app)->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$user->id]);
        self::assertTrue(password_verify(self::PASSWORD, $hash));
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($user));
        self::assertSame(1, $this->users($app)->count());
    }

    public function testRejectsBadOrTakenHandlesEmailsPasswordsAndRoles(): void
    {
        $app = $this->installed();
        $this->user($app, 'ada');
        foreach ([
            ['A', 'x@example.com', self::PASSWORD, [], 'A handle is'],
            ['Ada', 'x@example.com', self::PASSWORD, [], 'A handle is'],
            ['ada', 'x@example.com', self::PASSWORD, [], "The handle 'ada' is taken"],
            ['bob', 'not-an-email', self::PASSWORD, [], 'The email address is not valid'],
            ['bob', 'ADA@example.com', self::PASSWORD, [], 'The email address is taken'],
            ['bob', 'bob@example.com', 'short', [], 'A password is at least 8 characters'],
            ['bob', 'bob@example.com', '', [], 'A password is at least 8 characters'],
            ['bob', 'bob@example.com', "correct horse\0battery", [], 'A password cannot contain a NUL character'],
            ['bob', 'bob@example.com', self::PASSWORD, ['Wizards'], "Unknown role 'Wizards'"],
        ] as [$handle, $email, $password, $roles, $message]) {
            try {
                $this->users($app)->create($handle, $email, $password, '', 'active', $roles);
                self::fail("accepted {$handle} {$email}");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame(1, $this->users($app)->count());
    }

    public function testFindsByHandleOrEmailIgnoringCase(): void
    {
        $app = $this->installed();
        $ada = $this->user($app, 'ada');
        $users = $this->users($app);
        self::assertSame($ada->id, $users->findByLogin('ada')?->id);
        self::assertSame($ada->id, $users->findByLogin(' ADA ')?->id);
        self::assertSame($ada->id, $users->findByLogin('Ada@Example.com')?->id);
        self::assertNull($users->findByLogin('nobody'));
        self::assertNull($users->findByLogin(''));
        self::assertSame($ada->id, $users->findByHandle('ada')?->id);
        self::assertSame($ada->id, $users->find($ada->id)?->id);
    }

    public function testAnOutdatedHashIsReplacedWhenThePasswordIsRight(): void
    {
        $app = $this->installed();
        $ada = $this->user($app, 'ada');
        $hash = fn(): string => (string) $this->db($app)->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$ada->id]);
        $old = $hash();
        self::assertStringContainsString('$04$', $old);

        $stronger = new UserRepository($this->db($app), ['cost' => 5]);
        self::assertFalse($stronger->verifyPassword($ada, 'wrong password'));
        self::assertSame($old, $hash(), 'a wrong password never rehashes');
        self::assertTrue($stronger->verifyPassword($ada, self::PASSWORD));
        self::assertStringContainsString('$05$', $hash());
        self::assertTrue($stronger->verifyPassword($ada, self::PASSWORD));
    }

    public function testStatusPasswordAndLastLoginUpdates(): void
    {
        $app = $this->installed();
        $users = $this->users($app);
        $ada = $this->user($app, 'ada');
        $users->setStatus($ada, 'disabled');
        self::assertFalse($users->find($ada->id)?->isActive());
        $users->setPassword($ada, 'a new password');
        self::assertTrue($users->verifyPassword($ada, 'a new password'));
        $now = new \DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('UTC'));
        $users->recordLogin($ada, $now);
        self::assertEquals($now, $users->find($ada->id)?->lastLogin);
        $this->expectException(InvalidArgumentException::class);
        $users->setStatus($ada, 'banned');
    }

    public function testANulPasswordCostsOneFullCheckLikeAnyOtherAndTooLongPasswordsAreRefused(): void
    {
        $app = $this->installed();
        $ada = $this->user($app, 'ada');
        $calls = [];
        $users = new UserRepository($this->db($app), ['cost' => 4], static function (string $password, string $hash) use (&$calls): bool {
            $calls[] = $password;

            return password_verify($password, $hash);
        });
        self::assertFalse($users->verifyPassword($ada, "x\0y"));
        self::assertCount(1, $calls, 'a NUL password still runs one check against the stored hash');
        $users->verifyNothing("x\0y");
        self::assertCount(2, $calls, 'verifyNothing runs one check too');
        self::assertFalse($users->verifyPassword($ada, 'wrong password'));
        self::assertCount(3, $calls);
        self::assertTrue($users->verifyPassword($ada, self::PASSWORD));
        self::assertCount(4, $calls);

        // bcrypt reads only 72 bytes, so a stored 72-byte password plus anything would otherwise verify.
        $max = $this->user($app, 'max', password: str_repeat('a', 72));
        self::assertFalse($users->verifyPassword($max, str_repeat('a', 72) . 'b'));
        self::assertCount(5, $calls, 'an over-long password still runs one full check');
        self::assertSame('', $calls[4]);
        self::assertTrue($users->verifyPassword($max, str_repeat('a', 72)));

        UserRepository::checkPassword(str_repeat('a', 72));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 72 bytes');
        UserRepository::checkPassword(str_repeat('a', 73));
    }

    public function testAPasswordWithANulIsRefusedOnSetAndNeverVerifies(): void
    {
        $app = $this->installed();
        $users = $this->users($app);
        $ada = $this->user($app, 'ada');
        $hash = fn(): string => (string) $this->db($app)->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$ada->id]);
        $before = $hash();

        foreach (['', "a new\0password"] as $bad) {
            try {
                $users->setPassword($ada, $bad);
                self::fail('setPassword accepted an invalid password');
            } catch (InvalidArgumentException) {
                self::assertSame($before, $hash(), 'a refused password leaves the hash alone');
            }
        }
        try {
            UserRepository::checkPassword("correct horse\0battery");
            self::fail('checkPassword accepted a NUL');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('NUL', $e->getMessage());
        }

        self::assertFalse($users->verifyPassword($ada, "correct horse battery\0"));
        self::assertFalse($users->verifyPassword($ada, "\0"));
        $users->verifyNothing("a\0b");
        self::assertTrue($users->verifyPassword($ada, self::PASSWORD));
    }
}
