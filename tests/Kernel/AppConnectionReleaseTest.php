<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use WeakReference;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Tests\Support\AppTestCase;

final class AppConnectionReleaseTest extends AppTestCase
{
    public function testConnectionIsReleasedWhenAppIsDropped(): void
    {
        $app = $this->boot();
        $ref = WeakReference::create($app->container()->get(Connection::class));
        self::assertNotNull($ref->get());

        unset($app);
        gc_collect_cycles();

        self::assertNull($ref->get(), 'Connection should be freed once the App is unreferenced');
    }
}
