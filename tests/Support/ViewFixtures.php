<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

/**
 * Fixture themes ("plain", "fancy") and a "shop" module for view tests. Every template exists as .php and .twig
 * with the same output, except where a test needs one engine only (mixed.twig, phponly.php, boom.php).
 */
final class ViewFixtures
{
    public static function themes(string $base): void
    {
        Fixtures::theme($base, 'plain', [
            'engine' => 'php',
            'regions' => ['header', 'sidebar', 'footer'],
            'settings' => ['tagline' => ['type' => 'string', 'default' => 'Hi']],
        ], [
            'templates/layout.php' => <<<'PHP'
                <!doctype html>
                <html lang="<?= $x->e($lang) ?>">
                <head><title><?= $x->e($title) ?></title>
                <?php foreach ($meta as $name => $value): ?>
                <meta <?= str_starts_with((string) $name, 'og:') ? 'property' : 'name' ?>="<?= $x->e($name) ?>" content="<?= $x->e($value) ?>">
                <?php endforeach ?>
                </head>
                <body data-tagline="<?= $x->e($settings['tagline']) ?>">
                <?= $content ?>
                </body>
                </html>
                PHP,
            'templates/layout.twig' => <<<'TWIG'
                <!doctype html>
                <html lang="{{ lang }}">
                <head><title>{{ title }}</title>
                {% for name, value in meta %}
                <meta {{ name starts with 'og:' ? 'property' : 'name' }}="{{ name }}" content="{{ value }}">
                {% endfor %}
                </head>
                <body data-tagline="{{ settings.tagline }}">
                {{ content|raw }}
                </body>
                </html>
                TWIG,
            'templates/hello.php' => '<p><?= $x->e($name) ?></p>',
            'templates/hello.twig' => '<p>{{ name }}</p>',
            'templates/helpers.php' => '<a href="<?= $x->e($x->url(\'shop.item\', [\'id\' => 7])) ?>"><?= $x->e($x->t(\'Item {id}\', [\'id\' => 7])) ?></a>'
                . '|<?= $x->e($x->asset(\'theme/plain/site.css\')) ?>|<?= $x->can(\'read\') ? \'r\' : \'\' ?><?= $x->can(\'edit\') ? \'e\' : \'\' ?>'
                . '|<?= $x->e($x->config(\'app.url\')) ?>|<?= $x->user() === null ? \'guest\' : \'user\' ?>|<?= $x->csrf() ?>|<?= $x->e(\'<b>\') ?>|<?= $x->e($x->t(\'<i>\')) ?>',
            'templates/helpers.twig' => '<a href="{{ url(\'shop.item\', {id: 7}) }}">{{ t(\'Item {id}\', {id: 7}) }}</a>'
                . '|{{ asset(\'theme/plain/site.css\') }}|{{ can(\'read\') ? \'r\' : \'\' }}{{ can(\'edit\') ? \'e\' : \'\' }}'
                . '|{{ config(\'app.url\') }}|{{ user() is null ? \'guest\' : \'user\' }}|{{ csrf() }}|{{ e(\'<b>\') }}|{{ t(\'<i>\') }}',
            'templates/page.php' => '<div><?= $x->include(\'part\', [\'v\' => $v]) ?></div>',
            'templates/page.twig' => '<div>{% include \'part\' with {v: v} only %}</div>',
            'templates/part.php' => '<b><?= $x->e($v) ?></b>',
            'templates/part.twig' => '<b>{{ v }}</b>',
            'templates/mixed.twig' => '<i>{{ render(\'phponly\', {v: v}) }}</i>',
            'templates/phponly.php' => '<u><?= $x->e($v) ?></u>',
            'templates/boom.php' => '<p>before<?php throw new \RuntimeException(\'boom\'); ?></p>',
            'templates/error/404.php' => '<h1>Missing: <?= $x->e($reason) ?></h1>',
            'templates/error/404.twig' => '<h1>Missing: {{ reason }}</h1>',
            'templates/error/default.php' => '<h1><?= $x->e($status) ?> <?= $x->e($reason) ?></h1><p><?= $x->e($message) ?></p>',
            'templates/error/default.twig' => '<h1>{{ status }} {{ reason }}</h1><p>{{ message }}</p>',
        ]);
        Fixtures::theme($base, 'fancy', ['parent' => 'plain', 'engine' => 'twig'], [
            'templates/layout.php' => '<!doctype html><html><body><main><?= $content ?></main><aside><?= $x->blocks(\'sidebar\') ?></aside></body></html>',
            'templates/layout.twig' => '<!doctype html><html><body><main>{{ content|raw }}</main><aside>{{ blocks(\'sidebar\') }}</aside></body></html>',
            'templates/block.php' => '<section class="block <?= $x->e($typeClass) ?>" data-region="<?= $x->e($region) ?>"><?php if ($title): ?><h2><?= $x->e($title) ?></h2><?php endif ?><?= $content ?></section>',
            'templates/block.twig' => '<section class="block {{ typeClass }}" data-region="{{ region }}">{% if title %}<h2>{{ title }}</h2>{% endif %}{{ content|raw }}</section>',
        ]);
    }

