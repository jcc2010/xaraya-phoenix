<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Module\Blog\Admin\Http\NoStore;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

final class DashboardTest extends AdminTestCase
{
    public function testAGuestIsSentToLoginAndAMemberIsRefused(): void
    {
        $app = $this->site();
        $response = (new Browser($app))->get('/admin/blog');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?next=%2Fadmin%2Fblog', $response->getHeaderLine('Location'));
        self::assertPrivateNoStore($response);
        $this->user($app, 'mia');
        $forbidden = $this->signIn($app, 'mia')->get('/admin/blog');
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertPrivateNoStore($forbidden);
    }

    public function testNoStoreMarksThrownHttpExceptionsToo(): void
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new NotFound('gone');
            }
        };
        try {
            (new NoStore())->process(new ServerRequest('GET', '/admin/blog'), $handler);
            self::fail('Expected the exception to be rethrown');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status());
            self::assertSame('gone', $e->getMessage());
            self::assertSame('private, no-store', $e->headers()['Cache-Control']);
        }
        $ok = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Cache-Control' => 'public, max-age=60']);
            }
        };
        self::assertPrivateNoStore((new NoStore())->process(new ServerRequest('GET', '/admin/blog'), $ok));
    }

    public function testTheDashboardListsEveryBlogWithItsCounts(): void
    {
        $app = $this->site();
        $notes = $this->nativeBlog($app, ['title' => 'Notes <&>']);
        $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena'], $this->now());
        $ada = $this->user($app, 'ada', ['Authors']);
        $this->postAs($app, $ada, $notes, ['content_html' => '<p>now</p>']);
        $this->postAs($app, $ada, $notes, ['content_html' => '<p>later</p>', 'date_published' => '2999-01-01T00:00:00Z']);
        $gone = $this->postAs($app, $ada, $notes, ['content_html' => '<p>gone</p>']);
        $app->container()->get(PostWriter::class)->delete($gone);

        $main = self::main($this->both('/admin/blog', 'ada'));
        self::assertStringContainsString('<tr id="blog-notes">', $main);
        self::assertStringContainsString('Notes &lt;&amp;&gt;', $main);
        self::assertMatchesRegularExpression('#<tr id="blog-notes">.*1 published.*1 scheduled.*1 deleted.*</tr>#sU', $main);
        self::assertStringContainsString('<tr id="blog-mirror">', $main);
        self::assertStringContainsString('<a href="/blog/notes">View blog</a>', $main);
        self::assertSame(['published' => 1, 'scheduled' => 1, 'deleted' => 1], $this->posts($app)->adminCounts($notes->id, $this->now()));
    }

    public function testFlashMessagesShowOnceEscapedWithTheirRole(): void
    {
        $app = $this->site();
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $store = $app->container()->get(SessionStore::class);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $session = $store->load($browser->cookies['xar_session'], $now) ?? self::fail('no session');
        $session->flash('Saved <b>');
        $session->flash('Broke', 'error');
        self::assertTrue($store->save($session, $now, 3600));
        $main = self::main(Html::normalize((string) $browser->get('/admin/blog')->getBody()));
        self::assertStringContainsString('<p class="admin-flash admin-flash-status" role="status">Saved &lt;b&gt;</p>', $main);
        self::assertStringContainsString('<p class="admin-flash admin-flash-error" role="alert">Broke</p>', $main);
        $again = (string) $browser->get('/admin/blog')->getBody();
        self::assertStringNotContainsString('admin-flash', $again);
    }

    public function testAdminResponsesAreNeverStoredAndTheHeaderLinksHere(): void
    {
        $app = $this->site();
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $response = $browser->get('/admin/blog');
        self::assertSame(200, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        self::assertStringContainsString('<a href="/admin/blog">Blog admin</a>', (string) $response->getBody());
    }

    public function testEveryAdminRouteIsSecureHtmlNoStoreAndAuthChecked(): void
    {
        $routes = array_filter(
            $this->site()->container()->get(Router::class)->routes(),
            static fn(Route $r): bool => str_starts_with((string) $r->name, 'admin.blog'),
        );
        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            self::assertStringStartsWith('/admin/blog', $route->path, (string) $route->name);
            self::assertTrue($route->hasMiddleware('secureHtml') && $route->hasMiddleware('noStore'), (string) $route->name);
            self::assertCount(1, preg_grep('/^auth:blog\.(post|blog)\.[a-z]+$/', $route->getMiddleware()) ?: [], (string) $route->name);
        }
    }
}
