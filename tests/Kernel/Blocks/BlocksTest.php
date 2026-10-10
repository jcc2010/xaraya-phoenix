<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Blocks;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Blocks\BlockInstance;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\ConnectionFactory;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Html;
use Xaraya\Tests\Support\ViewFixtures;

final class BlocksTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ViewFixtures::themes($this->tmp . '/themes');
        ViewFixtures::shop($this->tmp . '/modules');
        $this->app()->container()->get(ModuleRegistry::class)->enable('shop');
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'fancy', ...$overrides]);
    }

    private function page(string $path): string
    {
        return Html::normalize((string) $this->app()->handle(new ServerRequest('GET', $path))->getBody());
    }

    private function blocks(): BlockRepository
    {
        return $this->app()->container()->get(BlockRepository::class);
    }

    public function testModuleBlockDefaultsAreSeededOnFirstInstallOnly(): void
    {
        $db = $this->app()->container()->get(Connection::class);
        self::assertSame(1, $db->select('blocks')->count());
        $registry = $this->app()->container()->get(ModuleRegistry::class);
        $registry->disable('shop');
        $registry->enable('shop');
        self::assertSame(1, $db->select('blocks')->count());
    }

    public function testVisibleBlocksRenderThroughTheThemeWrapper(): void
    {
        self::assertStringContainsString(
            '<aside><section class="block block-shop-note" data-region="sidebar"><h2>Note</h2><p>note:hi:shop.item:7</p></section></aside>',
            $this->page('/shop/7'),
        );
        self::assertStringContainsString('<aside></aside>', $this->page('/shop'), 'the seeded block is limited to shop.item');
    }

    public function testRoleVisibilityUsesTheGuestRoles(): void
    {
        $this->blocks()->create('shop.note', 'sidebar', null, ['text' => 'members'], ['roles' => ['Members']]);
        $this->blocks()->create('shop.note', 'sidebar', null, ['text' => 'anyone'], ['roles' => ['Anonymous']]);
        $html = $this->page('/shop');
        self::assertStringNotContainsString('note:members', $html);
        self::assertStringContainsString('note:anyone:shop.list:-', $html);
    }

    public function testSortOrderDisabledBlocksAndUnknownTypes(): void
    {
        $repo = $this->blocks();
        $repo->create('shop.note', 'sidebar', null, ['text' => 'second'], [], 2);
        $first = $repo->create('shop.note', 'sidebar', null, ['text' => 'first'], [], 1);
        $hidden = $repo->create('shop.note', 'sidebar', null, ['text' => 'hidden']);
        $repo->setEnabled($hidden, false);
        $repo->create('gone.type', 'sidebar', 'Ghost');
        $html = $this->page('/shop');
        self::assertMatchesRegularExpression('/note:first.*note:second/', $html);
        self::assertStringNotContainsString('note:hidden', $html);
        self::assertStringNotContainsString('Ghost', $html);
        self::assertSame(['text' => 'first'], $repo->find($first)?->config);
    }

    public function testFailingBlockIsLoggedAndSkippedOutsideDebug(): void
    {
        $this->blocks()->create('shop.broken', 'sidebar', 'Broken');
        $this->blocks()->create('shop.note', 'sidebar', null, ['text' => 'ok']);
        $response = $this->app(['app.debug' => false])->handle(new ServerRequest('GET', '/shop'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('note:ok', (string) $response->getBody());
        $log = implode('', array_map('file_get_contents', glob($this->tmp . '/logs/*') ?: []));
        self::assertStringContainsString('shop block exploded', $log);

        self::assertSame(500, $this->app()->handle(new ServerRequest('GET', '/shop'))->getStatusCode(), 'debug mode surfaces the failure');
    }

    public function testFallbackWrapperWithoutABlockTemplate(): void
    {
        $app = $this->app(['app.theme' => 'plain']);
        $request = RouteHandler::attach(new ServerRequest('GET', '/shop/7'), $app->container()->get(Router::class)->match('GET', '/shop/7'));
        self::assertSame(
            "<section class=\"block block-shop-note\"><h2 class=\"block-title\">Note</h2><p>note:hi:shop.item:7</p></section>\n",
            $app->container()->get(View::class)->helpers($request)->blocks('sidebar'),
        );
        self::assertSame('', $app->container()->get(View::class)->helpers($request)->blocks('footer'));
    }

    public function testRepositoryWithoutTheTableIsEmpty(): void
    {
        self::assertSame([], (new BlockRepository(ConnectionFactory::make(['dsn' => 'sqlite::memory:'])))->forRegion('sidebar'));
    }

    public function testRoutePatterns(): void
    {
        $block = new BlockInstance(1, 'text', 'sidebar', null, [], 0, ['routes' => ['blog.*', 'hello.index']], true);
        self::assertTrue($block->visibleFor('blog.home', ['Anonymous']));
        self::assertTrue($block->visibleFor('hello.index', []));
        self::assertFalse($block->visibleFor('hello.show', []));
        self::assertFalse($block->visibleFor('blogs.home', []));
        self::assertFalse($block->visibleFor(null, []), 'error pages have no route');
        self::assertTrue((new BlockInstance(2, 'text', 'sidebar', null, [], 0, [], true))->visibleFor(null, []));
    }

    public function testMalformedVisibilityIsRejectedAtCreate(): void
    {
        foreach ([['roles' => 'Admin'], ['routes' => [1]], ['roles' => ['']], ['routes' => ['a' => 'b']]] as $bad) {
            try {
                /** @phpstan-ignore argument.type */
                $this->blocks()->create('shop.note', 'sidebar', null, [], $bad);
                self::fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCorruptVisibilityRowsFailClosed(): void
    {
        $db = $this->app()->container()->get(Connection::class);
        foreach (['{"roles":"Admin"}', '"x"', '{"routes":[1]}'] as $i => $json) {
            $db->insert('blocks', ['type' => 'shop.note', 'region' => 'sidebar', 'title' => null, 'config' => ['text' => 'corrupt' . $i], 'sort' => 0, 'visibility' => $json, 'enabled' => true]);
        }
        $html = $this->page('/shop');
        self::assertStringNotContainsString('note:corrupt', $html);
        // Undecodable JSON cannot be stored in a MySQL JSON column, so check the row mapping directly.
        $row = ['id' => 1, 'type' => 'text', 'region' => 'sidebar', 'title' => null, 'config' => '{}', 'sort' => 0, 'visibility' => 'not json', 'enabled' => 1];
        self::assertFalse(BlockInstance::fromRow($row)->enabled);
    }

    public function testBlockOfADisabledModuleDoesNotRender(): void
    {
        $this->blocks()->create('shop.note', 'sidebar', null, ['text' => 'orphan']);
        $this->app()->container()->get(ModuleRegistry::class)->disable('shop');
        self::assertStringNotContainsString('note:orphan', $this->page('/'));
    }

    public function testEnableFailsOnBadBlockDefaultsAndStaysDisabled(): void
    {
        $this->app()->container()->get(ModuleRegistry::class)->disable('shop');
        $file = $this->tmp . '/modules/shop/module.json';
        $json = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($json);
        $json['blockDefaults'] = [['type' => 'shop.note', 'region' => 'Bad Region']];
        file_put_contents($file, json_encode($json));
        $registry = $this->app()->container()->get(ModuleRegistry::class);
        try {
            $registry->enable('shop');
            self::fail('expected ModuleException');
        } catch (\Xaraya\Kernel\Module\ModuleException) {
            self::addToAssertionCount(1);
        }
        self::assertFalse($registry->isEnabled('shop'));
    }

    public function testCorruptBlocksMapOfAnEnabledModuleIsLoggedNotFatal(): void
    {
        $this->blocks()->create('text', 'sidebar', null, []);
        $file = $this->tmp . '/modules/shop/module.json';
        $json = json_decode((string) file_get_contents($file), true);
        self::assertIsArray($json);
        $json['blocks'] = ['other.note' => 'X'];
        file_put_contents($file, json_encode($json));
        $response = $this->app(['app.debug' => false])->handle(new ServerRequest('GET', '/shop'));
        self::assertSame(200, $response->getStatusCode());
        $log = implode('', array_map('file_get_contents', glob($this->tmp . '/logs/*') ?: []));
        self::assertStringContainsString("Ignoring the blocks of module 'shop'", $log);
    }

    public function testSeedIsAtomic(): void
    {
        $defaults = [
            ['type' => 'text', 'region' => 'sidebar', 'title' => null, 'config' => [], 'visibility' => [], 'sort' => 0],
            ['type' => 'text', 'region' => 'Bad Region', 'title' => null, 'config' => [], 'visibility' => [], 'sort' => 0],
        ];
        $before = $this->app()->container()->get(Connection::class)->select('blocks')->count();
        try {
            $this->blocks()->seedDefaults($defaults);
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($before, $this->app()->container()->get(Connection::class)->select('blocks')->count());
    }
}
