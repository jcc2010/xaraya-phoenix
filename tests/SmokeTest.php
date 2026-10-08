<?php

declare(strict_types=1);

namespace Xaraya\Tests;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testRuntimeDependenciesAutoload(): void
    {
        self::assertGreaterThanOrEqual(80300, PHP_VERSION_ID);
        self::assertTrue(class_exists(Response::class));
        self::assertTrue(class_exists(\FastRoute\RouteCollector::class));
    }
}
