<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use InvalidArgumentException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Tests\Support\AuthTestCase;

final class AuthTest extends AuthTestCase
{
    private function auth(App $app): Auth
    {
        return $app->container()->get(Auth::class);
    }

    public function testAccessIsBoundToAuth(): void
    {
        $app = $this->installed();
        self::assertSame($this->auth($app), $app->container()->get(Access::class));
    }

    public function testAGuestHasAnonymousAndMayRead(): void
    {
        $auth = $this->auth($this->installed());
        self::assertNull($auth->user());
        self::assertFalse($auth->check());
        self::assertSame(['Anonymous'], $auth->roles());
        self::assertTrue($auth->can('overview'));
        self::assertTrue($auth->can('read', 'blog', 'post'));
        self::assertFalse($auth->can('comment', 'blog', 'post'));
        $this->expectException(InvalidArgumentException::class);
        $auth->can('superuser');
    }

    public function testASignedInUserAlsoHasMembersAndTheirRoles(): void
    {
        $app = $this->installed();
        $grants = $app->container()->get(Grants::class);
        $grants->grant('Members', 'wiki', '*', '*', 'comment');
        $grants->grant('Editors', 'wiki', 'page', '*', 'delete');
        $auth = $this->auth($app);

        $auth->actAs($this->user($app, 'ed', ['Editors']));
        self::assertTrue($auth->check());
        self::assertSame(['Anonymous', 'Members', 'Editors'], $auth->roles());
        self::assertTrue($auth->can('delete', 'wiki', 'page'));
        self::assertTrue($auth->can('comment', 'wiki', 'talk'));
        self::assertFalse($auth->can('delete', 'wiki', 'talk'));
        self::assertFalse($auth->can('admin', 'wiki', 'page'));

        $auth->actAs($this->user($app, 'root', ['Administrators']));
        self::assertTrue($auth->can('admin', 'anything', 'at-all', ['id' => 'x']));

        $auth->actAs(null);
        self::assertFalse($auth->can('comment', 'wiki', 'talk'));
    }

    public function testAnInactiveUserIsAGuest(): void
    {
        $app = $this->installed();
        $auth = $this->auth($app);
        $auth->actAs($this->user($app, 'gone', ['Administrators'], 'disabled'));
        self::assertNull($auth->user());
        self::assertSame(['Anonymous'], $auth->roles());
        self::assertFalse($auth->can('admin', 'hello', 'greeting'));
    }

    public function testRolesInheritTheirAncestorsGrants(): void
    {
        $app = $this->installed();
        $grants = $app->container()->get(Grants::class);
        $grants->ensureRole('Seniors', 'Editors');
        $grants->ensureRole('Leads', 'Seniors');
        $grants->grant('Editors', 'wiki', 'page', '*', 'edit');
        $auth = $this->auth($app);
        $auth->actAs($this->user($app, 'lea', ['Leads']));
        self::assertTrue($auth->can('edit', 'wiki', 'page'));
        self::assertFalse($auth->can('add', 'wiki', 'page'));
    }

    public function testInstancesMatchByIdOrOwner(): void
    {
        $app = $this->installed();
        $grants = $app->container()->get(Grants::class);
        $grants->grant('Members', 'wiki', 'page', '42', 'edit');
        $grants->grant('Authors', 'blog', 'post', 'own', 'delete');
        $auth = $this->auth($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $auth->actAs($ada);

        self::assertTrue($auth->can('edit', 'wiki', 'page', ['id' => 42]));
        self::assertTrue($auth->can('edit', 'wiki', 'page', '42'));
        self::assertFalse($auth->can('edit', 'wiki', 'page', ['id' => 43]));
        self::assertTrue($auth->can('edit', 'wiki', 'page'), 'without an item, any instance counts');

        $mine = (object) ['id' => 'p1', 'owner_id' => $ada->id];
        self::assertTrue($auth->can('delete', 'blog', 'post', $mine));
        self::assertTrue($auth->can('delete', 'blog', 'post', ['id' => 'p1', 'owner_id' => $ada->id]));
        self::assertFalse($auth->can('delete', 'blog', 'post', ['id' => 'p2', 'owner_id' => 'someone-else']));
        self::assertFalse($auth->can('delete', 'blog', 'post', ['id' => 'p3']), 'an item without owner_id is nobody\'s own');
        self::assertTrue($auth->can('read', 'blog', 'post', ['id' => 'p3']), 'Anonymous read still applies');

        $auth->actAs(null);
        self::assertFalse($auth->can('delete', 'blog', 'post', ['id' => 'p1', 'owner_id' => $ada->id]));
    }

    public function testResultsAreMemoisedUntilTheIdentityChanges(): void
    {
        $app = $this->installed();
        $auth = $this->auth($app);
        self::assertFalse($auth->can('edit', 'wiki', 'page'));
        $app->container()->get(Grants::class)->grant('Anonymous', 'wiki', 'page', '*', 'edit');
        self::assertFalse($auth->can('edit', 'wiki', 'page'), 'memoised for the request');
        $auth->actAs(null);
        self::assertTrue($auth->can('edit', 'wiki', 'page'));
    }
}
