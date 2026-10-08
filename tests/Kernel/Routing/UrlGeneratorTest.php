<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Routing;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\UrlGenerator;

final class UrlGeneratorTest extends TestCase
{
    private function urls(): UrlGenerator
    {
        $r = new RouteCollector();
        $r->get('/blog/{handle}/feed.json', 'F', 'blog.feed');
        $r->get('/s/{id:[0-9a-z]{26}}', 'P', 'post.show');
        $r->get('/tag/{slug}', 'T', 'tag');

        return new UrlGenerator($r->routes(), 'https://example.test/');
    }

    public function testGeneratesPathsQueryAndAbsolute(): void
    {
        $u = $this->urls();
        self::assertSame('/blog/wyome/feed.json?before=abc', $u->generate('blog.feed', ['handle' => 'wyome', 'before' => 'abc']));
        self::assertSame('https://example.test/s/01m3t19wn8reaww81zg1m47tjq', $u->generate('post.show', ['id' => '01m3t19wn8reaww81zg1m47tjq'], true));
        self::assertSame('/tag/rock%20%26%20roll', $u->generate('tag', ['slug' => 'rock & roll']));
        self::assertTrue($u->has('tag'));
        self::assertFalse($u->has('nope'));
    }

    public function testMissingParamThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->urls()->generate('blog.feed');
    }

    public function testUnknownRouteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->urls()->generate('nope');
    }

    public function testDuplicateNamesThrow(): void
    {
        $r = new RouteCollector();
        $r->get('/a', 'A', 'same');
        $r->get('/b', 'B', 'same');
        $this->expectException(LogicException::class);
        new UrlGenerator($r->routes(), '');
    }
}
