<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Routing;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\Router;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new RouteCollector();
        $r->get('/blog/{handle}/feed.json', 'FeedController', 'blog.feed')->middleware('cors', 'conditional');
        $r->get('/s/{id:[0-9a-z]{26}}', 'PostController', 'post.show');
        $r->post('/s', 'PostController::store', 'post.store');
        $r->group('/admin', ['auth'], function (RouteCollector $r): void {
            $r->get('/posts', 'AdminController', 'admin.posts')->middleware('auth:blog.post.edit');
        });

        return new Router($r->routes());
    }

    public function testMatchesWithParams(): void
    {
        $m = $this->router()->match('GET', '/blog/wyome/feed.json');
        self::assertSame('blog.feed', $m->route->name);
        self::assertSame(['handle' => 'wyome'], $m->params);
        self::assertSame(['cors', 'conditional'], $m->route->getMiddleware());
    }

    public function testRegexConstraint(): void
    {
        self::assertSame('post.show', $this->router()->match('GET', '/s/01m3t19wn8reaww81zg1m47tjq')->route->name);
        $this->expectException(NotFound::class);
        $this->router()->match('GET', '/s/not-a-ulid');
    }

    public function testHeadFallsBackToGet(): void
    {
        self::assertSame('post.show', $this->router()->match('HEAD', '/s/01m3t19wn8reaww81zg1m47tjq')->route->name);
    }

    public function testMethodNotAllowedCarriesAllowHeader(): void
    {
        try {
            $this->router()->match('DELETE', '/s');
            self::fail('expected 405');
        } catch (MethodNotAllowed $e) {
            self::assertSame(405, $e->status());
            self::assertSame(['Allow' => 'POST'], $e->headers());
        }
    }

    public function testGroupPrefixAndMiddleware(): void
    {
        $m = $this->router()->match('GET', '/admin/posts');
        self::assertSame(['auth', 'auth:blog.post.edit'], $m->route->getMiddleware());
        self::assertTrue($m->route->hasMiddleware('auth'));
        self::assertFalse($m->route->hasMiddleware('cors'));
    }

    public function testCorsRoutesGetPreflight(): void
    {
        $m = $this->router()->match('OPTIONS', '/blog/wyome/feed.json');
        self::assertSame(['OPTIONS'], $m->route->methods);
        self::assertSame(['cors'], $m->route->getMiddleware());
    }

    public function testCachedDispatcher(): void
    {
        $cache = sys_get_temp_dir() . '/xar-routes-' . bin2hex(random_bytes(4)) . '.php';
        $r = new RouteCollector();
        $r->get('/x', 'X', 'x');
        self::assertSame('x', (new Router($r->routes(), $cache))->match('GET', '/x')->route->name);
        self::assertFileExists($cache);
        self::assertSame('x', (new Router($r->routes(), $cache))->match('GET', '/x')->route->name);
        unlink($cache);
    }
}
