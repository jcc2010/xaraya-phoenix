<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\View\Helpers;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

final class BlocksAndHelpersTest extends AuthTestCase
{
    private function blocks(App $app): BlockRepository
    {
        return $app->container()->get(BlockRepository::class);
    }

    /** @return list<string> the text blocks shown on the home page */
    private static function shown(Browser $browser): array
    {
        preg_match_all('/<p class="t">([a-z]+)<\/p>/', $browser->get('/')->getBody()->__toString(), $m);

        return $m[1];
    }

    public function testRoleRulesFollowTheSignedInUser(): void
    {
        $app = $this->probeApp();
        foreach (['everyone' => ['Anonymous'], 'members' => ['Members'], 'staff' => ['Editors', 'Administrators'], 'nobody' => ['Wizards']] as $text => $roles) {
            $this->blocks($app)->create('text', 'sidebar', null, ['body' => "<p class=\"t\">{$text}</p>"], ['roles' => $roles]);
        }
        $this->blocks($app)->create('text', 'sidebar', null, ['body' => '<p class="t">always</p>']);
        $this->user($app, 'mia');
        $this->user($app, 'ed', ['Editors']);

        self::assertSame(['everyone', 'always'], self::shown(new Browser($app)));
        $member = new Browser($app);
        $member->login('mia', self::PASSWORD);
        self::assertSame(['everyone', 'members', 'always'], self::shown($member));
        $editor = new Browser($app);
        $editor->login('ed', self::PASSWORD);
        self::assertSame(['everyone', 'members', 'staff', 'always'], self::shown($editor));
    }

    public function testAMalformedRoleRuleHidesTheBlockFromEveryone(): void
    {
        $app = $this->probeApp();
        $this->db($app)->insert('blocks', ['type' => 'text', 'region' => 'sidebar', 'title' => null, 'config' => ['body' => '<p class="t">broken</p>'], 'sort' => 0, 'visibility' => '{"roles":"Members"}', 'enabled' => true]);
        $this->user($app, 'root', ['Administrators']);
        $admin = new Browser($app);
        $admin->login('root', self::PASSWORD);
        self::assertSame([], self::shown($admin));
        self::assertSame([], self::shown(new Browser($app)));
    }

    public function testTheLoginBlock(): void
    {
        $app = $this->probeApp();
        $this->blocks($app)->create('login', 'header', null);
        $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->get('/?page=2');
        self::assertStringContainsString('<p><a href="/login?next=%2F%3Fpage%3D2">Log in</a></p>', $browser->body());

        $browser->login('ada', self::PASSWORD);
        $browser->get('/');
        $html = Html::normalize((string) preg_replace('/value="[A-Za-z0-9_-]{43}"/', 'value="T"', $browser->body()));
        self::assertStringContainsString('<p>Signed in as Ada</p><form class="logout-form" method="post" action="/logout"><input type="hidden" name="_csrf" value="T"><button type="submit">Log out</button></form>', $html);
        self::assertSame(303, $browser->post('/logout')->getStatusCode(), 'the block\'s form logs out');
        self::assertStringContainsString('>Log in</a>', $browser->get('/')->getBody()->__toString());
    }

    public function testTheLoginBlockNeverMintsASessionForAGuest(): void
    {
        $app = $this->probeApp();
        $this->blocks($app)->create('login', 'header', null);
        $response = (new Browser($app))->get('/');
        self::assertSame([], $response->getHeader('Set-Cookie'));
        self::assertStringNotContainsString('_csrf', $response->getBody()->__toString());
        self::assertStringNotContainsString('private', $response->getHeaderLine('Cache-Control'));
    }

    public function testUserAndCanHelpersInBothEngines(): void
    {
        $theme = Fixtures::theme($this->tmp . '/themes', 'probe', ['engine' => 'php'], [
            'templates/who.php' => "<?= \$x->e(\$x->user()?->handle ?? 'guest') ?> <?= \$x->can('admin', 'hello', 'greeting') ? 'admin' : 'reader' ?> <?= \$x->can('read', 'hello', 'greeting') ? 'reads' : 'blind' ?>",
            'templates/who.twig' => "{{ user() ? user().handle : 'guest' }} {{ can('admin', 'hello', 'greeting') ? 'admin' : 'reader' }} {{ can('read', 'hello', 'greeting') ? 'reads' : 'blind' }}",
        ]);
        foreach (['php', 'twig'] as $engine) {
            $app = $this->installed(['themes.paths' => [dirname($theme)], 'app.theme' => 'probe', 'view.engine' => $engine]);
            $view = $app->container()->get(View::class);
            self::assertSame('guest reader reads', $view->render('who'), $engine);
            $app->container()->get(Auth::class)->actAs($this->users($app)->findByHandle('root') ?? $this->user($app, 'root', ['Administrators']));
            self::assertSame('root admin reads', $view->render('who'), $engine);
        }
    }

    public function testAdminLinksShowOnlyToUsersWhoMayUseThem(): void
    {
        foreach (['php', 'twig'] as $engine) {
            $app = $this->probeApp(['view.engine' => $engine]);
            $helpers = new Helpers($app->container()->get(View::class), $app->container());
            self::assertSame([], $helpers->adminLinks(), "{$engine}: guest");
            $guestHome = (string) $app->handle(new ServerRequest('GET', '/'))->getBody();
            self::assertStringNotContainsString('admin-links', $guestHome);

            $app->container()->get(Auth::class)->actAs($this->users($app)->findByHandle('mia') ?? $this->user($app, 'mia'));
            self::assertSame([], $helpers->adminLinks(), "{$engine}: member");

            $root = $this->users($app)->findByHandle('root') ?? $this->user($app, 'root', ['Administrators']);
            $app->container()->get(Auth::class)->actAs($root);
            self::assertSame([['url' => '/probe/admin', 'label' => 'Probe admin']], $helpers->adminLinks(), "{$engine}: the missing route is skipped");

            $browser = new Browser($app);
            $browser->login('root', self::PASSWORD);
            self::assertSame(200, $browser->get('/')->getStatusCode(), "{$engine}: a parameterised admin route never breaks the header");
            self::assertStringNotContainsString('Needs a parameter', $browser->body());
            self::assertStringContainsString('<nav class="admin-links" aria-label="Admin"><ul><li><a href="/probe/admin">Probe admin</a></li></ul></nav>', Html::normalize((string) $browser->get('/')->getBody()));
        }
    }
}
