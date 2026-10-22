<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

/** Kernel spec §7, items 1 and 2 and the auth bullets of item 3, end to end through the CLI and App::handle(). */
final class KernelAuthDoneTest extends AuthTestCase
{
    private function site(): App
    {
        return $this->app([
            'install.env_file' => $this->tmp . '/.env',
            'assets.path' => $this->tmp . '/assets',
            'blog.locks' => $this->tmp . '/locks',
            'mail.driver' => 'memory',
            'session.gc_probability' => 0,
        ], [dirname(__DIR__, 2) . '/modules', dirname(__DIR__, 2) . '/examples']);
    }

    private function cli(string ...$args): string
    {
        [$code, $out, $err] = $this->xar($this->site(), array_values($args));
        self::assertSame(0, $code, $out . $err);

        return $out;
    }

    private function api(App $app, ?string $token, string $name): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/api/hello/greetings', ['Content-Type' => 'application/json'], (string) json_encode(['name' => $name])));

        return $app->handle($token === null ? $request : $request->withHeader('Authorization', 'Bearer ' . $token));
    }

    public function testItem1InstallServesThePhoenixHomePage(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $home = $this->site()->handle(new ServerRequest('GET', '/'));
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css">', Html::normalize((string) $home->getBody()));
        self::assertFileExists($this->tmp . '/assets/theme/phoenix/phoenix.css');
    }

    public function testItem2LoginThrottlingCsrfResetAndUserPassword(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $app = $this->site();

        $admin = new Browser($app);
        self::assertSame(303, $admin->login('admin', self::PASSWORD)->getStatusCode());
        $admin->get('/login');
        self::assertSame(303, $admin->post('/logout')->getStatusCode());

        self::assertSame(403, (new Browser($app))->post('/login', ['login' => 'admin', 'password' => self::PASSWORD])->getStatusCode(), 'CSRF');

        $attacker = new Browser($app, '198.51.100.66');
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $attacker->login('admin', 'not the password')->getStatusCode());
        }
        self::assertSame(429, $attacker->login('admin', self::PASSWORD)->getStatusCode(), 'throttled');

        $forgot = new Browser($app);
        $forgot->get('/password/forgot');
        $forgot->post('/password/forgot', ['email' => 'admin@example.com']);
        $mailer = $app->container()->get(Mailer::class);
        self::assertInstanceOf(MemoryMailer::class, $mailer);
        self::assertSame(1, preg_match('#http://xar\.test(/password/reset/\S+)#', $mailer->sent[0]->text ?? '', $m));
        $forgot->get($m[1]);
        self::assertSame(303, $forgot->post($m[1], ['password' => 'reset password', 'password_confirmation' => 'reset password'])->getStatusCode());
        self::assertSame(303, (new Browser($app))->login('admin', 'reset password')->getStatusCode());

        $this->cli('user:password', 'admin', '--password=cli password');
        self::assertSame(303, (new Browser($this->site()))->login('admin', 'cli password')->getStatusCode());
    }

    public function testItem3HelloAdminRouteAndTokenScope(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $this->cli('module:enable', 'hello');
        $grant = $this->db($this->site())->fetchOne("SELECT g.component, g.level FROM {grants} g JOIN {roles} r ON r.id = g.role_id WHERE r.name = 'Members' AND g.module = 'hello'");
        self::assertEquals(['component' => 'greeting', 'level' => 300], $grant, 'module:enable seeds the manifest grants');
        $this->cli('user:create', 'mia', '--email=mia@example.com', '--password=' . self::PASSWORD);
        $app = $this->site();

        $member = new Browser($app);
        $member->login('mia', self::PASSWORD);
        self::assertSame(403, $member->get('/hello/admin')->getStatusCode());
        $admin = new Browser($app);
        $admin->login('admin', self::PASSWORD);
        self::assertSame(200, $admin->get('/hello/admin')->getStatusCode());
        self::assertStringContainsString('<h1>Greetings admin</h1>', $admin->body());
        self::assertSame(302, (new Browser($app))->get('/hello/admin')->getStatusCode());

        // The blog module stays disabled: the tokens module no longer depends on it.
        $this->cli('module:enable', 'tokens');
        $lines = explode("\n", trim($this->cli('token:create', '--name=hello', '--scopes=hello:write')));
        $writer = (string) end($lines);
        $lines = explode("\n", trim($this->cli('token:create', '--name=other', '--scopes=blog:write')));
        $otherScope = (string) end($lines);

        $app = $this->site();
        $created = $this->api($app, $writer, 'ada');
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(403, $this->api($app, $otherScope, 'bob')->getStatusCode());
        self::assertSame(401, $this->api($app, null, 'bob')->getStatusCode());
        self::assertSame(422, $this->api($app, $writer, 'Not Valid')->getStatusCode());
        self::assertSame(['greetings' => ['ada']], json_decode((string) $app->handle(new ServerRequest('GET', '/hello.json'))->getBody(), true));
    }

    public function testTheAdminPageIsTheSameInBothEngines(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $this->cli('module:enable', 'hello');
        $pages = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = new Browser($this->app([
                'view.engine' => $engine,
                'blog.locks' => $this->tmp . '/locks',
            ], [dirname(__DIR__, 2) . '/modules', dirname(__DIR__, 2) . '/examples']));
            $browser->login('admin', self::PASSWORD);
            $pages[$engine] = Html::normalize($browser->get('/hello/admin')->getBody()->__toString());
        }
        self::assertSame($pages['php'], $pages['twig']);
        self::assertStringContainsString('<p>Greetings so far: 0</p>', $pages['php']);
    }
}
