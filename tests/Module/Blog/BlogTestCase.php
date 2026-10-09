<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Tests\Support\AppTestCase;

abstract class BlogTestCase extends AppTestCase
{
    /** @param array<string, mixed> $overrides */
    protected function app(array $overrides = []): App
    {
        return $this->boot([dirname(__DIR__, 3) . '/modules'], [
            'blog.locks' => $this->tmp . '/locks',
            'blog.uploads' => $this->tmp . '/uploads',
            ...$overrides,
        ]);
    }

    protected function enableBlog(): App
    {
        [$code, $out, $err] = $this->xar(['module:enable', 'blog']);
        self::assertSame(0, $code, $out . $err);

        return $this->app();
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    protected function xar(array $args, ?App $app = null): array
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        $err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        $code = (new Application($app ?? $this->app()))->run(['xar', ...$args], new Output($out, $err));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    protected function db(App $app): Connection
    {
        return $app->container()->get(Connection::class);
    }

    protected function blogs(App $app): BlogRepository
    {
        return $app->container()->get(BlogRepository::class);
    }

    protected function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
