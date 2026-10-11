<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Assets;

use InvalidArgumentException;
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;

final class AssetUrlsTest extends AppTestCase
{
    public function testJoinsTheBaseUrlAndThePath(): void
    {
        self::assertSame('/assets/module/blog/blog.css', (new AssetUrls('/assets'))->url('module/blog/blog.css'));
        self::assertSame('https://cdn.test/a/theme/x.css', (new AssetUrls('https://cdn.test/a/'))->url('/theme/x.css'));
        self::assertSame('/x.css', (new AssetUrls(''))->url('x.css'));
    }

    public function testRejectsEmptyAndParentPaths(): void
    {
        foreach (['', '/', '../x', 'a/../b', 'a/..'] as $path) {
            try {
                (new AssetUrls())->url($path);
                self::fail("'{$path}' must be rejected");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid asset path', $e->getMessage());
            }
        }
    }

    public function testTheBindingFollowsAssetsUrlAndTheHelperDelegatesToIt(): void
    {
        $app = $this->boot([], ['assets.url' => 'https://cdn.test/static']);
        self::assertSame(
            'https://cdn.test/static/module/blog/blog.css',
            $app->container()->get(AssetUrls::class)->url('module/blog/blog.css'),
        );
        self::assertSame(
            'https://cdn.test/static/theme/phoenix/phoenix.css',
            $app->container()->get(View::class)->helpers()->asset('theme/phoenix/phoenix.css'),
        );
    }
}