    public static function shop(string $base): void
    {
        Fixtures::module($base, 'shop', [
            'routes' => 'Xaraya\\Module\\Shop\\Routes',
            'blocks' => ['shop.note' => 'Xaraya\\Module\\Shop\\ShopNote', 'shop.broken' => 'Xaraya\\Module\\Shop\\ShopBroken'],
            'blockDefaults' => [
                ['type' => 'shop.note', 'region' => 'sidebar', 'title' => 'Note', 'config' => ['text' => 'hi'], 'visibility' => ['routes' => ['shop.item']]],
            ],
        ], [
            'src/Routes.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Xaraya\Module\Shop;

                use Xaraya\Kernel\Routing\RouteCollector;
                use Xaraya\Kernel\Routing\RouteProvider;

                final class Routes implements RouteProvider
                {
                    public function routes(RouteCollector $routes): void
                    {
                        $routes->get('/shop', [ShopController::class, 'index'], 'shop.list');
                        $routes->get('/shop/boom', [ShopController::class, 'boom'], 'shop.boom');
                        $routes->get('/shop/{id:\d+}', [ShopController::class, 'item'], 'shop.item');
                        $routes->get('/shop/{id:\d+}/gone', [ShopController::class, 'gone'], 'shop.gone');
                    }
                }
                PHP,
            'src/ShopController.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Xaraya\Module\Shop;

                use Psr\Http\Message\ServerRequestInterface;
                use Xaraya\Kernel\Http\Controller;
                use Xaraya\Kernel\View\Page;

                final class ShopController extends Controller
                {
                    /** @param array<string, string> $params */
                    public function index(ServerRequestInterface $request, array $params): Page
                    {
                        return $this->view('shop::list', ['ids' => ['1', '2']], 'Shop');
                    }

                    /** @param array<string, string> $params */
                    public function item(ServerRequestInterface $request, array $params): Page
                    {
                        return new Page(
                            'shop::item',
                            ['id' => $params['id']],
                            title: 'Item',
                            meta: ['description' => 'An item', 'og:title' => 'Item ' . $params['id']],
                            headers: ['X-Shop' => '1'],
                        );
                    }

                    /** @param array<string, string> $params */
                    public function gone(ServerRequestInterface $request, array $params): Page
                    {
                        return $this->view('shop::item', ['id' => $params['id']], 'Gone', 410);
                    }

                    /** @param array<string, string> $params */
                    public function boom(ServerRequestInterface $request, array $params): never
                    {
                        throw new \RuntimeException('shop exploded');
                    }
                }
                PHP,
            'src/ShopNote.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Xaraya\Module\Shop;

                use Xaraya\Kernel\Blocks\Block;
                use Xaraya\Kernel\Blocks\BlockContext;

                final class ShopNote implements Block
                {
                    public function render(array $config, BlockContext $context): string
                    {
                        $text = is_string($config['text'] ?? null) ? $config['text'] : '';

                        return '<p>note:' . $context->helpers->e($text) . ':' . ($context->routeName ?? '-') . ':' . ($context->params['id'] ?? '-') . '</p>';
                    }
                }
                PHP,
            'src/ShopBroken.php' => <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Xaraya\Module\Shop;

                use Xaraya\Kernel\Blocks\Block;
                use Xaraya\Kernel\Blocks\BlockContext;

                final class ShopBroken implements Block
                {
                    public function render(array $config, BlockContext $context): string
                    {
                        throw new \RuntimeException('shop block exploded');
                    }
                }
                PHP,
            'templates/item.php' => '<p>Item <?= $x->e($id) ?></p>',
            'templates/item.twig' => '<p>Item {{ id }}</p>',
            'templates/list.php' => '<ul><?php foreach ($ids as $id): ?><li><?= $x->e($id) ?></li><?php endforeach ?></ul>',
            'templates/list.twig' => '<ul>{% for id in ids %}<li>{{ id }}</li>{% endfor %}</ul>',
        ]);
    }
}
