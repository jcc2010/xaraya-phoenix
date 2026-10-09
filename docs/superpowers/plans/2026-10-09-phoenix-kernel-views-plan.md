# Phoenix Kernel Plan 2: Views, Themes, Blocks and Display Hooks

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the kernel a view layer: PHP and Twig templates resolved through themes, a layout, blocks in theme regions, display hooks bound in `xar_hooks`, translation, a settings store, asset publishing, a file cache, themed error pages, the `secureHtml` middleware and the neutral `phoenix` theme. When it's done, `examples/hello` renders identical HTML through both engines, shows a sidebar block that follows its visibility rule, and shows a display-hook fragment from `examples/hello-hooks` that disappears when the binding is disabled.

**Architecture:**

- **Lookup:** `ThemeRegistry` reads `theme.json` files from `themes.paths`. `TemplateLocator` turns a template name into a file:
  - `module::path` names a module template. It is looked up in each theme of the active chain under `templates/modules/<module>/`, then in `modules/<module>/templates/`.
  - A plain `path` names a theme-level template (`layout`, `block`, `home`, `error/404`). It is looked up in each theme of the chain under `templates/`.
- **Engines:** the file extension picks the engine (`PhpEngine`, `TwigEngine`). `View` renders a body, then wraps it in the theme's `layout`. Both engines get the same `Helpers` object: `$x->helper()` in PHP, and functions of the same names in Twig.
- **Controllers:** a controller returns a `Page` value object. `RouteHandler` renders it with `View`, so controllers need no view dependency.
- **Blocks:** `BlockRenderer` renders the enabled, visible instances of a region from `xar_blocks`. Built-in types are `text`, `menu` and `recent-items`, and modules add types through their manifest.
- **Display hooks:** `DisplayHooks` asks each observer module bound in `xar_hooks` for an HTML fragment.
- **Errors:** `ErrorHandler`'s existing `$renderHtml` closure now renders the theme's `error/{status}` template (or `error/default`) inside the layout.
- **Auth stubs:** users and privileges arrive in Plan 3. Until then `GuestAccess` answers `can()` and `user()`, and `csrf()` returns `''`.

**Tech stack:** PHP ≥ 8.3, the Phoenix kernel (Plan 1), PHPUnit 11, PHPStan level 8. Twig 3 is optional: it stays in `require-dev` and `suggest`. There are no new Composer packages and no JavaScript.

**Specs:**
- `docs/superpowers/specs/2026-10-08-xaraya-phoenix-kernel-design.md`: §5 (all of it), plus §3.4 display hooks and bindings, `xar_settings` (§5.4), `asset:publish` (§5.4, §6.1) and `hook:enable|disable` (§6.1).
- `docs/superpowers/specs/2026-10-09-phoenix-blog-pages-design.md`: §3.3 (the file cache), §3.4 (`secureHtml`) and §7 item 1 (the renderHtml error-page hook).

**Deviations from the specs, decided while planning:**

1. **Template names.** Module templates are named `module::path` and theme-level templates plain `path`. With `/` alone, `error/404` and a module called `error` could not be told apart.
   - The blog pages plan must therefore write `blog::home` and `blog::partials/kinds/link`, not `blog/home`.
   - A theme still overrides them at `themes/<t>/templates/modules/blog/partials/kinds/link.*`.
2. **`Page` instead of `Controller::view()` returning HTML.** `Controller::view()` returns a `Page`, and `RouteHandler` renders it. The spec's `view()` helper exists, but the base class stays free of constructor dependencies.
3. **Manifest `blocks` is a map.** It maps `"<module>.<name>"` type ids to classes, where the spec's example shows a list. The blog spec's ids (`blog.info`, `blog.tagcloud`) need stable names, which a list of classes does not give.
4. **New manifest key `blockDefaults`.** Its block instances are created the first time a module is enabled. The blog spec's seeded sidebar blocks need it, and so does the hello sidebar block. Re-enabling a module never re-seeds.
5. **Error templates.** `error/{status}` falls back to the theme's `error/default`, then to the built-in page. In debug mode, 5xx errors always use the built-in page because it shows the stack trace.
6. **Engine choice.**
   - **`view.engine` override:** a new config key, `view.engine` (env `VIEW_ENGINE`), overrides the theme's `engine` preference. The parity tests use it, and so can operators.
   - **`phoenix` preference:** `phoenix` prefers `php`, because Twig is a dev-only dependency and a `--no-dev` install has no Twig.
   - **Ties:** when Twig is missing, a `.php` sibling wins a tie. A lone `.twig` file throws a clear `ViewException`.
7. **Kernel `home` route.** The kernel registers `GET /` (named `home`) rendering the theme's `home` template, unless a module route already claims `GET /` or the name `home`. Kernel definition-of-done item 1 needs a home page.
8. **Assets.** Assets publish to `public/assets/module/<name>/` and `public/assets/theme/<name>/`. Templates call `asset('theme/phoenix/phoenix.css')`. The base URL is `assets.url` (default `/assets`).
9. **Twig helper arguments.** Twig helpers take positional arguments only. They are registered generically from `Helpers::TWIG`.
10. **Text block HTML is trusted.** Admins write `text` blocks with `format: "html"`, so that HTML is output as-is. Markdown blocks use a tiny safe subset (`MiniMarkdown`) that escapes everything first.

## Global Constraints

- **File headers:** every PHP class file starts with `<?php`, a blank line, then `declare(strict_types=1);`. PHP *templates* (`*/templates/*.php`) are exempt. They hold HTML with `<?= ?>` tags, and PHPStan and php-cs-fixer skip them (Task 7).
- **Namespaces:**
  - Kernel classes are `Xaraya\Kernel\…` under `src/Kernel/`.
  - Tests are `Xaraya\Tests\Kernel\…` under `tests/Kernel/`, with shared helpers in `Xaraya\Tests\Support\…`.
  - The example modules are `Xaraya\Module\Hello\…` and `Xaraya\Module\HelloHooks\…`.
- **Database:**
  - Kernel tables are created by migrations in `src/Kernel/migrations/`. They run on `xar migrate` and on every `module:enable`.
  - Use `Connection`/`Schema`/`Select` with the prefix, and no raw table names.
  - Identifiers match `/^[A-Za-z_][A-Za-z0-9_]*$/D`.
  - JSON columns are written as PHP arrays (`Connection::normalize` encodes them) or as pre-encoded JSON strings, and read back with `json_decode(…, true)`.
- **Template names:**
  - `module::path` or `path`, where every path segment matches `[A-Za-z0-9_-]+`.
  - Anything else (`..`, dots, empty segments) throws `ViewException`.
- **Escaping:**
  - Twig auto-escapes HTML.
  - PHP templates never auto-escape: every value goes through `$x->e()`, which uses `htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE` and `UTF-8`, the same flags as Twig, so both engines produce the same bytes.
  - Output that is already HTML is printed raw: `content`, and the results of `blocks()`, `hooks()`, `render()` and `csrf()`. In Twig use `|raw` when it went through a variable.
- **Engine parity:**
  - Every template in `themes/phoenix` and `examples/hello/templates` exists as both `.twig` and `.php`.
  - Parity tests compare the output after `Xaraya\Tests\Support\Html::normalize()`, which collapses whitespace runs to one space, drops whitespace next to tags, and trims.
  - In Twig, `include` stays in Twig and is written `{% include 'x' with {…} only %}`. In PHP, `$x->include('x', […])` stays in PHP. `render()` crosses engines.
- **`phoenix` theme:**
  - No JavaScript, no inline `style=` attributes (the CSP has `style-src 'self'`) and no third-party URLs.
  - Landmarks (`header`, `main`, `aside`, `footer`) and a skip link.
  - Visible `:focus-visible` outlines, `prefers-color-scheme` and `prefers-reduced-motion`.
- **Dependencies:** no new Composer package. `twig/twig` stays in `require-dev` and `suggest`, and the kernel must work without it as long as only `.php` templates are rendered.
- **Auth stubs:**
  - `can()` returns true only for levels ≤ `read` (none, overview, read).
  - `user()` returns null, `roles()` returns `['Anonymous']`, and `csrf()` returns `''`.
  - Plan 3 replaces the `Access` binding and `Helpers::csrf()`. Do not build users, sessions or CSRF here.
- **Test classes:** `AppTestCase::tearDown()` unsets every subclass property, so test properties must never be `readonly`.
- **Size budget:** `src/Kernel` stays under about 6,000 lines. It is about 4,000 now, and this plan adds about 1,600.
- **Commits:** every commit message ends with a blank line, then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, exactly, passed as a second `-m`.
- **Checks:** `composer test`, `composer stan` and `composer cs` must pass before each commit.
  - Database tests run on SQLite by default.
  - Also run them on MySQL: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root`, with local root and no password.
  - Postgres runs in CI.
- **Kernel APIs this plan relies on, all existing (read the files before changing them):**
  - **`App`:**
    - `App::boot(string $root, array $overrides = [], bool $safe = false, bool $useConfigCache = true)`, `->container()`, `->config()`, `->path()`, `->debug()`, `->routes()`, `->handle()` and `->clearCache()`.
    - The constructor binds services, then calls `bootModules()`.
    - `handle()` runs `ErrorHandler` outermost around a lazy closure that resolves global middleware, matches the route (`RouteHandler::attach`), then runs the route pipeline.
  - **Container:** `Container::get/set/instance/make/has`. `get()` autowires and shares any instantiable class.
  - **Config:** `Config::get/set` with dotted keys. `AppTestCase` overrides are applied with `set()`.
  - **Modules:**
    - `ModuleRegistry(Connection, Migrator, list<string> $paths, string $kernelMigrations)` with `discover()`, `get()`, `enabled()`, `isEnabled()`, `enable()`, `disable()`, `migrationPaths()` and `registerAutoloader()`.
    - `Manifest::fromDirectory()`, `->name`, `->path`, `->get(key, default)`, `->routes()`, `->subscribers()` and `->commands()`.
  - **HTTP:**
    - `MiddlewareRegistry::register(string $alias, string|Closure $factory)`, `registerMarker()`, `resolve()` and `stack()`.
    - `RouteHandler::attach(ServerRequestInterface, RouteMatch)`, and `RouteMatch(Route $route, array $params)` with `Route->name` and `->path`.
    - `ErrorHandler(LoggerInterface $logger, bool $debug = false, ?Closure $renderHtml = null)`, where `$renderHtml` is `Closure(int $status, string $message, ServerRequestInterface): ?string`. It falls back to the built-in page when the closure returns null or throws.
    - `HttpException(int $status, string $message = '', array $headers = [], ?Throwable $previous = null)` with `status()`, `headers()` and static `reason()`, plus `NotFound` and `MethodNotAllowed`.
    - `Controller` (`json`, `html`, `redirect`, `notFound`, static `htmlResponse`/`jsonResponse`).
  - **Routing:** `Router::match(method, path)` and `UrlGenerator::generate(name, params, absolute)` / `has(name)`.
  - **Events:** `EventDispatcher::listen/dispatch`, and `Event::stopPropagation()`.
  - **Database:** `Connection` (`insert`, `upsert`, `update`, `delete`, `select`, `hasTable`, `pdo`, `fetchValue`), `Select` (`columns`, `where`, `whereIn`, `orderBy`, `all`, `first`, `count`), `Schema::create` with `Blueprint` (`increments`, `string`, `int`, `bool`, `json`, `primary`, `index`), and `Migrator::migrate(array<string, string> $paths)`.
  - **CLI:** `Command`, `Input`, `Output`, and `Cli\Application` with its `KERNEL_COMMANDS` list.
  - **Test support:**
    - `AppTestCase`: `$this->tmp`, `boot(list<string> $modulePaths = [], array $overrides = [])`, and `rmdir()`. Its overrides set `app.debug=true`, `app.url=http://xar.test`, `app.cache=$tmp/cache`, the test database and `log.path=$tmp/logs`.
    - `DbTestCase`: `$this->db`, `connect()` and `dbConfig()`.

## File Map

```
config/app.php                                     + themes.paths, view.engine, assets.path, assets.url
.env.example, .gitignore, README.md, phpstan.neon.dist, .php-cs-fixer.dist.php
src/Kernel/App.php                                 bindings, kernel home route, cache clearing
src/Kernel/Cache/Cache.php                         file cache: get/set/has/remember/forget/clear, removeTree
src/Kernel/Settings/Settings.php                   xar_settings key-value store
src/Kernel/migrations/2026_10_09_000001_create_settings.php
src/Kernel/migrations/2026_10_09_000002_create_hooks.php
src/Kernel/migrations/2026_10_09_000003_create_blocks.php
src/Kernel/View/ViewException.php
src/Kernel/View/Theme.php                          theme.json value object
src/Kernel/View/ThemeRegistry.php                  discovery, active chain, regions, setting defaults
src/Kernel/View/Translator.php                     lang/<locale>.php catalogs, t()
src/Kernel/View/TemplateLocator.php                name -> file, lookup order, engine tie-break
src/Kernel/View/Engine.php, PhpEngine.php, TwigEngine.php, TwigLoader.php
src/Kernel/View/Helpers.php                        url asset can hooks blocks csrf t e user render include config
src/Kernel/View/View.php                           render, renderWith, page (layout), themeSettings
src/Kernel/View/Page.php                           what controllers return
src/Kernel/View/ErrorPages.php                     error/{status} rendering for ErrorHandler
src/Kernel/Auth/Access.php, GuestAccess.php        can/user/roles stub until Plan 3
src/Kernel/Hooks/DisplayHook.php, DisplayHooks.php, HookBindings.php
src/Kernel/Blocks/Block.php, ConfigurableBlock.php, BlockContext.php, BlockInstance.php
src/Kernel/Blocks/BlockRepository.php, BlockTypes.php, BlockRenderer.php
src/Kernel/Blocks/TextBlock.php, MenuBlock.php, RecentItemsBlock.php
src/Kernel/Support/MiniMarkdown.php
src/Kernel/Events/RecentItem.php, RecentItemsQuery.php
src/Kernel/Http/Middleware/SecureHtml.php
src/Kernel/Http/HomeController.php
src/Kernel/Http/Controller.php, RouteHandler.php, Middleware/ErrorHandler.php, Exception/HttpException.php  (modified)
src/Kernel/Module/Manifest.php, ModuleRegistry.php (modified)
src/Kernel/Assets/AssetPublisher.php
src/Kernel/Cli/Application.php (modified), Commands/CacheClearCommand.php (modified)
src/Kernel/Cli/Commands/HookCommand.php, HookEnableCommand.php, HookDisableCommand.php, AssetPublishCommand.php
themes/phoenix/theme.json
themes/phoenix/templates/{layout,home,block,error/404,error/default}.{php,twig}
themes/phoenix/assets/phoenix.css
examples/hello/module.json, src/{HelloController,Routes,GreetingCountBlock}.php
examples/hello/templates/{index,show,partials/greeting}.{php,twig}, lang/fr.php
examples/hello-hooks/module.json, src/NoteHook.php
tests/Support/AppTestCase.php (modified), Fixtures.php, ViewFixtures.php, Html.php
tests/Kernel/...Test.php                           one per unit, listed per task
```

---

### Task 1: File cache and `cache:clear`

**Files:**
- Create: `src/Kernel/Cache/Cache.php`
- Modify: `src/Kernel/App.php` (bind `Cache`, extend `clearCache()`), `src/Kernel/Cli/Commands/CacheClearCommand.php` (description)
- Test: `tests/Kernel/Cache/CacheTest.php`, `tests/Kernel/AppTest.php` (one new test)

**Interfaces:**
- **Consumes:** `App::cacheDir()` (private, `app.cache`, default `var/cache`).
- **Produces `Xaraya\Kernel\Cache\Cache(string $dir)`:**
  - `get(string $key, mixed $default = null): mixed`
  - `has(string $key): bool`
  - `set(string $key, mixed $value): void`. Values are scalars, null, or arrays of them. Anything else throws `InvalidArgumentException`.
  - `remember(string $key, callable $make): mixed`
  - `forget(string $key): void`
  - `clear(): int`, returning the files deleted
  - `static removeTree(string $path): int`, which deletes a file, a link or a tree, never following links
- **Produces the container binding:** `Cache::class` → `new Cache({app.cache}/data)`.
- **Produces `App::clearCache()`:** it also empties `{app.cache}/data` and `{app.cache}/twig`, where Task 7 puts compiled Twig templates.
- **Key rule:** any string is a valid key. Entries never expire, so callers version their keys. The blog's `sanitized/{post_id}-{updated_at}-{field}` is the model.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Cache/CacheTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Cache;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Cache\Cache;

final class CacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/xar-cache-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        Cache::removeTree($this->dir);
        Cache::removeTree($this->dir . '-target');
    }

    public function testMissReturnsTheDefault(): void
    {
        $cache = new Cache($this->dir);
        self::assertNull($cache->get('nope'));
        self::assertSame('d', $cache->get('nope', 'd'));
        self::assertFalse($cache->has('nope'));
    }

    public function testRoundTripsScalarsArraysNullAndFalse(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('sanitized/01jabc-2026-10-09 10:00:00-content', '<p>ok</p>');
        $cache->set('list', ['a' => [1, 2.5, true]]);
        $cache->set('null', null);
        $cache->set('false', false);

        $fresh = new Cache($this->dir);
        self::assertSame('<p>ok</p>', $fresh->get('sanitized/01jabc-2026-10-09 10:00:00-content'));
        self::assertSame(['a' => [1, 2.5, true]], $fresh->get('list'));
        self::assertTrue($fresh->has('null'));
        self::assertNull($fresh->get('null', 'default'));
        self::assertFalse($fresh->get('false', 'default'));
    }

    public function testForget(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('k', 'v');
        $cache->forget('k');
        $cache->forget('never-set');
        self::assertFalse($cache->has('k'));
    }

    public function testRememberComputesOnce(): void
    {
        $cache = new Cache($this->dir);
        $calls = 0;
        $make = function () use (&$calls): string {
            $calls++;

            return 'v';
        };
        self::assertSame('v', $cache->remember('k', $make));
        self::assertSame('v', $cache->remember('k', $make));
        self::assertSame(1, $calls);
    }

    public function testCorruptEntryIsAMiss(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('k', 'v');
        $files = glob($this->dir . '/*/*.cache') ?: [];
        self::assertCount(1, $files);
        file_put_contents($files[0], 'not serialized');
        self::assertSame('d', $cache->get('k', 'd'));
    }

    public function testObjectsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Cache($this->dir))->set('k', ['nested' => new \stdClass()]);
    }

    public function testClearRemovesEveryEntry(): void
    {
        $cache = new Cache($this->dir);
        $cache->set('a', 1);
        $cache->set('b', 2);
        self::assertSame(2, $cache->clear());
        self::assertFalse($cache->has('a'));
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testRemoveTreeDeletesLinksWithoutFollowingThem(): void
    {
        mkdir($this->dir . '-target');
        file_put_contents($this->dir . '-target/keep.txt', 'x');
        mkdir($this->dir);
        symlink($this->dir . '-target', $this->dir . '/link');
        self::assertSame(1, Cache::removeTree($this->dir));
        self::assertFileExists($this->dir . '-target/keep.txt');
    }
}
```

Add to `tests/Kernel/AppTest.php`, with `use Xaraya\Kernel\Cache\Cache;` among the imports:
```php
    public function testClearCacheAlsoEmptiesCacheEntriesAndCompiledTemplates(): void
    {
        $app = $this->boot();
        $cache = $app->container()->get(Cache::class);
        $cache->set('sanitized/abc', '<p>x</p>');
        mkdir($this->tmp . '/cache/twig/ab', 0775, true);
        file_put_contents($this->tmp . '/cache/twig/ab/compiled.php', '<?php');
        file_put_contents($this->tmp . '/cache/keep.txt', 'x');

        self::assertGreaterThanOrEqual(2, $app->clearCache());
        self::assertFalse($cache->has('sanitized/abc'));
        self::assertDirectoryDoesNotExist($this->tmp . '/cache/twig');
        self::assertFileExists($this->tmp . '/cache/keep.txt');
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Cache tests/Kernel/AppTest.php`
Expected: errors, `Class "Xaraya\Kernel\Cache\Cache" not found`.

- [ ] **Step 3: Implement**

`src/Kernel/Cache/Cache.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cache;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A small file cache. Each entry is one file under $dir, named by the sha1 of its key, so any string is a valid
 * key. Values are scalars, null or arrays of them; objects are refused because they cannot be read back safely.
 * Entries never expire: put a version (such as an updated_at) in the key. `xar cache:clear` empties $dir.
 */
final class Cache
{
    public function __construct(private readonly string $dir) {}

    public function get(string $key, mixed $default = null): mixed
    {
        [$hit, $value] = $this->read($key);

        return $hit ? $value : $default;
    }

    public function has(string $key): bool
    {
        return $this->read($key)[0];
    }

    public function set(string $key, mixed $value): void
    {
        self::assertStorable($value);
        $file = $this->file($key);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create cache directory '{$dir}'");
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        $content = serialize(['v' => $value]);
        if (@file_put_contents($tmp, $content) !== strlen($content) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write cache entry '{$key}'");
        }
    }

    /**
     * @template T
     * @param callable(): T $make
     * @return T
     */
    public function remember(string $key, callable $make): mixed
    {
        [$hit, $value] = $this->read($key);
        if ($hit) {
            /** @var T $value */
            return $value;
        }
        $value = $make();
        $this->set($key, $value);

        return $value;
    }

    public function forget(string $key): void
    {
        $file = $this->file($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** Removes every entry; returns the number of files deleted. */
    public function clear(): int
    {
        return self::removeTree($this->dir);
    }

    /** Deletes $path (a file, a symlink or a directory tree, never following links); returns the files deleted. */
    public static function removeTree(string $path): int
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path) ? 1 : 0;
        }
        if (!is_dir($path)) {
            return 0;
        }
        $removed = 0;
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } elseif (@unlink($item->getPathname())) {
                $removed++;
            }
        }
        @rmdir($path);

        return $removed;
    }

    /** @return array{0: bool, 1: mixed} */
    private function read(string $key): array
    {
        $file = $this->file($key);
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false) {
            return [false, null];
        }
        $entry = @unserialize($raw, ['allowed_classes' => false]);

        return is_array($entry) && array_key_exists('v', $entry) ? [true, $entry['v']] : [false, null];
    }

    private function file(string $key): string
    {
        $hash = sha1($key);

        return $this->dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }

    private static function assertStorable(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertStorable($item);
            }

            return;
        }
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException('Cache values must be scalars, null or arrays of them');
        }
    }
}
```

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\Cache\Cache;`.
- In the constructor, immediately before `$this->bootModules();`, add:
```php
        $c->set(Cache::class, fn(): Cache => new Cache($this->cacheDir() . '/data'));
```
- Replace `clearCache()` with:
```php
    public function clearCache(): int
    {
        $removed = Cache::removeTree($this->cacheDir() . '/data') + Cache::removeTree($this->cacheDir() . '/twig');
        foreach ([...(glob($this->cacheDir() . '/*.php') ?: []), ...(glob($this->configCachePath()) ?: [])] as $file) {
            if (is_file($file) && unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
```

In `CacheClearCommand::description()` return `'Delete cached config, routes, compiled templates and cache entries'`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Cache tests/Kernel/AppTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add a file cache and clear it with cache:clear" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Settings store (`xar_settings`)

**Files:**
- Create: `src/Kernel/migrations/2026_10_09_000001_create_settings.php`, `src/Kernel/Settings/Settings.php`
- Modify: `tests/Support/AppTestCase.php` (add `migrateKernel()`)
- Test: `tests/Kernel/Settings/SettingsTest.php`

**Interfaces:**
- **Consumes:** `Connection`, `Migrator`.
- **Produces the table `settings`:** `scope` string(64), `key` string(191), `value` json, with primary key `(scope, key)`.
- **Produces `Xaraya\Kernel\Settings\Settings(Connection)`.** The container autowires it, so it needs no binding.
  - `get(string $scope, string $key, mixed $default = null): mixed`
  - `all(string $scope): array<string, mixed>`, ordered by key and memoised per scope
  - `set(string $scope, string $key, mixed $value): void`, which stores any JSON-encodable value
  - `forget(string $scope, string $key): void`
  - Scopes are 1–64 and keys 1–191 printable ASCII characters without spaces. Anything else throws `InvalidArgumentException`.
  - Without the table, reads return defaults.
  - Scope conventions: theme settings use `theme.<name>` (Task 8), and modules use their own name.
- **Produces `AppTestCase::migrateKernel(App $app): void`**, which runs the kernel migrations on the test database.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/Settings/SettingsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Settings;

use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Settings\Settings;
use Xaraya\Tests\Support\DbTestCase;

final class SettingsTest extends DbTestCase
{
    private function settings(): Settings
    {
        (new Migrator($this->db))->migrate(['kernel' => dirname(__DIR__, 3) . '/src/Kernel/migrations']);

        return new Settings($this->db);
    }

    public function testReadsDefaultsWithoutTheTable(): void
    {
        $settings = new Settings($this->db);
        self::assertSame('d', $settings->get('theme.phoenix', 'tagline', 'd'));
        self::assertSame([], $settings->all('theme.phoenix'));
    }

    public function testRoundTripsJsonValuesPerScope(): void
    {
        $settings = $this->settings();
        $values = ['string' => 'Small & neutral', 'int' => 42, 'float' => 1.5, 'bool' => false, 'null' => null, 'list' => ['a', 'b'], 'map' => ['x' => 1]];
        foreach ($values as $key => $value) {
            $settings->set('demo', $key, $value);
        }
        $settings->set('other', 'string', 'elsewhere');

        $fresh = new Settings($this->db);
        foreach ($values as $key => $value) {
            self::assertSame($value, $fresh->get('demo', $key, 'missing'), $key);
        }
        self::assertSame(['bool', 'float', 'int', 'list', 'map', 'null', 'string'], array_keys($fresh->all('demo')));
        self::assertSame(['string' => 'elsewhere'], $fresh->all('other'));
    }

    public function testOverwriteAndForget(): void
    {
        $settings = $this->settings();
        $settings->set('demo', 'k', 'one');
        self::assertSame('one', $settings->get('demo', 'k'));
        $settings->set('demo', 'k', 'two');
        self::assertSame('two', $settings->get('demo', 'k'));
        $settings->forget('demo', 'k');
        self::assertNull($settings->get('demo', 'k'));
    }

    public function testRejectsBadScopesAndKeys(): void
    {
        $settings = $this->settings();
        foreach ([['', 'k'], ['demo', ''], ['has space', 'k'], ['demo', str_repeat('k', 192)], [str_repeat('s', 65), 'k']] as [$scope, $key]) {
            try {
                $settings->set($scope, $key, 1);
                self::fail("'{$scope}'/'{$key}' should be rejected");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Settings`
Expected: errors, `Settings` not found.

- [ ] **Step 3: Implement**

`src/Kernel/migrations/2026_10_09_000001_create_settings.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('settings', function (Blueprint $t): void {
            $t->string('scope', 64);
            $t->string('key', 191);
            $t->json('value');
            $t->primary('scope', 'key');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('settings');
    }
};
```

`src/Kernel/Settings/Settings.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Settings;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;

/** The general key-value store (xar_settings): theme settings use scope "theme.<name>", modules their own name. */
final class Settings
{
    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    private ?bool $ready = null;

    public function __construct(private readonly Connection $db) {}

    public function get(string $scope, string $key, mixed $default = null): mixed
    {
        $values = $this->all($scope);

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /** @return array<string, mixed> */
    public function all(string $scope): array
    {
        self::check($scope, 'scope', 64);
        if (isset($this->loaded[$scope])) {
            return $this->loaded[$scope];
        }
        $values = [];
        if ($this->ready()) {
            foreach ($this->db->select('settings')->where('scope', '=', $scope)->orderBy('key')->all() as $row) {
                $values[(string) $row['key']] = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $this->loaded[$scope] = $values;
    }

    public function set(string $scope, string $key, mixed $value): void
    {
        self::check($scope, 'scope', 64);
        self::check($key, 'key', 191);
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $this->db->upsert('settings', ['scope' => $scope, 'key' => $key, 'value' => $json], ['scope', 'key']);
        unset($this->loaded[$scope]);
        $this->ready = null;
    }

    public function forget(string $scope, string $key): void
    {
        self::check($scope, 'scope', 64);
        self::check($key, 'key', 191);
        if ($this->ready()) {
            $this->db->delete('settings', ['scope' => $scope, 'key' => $key]);
        }
        unset($this->loaded[$scope]);
    }

    private function ready(): bool
    {
        return $this->ready ??= $this->db->hasTable('settings');
    }

    private static function check(string $value, string $what, int $max): void
    {
        if (strlen($value) > $max || preg_match('/^[\x21-\x7e]+$/D', $value) !== 1) {
            throw new InvalidArgumentException("A setting {$what} must be 1-{$max} printable ASCII characters without spaces");
        }
    }
}
```

In `tests/Support/AppTestCase.php`, add `use Xaraya\Kernel\Db\Migrations\Migrator;` and this method:
```php
    protected function migrateKernel(App $app): void
    {
        $app->container()->get(Migrator::class)->migrate(['kernel' => dirname(__DIR__, 2) . '/src/Kernel/migrations']);
    }
```

- [ ] **Step 4: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Kernel/Settings`, then repeat with the MySQL env vars.
Expected: PASS (4 tests) on both.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add the xar_settings key-value store" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Themes (`theme.json`, `ThemeRegistry`) and config keys

**Files:**
- Create: `src/Kernel/View/ViewException.php`, `src/Kernel/View/Theme.php`, `src/Kernel/View/ThemeRegistry.php`, `tests/Support/Fixtures.php`
- Modify: `config/app.php` (`themes.paths`, `view.engine`), `.env.example`, `src/Kernel/App.php` (bind `ThemeRegistry`)
- Test: `tests/Kernel/View/ThemeRegistryTest.php`

**Interfaces:**
- **Produces `ViewException extends RuntimeException`.**
- **Produces `Theme`:**
  - `ENGINES = ['php', 'twig']`.
  - Readonly fields: `string $name`, `string $version`, `string $path` (realpath), `?string $parent`, `string $engine` (default `php`), `list<string> $regions`, `array<string, array<string, mixed>> $settings` (setting name → schema with `type`, `label`, `default`).
  - `static fromDirectory(string $dir): Theme`, which throws `ViewException` on a bad `theme.json`.
  - `settingDefaults(): array<string, mixed>`.
- **Produces `ThemeRegistry(list<string> $paths, string $active)`:**
  - `discover(): array<string, Theme>`, sorted by name; duplicates throw
  - `get(string $name): Theme`, throwing `ViewException("Unknown theme '<name>'")`
  - `active(): Theme`
  - `chain(): list<Theme>`, the active theme first, then its parents. Cycles throw.
  - `regions(): list<string>`, where the first theme in the chain that declares regions wins
  - `settingDefaults(): array<string, mixed>`, where a child overrides its parent
- **Produces the binding:** `ThemeRegistry::class`, built from `themes.paths` (each passed through `App::path()`) and `app.theme`.
- **Produces these config keys:**
  - `themes.paths` (env `THEME_PATHS`, comma list, default `themes`)
  - `view.engine` (env `VIEW_ENGINE`, `twig`|`php`|unset)
- **Produces test helper `Xaraya\Tests\Support\Fixtures`:**
  - `theme(string $base, string $name, array $json = [], array $files = []): string`
  - `module(string $base, string $name, array $json = [], array $files = []): string`
  - Both write a manifest (`theme.json`/`module.json` with `name` and `version` `1.0.0`, plus `$json`) and `$files` (relative path → content), then return the directory.

- [ ] **Step 1: Write the test helper and the failing tests**

`tests/Support/Fixtures.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

/** Writes small fixture themes and modules (a manifest plus files) into a temp directory. */
final class Fixtures
{
    /**
     * @param array<string, mixed> $json merged over {"name", "version": "1.0.0"}
     * @param array<string, string> $files relative path => content
     */
    public static function theme(string $base, string $name, array $json = [], array $files = []): string
    {
        return self::write($base . '/' . $name, 'theme.json', ['name' => $name, 'version' => '1.0.0', ...$json], $files);
    }

    /**
     * @param array<string, mixed> $json merged over {"name", "version": "1.0.0"}
     * @param array<string, string> $files relative path => content
     */
    public static function module(string $base, string $name, array $json = [], array $files = []): string
    {
        return self::write($base . '/' . $name, 'module.json', ['name' => $name, 'version' => '1.0.0', ...$json], $files);
    }

    /**
     * @param array<string, mixed> $json
     * @param array<string, string> $files
     */
    private static function write(string $dir, string $manifest, array $json, array $files): string
    {
        $all = [$manifest => json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), ...$files];
        foreach ($all as $path => $content) {
            $file = $dir . '/' . $path;
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, $content);
        }

        return $dir;
    }
}
```

`tests/Kernel/View/ThemeRegistryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\View\Theme;
use Xaraya\Kernel\View\ThemeRegistry;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class ThemeRegistryTest extends AppTestCase
{
    private function registry(string $active): ThemeRegistry
    {
        return new ThemeRegistry([$this->tmp . '/themes'], $active);
    }

    public function testDiscoversThemesWithDefaults(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'base');
        $theme = $this->registry('base')->active();
        self::assertSame('base', $theme->name);
        self::assertSame('1.0.0', $theme->version);
        self::assertSame(realpath($this->tmp . '/themes/base'), $theme->path);
        self::assertNull($theme->parent);
        self::assertSame('php', $theme->engine);
        self::assertSame([], $theme->regions);
        self::assertSame([], $theme->settings);
    }

    public function testChainRegionsAndSettingsFollowTheParent(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'base', [
            'engine' => 'twig',
            'regions' => ['header', 'sidebar'],
            'settings' => ['tagline' => ['default' => 'Base'], 'accent' => ['default' => 'blue']],
        ]);
        Fixtures::theme($this->tmp . '/themes', 'kid', ['parent' => 'base', 'settings' => ['tagline' => ['default' => 'Kid']]]);
        $registry = $this->registry('kid');
        self::assertSame(['kid', 'base'], array_map(fn(Theme $t): string => $t->name, $registry->chain()));
        self::assertSame(['header', 'sidebar'], $registry->regions());
        self::assertSame(['tagline' => 'Kid', 'accent' => 'blue'], $registry->settingDefaults());
    }

    public function testUnknownActiveThemeThrows(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage("Unknown theme 'kid'");
        $this->registry('kid')->active();
    }

    public function testCircularParentsThrow(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'aa', ['parent' => 'bb']);
        Fixtures::theme($this->tmp . '/themes', 'bb', ['parent' => 'aa']);
        $this->expectExceptionMessage('Circular theme parents: aa -> bb -> aa');
        $this->registry('aa')->chain();
    }

    public function testInvalidManifestsThrow(): void
    {
        $bad = [['engine' => 'smarty'], ['regions' => ['Bad Region']], ['parent' => 'Not-Lower'], ['settings' => ['tagline' => 'not-an-object']], ['version' => '']];
        foreach ($bad as $i => $json) {
            $dir = Fixtures::theme($this->tmp . '/bad', 'bad' . $i, $json);
            try {
                Theme::fromDirectory($dir);
                self::fail('expected a ViewException for ' . json_encode($json));
            } catch (ViewException) {
                self::addToAssertionCount(1);
            }
        }
        file_put_contents($this->tmp . '/bad/bad0/theme.json', '{not json');
        $this->expectExceptionMessage('Invalid JSON');
        Theme::fromDirectory($this->tmp . '/bad/bad0');
    }

    public function testDuplicateNamesAcrossPathsThrow(): void
    {
        Fixtures::theme($this->tmp . '/one', 'same');
        Fixtures::theme($this->tmp . '/two', 'same');
        $this->expectExceptionMessage("Theme 'same' found twice");
        (new ThemeRegistry([$this->tmp . '/one', $this->tmp . '/two'], 'same'))->discover();
    }

    public function testAppWiresPathsAndActiveThemeFromConfig(): void
    {
        Fixtures::theme($this->tmp . '/themes', 'kid');
        $app = $this->boot([], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid']);
        self::assertSame('kid', $app->container()->get(ThemeRegistry::class)->active()->name);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/View/ThemeRegistryTest.php`
Expected: errors, `ThemeRegistry` not found.

- [ ] **Step 3: Implement**

`src/Kernel/View/ViewException.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

final class ViewException extends \RuntimeException {}
```

`src/Kernel/View/Theme.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use JsonException;

final class Theme
{
    public const ENGINES = ['php', 'twig'];

    private const NAME = '/^[a-z][a-z0-9_-]*$/D';

    /**
     * @param list<string> $regions
     * @param array<string, array<string, mixed>> $settings setting name => schema (type, label, default)
     */
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $path,
        public readonly ?string $parent,
        public readonly string $engine,
        public readonly array $regions,
        public readonly array $settings,
    ) {}

    public static function fromDirectory(string $dir): self
    {
        $file = $dir . '/theme.json';
        if (!is_file($file)) {
            throw new ViewException("No theme.json in {$dir}");
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ViewException("Invalid JSON in {$file}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new ViewException("{$file} must contain a JSON object");
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME, $name) !== 1) {
            throw new ViewException("{$file}: \"name\" must be a lower-case identifier");
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new ViewException("{$file}: \"version\" is required");
        }
        $parent = $data['parent'] ?? null;
        if ($parent !== null) {
            if (!is_string($parent) || preg_match(self::NAME, $parent) !== 1 || $parent === $name) {
                throw new ViewException("{$file}: \"parent\" must be another theme's name");
            }
        }
        $engine = $data['engine'] ?? 'php';
        if (!is_string($engine) || !in_array($engine, self::ENGINES, true)) {
            throw new ViewException("{$file}: \"engine\" must be \"php\" or \"twig\"");
        }
        $regions = [];
        foreach ((array) ($data['regions'] ?? []) as $region) {
            if (!is_string($region) || preg_match(self::NAME, $region) !== 1) {
                throw new ViewException("{$file}: every region must be a lower-case identifier");
            }
            $regions[] = $region;
        }
        $settings = [];
        foreach ((array) ($data['settings'] ?? []) as $key => $schema) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1 || !is_array($schema)) {
                throw new ViewException("{$file}: \"settings\" must map lower-case names to objects");
            }
            /** @var array<string, mixed> $schema */
            $settings[$key] = $schema;
        }

        return new self($name, $version, realpath($dir) ?: $dir, $parent, $engine, $regions, $settings);
    }

    /** @return array<string, mixed> */
    public function settingDefaults(): array
    {
        $defaults = [];
        foreach ($this->settings as $key => $schema) {
            $defaults[$key] = $schema['default'] ?? null;
        }

        return $defaults;
    }
}
```

`src/Kernel/View/ThemeRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

final class ThemeRegistry
{
    /** @var array<string, Theme>|null */
    private ?array $discovered = null;

    /** @param list<string> $paths directories that contain theme folders */
    public function __construct(private readonly array $paths, private readonly string $active) {}

    /** @return array<string, Theme> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }
        $found = [];
        foreach ($this->paths as $base) {
            foreach (glob(rtrim($base, '/') . '/*/theme.json') ?: [] as $file) {
                $theme = Theme::fromDirectory(dirname($file));
                if (isset($found[$theme->name])) {
                    throw new ViewException("Theme '{$theme->name}' found twice: {$found[$theme->name]->path} and {$theme->path}");
                }
                $found[$theme->name] = $theme;
            }
        }
        ksort($found);

        return $this->discovered = $found;
    }

    public function get(string $name): Theme
    {
        return $this->discover()[$name] ?? throw new ViewException("Unknown theme '{$name}'");
    }

    public function active(): Theme
    {
        return $this->get($this->active);
    }

    /** @return list<Theme> the active theme first, then its parent, grandparent and so on */
    public function chain(): array
    {
        $chain = [];
        $theme = $this->active();
        while (true) {
            if (isset($chain[$theme->name])) {
                throw new ViewException('Circular theme parents: ' . implode(' -> ', [...array_keys($chain), $theme->name]));
            }
            $chain[$theme->name] = $theme;
            if ($theme->parent === null) {
                return array_values($chain);
            }
            $theme = $this->get($theme->parent);
        }
    }

    /** @return list<string> the active theme's regions; a theme that declares none inherits its parent's */
    public function regions(): array
    {
        foreach ($this->chain() as $theme) {
            if ($theme->regions !== []) {
                return $theme->regions;
            }
        }

        return [];
    }

    /** @return array<string, mixed> setting defaults, a child's overriding its parent's */
    public function settingDefaults(): array
    {
        $defaults = [];
        foreach (array_reverse($this->chain()) as $theme) {
            $defaults = [...$defaults, ...$theme->settingDefaults()];
        }

        return $defaults;
    }
}
```

In `config/app.php`, add these entries after `'modules' => [...]`:
```php
    'themes' => [
        // THEME_PATHS is a comma-separated list of directories, relative to the project root.
        'paths' => array_values(array_filter(array_map('trim', explode(',', (string) env('THEME_PATHS', 'themes'))))),
    ],
    'view' => [
        // "twig" or "php" overrides the active theme's engine preference; unset keeps the theme's choice.
        'engine' => env('VIEW_ENGINE'),
    ],
```

Append to `.env.example`:
```
# APP_THEME=phoenix
# THEME_PATHS=themes
# VIEW_ENGINE=php
```

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\View\ThemeRegistry;`.
- Before `$this->bootModules();`, add:
```php
        $c->set(ThemeRegistry::class, fn(): ThemeRegistry => new ThemeRegistry(
            array_values(array_map(fn(mixed $p): string => $this->path((string) $p), (array) $config->get('themes.paths', ['themes']))),
            (string) $config->get('app.theme', 'phoenix'),
        ));
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/View/ThemeRegistryTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add theme manifests, the theme registry and themes.paths" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Translation catalogs (`t()`)

**Files:**
- Create: `src/Kernel/View/Translator.php`
- Modify: `src/Kernel/App.php` (bind `Translator`)
- Test: `tests/Kernel/View/TranslatorTest.php`

**Interfaces:**
- **Consumes:** `ModuleRegistry::enabled()`, `ThemeRegistry::chain()` (Task 3), `Fixtures` (Task 3).
- **Produces `Translator(string $locale, list<string> $dirs)`:**
  - `$dirs` are `lang` directories, lowest priority first.
  - `t(string $key, array<string, mixed> $params = []): string`. An unknown key returns itself, and `{name}` placeholders are replaced by scalar or `Stringable` params.
  - `locale(): string`.
  - An invalid locale throws `InvalidArgumentException`. A catalog that does not return an array throws `ViewException`.
- **Catalog order:**
  - For each directory, `<base>.php` loads, then `<locale>.php` (`fr`, then `fr_CA`).
  - Directories load in this order: enabled modules (dependency order), then the theme chain from the root parent down to the active theme.
  - Later entries win, so the active theme has the last word.
- **Produces the binding:** `Translator::class`, built from `app.locale`. A missing or broken theme only drops the theme catalogs.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/View/TranslatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\Translator;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class TranslatorTest extends AppTestCase
{
    /** @param array<string, string> $messages */
    private function catalog(string $dir, string $locale, array $messages): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents("{$dir}/{$locale}.php", '<?php return ' . var_export($messages, true) . ';');

        return $dir;
    }

    public function testFallsBackToTheKeyAndReplacesPlaceholders(): void
    {
        $t = new Translator('en', []);
        self::assertSame('Hello, Ada!', $t->t('Hello, {name}!', ['name' => 'Ada']));
        self::assertSame('{missing} stays', $t->t('{missing} stays', ['other' => 'x']));
        self::assertSame('7 items', $t->t('{n} items', ['n' => 7]));
    }

    public function testLaterDirectoriesWinAndRegionalLocalesExtendTheBase(): void
    {
        $module = $this->catalog($this->tmp . '/module/lang', 'fr', ['Hello' => 'Bonjour', 'Bye' => 'Au revoir']);
        $this->catalog($this->tmp . '/module/lang', 'fr_CA', ['Bye' => 'Bye-bye']);
        $theme = $this->catalog($this->tmp . '/theme/lang', 'fr', ['Hello' => 'Salut']);
        $t = new Translator('fr-CA', [$module, $theme]);
        self::assertSame('Salut', $t->t('Hello'));
        self::assertSame('Bye-bye', $t->t('Bye'));
        self::assertSame('fr-CA', $t->locale());
    }

    public function testInvalidLocaleAndCatalogThrow(): void
    {
        try {
            new Translator('../etc', []);
            self::fail('expected an invalid locale to throw');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        mkdir($this->tmp . '/bad');
        file_put_contents($this->tmp . '/bad/en.php', '<?php return "nope";');
        $this->expectException(ViewException::class);
        (new Translator('en', [$this->tmp . '/bad']))->t('x');
    }

    public function testAppReadsEnabledModulesThenTheThemeChain(): void
    {
        Fixtures::module($this->tmp . '/modules', 'delta', [], ['lang/fr.php' => "<?php return ['Hi' => 'Salut (delta)', 'Only' => 'Seulement'];"]);
        Fixtures::theme($this->tmp . '/themes', 'base', [], ['lang/fr.php' => "<?php return ['Hi' => 'Salut (base)'];"]);
        Fixtures::theme($this->tmp . '/themes', 'kid', ['parent' => 'base'], ['lang/fr.php' => "<?php return ['Hi' => 'Salut (kid)'];"]);
        $overrides = ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid', 'app.locale' => 'fr'];
        $this->boot([$this->tmp . '/modules'], $overrides)->container()->get(ModuleRegistry::class)->enable('delta');

        $t = $this->boot([$this->tmp . '/modules'], $overrides)->container()->get(Translator::class);
        self::assertSame('Salut (kid)', $t->t('Hi'));
        self::assertSame('Seulement', $t->t('Only'));

        $noTheme = $this->boot([$this->tmp . '/modules'], [...$overrides, 'app.theme' => 'missing'])->container()->get(Translator::class);
        self::assertSame('Salut (delta)', $noTheme->t('Hi'));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/View/TranslatorTest.php`
Expected: errors, `Translator` not found.

- [ ] **Step 3: Implement**

`src/Kernel/View/Translator.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use InvalidArgumentException;
use Stringable;

/** PHP array catalogs at <dir>/<locale>.php; English source strings are the keys and the fallback. */
final class Translator
{
    /** @var array<string, string>|null */
    private ?array $messages = null;

    /** @param list<string> $dirs lang directories, lowest priority first */
    public function __construct(private readonly string $locale, private readonly array $dirs)
    {
        if (preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException("Invalid locale '{$locale}'");
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, mixed> $params values for {name} placeholders */
    public function t(string $key, array $params = []): string
    {
        $message = $this->messages()[$key] ?? $key;
        if ($params === []) {
            return $message;
        }
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
        }

        return strtr($message, $replace);
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        if ($this->messages !== null) {
            return $this->messages;
        }
        $normal = str_replace('-', '_', $this->locale);
        $base = explode('_', $normal)[0];
        $locales = $base === $normal ? [$normal] : [$base, $normal];
        $messages = [];
        foreach ($this->dirs as $dir) {
            foreach ($locales as $locale) {
                $file = $dir . '/' . $locale . '.php';
                if (!is_file($file)) {
                    continue;
                }
                $catalog = (static fn(string $f): mixed => require $f)($file);
                if (!is_array($catalog)) {
                    throw new ViewException("Translation catalog {$file} must return an array");
                }
                foreach ($catalog as $from => $to) {
                    if (is_string($to)) {
                        $messages[(string) $from] = $to;
                    }
                }
            }
        }

        return $this->messages = $messages;
    }
}
```

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\View\Translator;` and `use Xaraya\Kernel\View\ViewException;`.
- Before `$this->bootModules();`, add:
```php
        $c->set(Translator::class, function (Container $c) use ($config): Translator {
            $dirs = [];
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
                $dirs[] = $manifest->path . '/lang';
            }
            try {
                $themes = array_reverse($c->get(ThemeRegistry::class)->chain());
            } catch (ViewException) {
                $themes = [];
            }
            foreach ($themes as $theme) {
                $dirs[] = $theme->path . '/lang';
            }

            return new Translator((string) $config->get('app.locale', 'en'), $dirs);
        });
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/View/TranslatorTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add translation catalogs from modules and the theme chain" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Display hooks, `xar_hooks` bindings and `hook:enable|disable`

**Files:**
- Create:
  - `src/Kernel/migrations/2026_10_09_000002_create_hooks.php`
  - `src/Kernel/Hooks/DisplayHook.php`, `src/Kernel/Hooks/HookBindings.php`, `src/Kernel/Hooks/DisplayHooks.php`
  - `src/Kernel/Cli/Commands/HookCommand.php`, `src/Kernel/Cli/Commands/HookEnableCommand.php`, `src/Kernel/Cli/Commands/HookDisableCommand.php`
- Modify:
  - `src/Kernel/Module/Manifest.php` (`displayHooks()`, `hookDefaults()`)
  - `src/Kernel/Module/ModuleRegistry.php` (memoised `enabled()`, `refresh()`, `onEnable()`)
  - `src/Kernel/App.php` (seed bindings on enable)
  - `src/Kernel/Cli/Application.php` (register the commands)
- Test:
  - `tests/Kernel/Hooks/DisplayHooksTest.php`, `tests/Kernel/Module/ManifestViewKeysTest.php`
  - `tests/Kernel/Module/ModuleRegistryTest.php` (two new tests), `tests/Kernel/Cli/ApplicationTest.php` (help list)

**Interfaces:**
- **Consumes:** `Fixtures` (Task 3), `Cli\Application`, `Output`.
- **Produces the table `hooks`:** `observer_module` string(64), `subject_module` string(64), `itemtype` string(64) (`*` allowed), `enabled` bool, with primary key `(observer_module, subject_module, itemtype)` and index `(subject_module, itemtype)`.
- **Produces `interface DisplayHook`:**
  - `HOOKS = ['item.display', 'item.form', 'item.form.save']`
  - `handle(string $hook, array<string, mixed> $item, array<string, mixed> $input = []): string`
- **Produces `HookBindings(Connection)`** (autowired):
  - `seed(Manifest $observer): void`, which inserts missing `hookDefaults` rows as enabled and never touches existing rows
  - `set(string $observer, string $subject, string $itemtype, bool $enabled): void`, an upsert
  - `observers(string $subject, string $itemtype): list<string>`, returning enabled observers bound to the exact itemtype or `*`, sorted, `[]` without the table
- **Produces `DisplayHooks(ModuleRegistry, HookBindings, Container)`** (autowired):
  - `call(string $hook, array<string, mixed> $item, array<string, mixed> $input = []): string`. `$item` needs non-empty string `module` and `itemtype` (and usually `id`).
  - It concatenates the fragments of the **enabled** observer modules, in name order, that declare `$hook`.
  - It returns `''` for `item.form.save`.
  - An unknown hook or an incomplete item throws `InvalidArgumentException`.
- **Produces new `Manifest` methods:**
  - `displayHooks(): array<string, string>` (hook → class)
  - `hookDefaults(): list<array{subject: string, itemtype: string}>`, where `itemtype` defaults to `*`
- **Produces new `ModuleRegistry` behaviour:**
  - `enabled()` is memoised until `enable()`, `disable()` or `refresh()`. This folds in the kernel follow-up "enabled() runs twice per request".
  - `refresh(): void`.
  - `onEnable(Closure(Manifest $manifest, bool $firstInstall): void $listener): void`. Listeners run after `enable()` has migrated and recorded the module. `$firstInstall` is true when `xar_modules` had no row for it before.
- **Produces the CLI commands:**
  - `xar hook:enable <observer> <subject> [itemtype=*]` and `xar hook:disable <observer> <subject> [itemtype=*]`.
  - Both validate that the modules exist and that the observer declares `displayHooks`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Module/ManifestViewKeysTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Module;

use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class ManifestViewKeysTest extends AppTestCase
{
    public function testDisplayHooksAndHookDefaults(): void
    {
        $m = Manifest::fromDirectory(Fixtures::module($this->tmp, 'obs', [
            'displayHooks' => ['item.display' => 'A\\Display', 'item.form.save' => 'A\\Save'],
            'hookDefaults' => [['subject' => 'blog', 'itemtype' => 'post'], ['subject' => 'hello']],
        ]));
        self::assertSame(['item.display' => 'A\\Display', 'item.form.save' => 'A\\Save'], $m->displayHooks());
        self::assertSame([['subject' => 'blog', 'itemtype' => 'post'], ['subject' => 'hello', 'itemtype' => '*']], $m->hookDefaults());

        $none = Manifest::fromDirectory(Fixtures::module($this->tmp, 'plainmod'));
        self::assertSame([], $none->displayHooks());
        self::assertSame([], $none->hookDefaults());
    }

    public function testInvalidHookKeysThrow(): void
    {
        $bad = [
            ['displayHooks' => ['item.delete' => 'A']],
            ['displayHooks' => ['item.display' => 5]],
            ['hookDefaults' => [['itemtype' => 'post']]],
            ['hookDefaults' => ['blog']],
        ];
        foreach ($bad as $i => $json) {
            $m = Manifest::fromDirectory(Fixtures::module($this->tmp, 'bad' . $i, $json));
            try {
                $m->displayHooks();
                $m->hookDefaults();
                self::fail('expected a ModuleException for ' . json_encode($json));
            } catch (ModuleException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
```

`tests/Kernel/Hooks/DisplayHooksTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Hooks;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class DisplayHooksTest extends AppTestCase
{
    private const ITEM = ['module' => 'subj', 'itemtype' => 'thing', 'id' => '7'];

    protected function setUp(): void
    {
        parent::setUp();
        $base = $this->tmp . '/modules';
        Fixtures::module($base, 'subj');
        Fixtures::module($base, 'obs', [
            'displayHooks' => [
                'item.display' => 'Xaraya\\Module\\Obs\\ObsHook',
                'item.form' => 'Xaraya\\Module\\Obs\\ObsHook',
                'item.form.save' => 'Xaraya\\Module\\Obs\\ObsHook',
            ],
            'hookDefaults' => [['subject' => 'subj', 'itemtype' => 'thing']],
        ], ['src/ObsHook.php' => self::hookClass('Obs', 'ObsHook', 'obs')]);
        Fixtures::module($base, 'obs2', [
            'displayHooks' => ['item.display' => 'Xaraya\\Module\\Obs2\\Obs2Hook'],
            'hookDefaults' => [['subject' => 'subj']],
        ], ['src/Obs2Hook.php' => self::hookClass('Obs2', 'Obs2Hook', 'obs2')]);
    }

    private static function hookClass(string $namespace, string $class, string $label): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace Xaraya\\Module\\{$namespace};

            use Xaraya\\Kernel\\Hooks\\DisplayHook;

            final class {$class} implements DisplayHook
            {
                /** @var list<array<string, mixed>> */
                public array \$saved = [];

                public function handle(string \$hook, array \$item, array \$input = []): string
                {
                    if (\$hook === 'item.form.save') {
                        \$this->saved[] = \$input;

                        return '';
                    }

                    return '<p>{$label} ' . \$hook . ' ' . \$item['id'] . '</p>';
                }
            }
            PHP;
    }

    private function app(): App
    {
        return $this->boot([$this->tmp . '/modules']);
    }

    /** @param list<string> $args */
    private function xar(array $args): int
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');

        return (new Application($this->app()))->run(['xar', ...$args], new Output($out, $out));
    }

    private function hooks(): DisplayHooks
    {
        return $this->app()->container()->get(DisplayHooks::class);
    }

    public function testSeededBindingRendersTheObserverFragment(): void
    {
        self::assertSame(0, $this->xar(['module:enable', 'subj']));
        self::assertSame(0, $this->xar(['module:enable', 'obs']));
        self::assertSame('<p>obs item.display 7</p>', $this->hooks()->call('item.display', self::ITEM));
        self::assertSame('<p>obs item.form 7</p>', $this->hooks()->call('item.form', self::ITEM));
        self::assertSame('', $this->hooks()->call('item.display', [...self::ITEM, 'itemtype' => 'other']));
        self::assertSame('', $this->hooks()->call('item.display', [...self::ITEM, 'module' => 'nobody']));
    }

    public function testWildcardBindingsRunInObserverNameOrder(): void
    {
        foreach (['subj', 'obs', 'obs2'] as $module) {
            self::assertSame(0, $this->xar(['module:enable', $module]));
        }
        self::assertSame('<p>obs item.display 7</p><p>obs2 item.display 7</p>', $this->hooks()->call('item.display', self::ITEM));
        self::assertSame('<p>obs2 item.display 7</p>', $this->hooks()->call('item.display', [...self::ITEM, 'itemtype' => 'other']));
        self::assertSame('', $this->hooks()->call('item.form', [...self::ITEM, 'itemtype' => 'other']), 'obs2 has no item.form hook');
    }

    public function testFormSaveReturnsNothingAndPassesTheInput(): void
    {
        self::assertSame(0, $this->xar(['module:enable', 'obs']));
        $app = $this->app();
        self::assertSame('', $app->container()->get(DisplayHooks::class)->call('item.form.save', self::ITEM, ['note' => 'hi']));
        self::assertSame([['note' => 'hi']], $app->container()->get('Xaraya\\Module\\Obs\\ObsHook')->saved);
    }

    public function testCliTogglesBindingsAndReEnablingKeepsTheChoice(): void
    {
        $this->xar(['module:enable', 'subj']);
        $this->xar(['module:enable', 'obs']);
        self::assertSame(0, $this->xar(['hook:disable', 'obs', 'subj', 'thing']));
        self::assertSame('', $this->hooks()->call('item.display', self::ITEM));
        self::assertSame(0, $this->xar(['module:enable', 'obs']));
        self::assertSame('', $this->hooks()->call('item.display', self::ITEM), 're-enabling must not re-seed a disabled binding');
        self::assertSame(0, $this->xar(['hook:enable', 'obs', 'subj', 'thing']));
        self::assertSame('<p>obs item.display 7</p>', $this->hooks()->call('item.display', self::ITEM));
    }

    public function testDisabledObserverModuleIsIgnored(): void
    {
        $this->xar(['module:enable', 'obs']);
        $this->xar(['module:disable', 'obs']);
        self::assertSame('', $this->hooks()->call('item.display', self::ITEM));
    }

    public function testCliRejectsUnknownModulesAndObserversWithoutHooks(): void
    {
        $this->xar(['module:enable', 'obs']);
        self::assertSame(1, $this->xar(['hook:enable', 'subj', 'obs']));
        self::assertSame(1, $this->xar(['hook:enable', 'nobody', 'subj']));
        self::assertSame(1, $this->xar(['hook:enable', 'obs']));
    }

    public function testUnknownHookOrIncompleteItemThrows(): void
    {
        try {
            $this->hooks()->call('item.delete', self::ITEM);
            self::fail('expected an unknown hook to throw');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->hooks()->call('item.display', ['id' => '7']);
    }
}
```

In `tests/Kernel/Module/ModuleRegistryTest.php`, add `use Xaraya\Kernel\Module\Manifest;` and these tests:
```php
    public function testOnEnableListenersSeeTheFirstInstallOnlyOnce(): void
    {
        $r = $this->registry();
        $calls = [];
        $r->onEnable(function (Manifest $manifest, bool $firstInstall) use (&$calls): void {
            $calls[] = [$manifest->name, $firstInstall];
        });
        $r->enable('alpha');
        $r->enable('alpha');
        self::assertSame([['alpha', true], ['alpha', false]], $calls);
    }

    public function testEnabledIsCachedUntilEnableDisableOrRefresh(): void
    {
        $r = $this->registry();
        $r->enable('alpha');
        self::assertSame(['alpha'], array_keys($r->enabled()));
        $this->db->update('modules', ['enabled' => false], ['name' => 'alpha']);
        self::assertSame(['alpha'], array_keys($r->enabled()), 'still cached');
        $r->refresh();
        self::assertSame([], $r->enabled());
        $r->enable('alpha');
        self::assertSame(['alpha'], array_keys($r->enabled()));
        $r->disable('alpha');
        self::assertSame([], $r->enabled());
    }
```

In `tests/Kernel/Cli/ApplicationTest.php::testHelpListsCommands`, replace the command list with:
```php
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'module:list', 'module:enable', 'module:disable', 'serve', 'cache:clear', 'hook:enable', 'hook:disable'] as $name) {
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Hooks tests/Kernel/Module tests/Kernel/Cli`
Expected: errors and failures, for example `Call to undefined method Manifest::displayHooks()`, `DisplayHooks` not found, and missing `hook:enable` in the help.

- [ ] **Step 3: Implement**

`src/Kernel/migrations/2026_10_09_000002_create_hooks.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('hooks', function (Blueprint $t): void {
            $t->string('observer_module', 64);
            $t->string('subject_module', 64);
            $t->string('itemtype', 64);
            $t->bool('enabled')->default(true);
            $t->primary('observer_module', 'subject_module', 'itemtype');
            $t->index('subject_module', 'itemtype');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('hooks');
    }
};
```

`src/Kernel/Hooks/DisplayHook.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Hooks;

/** An observer module's display hook, named for each hook it handles in the manifest's "displayHooks". */
interface DisplayHook
{
    public const HOOKS = ['item.display', 'item.form', 'item.form.save'];

    /**
     * Returns an HTML fragment for item.display and item.form. For item.form.save it persists the observer's
     * own fields from $input and returns ''.
     *
     * @param array<string, mixed> $item at least "module", "itemtype" and "id"
     * @param array<string, mixed> $input the submitted form fields (item.form.save only)
     */
    public function handle(string $hook, array $item, array $input = []): string;
}
```

`src/Kernel/Hooks/HookBindings.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Hooks;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\Manifest;

/** xar_hooks: which observer modules' display hooks run for which subject module and itemtype. */
final class HookBindings
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    private ?bool $ready = null;

    public function __construct(private readonly Connection $db) {}

    /** Adds the manifest's hookDefaults as enabled bindings; existing rows, and their enabled flag, are kept. */
    public function seed(Manifest $observer): void
    {
        foreach ($observer->hookDefaults() as $default) {
            $key = ['observer_module' => $observer->name, 'subject_module' => $default['subject'], 'itemtype' => $default['itemtype']];
            $existing = $this->db->select('hooks')
                ->where('observer_module', '=', $key['observer_module'])
                ->where('subject_module', '=', $key['subject_module'])
                ->where('itemtype', '=', $key['itemtype'])
                ->first();
            if ($existing === null) {
                $this->db->insert('hooks', [...$key, 'enabled' => true]);
            }
        }
        $this->memo = [];
        $this->ready = null;
    }

    public function set(string $observer, string $subject, string $itemtype, bool $enabled): void
    {
        self::check($observer);
        self::check($subject);
        self::check($itemtype, true);
        $this->db->upsert('hooks', [
            'observer_module' => $observer,
            'subject_module' => $subject,
            'itemtype' => $itemtype,
            'enabled' => $enabled,
        ], ['observer_module', 'subject_module', 'itemtype']);
        $this->memo = [];
        $this->ready = null;
    }

    /** @return list<string> enabled observer module names bound to $subject for $itemtype (exact or '*'), sorted */
    public function observers(string $subject, string $itemtype): array
    {
        $key = $subject . "\0" . $itemtype;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (!($this->ready ??= $this->db->hasTable('hooks'))) {
            return [];
        }
        $rows = $this->db->select('hooks')
            ->columns('observer_module')
            ->where('subject_module', '=', $subject)
            ->whereIn('itemtype', array_values(array_unique([$itemtype, '*'])))
            ->where('enabled', '=', true)
            ->all();
        $names = array_values(array_unique(array_map(static fn(array $row): string => (string) $row['observer_module'], $rows)));
        sort($names);

        return $this->memo[$key] = $names;
    }

    private static function check(string $value, bool $wildcard = false): void
    {
        if (($wildcard && $value === '*') || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $value) === 1) {
            return;
        }
        throw new InvalidArgumentException("Invalid hook binding part '{$value}'");
    }
}
```

`src/Kernel/Hooks/DisplayHooks.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Hooks;

use InvalidArgumentException;
use LogicException;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Module\ModuleRegistry;

/** Runs the display hooks of the enabled observer modules bound to an item's module and itemtype. */
final class DisplayHooks
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly HookBindings $bindings,
        private readonly Container $container,
    ) {}

    /**
     * @param array<string, mixed> $item at least "module" and "itemtype"
     * @param array<string, mixed> $input form fields, for item.form.save
     */
    public function call(string $hook, array $item, array $input = []): string
    {
        if (!in_array($hook, DisplayHook::HOOKS, true)) {
            throw new InvalidArgumentException("Unknown display hook '{$hook}'");
        }
        $module = $item['module'] ?? null;
        $itemtype = $item['itemtype'] ?? null;
        if (!is_string($module) || $module === '' || !is_string($itemtype) || $itemtype === '') {
            throw new InvalidArgumentException('Display hooks need an item with string "module" and "itemtype"');
        }
        $enabled = $this->modules->enabled();
        $html = '';
        foreach ($this->bindings->observers($module, $itemtype) as $observer) {
            $class = isset($enabled[$observer]) ? ($enabled[$observer]->displayHooks()[$hook] ?? null) : null;
            if ($class === null) {
                continue;
            }
            $handler = $this->container->get($class);
            if (!$handler instanceof DisplayHook) {
                throw new LogicException("{$observer}: {$class} must implement DisplayHook");
            }
            $html .= $handler->handle($hook, $item, $input);
        }

        return $hook === 'item.form.save' ? '' : $html;
    }
}
```

In `src/Kernel/Module/Manifest.php`, add `use Xaraya\Kernel\Hooks\DisplayHook;` and these methods after `commands()`:
```php
    /** @return array<string, string> display hook name => class implementing DisplayHook */
    public function displayHooks(): array
    {
        $out = [];
        foreach ((array) ($this->data['displayHooks'] ?? []) as $hook => $class) {
            if (!is_string($hook) || !in_array($hook, DisplayHook::HOOKS, true) || !is_string($class) || $class === '') {
                throw new ModuleException("{$this->name}: displayHooks must map " . implode(', ', DisplayHook::HOOKS) . ' to class names');
            }
            $out[$hook] = $class;
        }

        return $out;
    }

    /** @return list<array{subject: string, itemtype: string}> bindings seeded when this (observer) module is enabled */
    public function hookDefaults(): array
    {
        $out = [];
        foreach ((array) ($this->data['hookDefaults'] ?? []) as $i => $default) {
            $subject = is_array($default) ? ($default['subject'] ?? null) : null;
            $itemtype = is_array($default) ? ($default['itemtype'] ?? '*') : null;
            if (!is_string($subject) || $subject === '' || !is_string($itemtype) || $itemtype === '') {
                throw new ModuleException("{$this->name}: hookDefaults[{$i}] needs a string \"subject\" and an optional string \"itemtype\"");
            }
            $out[] = ['subject' => $subject, 'itemtype' => $itemtype];
        }

        return $out;
    }
```

In `src/Kernel/Module/ModuleRegistry.php`:
- Add these properties:
```php
    /** @var array<string, Manifest>|null */
    private ?array $enabledCache = null;

    /** @var list<Closure(Manifest, bool): void> */
    private array $enableListeners = [];
```
- Replace `enabled()` with:
```php
    /** @return array<string, Manifest> memoised until enable(), disable() or refresh() */
    public function enabled(): array
    {
        if ($this->enabledCache !== null) {
            return $this->enabledCache;
        }
        if (!$this->db->hasTable('modules')) {
            return $this->enabledCache = [];
        }
        $discovered = $this->discover();
        $enabled = [];
        foreach ($this->db->select('modules')->where('enabled', '=', true)->orderBy('name')->all() as $row) {
            $name = (string) $row['name'];
            if (isset($discovered[$name])) {
                $enabled[$name] = $discovered[$name];
            }
        }

        return $this->enabledCache = $this->sortByDependencies($enabled);
    }

    /** Forgets the memoised enabled() list, after xar_modules was changed by other code. */
    public function refresh(): void
    {
        $this->enabledCache = null;
    }

    /**
     * Registers a callback that runs after enable() has migrated and recorded a module. $firstInstall is true
     * when xar_modules had no row for the module before, so seeds that must run once can check it.
     *
     * @param Closure(Manifest, bool): void $listener
     */
    public function onEnable(Closure $listener): void
    {
        $this->enableListeners[] = $listener;
    }
```
- In `enable()`, replace everything from the `$this->db->upsert('modules', …` call to the end of the method with:
```php
        $firstInstall = $this->db->select('modules')->where('name', '=', $name)->first() === null;
        $this->db->upsert('modules', [
            'name' => $name,
            'version' => $manifest->version,
            'enabled' => true,
            'installed_at' => new DateTimeImmutable(),
        ], ['name']);
        $this->enabledCache = null;
        foreach ($this->enableListeners as $listener) {
            $listener($manifest, $firstInstall);
        }
    }
```
- In `disable()`, after the `if ($this->db->hasTable('modules')) { … }` block, add `$this->enabledCache = null;`.

`src/Kernel/Cli/Commands/HookCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Hooks\HookBindings;
use Xaraya\Kernel\Module\ModuleRegistry;

abstract class HookCommand extends Command
{
    public function __construct(private readonly HookBindings $bindings, private readonly ModuleRegistry $modules) {}

    abstract protected function enabled(): bool;

    public function usage(): string
    {
        return $this->name() . ' <observer> <subject> [itemtype]';
    }

    public function run(Input $input, Output $output): int
    {
        $observer = $input->argument(0);
        $subject = $input->argument(1);
        $itemtype = $input->argument(2) ?? '*';
        if ($observer === null || $subject === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $manifest = $this->modules->get($observer);
        $this->modules->get($subject);
        if ($manifest->displayHooks() === []) {
            $output->error("Module '{$observer}' declares no displayHooks");

            return 1;
        }
        $this->bindings->set($observer, $subject, $itemtype, $this->enabled());
        $output->line(sprintf(
            "%s display hooks from '%s' on '%s' (itemtype %s).",
            $this->enabled() ? 'Enabled' : 'Disabled',
            $observer,
            $subject,
            $itemtype,
        ));

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/HookEnableCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

final class HookEnableCommand extends HookCommand
{
    public function name(): string
    {
        return 'hook:enable';
    }

    public function description(): string
    {
        return "Bind a module's display hooks to a subject module (and itemtype)";
    }

    protected function enabled(): bool
    {
        return true;
    }
}
```

`src/Kernel/Cli/Commands/HookDisableCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

final class HookDisableCommand extends HookCommand
{
    public function name(): string
    {
        return 'hook:disable';
    }

    public function description(): string
    {
        return "Unbind a module's display hooks from a subject module (and itemtype)";
    }

    protected function enabled(): bool
    {
        return false;
    }
}
```

In `src/Kernel/Cli/Application.php`, import both commands and add `HookEnableCommand::class` and `HookDisableCommand::class` to `KERNEL_COMMANDS`, after `CacheClearCommand::class`.

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\Hooks\HookBindings;` and `use Xaraya\Kernel\Module\Manifest;`.
- Replace the `ModuleRegistry` binding with:
```php
        $c->set(ModuleRegistry::class, function (Container $c) use ($config): ModuleRegistry {
            $registry = new ModuleRegistry(
                $c->get(Connection::class),
                $c->get(Migrator::class),
                array_values(array_map(fn(mixed $p): string => $this->path((string) $p), (array) $config->get('modules.paths', ['modules']))),
                __DIR__ . '/migrations',
            );
            $registry->onEnable(static function (Manifest $manifest, bool $firstInstall) use ($c): void {
                $c->get(HookBindings::class)->seed($manifest);
            });

            return $registry;
        });
```

- [ ] **Step 4: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Kernel/Hooks tests/Kernel/Module tests/Kernel/Cli`, then repeat with the MySQL env vars.
Expected: PASS on both.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add display hooks, xar_hooks bindings and hook:enable/disable" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Template locator

**Files:**
- Create: `src/Kernel/View/TemplateLocator.php`
- Modify: `src/Kernel/App.php` (bind `TemplateLocator`)
- Test: `tests/Kernel/View/TemplateLocatorTest.php`

**Interfaces:**
- **Consumes:** `ThemeRegistry::active()/chain()` (Task 3), `ModuleRegistry::discover()`, `Fixtures` (Task 3).
- **Produces `TemplateLocator(ThemeRegistry, ModuleRegistry, ?string $engine = null, ?bool $twigInstalled = null)`:**
  - `EXTENSIONS = ['php', 'twig']`. An `$engine` outside them throws `ViewException`. `$twigInstalled` defaults to `class_exists(\Twig\Environment::class)`.
  - `preferred(): string`: `$engine` or the active theme's `engine`, but `php` when that says `twig` and Twig is not installed.
  - `static parse(string $name): array{0: ?string, 1: string}`, returning the module (or null) and the relative path. A bad name throws `ViewException`.
  - `directories(string $name): list<string>`, in lookup order:
    - for `module::path`: `<theme>/templates/modules/<module>` for each theme in the chain, then `<module>/templates` when the module is discovered;
    - for a plain `path`: `<theme>/templates` for each theme in the chain.
  - `find(string $name, ?string $extension = null): ?string`, memoised. In the first directory holding any candidate it returns `<path>.<ext>`, trying only `$extension` when it is given, else `preferred()` first and the other extension second.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/View/TemplateLocatorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\TemplateLocator;
use Xaraya\Kernel\View\ThemeRegistry;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class TemplateLocatorTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $themes = $this->tmp . '/themes';
        Fixtures::theme($themes, 'base', ['engine' => 'php'], [
            'templates/layout.php' => 'base layout php',
            'templates/layout.twig' => 'base layout twig',
            'templates/error/404.php' => '404',
            'templates/modules/shop/item.twig' => 'base shop item twig',
        ]);
        Fixtures::theme($themes, 'kid', ['parent' => 'base'], [
            'templates/modules/shop/item.php' => 'kid shop item php',
            'templates/only-kid.twig' => 'kid only',
        ]);
        Fixtures::module($this->tmp . '/modules', 'shop', [], [
            'templates/item.php' => 'module item',
            'templates/list.php' => 'module list',
            'templates/partials/row.twig' => 'module row',
        ]);
    }

    private function locator(?string $engine = null, bool $twig = true): TemplateLocator
    {
        $c = $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'kid'])->container();

        return new TemplateLocator($c->get(ThemeRegistry::class), $c->get(ModuleRegistry::class), $engine, $twig);
    }

    private function path(string $relative): string
    {
        return realpath($this->tmp) . '/' . $relative;
    }

    public function testActiveThemeOverridesItsParentAndTheModule(): void
    {
        self::assertSame($this->path('themes/kid/templates/modules/shop/item.php'), $this->locator()->find('shop::item'));
    }

    public function testExtensionFilterSkipsToTheFirstMatchingFile(): void
    {
        $locator = $this->locator();
        self::assertSame($this->path('themes/base/templates/modules/shop/item.twig'), $locator->find('shop::item', 'twig'));
        self::assertSame($this->path('modules/shop/templates/partials/row.twig'), $locator->find('shop::partials/row', 'twig'));
        self::assertNull($locator->find('shop::partials/row', 'php'));
    }

    public function testModuleTemplatesAreTheLastResort(): void
    {
        self::assertSame($this->path('modules/shop/templates/list.php'), $this->locator()->find('shop::list'));
        self::assertNull($this->locator()->find('shop::nothing'));
    }

    public function testThemeLevelTemplatesFollowTheChainAndThePreference(): void
    {
        self::assertSame($this->path('themes/base/templates/layout.php'), $this->locator()->find('layout'));
        self::assertSame($this->path('themes/base/templates/layout.twig'), $this->locator('twig')->find('layout'));
        self::assertSame($this->path('themes/base/templates/layout.php'), $this->locator('twig', twig: false)->find('layout'), 'without Twig a .php sibling wins');
        self::assertSame($this->path('themes/kid/templates/only-kid.twig'), $this->locator(twig: false)->find('only-kid'), 'a lone .twig file is still found');
        self::assertSame($this->path('themes/base/templates/error/404.php'), $this->locator()->find('error/404'));
    }

    public function testPreferredEngine(): void
    {
        self::assertSame('php', $this->locator()->preferred());
        self::assertSame('twig', $this->locator('twig')->preferred());
        self::assertSame('php', $this->locator('twig', twig: false)->preferred());
    }

    public function testDirectoriesListTheLookupOrder(): void
    {
        $locator = $this->locator();
        self::assertSame([
            $this->path('themes/kid/templates/modules/shop'),
            $this->path('themes/base/templates/modules/shop'),
            $this->path('modules/shop/templates'),
        ], $locator->directories('shop::item'));
        self::assertSame([$this->path('themes/kid/templates'), $this->path('themes/base/templates')], $locator->directories('error/404'));
        self::assertSame([
            $this->path('themes/kid/templates/modules/ghost'),
            $this->path('themes/base/templates/modules/ghost'),
        ], $locator->directories('ghost::x'));
    }

    public function testInvalidNamesAndEnginesThrow(): void
    {
        foreach (['../secret', 'shop::', 'a//b', 'Shop::x', 'shop::../x', 'x.php', ''] as $name) {
            try {
                $this->locator()->find($name);
                self::fail("'{$name}' should be rejected");
            } catch (ViewException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(ViewException::class);
        $this->locator('smarty');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/View/TemplateLocatorTest.php`
Expected: errors, `TemplateLocator` not found.

- [ ] **Step 3: Implement**

`src/Kernel/View/TemplateLocator.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Xaraya\Kernel\Module\ModuleRegistry;

/**
 * Resolves template names to files. "module::path" is a module template: the active theme chain's
 * templates/modules/<module>/ first, then the module's own templates/. A plain "path" is a theme-level
 * template (layout, block, home, error/404) looked up in each theme's templates/. In one directory holding
 * both a .twig and a .php file, preferred() wins.
 */
final class TemplateLocator
{
    public const EXTENSIONS = ['php', 'twig'];

    private const NAME = '/^(?:([a-z][a-z0-9_-]*)::)?([A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*)$/D';

    /** @var array<string, ?string> */
    private array $found = [];

    private readonly bool $twigInstalled;

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly ModuleRegistry $modules,
        private readonly ?string $engine = null,
        ?bool $twigInstalled = null,
    ) {
        if ($engine !== null && !in_array($engine, self::EXTENSIONS, true)) {
            throw new ViewException("view.engine must be 'php' or 'twig', not '{$engine}'");
        }
        $this->twigInstalled = $twigInstalled ?? class_exists(\Twig\Environment::class);
    }

    public function preferred(): string
    {
        $preferred = $this->engine ?? $this->themes->active()->engine;

        return $preferred === 'twig' && !$this->twigInstalled ? 'php' : $preferred;
    }

    /** @return array{0: ?string, 1: string} the module (null for theme-level templates) and the relative path */
    public static function parse(string $name): array
    {
        if (preg_match(self::NAME, $name, $m) !== 1) {
            throw new ViewException("Invalid template name '{$name}'");
        }

        return [$m[1] === '' ? null : $m[1], $m[2]];
    }

    /** @return list<string> the directories searched for $name, in lookup order */
    public function directories(string $name): array
    {
        [$module] = self::parse($name);
        $dirs = [];
        foreach ($this->themes->chain() as $theme) {
            $dirs[] = $theme->path . '/templates' . ($module === null ? '' : '/modules/' . $module);
        }
        if ($module !== null) {
            $manifest = $this->modules->discover()[$module] ?? null;
            if ($manifest !== null) {
                $dirs[] = $manifest->path . '/templates';
            }
        }

        return $dirs;
    }

    /** The first file for $name: of $extension only, or of either engine with preferred() breaking ties. */
    public function find(string $name, ?string $extension = null): ?string
    {
        $key = $name . '|' . ($extension ?? '');
        if (array_key_exists($key, $this->found)) {
            return $this->found[$key];
        }
        if ($extension !== null && !in_array($extension, self::EXTENSIONS, true)) {
            throw new ViewException("Unknown template extension '{$extension}'");
        }
        $path = self::parse($name)[1];
        $order = $extension !== null ? [$extension] : array_values(array_unique([$this->preferred(), ...self::EXTENSIONS]));
        foreach ($this->directories($name) as $dir) {
            foreach ($order as $ext) {
                $file = $dir . '/' . $path . '.' . $ext;
                if (is_file($file)) {
                    return $this->found[$key] = $file;
                }
            }
        }

        return $this->found[$key] = null;
    }
}
```

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\View\TemplateLocator;`.
- Before `$this->bootModules();`, add:
```php
        $c->set(TemplateLocator::class, function (Container $c) use ($config): TemplateLocator {
            $engine = $config->get('view.engine');

            return new TemplateLocator(
                $c->get(ThemeRegistry::class),
                $c->get(ModuleRegistry::class),
                is_string($engine) && $engine !== '' ? $engine : null,
            );
        });
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Kernel/View/TemplateLocatorTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): resolve module and theme templates through the theme chain" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Engines, helpers and `View::render`

**Files:**
- Create:
  - `src/Kernel/Auth/Access.php`, `src/Kernel/Auth/GuestAccess.php`
  - `src/Kernel/View/Engine.php`, `src/Kernel/View/PhpEngine.php`, `src/Kernel/View/TwigEngine.php`, `src/Kernel/View/TwigLoader.php`
  - `src/Kernel/View/Helpers.php`, `src/Kernel/View/View.php`
  - `tests/Support/Html.php`, `tests/Support/ViewFixtures.php`
- Modify: `src/Kernel/App.php` (bind `Access`, `TwigEngine`, `View`), `phpstan.neon.dist`, `.php-cs-fixer.dist.php`
- Test: `tests/Kernel/View/ViewTest.php`

**Interfaces:**
- **Consumes:** `TemplateLocator` (Task 6), `Translator` (Task 4), `DisplayHooks` (Task 5), `UrlGenerator`, `Config`.
- **Produces `interface Access`:**
  - `LEVELS = ['none' => 0, 'overview' => 100, 'read' => 200, 'comment' => 300, 'moderate' => 400, 'edit' => 500, 'add' => 600, 'delete' => 700, 'admin' => 800]`
  - `can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool`
  - `user(): ?object`
  - `roles(): list<string>`
- **Produces `GuestAccess implements Access`:**
  - `can()` is true for levels ≤ read and throws `InvalidArgumentException` for an unknown level.
  - `user()` returns null, and `roles()` returns `['Anonymous']`.
  - It is bound as `Access::class`. **Plan 3 replaces this binding.**
- **Produces `interface Engine`:** `render(string $name, string $path, array<string, mixed> $data, Helpers $x): string`.
- **Produces `PhpEngine`:** it extracts `$data` (it never overwrites `$x`), exposes the helpers as `$x`, and discards its output buffers if the template throws.
- **Produces `TwigEngine(TemplateLocator $locator, ?string $cacheDir = null, bool $debug = false, ?bool $installed = null)`:**
  - The environment is lazy, with `autoescape: html`, `strict_variables: true`, and a cache only outside debug.
  - It registers one Twig function per `Helpers::TWIG` entry. They take positional arguments.
  - When Twig is missing, it throws `ViewException` saying `composer require twig/twig`.
- **Produces `TwigLoader(TemplateLocator)`:** it resolves names through `find($name, 'twig')`.
- **Produces `Helpers(View $view, Container $container, ?ServerRequestInterface $request = null)`:**
  - `TWIG` constant: name → returns HTML.
  - Accessors: `view(): View`, `request(): ?ServerRequestInterface`.
  - `url(string $name, array<string, string|int> $params = [], bool $absolute = false): string`
  - `asset(string $path): string`: `{assets.url|'/assets'}/{path}`, rejecting `..`
  - `can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool`
  - `hooks(string $hook, array<string, mixed> $item, array<string, mixed> $input = []): string`
  - `csrf(): string` (returns `''` until Plan 3)
  - `t(string $key, array<string, mixed> $params = []): string`
  - `e(mixed $value): string` (null gives `''`; non-scalars throw)
  - `user(): ?object`
  - `render(string $name, array<string, mixed> $data = []): string` (crosses engines)
  - `include(string $name, array<string, mixed> $data = []): string` (PHP only)
  - `config(string $key, mixed $default = null): mixed`
  - Task 9 adds `blocks(string $region): string`.
- **Produces `View(TemplateLocator, PhpEngine, TwigEngine, Container)`:**
  - `render(string $name, array<string, mixed> $data = [], ?ServerRequestInterface $request = null): string`
  - `renderWith(string $name, array<string, mixed> $data, Helpers $x, ?string $engine = null): string`. A template that is not found throws `ViewException("Template '<name>' not found in: <dirs>")`.
  - `exists(string $name): bool`
  - `helpers(?ServerRequestInterface $request = null): Helpers`
- **Produces test helpers:**
  - `Html::normalize(string): string`.
  - `ViewFixtures::themes(string $base)` writes the themes `plain` (engine php, regions header/sidebar/footer, setting `tagline` with default `Hi`, no `block` template) and `fancy` (parent plain, engine twig, a `layout` that renders the sidebar, and a `block` template).
  - `ViewFixtures::shop(string $base)` writes the module `shop`, which has:
    - routes `shop.list` `/shop`, `shop.boom` `/shop/boom`, `shop.item` `/shop/{id}` and `shop.gone` `/shop/{id}/gone`;
    - templates `item` and `list`;
    - block types `shop.note` and `shop.broken`, plus a seeded `shop.note` block.
  - Some fixture classes use `Page` (Task 8) and `Block` (Task 9). They are written as files and loaded only when a later task's test uses them.

- [ ] **Step 1: Write the test helpers**

`tests/Support/Html.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

final class Html
{
    /** Collapses whitespace runs and removes whitespace next to tags, for engine-parity comparisons. */
    public static function normalize(string $html): string
    {
        $html = preg_replace('/\s+/u', ' ', $html) ?? $html;
        $html = preg_replace('/\s*(<[^>]*>)\s*/u', '$1', $html) ?? $html;

        return trim($html);
    }
}
```

`tests/Support/ViewFixtures.php`:
```php
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
                . '|<?= $x->e($x->config(\'app.url\')) ?>|<?= $x->user() === null ? \'guest\' : \'user\' ?>|<?= $x->csrf() ?>|<?= $x->e(\'<b>\') ?>|<?= $x->e(\'<i>\') ?>',
            'templates/helpers.twig' => '<a href="{{ url(\'shop.item\', {id: 7}) }}">{{ t(\'Item {id}\', {id: 7}) }}</a>'
                . '|{{ asset(\'theme/plain/site.css\') }}|{{ can(\'read\') ? \'r\' : \'\' }}{{ can(\'edit\') ? \'e\' : \'\' }}'
                . '|{{ config(\'app.url\') }}|{{ user() is null ? \'guest\' : \'user\' }}|{{ csrf() }}|{{ e(\'<b>\') }}|{{ \'<i>\' }}',
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
```

- [ ] **Step 2: Write the failing test**

`tests/Kernel/View/ViewTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\GuestAccess;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\TemplateLocator;
use Xaraya\Kernel\View\TwigEngine;
use Xaraya\Kernel\View\View;
use Xaraya\Kernel\View\ViewException;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\ViewFixtures;

final class ViewTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ViewFixtures::themes($this->tmp . '/themes');
        ViewFixtures::shop($this->tmp . '/modules');
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'plain', ...$overrides]);
    }

    private function view(string $engine = 'php'): View
    {
        return $this->app(['view.engine' => $engine])->container()->get(View::class);
    }

    public function testRendersPhpAndTwigTemplatesWithTheSameOutput(): void
    {
        foreach (['php', 'twig'] as $engine) {
            self::assertSame('<p>&lt;Ada&gt;</p>', trim($this->view($engine)->render('hello', ['name' => '<Ada>'])), $engine);
        }
    }

    public function testHelpersBehaveTheSameInBothEngines(): void
    {
        $this->app()->container()->get(ModuleRegistry::class)->enable('shop');
        $expected = '<a href="/shop/7">Item 7</a>|/assets/theme/plain/site.css|r|http://xar.test|guest||&lt;b&gt;|&lt;i&gt;';
        foreach (['php', 'twig'] as $engine) {
            self::assertSame($expected, trim($this->view($engine)->render('helpers')), $engine);
        }
    }

    public function testIncludeStaysInItsEngineAndRenderCrossesEngines(): void
    {
        foreach (['php', 'twig'] as $engine) {
            self::assertSame('<div><b>v</b></div>', trim($this->view($engine)->render('page', ['v' => 'v'])), $engine);
        }
        self::assertSame('<i><u>x</u></i>', trim($this->view()->render('mixed', ['v' => 'x'])));
    }

    public function testModuleTemplatesRender(): void
    {
        self::assertSame('<p>Item 3</p>', trim($this->view('twig')->render('shop::item', ['id' => 3])));
        self::assertTrue($this->view()->exists('shop::list'));
        self::assertFalse($this->view()->exists('shop::nothing'));
    }

    public function testMissingTemplateNamesTheDirectoriesSearched(): void
    {
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage("Template 'nope' not found in: ");
        $this->view()->render('nope');
    }

    public function testPhpTemplateFailureDiscardsItsOutputBuffer(): void
    {
        $level = ob_get_level();
        try {
            $this->view()->render('boom');
            self::fail('expected the template to throw');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame($level, ob_get_level());
    }

    public function testTwigMissingGivesAClearException(): void
    {
        $c = $this->app()->container();
        $engine = new TwigEngine($c->get(TemplateLocator::class), null, true, installed: false);
        $this->expectException(ViewException::class);
        $this->expectExceptionMessage('composer require twig/twig');
        $engine->render('hello', $this->tmp . '/themes/plain/templates/hello.twig', ['name' => 'x'], $c->get(View::class)->helpers());
    }

    public function testGuestAccessAllowsReadingOnly(): void
    {
        $access = new GuestAccess();
        self::assertTrue($access->can('overview'));
        self::assertTrue($access->can('read', 'blog', 'post'));
        self::assertFalse($access->can('comment'));
        self::assertFalse($access->can('admin'));
        self::assertNull($access->user());
        self::assertSame(['Anonymous'], $access->roles());
        $this->expectException(\InvalidArgumentException::class);
        $access->can('superuser');
    }

    public function testEscapeAndAssetGuards(): void
    {
        $x = $this->view()->helpers();
        self::assertSame('', $x->e(null));
        self::assertSame('&quot;a&quot; &amp; &#039;b&#039;', $x->e('"a" & \'b\''));
        try {
            $x->e(['array']);
            self::fail('arrays cannot be escaped');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(\InvalidArgumentException::class);
        $x->asset('../config/app.php');
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/View/ViewTest.php`
Expected: errors, `View` and `GuestAccess` not found.

- [ ] **Step 4: Implement access, engines, helpers and the view**

`src/Kernel/Auth/Access.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

/** Privilege checks for the current request (kernel spec §4.3). Plan 3 binds the real implementation. */
interface Access
{
    public const LEVELS = [
        'none' => 0, 'overview' => 100, 'read' => 200, 'comment' => 300, 'moderate' => 400,
        'edit' => 500, 'add' => 600, 'delete' => 700, 'admin' => 800,
    ];

    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool;

    /** The signed-in user, or null for a guest. */
    public function user(): ?object;

    /** @return list<string> the current request's role names (block visibility uses them) */
    public function roles(): array;
}
```

`src/Kernel/Auth/GuestAccess.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use InvalidArgumentException;

/**
 * Kernel Plan 2 has no users: every request is an anonymous guest who may read. Plan 3 replaces the Access
 * binding with real users, roles and grants.
 */
final class GuestAccess implements Access
{
    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool
    {
        $required = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown access level '{$level}'");

        return $required <= self::LEVELS['read'];
    }

    public function user(): ?object
    {
        return null;
    }

    public function roles(): array
    {
        return ['Anonymous'];
    }
}
```

`src/Kernel/View/Engine.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

interface Engine
{
    /** @param array<string, mixed> $data */
    public function render(string $name, string $path, array $data, Helpers $x): string;
}
```

`src/Kernel/View/PhpEngine.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

/**
 * Plain PHP templates. Variables arrive directly, helpers as $x. Nothing is escaped automatically:
 * every value must go through $x->e().
 */
final class PhpEngine implements Engine
{
    public function render(string $name, string $path, array $data, Helpers $x): string
    {
        unset($data['this']);
        $level = ob_get_level();
        ob_start();
        try {
            (static function (string $__file, array $__data, Helpers $x): void {
                extract($__data, EXTR_SKIP);
                require $__file;
            })($path, $data, $x);

            return (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
```

`src/Kernel/View/TwigLoader.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

/** Resolves Twig template names through the TemplateLocator, .twig files only. */
final class TwigLoader implements LoaderInterface
{
    public function __construct(private readonly TemplateLocator $locator) {}

    public function getSourceContext(string $name): Source
    {
        $path = $this->path($name);

        return new Source((string) file_get_contents($path), $name, $path);
    }

    public function getCacheKey(string $name): string
    {
        return $this->path($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        $modified = @filemtime($this->path($name));

        return $modified !== false && $modified <= $time;
    }

    public function exists(string $name): bool
    {
        try {
            return $this->locator->find($name, 'twig') !== null;
        } catch (ViewException) {
            return false;
        }
    }

    private function path(string $name): string
    {
        try {
            $path = $this->locator->find($name, 'twig');
        } catch (ViewException $e) {
            throw new LoaderError($e->getMessage());
        }

        return $path ?? throw new LoaderError("Twig template '{$name}' not found in: " . implode(', ', $this->locator->directories($name)));
    }
}
```

`src/Kernel/View/TwigEngine.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use LogicException;
use Twig\Environment;
use Twig\TwigFunction;

/** Twig templates (optional: needs twig/twig). Helpers are Twig functions of the same names. */
final class TwigEngine implements Engine
{
    private ?Environment $twig = null;

    private ?Helpers $current = null;

    private readonly bool $installed;

    public function __construct(
        private readonly TemplateLocator $locator,
        private readonly ?string $cacheDir = null,
        private readonly bool $debug = false,
        ?bool $installed = null,
    ) {
        $this->installed = $installed ?? class_exists(Environment::class);
    }

    public function render(string $name, string $path, array $data, Helpers $x): string
    {
        $twig = $this->environment($path);
        $previous = $this->current;
        $this->current = $x;
        try {
            return $twig->render($name, $data);
        } finally {
            $this->current = $previous;
        }
    }

    private function environment(string $path): Environment
    {
        if (!$this->installed) {
            throw new ViewException("Cannot render {$path}: .twig templates need twig/twig, which is not installed. Run `composer require twig/twig`, or provide a .php template.");
        }
        if ($this->twig !== null) {
            return $this->twig;
        }
        $twig = new Environment(new TwigLoader($this->locator), [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $this->debug || $this->cacheDir === null ? false : $this->cacheDir,
            'auto_reload' => true,
            'debug' => $this->debug,
        ]);
        foreach (Helpers::TWIG as $function => $html) {
            $twig->addFunction(new TwigFunction(
                $function,
                fn(mixed ...$args): mixed => $this->helpers()->{$function}(...$args),
                $html ? ['is_safe' => ['html']] : [],
            ));
        }

        return $this->twig = $twig;
    }

    private function helpers(): Helpers
    {
        return $this->current ?? throw new LogicException('A Twig helper was called outside a render');
    }
}
```

`src/Kernel/View/Helpers.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Stringable;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Routing\UrlGenerator;

/**
 * The helper set both engines share: $x->name() in PHP templates, name() in Twig (positional arguments).
 * Services are resolved lazily, so a template that uses no helper touches no database.
 */
final class Helpers
{
    /** Twig function name => whether it returns HTML (is_safe). */
    public const TWIG = [
        'url' => false,
        'asset' => false,
        'can' => false,
        'hooks' => true,
        'csrf' => true,
        't' => false,
        'e' => true,
        'user' => false,
        'render' => true,
        'config' => false,
    ];

    public function __construct(
        private readonly View $view,
        private readonly Container $container,
        private readonly ?ServerRequestInterface $request = null,
    ) {}

    public function view(): View
    {
        return $this->view;
    }

    public function request(): ?ServerRequestInterface
    {
        return $this->request;
    }

    /** @param array<string, string|int> $params */
    public function url(string $name, array $params = [], bool $absolute = false): string
    {
        return $this->container->get(UrlGenerator::class)->generate($name, $params, $absolute);
    }

    /** The URL of a published asset, e.g. asset('theme/phoenix/phoenix.css'). */
    public function asset(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException("Invalid asset path '{$path}'");
        }

        return rtrim((string) $this->config('assets.url', '/assets'), '/') . '/' . $path;
    }

    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool
    {
        return $this->container->get(Access::class)->can($level, $module, $component, $item);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $input
     */
    public function hooks(string $hook, array $item, array $input = []): string
    {
        return $this->container->get(DisplayHooks::class)->call($hook, $item, $input);
    }

    /** The CSRF hidden field. A stub until Plan 3 adds sessions: it returns ''. */
    public function csrf(): string
    {
        return '';
    }

    /** @param array<string, mixed> $params */
    public function t(string $key, array $params = []): string
    {
        return $this->container->get(Translator::class)->t($key, $params);
    }

    public function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_scalar($value) && !$value instanceof Stringable) {
            throw new InvalidArgumentException('e() needs a scalar or Stringable, got ' . get_debug_type($value));
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function user(): ?object
    {
        return $this->container->get(Access::class)->user();
    }

    /**
     * Renders another template in whichever engine its file uses.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $name, array $data = []): string
    {
        return $this->view->renderWith($name, $data, $this);
    }

    /**
     * PHP templates only: renders a .php template, the PHP twin of Twig's include.
     *
     * @param array<string, mixed> $data
     */
    public function include(string $name, array $data = []): string
    {
        return $this->view->renderWith($name, $data, $this, 'php');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->container->get(Config::class)->get($key, $default);
    }
}
```

`src/Kernel/View/View.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Container\Container;

final class View
{
    public function __construct(
        private readonly TemplateLocator $locator,
        private readonly PhpEngine $php,
        private readonly TwigEngine $twig,
        private readonly Container $container,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $name, array $data = [], ?ServerRequestInterface $request = null): string
    {
        return $this->renderWith($name, $data, $this->helpers($request));
    }

    /**
     * @param array<string, mixed> $data
     * @param string|null $engine 'php' or 'twig' to stay in one engine; null lets the file found decide
     */
    public function renderWith(string $name, array $data, Helpers $x, ?string $engine = null): string
    {
        $path = $this->locator->find($name, $engine)
            ?? throw new ViewException("Template '{$name}'" . ($engine === null ? '' : " ({$engine})") . ' not found in: ' . implode(', ', $this->locator->directories($name)));

        return (str_ends_with($path, '.twig') ? $this->twig : $this->php)->render($name, $path, $data, $x);
    }

    public function exists(string $name): bool
    {
        return $this->locator->find($name) !== null;
    }

    public function helpers(?ServerRequestInterface $request = null): Helpers
    {
        return new Helpers($this, $this->container, $request);
    }
}
```

In `src/Kernel/App.php`:
- Add these imports: `use Xaraya\Kernel\Auth\Access;`, `use Xaraya\Kernel\Auth\GuestAccess;`, `use Xaraya\Kernel\View\PhpEngine;`, `use Xaraya\Kernel\View\TwigEngine;` and `use Xaraya\Kernel\View\View;`.
- Before `$this->bootModules();`, add:
```php
        $c->set(Access::class, fn(): Access => new GuestAccess());
        $c->set(TwigEngine::class, fn(Container $c): TwigEngine => new TwigEngine(
            $c->get(TemplateLocator::class),
            $this->cacheDir() . '/twig',
            $this->debug(),
        ));
        $c->set(View::class, fn(Container $c): View => new View(
            $c->get(TemplateLocator::class),
            new PhpEngine(),
            $c->get(TwigEngine::class),
            $c,
        ));
```

Keep templates out of static analysis and style checks. They are HTML with PHP tags, and their variables come from `extract()`.
- In `phpstan.neon.dist`, add under `parameters:`:
```yaml
    excludePaths:
        - examples/*/templates/*
        - modules/*/templates/*
```
- In `.php-cs-fixer.dist.php`, change the finder line to:
```php
$finder = (new PhpCsFixer\Finder())->in($dirs)->notPath('#(^|/)templates/#');
```

- [ ] **Step 5: Run the test**

Run: `vendor/bin/phpunit tests/Kernel/View/ViewTest.php`
Expected: PASS (9 tests).

- [ ] **Step 6: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add PHP and Twig engines with a shared helper set" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Pages, the layout, and controllers returning `Page`

**Files:**
- Create: `src/Kernel/View/Page.php`
- Modify: `src/Kernel/View/View.php` (`page()`, `themeSettings()`), `src/Kernel/Http/Controller.php` (`view()`), `src/Kernel/Http/RouteHandler.php` (render `Page` results)
- Test: `tests/Kernel/View/PageTest.php`

**Interfaces:**
- **Consumes:** `View::renderWith/helpers` (Task 7), `ThemeRegistry::settingDefaults/active` (Task 3), `Settings::all` (Task 2), `ViewFixtures`/`Html` (Task 7).
- **Produces `Page`**, with readonly constructor fields in this order:
  - `string $template`
  - `array<string, mixed> $data = []`
  - `string $title = ''`
  - `array<string, string> $meta = []`. Names starting `og:` are written as `property=`.
  - `list<array{type: string, title: string, href: string}> $feeds = []`
  - `?string $canonical = null`
  - `?string $lang = null`
  - `list<string> $styles = []`
  - `int $status = 200`
  - `array<string, string> $headers = []`
- **Produces `View::page(Page $page, ?ServerRequestInterface $request = null): string`.** It renders the body, then the theme-level `layout` template with these variables:
  - `content` (HTML), `title` (the page title, or `app.name` when empty), `site` (`app.name`)
  - `lang` (`$page->lang` or `app.locale`, with `_` turned into `-`)
  - `meta`, `feeds`, `canonical`, `styles`
  - `settings` (`themeSettings()`)
- **Produces `View::themeSettings(): array<string, mixed>`:** the theme chain's setting defaults, overridden by `Settings` scope `theme.<active name>`. Only keys the schema declares are used.
- **Produces `Controller::view(string $template, array<string, mixed> $data = [], string $title = '', int $status = 200): Page`.**
- **`RouteHandler` behaviour:** a handler may return a `Page`. It becomes `text/html; charset=utf-8` with `$page->status` and `$page->headers`.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/View/PageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Settings\Settings;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Html;
use Xaraya\Tests\Support\ViewFixtures;

final class PageTest extends AppTestCase
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
        return $this->boot([$this->tmp . '/modules'], [
            'themes.paths' => [$this->tmp . '/themes'],
            'app.theme' => 'plain',
            'app.name' => 'Test Site',
            'app.locale' => 'en',
            ...$overrides,
        ]);
    }

    public function testControllersReturnPagesWrappedInTheThemeLayout(): void
    {
        $response = $this->app()->handle(new ServerRequest('GET', '/shop/7'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('1', $response->getHeaderLine('X-Shop'));
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('<title>Item</title>', $html);
        self::assertStringContainsString('<meta name="description" content="An item">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Item 7">', $html);
        self::assertStringContainsString('<body data-tagline="Hi"><p>Item 7</p></body>', $html);
    }

    public function testControllerViewHelperSetsTitleAndStatus(): void
    {
        $app = $this->app();
        self::assertStringContainsString('<title>Shop</title>', (string) $app->handle(new ServerRequest('GET', '/shop'))->getBody());
        $gone = $app->handle(new ServerRequest('GET', '/shop/7/gone'));
        self::assertSame(410, $gone->getStatusCode());
        self::assertStringContainsString('<title>Gone</title>', (string) $gone->getBody());
    }

    public function testEmptyTitleFallsBackToTheSiteName(): void
    {
        $html = $this->app()->container()->get(View::class)->page(new Page('hello', ['name' => 'x']));
        self::assertStringContainsString('<title>Test Site</title>', $html);
    }

    public function testStoredThemeSettingsOverrideDefaults(): void
    {
        $app = $this->app();
        $settings = $app->container()->get(Settings::class);
        $settings->set('theme.plain', 'tagline', 'Yo');
        $settings->set('theme.plain', 'unknown', 'ignored');
        self::assertSame(['tagline' => 'Yo'], $this->app()->container()->get(View::class)->themeSettings());
        self::assertStringContainsString('data-tagline="Yo"', (string) $this->app()->handle(new ServerRequest('GET', '/shop/7'))->getBody());
    }

    public function testLangComesFromThePageOrTheLocale(): void
    {
        $view = $this->app(['app.locale' => 'pt_BR'])->container()->get(View::class);
        self::assertStringContainsString('<html lang="pt-BR">', $view->page(new Page('hello', ['name' => 'x'])));
        self::assertStringContainsString('<html lang="fr">', $view->page(new Page('hello', ['name' => 'x'], lang: 'fr')));
    }

    public function testBothEnginesProduceTheSamePage(): void
    {
        $bodies = [];
        foreach (['php', 'twig'] as $engine) {
            $bodies[$engine] = Html::normalize((string) $this->app(['view.engine' => $engine])->handle(new ServerRequest('GET', '/shop/7'))->getBody());
        }
        self::assertSame($bodies['php'], $bodies['twig']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/View/PageTest.php`
Expected: errors, `Class "Xaraya\Kernel\View\Page" not found`.

- [ ] **Step 3: Implement**

`src/Kernel/View/Page.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

/** What a controller returns for an HTML page: RouteHandler renders it through the theme layout. */
final class Page
{
    /**
     * @param array<string, mixed> $data body template variables
     * @param array<string, string> $meta <meta> tags; names starting "og:" are written as property=
     * @param list<array{type: string, title: string, href: string}> $feeds <link rel="alternate"> entries
     * @param list<string> $styles extra stylesheet URLs, after the theme's own
     * @param array<string, string> $headers extra response headers
     */
    public function __construct(
        public readonly string $template,
        public readonly array $data = [],
        public readonly string $title = '',
        public readonly array $meta = [],
        public readonly array $feeds = [],
        public readonly ?string $canonical = null,
        public readonly ?string $lang = null,
        public readonly array $styles = [],
        public readonly int $status = 200,
        public readonly array $headers = [],
    ) {}
}
```

In `src/Kernel/View/View.php`, add `use Xaraya\Kernel\Settings\Settings;` and these methods:
```php
    /** Renders the page body, then the theme's "layout" around it. */
    public function page(Page $page, ?ServerRequestInterface $request = null): string
    {
        $x = $this->helpers($request);
        $content = $this->renderWith($page->template, $page->data, $x);
        $site = (string) $x->config('app.name', 'Xaraya Phoenix');

        return $this->renderWith('layout', [
            'content' => $content,
            'title' => $page->title !== '' ? $page->title : $site,
            'site' => $site,
            'lang' => str_replace('_', '-', $page->lang ?? (string) $x->config('app.locale', 'en')),
            'meta' => $page->meta,
            'feeds' => $page->feeds,
            'canonical' => $page->canonical,
            'styles' => $page->styles,
            'settings' => $this->themeSettings(),
        ], $x);
    }

    /** @return array<string, mixed> theme.json defaults (parents first), overridden by stored "theme.<name>" settings */
    public function themeSettings(): array
    {
        $themes = $this->container->get(ThemeRegistry::class);
        $defaults = $themes->settingDefaults();
        $stored = $this->container->get(Settings::class)->all('theme.' . $themes->active()->name);

        return [...$defaults, ...array_intersect_key($stored, $defaults)];
    }
```

In `src/Kernel/Http/Controller.php`, add `use Xaraya\Kernel\View\Page;` and:
```php
    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], string $title = '', int $status = 200): Page
    {
        return new Page($template, $data, $title, status: $status);
    }
```

In `src/Kernel/Http/RouteHandler.php`:
- Add `use Xaraya\Kernel\View\Page;` and `use Xaraya\Kernel\View\View;`.
- In `invoke()`, before the `if (is_string($result))` check, add:
```php
        if ($result instanceof Page) {
            $response = Controller::htmlResponse($this->container->get(View::class)->page($result, $request), $result->status);
            foreach ($result->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        }
```
- Change the final exception message to `'Controllers must return a ResponseInterface, a Page or a string'`.

- [ ] **Step 4: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Kernel/View`, then repeat with the MySQL env vars.
Expected: PASS on both.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): render controller pages inside the theme layout" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Blocks: instances, visibility, regions and module block types

**Files:**
- Create:
  - `src/Kernel/migrations/2026_10_09_000003_create_blocks.php`
  - `src/Kernel/Blocks/Block.php`, `src/Kernel/Blocks/ConfigurableBlock.php`, `src/Kernel/Blocks/BlockContext.php`, `src/Kernel/Blocks/BlockInstance.php`
  - `src/Kernel/Blocks/BlockRepository.php`, `src/Kernel/Blocks/BlockTypes.php`, `src/Kernel/Blocks/BlockRenderer.php`
- Modify:
  - `src/Kernel/Module/Manifest.php` (`blocks()`, `blockDefaults()`)
  - `src/Kernel/View/Helpers.php` (`blocks()`)
  - `src/Kernel/App.php` (bind `BlockTypes` and `BlockRenderer`, seed `blockDefaults` on first install)
- Test: `tests/Kernel/Blocks/BlocksTest.php`, `tests/Kernel/Module/ManifestViewKeysTest.php` (two new tests)

**Interfaces:**
- **Consumes:** `Helpers`/`View` (Task 7), `Page` (Task 8), `Access::roles()` (Task 7), `ModuleRegistry::onEnable` (Task 5), `ViewFixtures`/`Html` (Task 7).
- **Produces the table `blocks`:** `id` increments, `type` string(128), `region` string(64), `title` string(255) nullable, `config` json, `sort` int default 0, `visibility` json, `enabled` bool default true, with index `(region, sort)`.
- **Produces `interface Block`:** `render(array<string, mixed> $config, BlockContext $context): string`. It returns the inner HTML, or `''` to hide the block.
- **Produces `interface ConfigurableBlock extends Block`:** `form(array<string, mixed> $config): string` and `validate(array<string, mixed> $input): array<string, mixed>`. The admin UI that uses them arrives in Plan 3.
- **Produces `BlockContext`**, with readonly fields `BlockInstance $block`, `Helpers $helpers`, `?string $routeName` and `array<string, string> $params`, plus `request(): ?ServerRequestInterface`.
- **Produces `BlockInstance`:**
  - Readonly fields: `int $id`, `string $type`, `string $region`, `?string $title`, `array<string, mixed> $config`, `int $sort`, `array{routes?: list<string>, roles?: list<string>} $visibility`, `bool $enabled`.
  - `static fromRow(array<string, mixed>): BlockInstance`
  - `static visibility(array<mixed>): array{routes?: list<string>, roles?: list<string>}`
  - `visibleFor(?string $routeName, list<string> $roles): bool`. Route patterns use `*` as a wildcard. A `routes` rule hides the block when there is no route (error pages). A `roles` rule needs an overlap.
- **Produces `BlockRepository(Connection)`** (autowired):
  - `create(string $type, string $region, ?string $title = null, array<string, mixed> $config = [], array $visibility = [], int $sort = 0, bool $enabled = true): int`
  - `find(int $id): ?BlockInstance`
  - `forRegion(string $region): list<BlockInstance>`: enabled blocks by `sort`, then `id`, and `[]` without the table
  - `setEnabled(int $id, bool $enabled): void`
  - `seed(Manifest $manifest): void`, which creates the manifest's `blockDefaults`
- **Produces `BlockTypes(Container $container, array<string, string> $types = [])`:** `register(string $type, string $class)`, `has(string $type): bool`, `all(): array<string, string>`, and `get(string $type): Block`.
- **Produces `BlockRenderer(BlockRepository, BlockTypes, Access, LoggerInterface, bool $debug = false)`:**
  - `region(string $region, Helpers $x): string`.
  - It skips hidden blocks, unknown types and blocks that render `''`.
  - A failing block is logged and skipped, or rethrown in debug mode.
  - Each block is wrapped by the theme-level `block` template, which gets `id`, `type`, `typeClass` (`block-` plus the type with non `[a-z0-9-]` characters turned into `-`), `title`, `region` and `content`. Without that template, the fallback is `<section class="block {typeClass}">` with an optional `<h2 class="block-title">`, ending in a newline.
- **Produces `Helpers::blocks(string $region): string`** (Twig `blocks`, HTML-safe).
- **Produces new `Manifest` methods:**
  - `blocks(): array<string, string>`: type → class, where the type must be `<module>.<name>`
  - `blockDefaults(): list<array{type: string, region: string, title: ?string, config: array<string, mixed>, visibility: array{routes?: list<string>, roles?: list<string>}, sort: int}>`
- **Produces the bindings:**
  - `BlockTypes` holds the enabled modules' `blocks()`. Task 10 adds the built-ins.
  - `BlockRenderer`.
  - `blockDefaults` are seeded when `$firstInstall` is true.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Blocks/BlocksTest.php`:
```php
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
}
```

Add to `tests/Kernel/Module/ManifestViewKeysTest.php`:
```php
    public function testBlocksAndBlockDefaults(): void
    {
        $m = Manifest::fromDirectory(Fixtures::module($this->tmp, 'shop', [
            'blocks' => ['shop.note' => 'S\\Note'],
            'blockDefaults' => [
                ['type' => 'shop.note', 'region' => 'sidebar', 'title' => 'Note', 'config' => ['text' => 'hi'], 'visibility' => ['routes' => ['shop.*'], 'extra' => true], 'sort' => 3],
                ['type' => 'text', 'region' => 'footer'],
            ],
        ]));
        self::assertSame(['shop.note' => 'S\\Note'], $m->blocks());
        self::assertSame([
            ['type' => 'shop.note', 'region' => 'sidebar', 'title' => 'Note', 'config' => ['text' => 'hi'], 'visibility' => ['routes' => ['shop.*']], 'sort' => 3],
            ['type' => 'text', 'region' => 'footer', 'title' => null, 'config' => [], 'visibility' => [], 'sort' => 0],
        ], $m->blockDefaults());
    }

    public function testBlockTypesMustBeNamespacedByTheModule(): void
    {
        $bad = [
            ['blocks' => ['other.note' => 'X']],
            ['blocks' => ['shop' => 'X']],
            ['blocks' => ['S\\Note']],
            ['blockDefaults' => [['region' => 'sidebar']]],
            ['blockDefaults' => [['type' => 'text', 'region' => 'footer', 'config' => 'nope']]],
        ];
        foreach ($bad as $i => $json) {
            $m = Manifest::fromDirectory(Fixtures::module($this->tmp . '/b' . $i, 'shop', $json));
            try {
                $m->blocks();
                $m->blockDefaults();
                self::fail('expected a ModuleException for ' . json_encode($json));
            } catch (ModuleException) {
                self::addToAssertionCount(1);
            }
        }
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Blocks tests/Kernel/Module/ManifestViewKeysTest.php`
Expected: errors, `BlockRepository` not found and `Manifest::blocks()` undefined.

- [ ] **Step 3: Implement**

`src/Kernel/migrations/2026_10_09_000003_create_blocks.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('blocks', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('type', 128);
            $t->string('region', 64);
            $t->string('title', 255)->nullable();
            $t->json('config');
            $t->int('sort')->default(0);
            $t->json('visibility');
            $t->bool('enabled')->default(true);
            $t->index('region', 'sort');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('blocks');
    }
};
```

`src/Kernel/Blocks/Block.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

interface Block
{
    /**
     * Returns the block's inner HTML, or '' to hide it. The theme's "block" template wraps it.
     *
     * @param array<string, mixed> $config
     */
    public function render(array $config, BlockContext $context): string;
}
```

`src/Kernel/Blocks/ConfigurableBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

/** A block type with an admin form; the admin UI that calls these arrives with Plan 3. */
interface ConfigurableBlock extends Block
{
    /** @param array<string, mixed> $config */
    public function form(array $config): string;

    /**
     * @param array<string, mixed> $input submitted form fields
     * @return array<string, mixed> the config to store
     * @throws \InvalidArgumentException on invalid input
     */
    public function validate(array $input): array;
}
```

`src/Kernel/Blocks/BlockContext.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\View\Helpers;

final class BlockContext
{
    /** @param array<string, string> $params the current route's parameters */
    public function __construct(
        public readonly BlockInstance $block,
        public readonly Helpers $helpers,
        public readonly ?string $routeName,
        public readonly array $params,
    ) {}

    public function request(): ?ServerRequestInterface
    {
        return $this->helpers->request();
    }
}
```

`src/Kernel/Blocks/BlockInstance.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

/** One row of xar_blocks. */
final class BlockInstance
{
    /**
     * @param array<string, mixed> $config
     * @param array{routes?: list<string>, roles?: list<string>} $visibility
     */
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $region,
        public readonly ?string $title,
        public readonly array $config,
        public readonly int $sort,
        public readonly array $visibility,
        public readonly bool $enabled,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['type'],
            (string) $row['region'],
            $row['title'] === null ? null : (string) $row['title'],
            self::json($row['config']),
            (int) $row['sort'],
            self::visibility(self::json($row['visibility'])),
            (bool) $row['enabled'],
        );
    }

    /**
     * @param array<mixed> $value
     * @return array{routes?: list<string>, roles?: list<string>}
     */
    public static function visibility(array $value): array
    {
        $out = [];
        if (isset($value['routes'])) {
            $out['routes'] = self::strings($value['routes']);
        }
        if (isset($value['roles'])) {
            $out['roles'] = self::strings($value['roles']);
        }

        return $out;
    }

    /**
     * A "routes" rule needs the current route name to match one pattern ("*" is a wildcard), so pages without a
     * route (error pages) hide the block. A "roles" rule needs one of the request's roles.
     *
     * @param list<string> $roles
     */
    public function visibleFor(?string $routeName, array $roles): bool
    {
        $routes = $this->visibility['routes'] ?? [];
        if ($routes !== []) {
            if ($routeName === null) {
                return false;
            }
            $matched = false;
            foreach ($routes as $pattern) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/D';
                $matched = $matched || preg_match($regex, $routeName) === 1;
            }
            if (!$matched) {
                return false;
            }
        }
        $wanted = $this->visibility['roles'] ?? [];

        return $wanted === [] || array_intersect($wanted, $roles) !== [];
    }

    /** @return array<string, mixed> */
    private static function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], 'is_string'));
    }
}
```

`src/Kernel/Blocks/BlockRepository.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\Manifest;

final class BlockRepository
{
    private ?bool $ready = null;

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string, mixed> $config
     * @param array{routes?: list<string>, roles?: list<string>} $visibility
     */
    public function create(
        string $type,
        string $region,
        ?string $title = null,
        array $config = [],
        array $visibility = [],
        int $sort = 0,
        bool $enabled = true,
    ): int {
        if (strlen($type) > 128 || preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)*$/D', $type) !== 1) {
            throw new InvalidArgumentException("Invalid block type '{$type}'");
        }
        if (strlen($region) > 64 || preg_match('/^[a-z][a-z0-9_-]*$/D', $region) !== 1) {
            throw new InvalidArgumentException("Invalid block region '{$region}'");
        }
        $this->db->insert('blocks', [
            'type' => $type,
            'region' => $region,
            'title' => $title,
            'config' => $config,
            'sort' => $sort,
            'visibility' => $visibility,
            'enabled' => $enabled,
        ]);
        $this->ready = true;

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function find(int $id): ?BlockInstance
    {
        $row = $this->db->select('blocks')->where('id', '=', $id)->first();

        return $row === null ? null : BlockInstance::fromRow($row);
    }

    /** @return list<BlockInstance> the region's enabled blocks, by sort then id */
    public function forRegion(string $region): array
    {
        if (!($this->ready ??= $this->db->hasTable('blocks'))) {
            return [];
        }
        $rows = $this->db->select('blocks')
            ->where('region', '=', $region)
            ->where('enabled', '=', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->all();

        return array_map(BlockInstance::fromRow(...), $rows);
    }

    public function setEnabled(int $id, bool $enabled): void
    {
        $this->db->update('blocks', ['enabled' => $enabled], ['id' => $id]);
    }

    /** Creates the manifest's blockDefaults; called once, on the module's first install. */
    public function seed(Manifest $manifest): void
    {
        foreach ($manifest->blockDefaults() as $default) {
            $this->create($default['type'], $default['region'], $default['title'], $default['config'], $default['visibility'], $default['sort']);
        }
    }
}
```

`src/Kernel/Blocks/BlockTypes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;
use LogicException;
use Xaraya\Kernel\Container\Container;

final class BlockTypes
{
    /** @param array<string, string> $types type => class implementing Block */
    public function __construct(private readonly Container $container, private array $types = []) {}

    public function register(string $type, string $class): void
    {
        $this->types[$type] = $class;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->types;
    }

    public function get(string $type): Block
    {
        $class = $this->types[$type] ?? throw new InvalidArgumentException("Unknown block type '{$type}'");
        $block = $this->container->get($class);
        if (!$block instanceof Block) {
            throw new LogicException("Block type '{$type}': {$class} must implement Block");
        }

        return $block;
    }
}
```

`src/Kernel/Blocks/BlockRenderer.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Routing\RouteMatch;
use Xaraya\Kernel\View\Helpers;

/** Renders a theme region: its enabled, visible blocks, each wrapped by the theme's "block" template. */
final class BlockRenderer
{
    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly BlockTypes $types,
        private readonly Access $access,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public function region(string $region, Helpers $x): string
    {
        $match = $x->request()?->getAttribute(RouteMatch::class);
        $routeName = $match instanceof RouteMatch ? $match->route->name : null;
        $params = $match instanceof RouteMatch ? $match->params : [];
        $roles = $this->access->roles();
        $html = '';
        foreach ($this->blocks->forRegion($region) as $block) {
            if (!$this->types->has($block->type) || !$block->visibleFor($routeName, $roles)) {
                continue;
            }
            try {
                $inner = $this->types->get($block->type)->render($block->config, new BlockContext($block, $x, $routeName, $params));
            } catch (Throwable $e) {
                if ($this->debug) {
                    throw $e;
                }
                $this->logger->error("Block {$block->id} ({$block->type}) failed: {$e->getMessage()}", ['exception' => $e]);
                continue;
            }
            if ($inner !== '') {
                $html .= $this->wrap($block, $inner, $x);
            }
        }

        return $html;
    }

    private function wrap(BlockInstance $block, string $inner, Helpers $x): string
    {
        $typeClass = 'block-' . (preg_replace('/[^a-z0-9-]+/', '-', $block->type) ?? 'block');
        if ($x->view()->exists('block')) {
            return $x->render('block', [
                'id' => $block->id,
                'type' => $block->type,
                'typeClass' => $typeClass,
                'title' => $block->title,
                'region' => $block->region,
                'content' => $inner,
            ]);
        }
        $title = $block->title === null || $block->title === '' ? '' : '<h2 class="block-title">' . $x->e($block->title) . '</h2>';

        return '<section class="block ' . $x->e($typeClass) . '">' . $title . $inner . "</section>\n";
    }
}
```

In `src/Kernel/Module/Manifest.php`, add `use Xaraya\Kernel\Blocks\BlockInstance;` and these methods after `hookDefaults()`:
```php
    /** @return array<string, string> block type ("<module>.<name>") => class implementing Block */
    public function blocks(): array
    {
        $out = [];
        foreach ((array) ($this->data['blocks'] ?? []) as $type => $class) {
            if (
                !is_string($type)
                || !str_starts_with($type, $this->name . '.')
                || preg_match('/^[a-z][a-z0-9_-]*(?:\.[a-z0-9_-]+)+$/D', $type) !== 1
                || !is_string($class)
                || $class === ''
            ) {
                throw new ModuleException("{$this->name}: blocks must map \"{$this->name}.<name>\" types to class names");
            }
            $out[$type] = $class;
        }

        return $out;
    }

    /**
     * Block instances created the first time this module is enabled.
     *
     * @return list<array{type: string, region: string, title: ?string, config: array<string, mixed>, visibility: array{routes?: list<string>, roles?: list<string>}, sort: int}>
     */
    public function blockDefaults(): array
    {
        $out = [];
        foreach ((array) ($this->data['blockDefaults'] ?? []) as $i => $default) {
            if (!is_array($default) || !is_string($default['type'] ?? null) || !is_string($default['region'] ?? null)) {
                throw new ModuleException("{$this->name}: blockDefaults[{$i}] needs string \"type\" and \"region\"");
            }
            $title = $default['title'] ?? null;
            $config = $default['config'] ?? [];
            $visibility = $default['visibility'] ?? [];
            if (($title !== null && !is_string($title)) || !is_array($config) || !is_array($visibility)) {
                throw new ModuleException("{$this->name}: blockDefaults[{$i}] has an invalid title, config or visibility");
            }
            /** @var array<string, mixed> $config */
            $out[] = [
                'type' => $default['type'],
                'region' => $default['region'],
                'title' => $title,
                'config' => $config,
                'visibility' => BlockInstance::visibility($visibility),
                'sort' => (int) ($default['sort'] ?? 0),
            ];
        }

        return $out;
    }
```

In `src/Kernel/View/Helpers.php`:
- Add `use Xaraya\Kernel\Blocks\BlockRenderer;`.
- Add `'blocks' => true,` to `TWIG`, after `'hooks' => true,`.
- Add this method:
```php
    /** Renders the enabled, visible blocks of a theme region ('' when there are none). */
    public function blocks(string $region): string
    {
        return $this->container->get(BlockRenderer::class)->region($region, $this);
    }
```

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\Blocks\BlockRenderer;`, `use Xaraya\Kernel\Blocks\BlockRepository;` and `use Xaraya\Kernel\Blocks\BlockTypes;`.
- In the `onEnable` closure inside the `ModuleRegistry` binding, after the `HookBindings` line, add:
```php
                if ($firstInstall) {
                    $c->get(BlockRepository::class)->seed($manifest);
                }
```
- Before `$this->bootModules();`, add:
```php
        $c->set(BlockTypes::class, function (Container $c): BlockTypes {
            $types = new BlockTypes($c);
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
                foreach ($manifest->blocks() as $type => $class) {
                    $types->register($type, $class);
                }
            }

            return $types;
        });
        $c->set(BlockRenderer::class, fn(Container $c): BlockRenderer => new BlockRenderer(
            $c->get(BlockRepository::class),
            $c->get(BlockTypes::class),
            $c->get(Access::class),
            $c->get(LoggerInterface::class),
            $this->debug(),
        ));
```

- [ ] **Step 4: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Kernel/Blocks tests/Kernel/Module`, then repeat with the MySQL env vars.
Expected: PASS on both.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add blocks with regions, visibility rules and module block types" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Built-in blocks: text (HTML or Markdown), menu and recent-items

**Files:**
- Create:
  - `src/Kernel/Support/MiniMarkdown.php`
  - `src/Kernel/Blocks/TextBlock.php`, `src/Kernel/Blocks/MenuBlock.php`, `src/Kernel/Blocks/RecentItemsBlock.php`
  - `src/Kernel/Events/RecentItem.php`, `src/Kernel/Events/RecentItemsQuery.php`
- Modify: `src/Kernel/App.php` (register the built-in types)
- Test: `tests/Kernel/Support/MiniMarkdownTest.php`, `tests/Kernel/Blocks/BuiltInBlocksTest.php`

**Interfaces:**
- **Consumes:** `Block`, `BlockContext`, `BlockRepository`, `BlockTypes` (Task 9); `Helpers::url/e/t/request` (Task 7); `EventDispatcher`; `Event`.
- **Produces `MiniMarkdown::toHtml(string $markdown): string`.** It escapes everything first, then supports:
  - paragraphs, with single newlines becoming `<br>`;
  - `- ` bullet lists;
  - `` `code` ``, `**strong**` and `*em*`;
  - `[text](url)` links, where the URL starts `http://`, `https://`, `mailto:`, `#` or a single `/`.
- **Produces `TextBlock` (type `text`):** the config is `{format: "html"|"markdown" (default html), body: string}`. HTML is trusted admin content and is output as-is. An empty body hides the block.
- **Produces `MenuBlock` (type `menu`):**
  - The config is `{links: list<{label, route, params?} | {label, url}>}`. URLs must be `http(s)://`, `/…` or `#…`, and other links are skipped.
  - It outputs `<nav aria-label="{title|Menu}"><ul class="menu">…</ul></nav>`, with `aria-current="page"` on the link whose href equals the request path.
- **Produces `RecentItem(string $module, string $title, string $url, ?DateTimeImmutable $date = null)`.**
- **Produces `RecentItemsQuery extends Event`:**
  - Constructor: `(int $limit = 5, ?string $module = null, ?string $itemtype = null)`.
  - `wants(string $module, string $itemtype): bool`
  - `add(RecentItem): void`
  - `items(): list<RecentItem>`: newest first, undated last, at most `$limit`
- **Produces `RecentItemsBlock(EventDispatcher)` (type `recent-items`):**
  - The config is `{limit?: int 1–50 (default 5), module?: string, itemtype?: string}`.
  - It outputs `<ul class="recent-items"><li><a href>title</a> <time datetime="ATOM">j M Y</time></li>…</ul>`, or `''` when nothing answers.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Support/MiniMarkdownTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Support\MiniMarkdown;

final class MiniMarkdownTest extends TestCase
{
    public function testParagraphsAndLineBreaks(): void
    {
        self::assertSame("<p>a<br>\nb</p>\n<p>c</p>", MiniMarkdown::toHtml("a\nb\n\nc"));
        self::assertSame('', MiniMarkdown::toHtml("  \n "));
    }

    public function testBulletLists(): void
    {
        self::assertSame('<ul><li>one</li><li><strong>two</strong></li></ul>', MiniMarkdown::toHtml("- one\n- **two**"));
        self::assertSame("<p>- a<br>\nb</p>", MiniMarkdown::toHtml("- a\nb"), 'a list needs every line to be an item');
    }

    public function testRawHtmlIsEscaped(): void
    {
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', MiniMarkdown::toHtml('<script>alert(1)</script>'));
    }

    public function testOnlySafeLinkSchemes(): void
    {
        self::assertSame(
            '<p><a href="https://x.test/a?b=1&amp;c=2">ok</a> [bad](javascript:alert(1)) [rel](//evil.test) <a href="/local">local</a></p>',
            MiniMarkdown::toHtml('[ok](https://x.test/a?b=1&c=2) [bad](javascript:alert(1)) [rel](//evil.test) [local](/local)'),
        );
        self::assertSame('<p><a href="/a&quot;b">x</a></p>', MiniMarkdown::toHtml('[x](/a"b)'));
    }

    public function testCodeProtectsEmphasis(): void
    {
        self::assertSame('<p><code>**x**</code> and <em>y</em> and 2 * 3 * 4</p>', MiniMarkdown::toHtml('`**x**` and *y* and 2 * 3 * 4'));
    }
}
```

`tests/Kernel/Blocks/BuiltInBlocksTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Blocks;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\RecentItem;
use Xaraya\Kernel\Events\RecentItemsQuery;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\ViewFixtures;

final class BuiltInBlocksTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ViewFixtures::themes($this->tmp . '/themes');
        ViewFixtures::shop($this->tmp . '/modules');
        $this->app()->container()->get(ModuleRegistry::class)->enable('shop');
    }

    private function app(): App
    {
        return $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'plain']);
    }

    private function repo(): BlockRepository
    {
        return $this->app()->container()->get(BlockRepository::class);
    }

    /** Renders the "footer" region (plain has no block template, so the fallback wrapper is used). */
    private function footer(App $app, string $path = '/shop/7'): string
    {
        $request = RouteHandler::attach(new ServerRequest('GET', $path), $app->container()->get(Router::class)->match('GET', $path));

        return $app->container()->get(View::class)->helpers($request)->blocks('footer');
    }

    public function testTextBlocksInHtmlAndMarkdown(): void
    {
        $repo = $this->repo();
        $repo->create('text', 'footer', 'About', ['format' => 'html', 'body' => '<p>Trusted <b>HTML</b></p>']);
        $repo->create('text', 'footer', null, ['format' => 'markdown', 'body' => "Hi **there**\n\n- <script>x</script>"]);
        $repo->create('text', 'footer', 'Empty', ['body' => '']);
        self::assertSame(
            "<section class=\"block block-text\"><h2 class=\"block-title\">About</h2><p>Trusted <b>HTML</b></p></section>\n"
            . "<section class=\"block block-text\"><p>Hi <strong>there</strong></p>\n<ul><li>&lt;script&gt;x&lt;/script&gt;</li></ul></section>\n",
            $this->footer($this->app()),
        );
    }

    public function testMenuBlockLinksRoutesAndUrlsAndMarksTheCurrentPage(): void
    {
        $this->repo()->create('menu', 'footer', 'Shop menu', ['links' => [
            ['label' => 'Item 7', 'route' => 'shop.item', 'params' => ['id' => 7]],
            ['label' => 'All', 'route' => 'shop.list'],
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
            ['label' => 'No target'],
        ]]);
        $html = $this->footer($this->app());
        self::assertStringContainsString(
            '<nav aria-label="Shop menu"><ul class="menu"><li><a href="/shop/7" aria-current="page">Item 7</a></li><li><a href="/shop">All</a></li><li><a href="/">Home</a></li></ul></nav>',
            $html,
        );
        self::assertStringNotContainsString('Bad', $html);
    }

    public function testRecentItemsAskModulesThroughAnEvent(): void
    {
        $app = $this->app();
        $app->container()->get(EventDispatcher::class)->listen(RecentItemsQuery::class, function (RecentItemsQuery $query): void {
            if ($query->wants('shop', 'item')) {
                $query->add(new RecentItem('shop', 'Old', '/shop/1', new DateTimeImmutable('2026-01-01T00:00:00Z')));
                $query->add(new RecentItem('shop', 'New <1>', '/shop/2', new DateTimeImmutable('2026-10-01T00:00:00Z')));
                $query->add(new RecentItem('shop', 'Undated', '/shop/3'));
            }
            if ($query->wants('blog', 'post')) {
                $query->add(new RecentItem('blog', 'Post', '/b/1', new DateTimeImmutable('2026-12-01T00:00:00Z')));
            }
        });
        $this->repo()->create('recent-items', 'footer', 'Recent', ['module' => 'shop', 'limit' => 2]);
        self::assertSame(
            '<section class="block block-recent-items"><h2 class="block-title">Recent</h2><ul class="recent-items">'
            . '<li><a href="/shop/2">New &lt;1&gt;</a> <time datetime="2026-10-01T00:00:00+00:00">1 Oct 2026</time></li>'
            . '<li><a href="/shop/1">Old</a> <time datetime="2026-01-01T00:00:00+00:00">1 Jan 2026</time></li>'
            . "</ul></section>\n",
            $this->footer($app),
        );
    }

    public function testRecentItemsWithNoAnswersIsHidden(): void
    {
        $this->repo()->create('recent-items', 'footer', 'Recent');
        self::assertSame('', $this->footer($this->app()));
    }

    public function testUnknownTextFormatFails(): void
    {
        $this->repo()->create('text', 'footer', null, ['format' => 'bbcode', 'body' => 'x']);
        $this->expectException(\InvalidArgumentException::class);
        $this->footer($this->app());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Support/MiniMarkdownTest.php tests/Kernel/Blocks/BuiltInBlocksTest.php`
Expected: errors, `MiniMarkdown` and `RecentItemsQuery` not found.

- [ ] **Step 3: Implement**

`src/Kernel/Support/MiniMarkdown.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Support;

/**
 * A deliberately tiny, safe Markdown subset for text blocks: paragraphs (single newlines become <br>),
 * "- " bullet lists, `code`, **strong**, *em* and [text](url) links whose URL is http(s), mailto,
 * root-relative or a #fragment. Everything is HTML-escaped first, so raw HTML shows as text.
 */
final class MiniMarkdown
{
    public static function toHtml(string $markdown): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $markdown));
        if ($text === '') {
            return '';
        }
        $html = [];
        foreach (preg_split('/\n\s*\n/', $text) ?: [] as $block) {
            $lines = array_map('trim', explode("\n", trim($block)));
            $items = array_filter($lines, static fn(string $line): bool => str_starts_with($line, '- '));
            if (count($items) === count($lines)) {
                $html[] = '<ul>' . implode('', array_map(static fn(string $line): string => '<li>' . self::inline(substr($line, 2)) . '</li>', $lines)) . '</ul>';
            } else {
                $html[] = '<p>' . implode("<br>\n", array_map(self::inline(...), $lines)) . '</p>';
            }
        }

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $parts = preg_split('/(`[^`]+`)/', $escaped, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$escaped];
        $out = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                $out .= '<code>' . substr($part, 1, -1) . '</code>';
                continue;
            }
            $part = preg_replace('/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|#|\/(?!\/))[^\s()*]*)\)/', '<a href="$2">$1</a>', $part) ?? $part;
            $part = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $part) ?? $part;
            $part = preg_replace('/\*(?=\S)(.+?)(?<=\S)\*/', '<em>$1</em>', $part) ?? $part;
            $out .= $part;
        }

        return $out;
    }
}
```

`src/Kernel/Events/RecentItem.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

use DateTimeImmutable;

final class RecentItem
{
    public function __construct(
        public readonly string $module,
        public readonly string $title,
        public readonly string $url,
        public readonly ?DateTimeImmutable $date = null,
    ) {}
}
```

`src/Kernel/Events/RecentItemsQuery.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

/** Asks modules for their newest items. A listener checks wants() and add()s what it has. */
final class RecentItemsQuery extends Event
{
    /** @var list<RecentItem> */
    private array $items = [];

    public function __construct(
        public readonly int $limit = 5,
        public readonly ?string $module = null,
        public readonly ?string $itemtype = null,
    ) {}

    public function wants(string $module, string $itemtype): bool
    {
        return ($this->module === null || $this->module === $module) && ($this->itemtype === null || $this->itemtype === $itemtype);
    }

    public function add(RecentItem $item): void
    {
        $this->items[] = $item;
    }

    /** @return list<RecentItem> newest first, undated last, at most $limit */
    public function items(): array
    {
        $items = $this->items;
        usort($items, static fn(RecentItem $a, RecentItem $b): int => ($b->date?->getTimestamp() ?? PHP_INT_MIN) <=> ($a->date?->getTimestamp() ?? PHP_INT_MIN));

        return array_slice($items, 0, max(0, $this->limit));
    }
}
```

`src/Kernel/Blocks/TextBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use InvalidArgumentException;
use Xaraya\Kernel\Support\MiniMarkdown;

/** Admin-written text. "html" bodies are trusted and output as-is; "markdown" bodies use MiniMarkdown. */
final class TextBlock implements Block
{
    public function render(array $config, BlockContext $context): string
    {
        $body = $config['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return '';
        }

        return match ($config['format'] ?? 'html') {
            'html' => $body,
            'markdown' => MiniMarkdown::toHtml($body),
            default => throw new InvalidArgumentException('A text block format must be "html" or "markdown"'),
        };
    }
}
```

`src/Kernel/Blocks/MenuBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

/** Links from the block config: {"links": [{"label", "route", "params"} or {"label", "url"}]}. */
final class MenuBlock implements Block
{
    public function render(array $config, BlockContext $context): string
    {
        $x = $context->helpers;
        $current = $context->request()?->getUri()->getPath();
        $items = '';
        foreach ((array) ($config['links'] ?? []) as $link) {
            if (!is_array($link) || !is_string($link['label'] ?? null)) {
                continue;
            }
            if (is_string($link['route'] ?? null)) {
                $params = [];
                foreach ((array) ($link['params'] ?? []) as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_int($value))) {
                        $params[$key] = $value;
                    }
                }
                $href = $x->url($link['route'], $params);
            } elseif (is_string($link['url'] ?? null) && preg_match('#^(?:https?://|/(?!/)|\#)#', $link['url']) === 1) {
                $href = $link['url'];
            } else {
                continue;
            }
            $items .= '<li><a href="' . $x->e($href) . '"' . ($href === $current ? ' aria-current="page"' : '') . '>' . $x->e($link['label']) . '</a></li>';
        }
        if ($items === '') {
            return '';
        }

        return '<nav aria-label="' . $x->e($context->block->title ?? $x->t('Menu')) . '"><ul class="menu">' . $items . '</ul></nav>';
    }
}
```

`src/Kernel/Blocks/RecentItemsBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\RecentItemsQuery;

/** The newest items of whichever modules answer a RecentItemsQuery: {"limit", "module", "itemtype"}. */
final class RecentItemsBlock implements Block
{
    public function __construct(private readonly EventDispatcher $events) {}

    public function render(array $config, BlockContext $context): string
    {
        $limit = is_int($config['limit'] ?? null) ? max(1, min(50, $config['limit'])) : 5;
        $module = is_string($config['module'] ?? null) ? $config['module'] : null;
        $itemtype = is_string($config['itemtype'] ?? null) ? $config['itemtype'] : null;
        $items = $this->events->dispatch(new RecentItemsQuery($limit, $module, $itemtype))->items();
        if ($items === []) {
            return '';
        }
        $x = $context->helpers;
        $html = '<ul class="recent-items">';
        foreach ($items as $item) {
            $html .= '<li><a href="' . $x->e($item->url) . '">' . $x->e($item->title) . '</a>';
            if ($item->date !== null) {
                $html .= ' <time datetime="' . $x->e($item->date->format(DATE_ATOM)) . '">' . $x->e($item->date->format('j M Y')) . '</time>';
            }
            $html .= '</li>';
        }

        return $html . '</ul>';
    }
}
```

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\Blocks\MenuBlock;`, `use Xaraya\Kernel\Blocks\RecentItemsBlock;` and `use Xaraya\Kernel\Blocks\TextBlock;`.
- In the `BlockTypes` binding, replace `$types = new BlockTypes($c);` with:
```php
            $types = new BlockTypes($c, [
                'text' => TextBlock::class,
                'menu' => MenuBlock::class,
                'recent-items' => RecentItemsBlock::class,
            ]);
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Support/MiniMarkdownTest.php tests/Kernel/Blocks`
Expected: PASS.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add text, menu and recent-items blocks" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: `secureHtml` middleware and themed error pages

**Files:**
- Create: `src/Kernel/Http/Middleware/SecureHtml.php`, `src/Kernel/View/ErrorPages.php`
- Modify:
  - `src/Kernel/App.php` (register `secureHtml`, wire `$renderHtml`)
  - `src/Kernel/Http/Middleware/ErrorHandler.php` (log renderer failures, case-insensitive `.json`)
  - `src/Kernel/Http/Exception/HttpException.php` (reasons for 502 and 504)
- Test: `tests/Kernel/Http/SecureHtmlTest.php`, `tests/Kernel/View/ErrorPagesTest.php`, `tests/Kernel/Http/ErrorHandlerTest.php` (three new tests)

**Interfaces:**
- **Consumes:** `View::exists/page` (Tasks 7–8), `Page` (Task 8), `MiddlewareRegistry::register`, `ErrorHandler`'s existing `$renderHtml` parameter, `ViewFixtures`/`Html` (Task 7).
- **Produces `SecureHtml implements MiddlewareInterface`**, registered as alias `secureHtml`:
  - `HEADERS` holds the exact CSP from the blog spec §3.4, plus `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`.
  - It adds each header the response lacks; a route may set its own CSP.
  - It rethrows an `HttpException` with the headers merged in, and wraps any other `Throwable` in `HttpException(500, '', HEADERS, $e)`. Error pages of `secureHtml` routes therefore carry the headers, as `Cors` does for its own.
- **Produces `ErrorPages(View $view, bool $debug = false)`:**
  - `render(int $status, string $message, ServerRequestInterface $request): ?string`.
  - It renders `error/{status}`, or else `error/default`, as a `Page` titled `"{status} {reason}"` with the data `status`, `reason` and `message`.
  - It returns null, meaning the built-in page, when neither template exists, or for 5xx in debug mode (the built-in page shows the trace).
- **`ErrorHandler` binding:** `$renderHtml` is `static fn(int $s, string $m, ServerRequestInterface $r): ?string => $c->get(ErrorPages::class)->render($s, $m, $r)`, built lazily.
- **Folded follow-ups:**
  - `ErrorHandler` logs a failing renderer.
  - `wantsJson()` matches `.json` case-insensitively.
  - `HttpException::reason()` knows 502 `Bad Gateway` and 504 `Gateway Timeout`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Http/SecureHtmlTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Pipeline;

final class SecureHtmlTest extends TestCase
{
    private const CSP = "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self'; script-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'";

    private function through(\Closure $final): ResponseInterface
    {
        return (new Pipeline([new ErrorHandler(new NullLogger()), new SecureHtml()], new CallableHandler($final)))
            ->handle(new ServerRequest('GET', '/x'));
    }

    public function testAddsTheSecurityHeaders(): void
    {
        $response = $this->through(fn() => new Response(200, [], 'ok'));
        self::assertSame(self::CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testKeepsAPolicyTheRouteSetItself(): void
    {
        $response = $this->through(fn() => new Response(200, ['Content-Security-Policy' => "default-src 'none'"]));
        self::assertSame("default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testErrorPagesCarryTheHeaders(): void
    {
        $notAllowed = $this->through(fn() => throw new MethodNotAllowed(['GET']));
        self::assertSame(405, $notAllowed->getStatusCode());
        self::assertSame('GET', $notAllowed->getHeaderLine('Allow'));
        self::assertSame('nosniff', $notAllowed->getHeaderLine('X-Content-Type-Options'));

        $broken = $this->through(fn() => throw new \RuntimeException('db down'));
        self::assertSame(500, $broken->getStatusCode());
        self::assertSame(self::CSP, $broken->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('db down', (string) $broken->getBody());
    }
}
```

`tests/Kernel/View/ErrorPagesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Html;
use Xaraya\Tests\Support\ViewFixtures;

final class ErrorPagesTest extends AppTestCase
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
        return $this->boot([$this->tmp . '/modules'], ['themes.paths' => [$this->tmp . '/themes'], 'app.theme' => 'plain', ...$overrides]);
    }

    private function log(): string
    {
        return implode('', array_map('file_get_contents', glob($this->tmp . '/logs/*') ?: []));
    }

    public function testNotFoundUsesTheThemeTemplateInsideTheLayout(): void
    {
        $response = $this->app()->handle(new ServerRequest('GET', '/nope'));
        self::assertSame(404, $response->getStatusCode());
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<title>404 Not Found</title>', $html);
        self::assertStringContainsString('<h1>Missing: Not Found</h1>', $html);
    }

    public function testOtherStatusesUseErrorDefaultAndKeepTheirHeaders(): void
    {
        $response = $this->app()->handle(new ServerRequest('DELETE', '/shop'));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertStringContainsString('<h1>405 Method Not Allowed</h1>', Html::normalize((string) $response->getBody()));
    }

    public function testJsonClientsStillGetJson(): void
    {
        $response = $this->app()->handle((new ServerRequest('GET', '/nope'))->withHeader('Accept', 'application/json'));
        self::assertSame(['error' => ['status' => 404, 'message' => 'Not Found']], json_decode((string) $response->getBody(), true));
    }

    public function testServerErrorsAreThemedAndMaskedInProduction(): void
    {
        $response = $this->app(['app.debug' => false])->handle(new ServerRequest('GET', '/shop/boom'));
        self::assertSame(500, $response->getStatusCode());
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<h1>500 Server Error</h1>', $html);
        self::assertStringNotContainsString('exploded', $html);
    }

    public function testDebugServerErrorsKeepTheBuiltInPageWithItsTrace(): void
    {
        $body = (string) $this->app()->handle(new ServerRequest('GET', '/shop/boom'))->getBody();
        self::assertStringContainsString('shop exploded', $body);
        self::assertStringContainsString('<pre>', $body);
        self::assertStringNotContainsString('data-tagline', $body);
    }

    public function testMissingThemeFallsBackToTheBuiltInPageAndLogs(): void
    {
        $response = $this->app(['app.theme' => 'nope'])->handle(new ServerRequest('GET', '/nope'));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('<h1>404 Not Found</h1>', (string) $response->getBody());
        self::assertStringContainsString("Unknown theme 'nope'", $this->log());
    }

    public function testSecureHtmlAliasIsRegistered(): void
    {
        self::assertInstanceOf(SecureHtml::class, $this->app()->container()->get(MiddlewareRegistry::class)->resolve('secureHtml'));
    }
}
```

Add to `tests/Kernel/Http/ErrorHandlerTest.php`:
```php
    public function testRendererFailureIsLogged(): void
    {
        $logger = new MemoryLogger();
        $eh = new ErrorHandler($logger, false, function (): never {
            throw new \RuntimeException('theme broke');
        });
        $response = $this->send($eh, new ServerRequest('GET', '/x'), new NotFound());
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error: theme broke'], $logger->lines);
    }

    public function testJsonPathMatchIsCaseInsensitive(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger()), new ServerRequest('GET', '/x/FEED.JSON'), new NotFound());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testGatewayReasons(): void
    {
        self::assertSame('Bad Gateway', HttpException::reason(502));
        self::assertSame('Gateway Timeout', HttpException::reason(504));
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Http tests/Kernel/View/ErrorPagesTest.php`
Expected: errors and failures, for example `SecureHtml` not found, the built-in page instead of `Missing: Not Found`, and `Error` instead of `Bad Gateway`.

- [ ] **Step 3: Implement**

`src/Kernel/Http/Middleware/SecureHtml.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;
use Xaraya\Kernel\Http\Exception\HttpException;

/** Security headers for HTML routes (alias "secureHtml"); headers a route sets itself are kept. */
final class SecureHtml implements MiddlewareInterface
{
    public const HEADERS = [
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self'; script-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (HttpException $e) {
            // ErrorHandler renders the page; it copies these headers onto it.
            throw new HttpException($e->status(), $e->getMessage(), [...self::HEADERS, ...$e->headers()], $e->getPrevious());
        } catch (Throwable $e) {
            // ErrorHandler masks the 5xx message and logs the wrapped exception.
            throw new HttpException(500, '', self::HEADERS, $e);
        }
        foreach (self::HEADERS as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
```

`src/Kernel/View/ErrorPages.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\View;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;

/** ErrorHandler's renderHtml hook: the theme's error/{status} (or error/default) page inside the layout. */
final class ErrorPages
{
    public function __construct(private readonly View $view, private readonly bool $debug = false) {}

    /** The themed page, or null to keep ErrorHandler's built-in page. */
    public function render(int $status, string $message, ServerRequestInterface $request): ?string
    {
        if ($this->debug && $status >= 500) {
            return null;
        }
        foreach (["error/{$status}", 'error/default'] as $template) {
            if ($this->view->exists($template)) {
                $reason = HttpException::reason($status);

                return $this->view->page(
                    new Page($template, ['status' => $status, 'reason' => $reason, 'message' => $message], title: "{$status} {$reason}"),
                    $request,
                );
            }
        }

        return null;
    }
}
```

In `src/Kernel/Http/Middleware/ErrorHandler.php`:
- In `respond()`, replace the `catch (Throwable) { $html = null; }` around the renderer call with:
```php
                } catch (Throwable $renderError) {
                    $this->log($renderError);
                    $html = null;
                }
```
- In `wantsJson()`, replace the first check with:
```php
        if (str_ends_with(strtolower($request->getUri()->getPath()), '.json')) {
```

In `HttpException::reason()`, add `502 => 'Bad Gateway',` after the 500 line and `504 => 'Gateway Timeout',` after the 503 line.

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\Http\Middleware\SecureHtml;` and `use Xaraya\Kernel\View\ErrorPages;`.
- In the `MiddlewareRegistry` binding, after `$registry->register('conditional', ConditionalGet::class);`, add:
```php
            $registry->register('secureHtml', SecureHtml::class);
```
- Replace the `ErrorHandler` binding with:
```php
        $c->set(ErrorPages::class, fn(Container $c): ErrorPages => new ErrorPages($c->get(View::class), $this->debug()));
        $c->set(ErrorHandler::class, fn(Container $c): ErrorHandler => new ErrorHandler(
            $c->get(LoggerInterface::class),
            $this->debug(),
            // Built lazily: the view (theme, database for blocks and settings) is only touched for an HTML error.
            static fn(int $status, string $message, ServerRequestInterface $request): ?string => $c->get(ErrorPages::class)->render($status, $message, $request),
        ));
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Http tests/Kernel/View`
Expected: PASS.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`
Until Task 13 adds the theme, HTML errors in tests that use the default `phoenix` theme fall back to the built-in page and log `Unknown theme 'phoenix'`. That is expected.

```bash
git add -A
git commit -m "feat(kernel): add the secureHtml middleware and themed error pages" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: `xar asset:publish`

**Files:**
- Create: `src/Kernel/Assets/AssetPublisher.php`, `src/Kernel/Cli/Commands/AssetPublishCommand.php`
- Modify: `config/app.php` (`assets.path`, `assets.url`), `.env.example`, `.gitignore`, `src/Kernel/App.php` (bind `AssetPublisher`), `src/Kernel/Cli/Application.php`
- Test: `tests/Kernel/Assets/AssetPublisherTest.php`, `tests/Kernel/Cli/ApplicationTest.php` (help list)

**Interfaces:**
- **Consumes:** `ModuleRegistry::discover()`, `ThemeRegistry::discover()` (Task 3), `Cache::removeTree()` (Task 1), `Helpers::asset()` (Task 7), `Fixtures` (Task 3).
- **Produces `AssetPublisher(ModuleRegistry $modules, ThemeRegistry $themes, string $target)`:**
  - `publish(bool $copy = false): list<array{kind: string, name: string, source: string, target: string, method: string}>`.
  - For each discovered module and then each discovered theme that has an `assets/` directory, it replaces `{target}/{module|theme}/{name}` with a symlink to that directory. It copies instead (`method` = `copy`) when `$copy` is true or `symlink()` fails.
  - Replacing never follows links, so a source is never deleted.
- **Produces the binding:** `AssetPublisher::class`, with the target `App::path(assets.path)`.
- **Produces these config keys:**
  - `assets.path` (default `{root}/public/assets`)
  - `assets.url` (env `ASSET_URL`, default `/assets`), already read by `Helpers::asset()`
- **Produces the CLI command:** `xar asset:publish [--copy]`, which prints a table with the columns Kind, Name, Method and Target.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Assets/AssetPublisherTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Assets;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Assets\AssetPublisher;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class AssetPublisherTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Fixtures::module($this->tmp . '/modules', 'shop', [], ['assets/shop.css' => 'shop{}', 'assets/img/logo.svg' => '<svg/>']);
        Fixtures::module($this->tmp . '/modules', 'bare');
        Fixtures::theme($this->tmp . '/themes', 'plain', [], ['assets/site.css' => 'body{}']);
    }

    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([$this->tmp . '/modules'], [
            'themes.paths' => [$this->tmp . '/themes'],
            'app.theme' => 'plain',
            'assets.path' => $this->tmp . '/public/assets',
            ...$overrides,
        ]);
    }

    public function testSymlinksModuleAndThemeAssets(): void
    {
        $done = $this->app()->container()->get(AssetPublisher::class)->publish();
        self::assertSame(
            [['module', 'shop', 'symlink'], ['theme', 'plain', 'symlink']],
            array_map(fn(array $row): array => [$row['kind'], $row['name'], $row['method']], $done),
        );
        self::assertTrue(is_link($this->tmp . '/public/assets/module/shop'));
        self::assertSame('<svg/>', file_get_contents($this->tmp . '/public/assets/module/shop/img/logo.svg'));
        self::assertSame('body{}', file_get_contents($this->tmp . '/public/assets/theme/plain/site.css'));
        self::assertFileDoesNotExist($this->tmp . '/public/assets/module/bare');
    }

    public function testRepublishingReplacesLinksAndCopiesWithoutTouchingSources(): void
    {
        $publisher = $this->app()->container()->get(AssetPublisher::class);
        $publisher->publish();
        $publisher->publish(copy: true);
        self::assertFalse(is_link($this->tmp . '/public/assets/module/shop'));
        self::assertSame('shop{}', file_get_contents($this->tmp . '/public/assets/module/shop/shop.css'));
        self::assertFileExists($this->tmp . '/modules/shop/assets/shop.css', 'replacing a link must not delete its source');
        $publisher->publish();
        self::assertTrue(is_link($this->tmp . '/public/assets/module/shop'));
        self::assertFileExists($this->tmp . '/modules/shop/assets/img/logo.svg', 'replacing a copy must not touch the source');
    }

    public function testCommandPrintsWhatItPublished(): void
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        self::assertSame(0, (new Application($this->app()))->run(['xar', 'asset:publish', '--copy'], new Output($out, $out)));
        rewind($out);
        $text = (string) stream_get_contents($out);
        self::assertMatchesRegularExpression('/module\s+shop\s+copy/', $text);
        self::assertMatchesRegularExpression('/theme\s+plain\s+copy/', $text);
    }

    public function testAssetHelperUsesTheConfiguredUrl(): void
    {
        $x = $this->app(['assets.url' => 'https://cdn.test/a/'])->container()->get(View::class)->helpers();
        self::assertSame('https://cdn.test/a/theme/plain/site.css', $x->asset('/theme/plain/site.css'));
    }
}
```

In `tests/Kernel/Cli/ApplicationTest.php::testHelpListsCommands`, replace the command list with:
```php
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'module:list', 'module:enable', 'module:disable', 'serve', 'cache:clear', 'hook:enable', 'hook:disable', 'asset:publish'] as $name) {
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Assets tests/Kernel/Cli`
Expected: errors, `AssetPublisher` not found, and no `asset:publish` in the help.

- [ ] **Step 3: Implement**

`src/Kernel/Assets/AssetPublisher.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Assets;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\ThemeRegistry;

/** Publishes modules/<name>/assets and themes/<name>/assets to {target}/module/<name> and {target}/theme/<name>. */
final class AssetPublisher
{
    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ThemeRegistry $themes,
        private readonly string $target,
    ) {}

    /** @return list<array{kind: string, name: string, source: string, target: string, method: string}> */
    public function publish(bool $copy = false): array
    {
        $sources = [];
        foreach ($this->modules->discover() as $manifest) {
            $sources[] = ['module', $manifest->name, $manifest->path . '/assets'];
        }
        foreach ($this->themes->discover() as $theme) {
            $sources[] = ['theme', $theme->name, $theme->path . '/assets'];
        }
        $done = [];
        foreach ($sources as [$kind, $name, $source]) {
            if (!is_dir($source)) {
                continue;
            }
            $target = $this->target . '/' . $kind . '/' . $name;
            Cache::removeTree($target);
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException("Cannot create directory '{$parent}'");
            }
            $method = !$copy && @symlink($source, $target) ? 'symlink' : self::copyTree($source, $target);
            $done[] = ['kind' => $kind, 'name' => $name, 'source' => $source, 'target' => $target, 'method' => $method];
        }

        return $done;
    }

    private static function copyTree(string $from, string $to): string
    {
        if (!is_dir($to) && !@mkdir($to, 0775, true) && !is_dir($to)) {
            throw new RuntimeException("Cannot create directory '{$to}'");
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            $destination = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) {
                if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
                    throw new RuntimeException("Cannot create directory '{$destination}'");
                }
            } elseif (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException("Cannot copy '{$item->getPathname()}'");
            }
        }

        return 'copy';
    }
}
```

`src/Kernel/Cli/Commands/AssetPublishCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Assets\AssetPublisher;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class AssetPublishCommand extends Command
{
    public function __construct(private readonly AssetPublisher $publisher) {}

    public function name(): string
    {
        return 'asset:publish';
    }

    public function description(): string
    {
        return 'Link (or copy) module and theme assets into public/assets';
    }

    public function usage(): string
    {
        return 'asset:publish [--copy]';
    }

    public function run(Input $input, Output $output): int
    {
        $done = $this->publisher->publish($input->flag('copy'));
        if ($done === []) {
            $output->line('No module or theme has an assets directory.');

            return 0;
        }
        $output->table(['Kind', 'Name', 'Method', 'Target'], array_map(
            static fn(array $row): array => [$row['kind'], $row['name'], $row['method'], $row['target']],
            $done,
        ));

        return 0;
    }
}
```

In `config/app.php`, add after the `'view'` entry:
```php
    'assets' => [
        // Where `xar asset:publish` links module and theme assets, and the URL they are served from.
        'path' => $root . '/public/assets',
        'url' => env('ASSET_URL', '/assets'),
    ],
```

Append `# ASSET_URL=/assets` to `.env.example`, and `/public/assets/` to `.gitignore`.

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\Assets\AssetPublisher;`.
- Before `$this->bootModules();`, add:
```php
        $c->set(AssetPublisher::class, fn(Container $c): AssetPublisher => new AssetPublisher(
            $c->get(ModuleRegistry::class),
            $c->get(ThemeRegistry::class),
            $this->path((string) $config->get('assets.path', 'public/assets')),
        ));
```

In `src/Kernel/Cli/Application.php`, import `AssetPublishCommand` and add `AssetPublishCommand::class` to `KERNEL_COMMANDS` after `HookDisableCommand::class`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Assets tests/Kernel/Cli`
Expected: PASS.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(kernel): add asset:publish for module and theme assets" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: The `phoenix` theme and the kernel home page

**Files:**
- Create:
  - `themes/phoenix/theme.json`
  - `themes/phoenix/templates/layout.php`, `layout.twig`, `home.php`, `home.twig`, `block.php`, `block.twig`
  - `themes/phoenix/templates/error/404.php`, `error/404.twig`, `error/default.php`, `error/default.twig`
  - `themes/phoenix/assets/phoenix.css`
  - `src/Kernel/Http/HomeController.php`
- Modify: `src/Kernel/App.php` (`routes()` adds `home`), `tests/Kernel/AppTest.php` (the 404 test uses `/nope`; boot asserts `home`)
- Test: `tests/Kernel/View/PhoenixThemeTest.php`

**Interfaces:**
- **Consumes:**
  - `Page`, `View::page` and the layout variables (Task 8): `content`, `title`, `site`, `lang`, `meta`, `feeds`, `canonical`, `styles`, `settings`
  - helpers `e`, `t`, `asset`, `blocks`, `config` (Tasks 7 and 9)
  - the `block` template variables (Task 9): `id`, `type`, `typeClass`, `title`, `region`, `content`
  - the error template variables (Task 11): `status`, `reason`, `message`
  - the `secureHtml` alias (Task 11), `BlockRepository` (Task 9), `Settings` (Task 2), `AppTestCase::migrateKernel` (Task 2), `Html` (Task 7)
- **Produces the `phoenix` theme:**
  - `engine` is `php`. The regions are `header`, `sidebar` and `footer`. The one setting is `tagline` (default `""`).
  - The templates are `layout`, `home`, `block`, `error/404` and `error/default`, each in `.php` and `.twig`.
  - The stylesheet is `assets/phoenix.css`, served as `/assets/theme/phoenix/phoenix.css` after `asset:publish`.
- **Layout contract, which the blog pages plan relies on:**
  - a skip link to `#main`;
  - `<header class="site-header">` holding the site title, the tagline and the `header` region;
  - `<main id="main" class="site-main" tabindex="-1">`;
  - `<aside class="site-sidebar">`, only when the `sidebar` region renders something;
  - `<footer class="site-footer">` holding the `footer` region;
  - `meta` names starting `og:` written as `property`;
  - `canonical`, `feeds` as `<link rel="alternate">`, and `styles` after `phoenix.css`.
- **Produces `HomeController`:** an invokable that returns `new Page('home')`.
- **Produces the `home` route:** `App::routes()` adds `GET /` named `home` with middleware `secureHtml` and `conditional`, unless a module route has path `/` with GET or the name `home`.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/View/PhoenixThemeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\View;

use FilesystemIterator;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Settings\Settings;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

final class PhoenixThemeTest extends AppTestCase
{
    private function theme(): string
    {
        return dirname(__DIR__, 3) . '/themes/phoenix';
    }

    /**
     * @param list<string> $modulePaths
     * @param array<string, mixed> $overrides
     */
    private function app(string $engine, array $modulePaths = [], array $overrides = []): App
    {
        return $this->boot($modulePaths, [
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.name' => 'Phoenix Test',
            'app.locale' => 'en',
            'view.engine' => $engine,
            ...$overrides,
        ]);
    }

    private static function sameHtml(ResponseInterface $php, ResponseInterface $twig): string
    {
        self::assertSame($php->getStatusCode(), $twig->getStatusCode());
        $html = Html::normalize((string) $php->getBody());
        self::assertSame($html, Html::normalize((string) $twig->getBody()));

        return $html;
    }

    public function testEveryTemplateShipsInBothEngines(): void
    {
        $names = ['php' => [], 'twig' => []];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->theme() . '/templates', FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($this->theme() . '/templates/'));
            $extension = $file->getExtension();
            self::assertContains($extension, ['php', 'twig'], $relative);
            $names[$extension][] = substr($relative, 0, -strlen($extension) - 1);
        }
        sort($names['php']);
        sort($names['twig']);
        self::assertSame(['block', 'error/404', 'error/default', 'home', 'layout'], $names['php']);
        self::assertSame($names['php'], $names['twig']);
    }

    public function testHomePageIsIdenticalInBothEnginesAndAccessible(): void
    {
        $php = $this->app('php')->handle(new ServerRequest('GET', '/'));
        $twig = $this->app('twig')->handle(new ServerRequest('GET', '/'));
        self::assertSame(200, $php->getStatusCode());
        self::assertSame('nosniff', $php->getHeaderLine('X-Content-Type-Options'));
        $html = self::sameHtml($php, $twig);
        foreach ([
            '<html lang="en">',
            '<title>Phoenix Test</title>',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css">',
            '<a class="skip-link" href="#main">Skip to content</a>',
            '<header class="site-header"><p class="site-title"><a href="/" rel="home">Phoenix Test</a></p></header>',
            '<main id="main" class="site-main" tabindex="-1"><h1>Welcome to Phoenix Test</h1>',
            '<footer class="site-footer"><p class="site-credit">Powered by Xaraya Phoenix</p></footer>',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringNotContainsString('<aside', $html, 'an empty sidebar is left out');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('style=', $html);
    }

    public function testErrorPagesAreIdenticalInBothEngines(): void
    {
        $html = self::sameHtml(
            $this->app('php')->handle(new ServerRequest('GET', '/nope')),
            $this->app('twig')->handle(new ServerRequest('GET', '/nope')),
        );
        self::assertStringContainsString('<title>404 Not Found</title>', $html);
        self::assertStringContainsString('<h1>Page not found</h1>', $html);

        $html = self::sameHtml(
            $this->app('php')->handle(new ServerRequest('DELETE', '/')),
            $this->app('twig')->handle(new ServerRequest('DELETE', '/')),
        );
        self::assertStringContainsString('<h1>405 Method Not Allowed</h1>', $html);
    }

    public function testRegionsMetaFeedsAndSettingsAreIdenticalInBothEngines(): void
    {
        $app = $this->app('php');
        $this->migrateKernel($app);
        $blocks = $app->container()->get(BlockRepository::class);
        $blocks->create('text', 'header', null, ['body' => '<p>head</p>']);
        $blocks->create('text', 'sidebar', 'Side', ['body' => '<p>side</p>']);
        $blocks->create('text', 'footer', null, ['format' => 'markdown', 'body' => 'foot *note*']);
        $app->container()->get(Settings::class)->set('theme.phoenix', 'tagline', 'Small & neutral');
        $page = new Page(
            'home',
            title: 'Custom',
            meta: ['description' => 'D', 'og:type' => 'website'],
            feeds: [['type' => 'application/feed+json', 'title' => 'Feed', 'href' => '/feed.json']],
            canonical: 'http://xar.test/',
            lang: 'fr',
            styles: ['/assets/module/blog/blog.css'],
        );
        $rendered = [];
        foreach (['php', 'twig'] as $engine) {
            $rendered[$engine] = Html::normalize($this->app($engine)->container()->get(View::class)->page($page));
        }
        self::assertSame($rendered['php'], $rendered['twig']);
        foreach ([
            '<html lang="fr">',
            '<title>Custom</title>',
            '<meta name="description" content="D">',
            '<meta property="og:type" content="website">',
            '<link rel="canonical" href="http://xar.test/">',
            '<link rel="alternate" type="application/feed+json" title="Feed" href="/feed.json">',
            '<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css"><link rel="stylesheet" href="/assets/module/blog/blog.css">',
            '<p class="site-tagline">Small &amp; neutral</p><section class="block block-text"><p>head</p></section></header>',
            '<aside class="site-sidebar" aria-label="Sidebar"><section class="block block-text" aria-labelledby="block-2-title"><h2 class="block-title" id="block-2-title">Side</h2><p>side</p></section></aside>',
            '<p>foot <em>note</em></p>',
        ] as $needle) {
            self::assertStringContainsString($needle, $rendered['php']);
        }
    }

    public function testStylesheetHonoursUserPreferences(): void
    {
        $css = (string) file_get_contents($this->theme() . '/assets/phoenix.css');
        foreach (['prefers-color-scheme: dark', 'prefers-reduced-motion: reduce', ':focus-visible', '.skip-link:focus'] as $needle) {
            self::assertStringContainsString($needle, $css);
        }
        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('url(http', $css);
    }

    public function testAModuleCanClaimTheHomeRoute(): void
    {
        Fixtures::module($this->tmp . '/modules', 'front', ['routes' => 'Xaraya\\Module\\Front\\Routes'], ['src/Routes.php' => <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Xaraya\Module\Front;

            use Nyholm\Psr7\Response;
            use Xaraya\Kernel\Routing\RouteCollector;
            use Xaraya\Kernel\Routing\RouteProvider;

            final class Routes implements RouteProvider
            {
                public function routes(RouteCollector $routes): void
                {
                    $routes->get('/', fn() => new Response(200, [], 'front'), 'front.home');
                }
            }
            PHP]);
        $this->app('php', [$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('front');
        $app = $this->app('php', [$this->tmp . '/modules']);
        self::assertSame('front', (string) $app->handle(new ServerRequest('GET', '/'))->getBody());
        self::assertFalse($app->container()->get(UrlGenerator::class)->has('home'));
    }
}
```

In `tests/Kernel/AppTest.php`:
- In `testUnknownRoutesGiveHtmlOrJson404`, change `new ServerRequest('GET', '/')` to `new ServerRequest('GET', '/nope')`. `/` is now the kernel home page.
- In `testBootWiresCoreServices`, add after the `has('anything')` assertion:
```php
        self::assertTrue($c->get(UrlGenerator::class)->has('home'));
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/View/PhoenixThemeTest.php tests/Kernel/AppTest.php`
Expected: failures and errors, for example the missing `themes/phoenix/templates` directory, a 404 for `/`, and no `home` route.

- [ ] **Step 3: Write the theme**

`themes/phoenix/theme.json`:
```json
{
  "name": "phoenix",
  "version": "0.1.0",
  "engine": "php",
  "regions": ["header", "sidebar", "footer"],
  "settings": {
    "tagline": { "type": "string", "label": "Tagline", "default": "" }
  }
}
```

`themes/phoenix/templates/layout.php`:
```php
<!doctype html>
<html lang="<?= $x->e($lang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $x->e($title) ?></title>
<?php foreach ($meta as $name => $value): ?>
<meta <?= str_starts_with((string) $name, 'og:') ? 'property' : 'name' ?>="<?= $x->e($name) ?>" content="<?= $x->e($value) ?>">
<?php endforeach ?>
<?php if ($canonical): ?>
<link rel="canonical" href="<?= $x->e($canonical) ?>">
<?php endif ?>
<?php foreach ($feeds as $feed): ?>
<link rel="alternate" type="<?= $x->e($feed['type']) ?>" title="<?= $x->e($feed['title']) ?>" href="<?= $x->e($feed['href']) ?>">
<?php endforeach ?>
<link rel="stylesheet" href="<?= $x->e($x->asset('theme/phoenix/phoenix.css')) ?>">
<?php foreach ($styles as $href): ?>
<link rel="stylesheet" href="<?= $x->e($href) ?>">
<?php endforeach ?>
</head>
<body>
<a class="skip-link" href="#main"><?= $x->e($x->t('Skip to content')) ?></a>
<header class="site-header">
<p class="site-title"><a href="/" rel="home"><?= $x->e($site) ?></a></p>
<?php if ($settings['tagline'] ?? ''): ?>
<p class="site-tagline"><?= $x->e($settings['tagline']) ?></p>
<?php endif ?>
<?= $x->blocks('header') ?>
</header>
<div class="site-body">
<main id="main" class="site-main" tabindex="-1">
<?= $content ?>
</main>
<?php $sidebar = $x->blocks('sidebar'); ?>
<?php if ($sidebar !== ''): ?>
<aside class="site-sidebar" aria-label="<?= $x->e($x->t('Sidebar')) ?>">
<?= $sidebar ?>
</aside>
<?php endif ?>
</div>
<footer class="site-footer">
<?= $x->blocks('footer') ?>
<p class="site-credit"><?= $x->e($x->t('Powered by {name}', ['name' => 'Xaraya Phoenix'])) ?></p>
</footer>
</body>
</html>
```

`themes/phoenix/templates/layout.twig`:
```twig
<!doctype html>
<html lang="{{ lang }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ title }}</title>
{% for name, value in meta %}
<meta {{ name starts with 'og:' ? 'property' : 'name' }}="{{ name }}" content="{{ value }}">
{% endfor %}
{% if canonical %}
<link rel="canonical" href="{{ canonical }}">
{% endif %}
{% for feed in feeds %}
<link rel="alternate" type="{{ feed.type }}" title="{{ feed.title }}" href="{{ feed.href }}">
{% endfor %}
<link rel="stylesheet" href="{{ asset('theme/phoenix/phoenix.css') }}">
{% for href in styles %}
<link rel="stylesheet" href="{{ href }}">
{% endfor %}
</head>
<body>
<a class="skip-link" href="#main">{{ t('Skip to content') }}</a>
<header class="site-header">
<p class="site-title"><a href="/" rel="home">{{ site }}</a></p>
{% if settings.tagline|default('') %}
<p class="site-tagline">{{ settings.tagline }}</p>
{% endif %}
{{ blocks('header') }}
</header>
<div class="site-body">
<main id="main" class="site-main" tabindex="-1">
{{ content|raw }}
</main>
{% set sidebar = blocks('sidebar') %}
{% if sidebar != '' %}
<aside class="site-sidebar" aria-label="{{ t('Sidebar') }}">
{{ sidebar|raw }}
</aside>
{% endif %}
</div>
<footer class="site-footer">
{{ blocks('footer') }}
<p class="site-credit">{{ t('Powered by {name}', {name: 'Xaraya Phoenix'}) }}</p>
</footer>
</body>
</html>
```

`themes/phoenix/templates/home.php`:
```php
<h1><?= $x->e($x->t('Welcome to {name}', ['name' => $x->config('app.name', 'Xaraya Phoenix')])) ?></h1>
<p><?= $x->e($x->t('This site runs Xaraya Phoenix. Enable a module to add pages, blocks and feeds.')) ?></p>
```

`themes/phoenix/templates/home.twig`:
```twig
<h1>{{ t('Welcome to {name}', {name: config('app.name', 'Xaraya Phoenix')}) }}</h1>
<p>{{ t('This site runs Xaraya Phoenix. Enable a module to add pages, blocks and feeds.') }}</p>
```

`themes/phoenix/templates/block.php`:
```php
<section class="block <?= $x->e($typeClass) ?>"<?php if ($title): ?> aria-labelledby="block-<?= $x->e($id) ?>-title"<?php endif ?>>
<?php if ($title): ?>
<h2 class="block-title" id="block-<?= $x->e($id) ?>-title"><?= $x->e($title) ?></h2>
<?php endif ?>
<?= $content ?>
</section>
```

`themes/phoenix/templates/block.twig`:
```twig
<section class="block {{ typeClass }}"{% if title %} aria-labelledby="block-{{ id }}-title"{% endif %}>
{% if title %}
<h2 class="block-title" id="block-{{ id }}-title">{{ title }}</h2>
{% endif %}
{{ content|raw }}
</section>
```

`themes/phoenix/templates/error/404.php`:
```php
<h1><?= $x->e($x->t('Page not found')) ?></h1>
<?php if ($message !== $reason): ?>
<p><?= $x->e($message) ?></p>
<?php endif ?>
<p><?= $x->e($x->t('Sorry, there is nothing at this address.')) ?></p>
<p><a href="/"><?= $x->e($x->t('Go to the home page')) ?></a></p>
```

`themes/phoenix/templates/error/404.twig`:
```twig
<h1>{{ t('Page not found') }}</h1>
{% if message != reason %}
<p>{{ message }}</p>
{% endif %}
<p>{{ t('Sorry, there is nothing at this address.') }}</p>
<p><a href="/">{{ t('Go to the home page') }}</a></p>
```

`themes/phoenix/templates/error/default.php`:
```php
<h1><?= $x->e($status) ?> <?= $x->e($reason) ?></h1>
<?php if ($message !== $reason): ?>
<p><?= $x->e($message) ?></p>
<?php endif ?>
<p><a href="/"><?= $x->e($x->t('Go to the home page')) ?></a></p>
```

`themes/phoenix/templates/error/default.twig`:
```twig
<h1>{{ status }} {{ reason }}</h1>
{% if message != reason %}
<p>{{ message }}</p>
{% endif %}
<p><a href="/">{{ t('Go to the home page') }}</a></p>
```

`themes/phoenix/assets/phoenix.css`:
```css
/* Phoenix: a small, neutral theme. No web fonts, no JavaScript, no third-party URLs. */
:root {
  color-scheme: light dark;
  --bg: #ffffff;
  --fg: #1b1b1f;
  --muted: #5c5c66;
  --accent: #0b57d0;
  --border: #d9d9e0;
  --focus: #ff9e1b;
  --max: 72rem;
}

@media (prefers-color-scheme: dark) {
  :root {
    --bg: #121216;
    --fg: #ececf1;
    --muted: #a3a3ad;
    --accent: #8ab4f8;
    --border: #2e2e36;
  }
}

*, *::before, *::after { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--fg); font: 1.0625rem/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
a { color: var(--accent); text-underline-offset: 0.15em; }
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible {
  outline: 3px solid var(--focus);
  outline-offset: 2px;
}
main:focus { outline: none; }

.skip-link { position: absolute; left: 1rem; top: -3rem; z-index: 10; padding: 0.5rem 1rem; background: var(--fg); color: var(--bg); }
.skip-link:focus { top: 1rem; }

.site-header, .site-body, .site-footer { max-width: var(--max); margin: 0 auto; padding: 1rem; }
.site-header { border-bottom: 1px solid var(--border); }
.site-title { margin: 0; font-size: 1.375rem; font-weight: 700; }
.site-title a { color: inherit; text-decoration: none; }
.site-tagline { margin: 0.25rem 0 0; color: var(--muted); }
.site-body { display: grid; gap: 2rem; }
@media (min-width: 60rem) {
  .site-body { grid-template-columns: minmax(0, 1fr) 18rem; }
}
.site-main { min-width: 0; }
.site-footer { border-top: 1px solid var(--border); color: var(--muted); font-size: 0.9375rem; }

.block { margin: 0 0 1.5rem; }
.block-title { margin: 0 0 0.5rem; font-size: 0.875rem; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); }
.menu, .recent-items { margin: 0; padding: 0; list-style: none; }
.menu li, .recent-items li { margin: 0.25rem 0; }
[aria-current="page"] { font-weight: 700; }

img, video, audio { max-width: 100%; height: auto; }
pre, code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
pre { overflow-x: auto; padding: 1rem; border: 1px solid var(--border); }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

- [ ] **Step 4: Add the home controller and route**

`src/Kernel/Http/HomeController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\View\Page;

/** The theme's "home" page at "/", until a module claims that route. */
final class HomeController
{
    /** @param array<string, string> $params */
    public function __invoke(ServerRequestInterface $request, array $params): Page
    {
        return new Page('home');
    }
}
```

In `src/Kernel/App.php`:
- Add `use Xaraya\Kernel\Http\HomeController;`.
- In `routes()`, replace `return $this->routes = $collector->routes();` with:
```php
        $claimed = false;
        foreach ($collector->routes() as $route) {
            $claimed = $claimed || $route->name === 'home' || ($route->path === '/' && in_array('GET', $route->methods, true));
        }
        if (!$claimed) {
            $collector->get('/', HomeController::class, 'home')->middleware('secureHtml', 'conditional');
        }

        return $this->routes = $collector->routes();
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/View/PhoenixThemeTest.php tests/Kernel/AppTest.php`
Expected: PASS.

If a parity assertion fails, diff the two normalised strings. Fix the template that differs and keep the HTML structure the test expects. Do not loosen `Html::normalize()`.

- [ ] **Step 6: Run the full checks and commit**

Run `composer test && composer stan && composer cs`, also with the MySQL env vars.
`AppTest::testCorsRouteErrorsCarryAllowOrigin` must still find `no such feed` in the body: `error/404` prints the message whenever it differs from the reason.

```bash
git add -A
git commit -m "feat(theme): add the neutral phoenix theme and the kernel home page" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Examples: `hello` templates and block, the `hello-hooks` display hook, and docs

**Files:**
- Create:
  - `examples/hello/templates/index.php`, `index.twig`, `show.php`, `show.twig`, `partials/greeting.php`, `partials/greeting.twig`
  - `examples/hello/lang/fr.php`, `examples/hello/src/GreetingCountBlock.php`
  - `examples/hello-hooks/module.json`, `examples/hello-hooks/src/NoteHook.php`
- Modify: `examples/hello/module.json`, `examples/hello/src/HelloController.php`, `examples/hello/src/Routes.php`, `README.md`
- Test: `tests/Kernel/HelloModuleTest.php` (replace the whole file)

**Interfaces:**
- **Consumes:**
  - `Controller::view`, `Page` (Task 8); `DisplayHooks::call` (Task 5); `Block`, `BlockContext` (Task 9); `Settings` (Task 2)
  - the `secureHtml` alias (Task 11), the `phoenix` theme (Task 13), `Html` (Task 7)
  - the existing `HelloController::feed/create`, `CountGreetings` and the `greetings` table
- **Produces the `hello` routes:**
  - `hello.index` `GET /hello` (`secureHtml`, `conditional`) renders `hello::index`.
  - `hello.show` `GET /hello/{id:[0-9a-hjkmnp-tv-z]{26}}` (`secureHtml`, `conditional`) renders `hello::show` and returns 404 for an unknown id.
  - `hello.create` `POST /hello/{name}` keeps its JSON 201, and also calls `item.form.save` with the parsed body.
  - `hello.feed` is unchanged.
- **Produces the hello block type and default:**
  - `hello.count` (`GreetingCountBlock`) renders `<p class="greeting-count">Greetings so far: N</p>`.
  - It is seeded into `sidebar` with the title `Greetings` and `{"routes": ["hello.index"]}`.
- **Produces `hello-hooks`:**
  - Its `NoteHook` handles all three hooks for `hello`/`greeting`. It stores notes in `Settings` scope `hello-hooks`, key `note.<id>`.
  - `item.display` renders `<aside class="hello-hooks" aria-label="Note">Note: …</aside>`, or `No note yet.`.
  - `item.form` renders an input named `note`.
- **Produces `examples/hello/lang/fr.php`:** French strings for every `t()` key that `hello` uses.

- [ ] **Step 1: Write the failing test**

Replace `tests/Kernel/HelloModuleTest.php` with:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Hello\CountGreetings;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Html;

final class HelloModuleTest extends AppTestCase
{
    /** @param array<string, mixed> $overrides */
    private function app(array $overrides = []): App
    {
        return $this->boot([dirname(__DIR__, 2) . '/examples'], [
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.locale' => 'en',
            ...$overrides,
        ]);
    }

    private function xar(string ...$args): void
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        $code = (new Application($this->app()))->run(['xar', ...$args], new Output($out, $out));
        rewind($out);
        self::assertSame(0, $code, (string) stream_get_contents($out));
    }

    private function enableHello(): void
    {
        $this->xar('module:enable', 'hello');
    }

    /** @param array<string, string> $body */
    private function greet(App $app, string $name, array $body = []): string
    {
        $request = new ServerRequest('POST', '/hello/' . $name);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        $response = $app->handle($request);
        self::assertSame(201, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true)['id'];
    }

    private function html(App $app, string $path): string
    {
        return Html::normalize((string) $app->handle(new ServerRequest('GET', $path))->getBody());
    }

    public function testRoutesAreAbsentUntilEnabled(): void
    {
        self::assertSame(404, $this->app()->handle(new ServerRequest('GET', '/hello'))->getStatusCode());
    }

    public function testCreateListAndEvents(): void
    {
        $this->enableHello();
        self::assertTrue($this->app()->container()->get(Connection::class)->hasTable('greetings'));

        $app = $this->app();
        $id = $this->greet($app, 'ada');
        self::assertTrue(Ulid::isValid($id));
        self::assertSame(1, $app->container()->get(CountGreetings::class)->count);

        $page = $app->handle(new ServerRequest('GET', '/hello'));
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Hello, ada!', (string) $page->getBody());
    }

    public function testJsonFeedWithCorsAndConditionalGet(): void
    {
        $this->enableHello();
        $app = $this->app();
        $this->greet($app, 'grace');

        $feed = $app->handle(new ServerRequest('GET', '/hello.json'));
        self::assertSame(200, $feed->getStatusCode());
        self::assertSame('*', $feed->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(['greetings' => ['grace']], json_decode((string) $feed->getBody(), true));
        $etag = $feed->getHeaderLine('ETag');
        self::assertNotSame('', $etag);

        $again = $app->handle((new ServerRequest('GET', '/hello.json'))->withHeader('If-None-Match', $etag));
        self::assertSame(304, $again->getStatusCode());
        self::assertSame('*', $again->getHeaderLine('Access-Control-Allow-Origin'));

        $preflight = $app->handle(new ServerRequest('OPTIONS', '/hello.json'));
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('GET, HEAD, OPTIONS', $preflight->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testRoutingErrors(): void
    {
        $this->enableHello();
        $app = $this->app();

        $wrongMethod = $app->handle(new ServerRequest('DELETE', '/hello'));
        self::assertSame(405, $wrongMethod->getStatusCode());
        self::assertSame('GET', $wrongMethod->getHeaderLine('Allow'));

        self::assertSame(404, $app->handle(new ServerRequest('POST', '/hello/Not-Lowercase'))->getStatusCode());
    }

    public function testUrlGeneration(): void
    {
        $this->enableHello();
        $urls = $this->app()->container()->get(UrlGenerator::class);
        self::assertSame('http://xar.test/hello/ada', $urls->generate('hello.create', ['name' => 'ada'], true));
    }

    public function testPagesUseThePhoenixThemeAndSecurityHeaders(): void
    {
        $this->enableHello();
        $app = $this->app();
        $id = $this->greet($app, 'ada');

        $index = $app->handle(new ServerRequest('GET', '/hello'));
        self::assertSame(200, $index->getStatusCode());
        self::assertSame('nosniff', $index->getHeaderLine('X-Content-Type-Options'));
        self::assertNotSame('', $index->getHeaderLine('ETag'));
        $html = Html::normalize((string) $index->getBody());
        self::assertStringContainsString('<a class="skip-link" href="#main">', $html);
        self::assertStringContainsString('<title>Hello</title>', $html);
        self::assertStringContainsString('<a href="/hello/' . $id . '">Hello, ada!</a>', $html);

        $missing = $app->handle(new ServerRequest('GET', '/hello/' . Ulid::generate()));
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString("default-src 'self'", $missing->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('<h1>Page not found</h1>', (string) $missing->getBody());
    }

    public function testTemplatesRenderIdenticallyInBothEngines(): void
    {
        $this->xar('module:enable', 'hello');
        $this->xar('module:enable', 'hello-hooks');
        $id = $this->greet($this->app(), 'grace', ['note' => 'parity']);
        foreach (['/hello', '/hello/' . $id] as $path) {
            self::assertSame(
                $this->html($this->app(['view.engine' => 'php']), $path),
                $this->html($this->app(['view.engine' => 'twig']), $path),
                $path,
            );
        }
    }

    public function testSidebarBlockHidesWhenItsVisibilityRuleExcludesTheRoute(): void
    {
        $this->enableHello();
        $app = $this->app();
        $id = $this->greet($app, 'ada');
        self::assertStringContainsString(
            '<aside class="site-sidebar" aria-label="Sidebar"><section class="block block-hello-count" aria-labelledby="block-1-title"><h2 class="block-title" id="block-1-title">Greetings</h2><p class="greeting-count">Greetings so far: 1</p></section></aside>',
            $this->html($app, '/hello'),
        );
        self::assertStringNotContainsString('greeting-count', $this->html($app, '/hello/' . $id));
    }

    public function testHelloHooksAddsAFragmentThatFollowsTheBinding(): void
    {
        $this->xar('module:enable', 'hello');
        $this->xar('module:enable', 'hello-hooks');
        $id = $this->greet($this->app(), 'ada', ['note' => 'likes engines']);
        self::assertStringContainsString('<aside class="hello-hooks" aria-label="Note">Note: likes engines</aside>', $this->html($this->app(), '/hello/' . $id));

        $this->xar('hook:disable', 'hello-hooks', 'hello', 'greeting');
        self::assertStringNotContainsString('hello-hooks', $this->html($this->app(), '/hello/' . $id));

        $this->xar('hook:enable', 'hello-hooks', 'hello', 'greeting');
        self::assertStringContainsString('Note: likes engines', $this->html($this->app(), '/hello/' . $id));
    }

    public function testHelloIsTranslatable(): void
    {
        $this->enableHello();
        $app = $this->app(['app.locale' => 'fr']);
        $this->greet($app, 'ada');
        $html = $this->html($app, '/hello');
        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringContainsString('<h1>Salutations</h1>', $html);
        self::assertStringContainsString('Bonjour, ada !', $html);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/HelloModuleTest.php`
Expected: failures. `/hello` is still bare HTML, there is no `hello.show` route, no block and no `hello-hooks`. The five original tests still pass.

- [ ] **Step 3: Update `hello`**

`examples/hello/module.json`:
```json
{
  "name": "hello",
  "version": "0.1.0",
  "requires": { "kernel": "^0.1", "modules": {} },
  "routes": "Xaraya\\Module\\Hello\\Routes",
  "migrations": "migrations",
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Hello\\CountGreetings", "priority": 0 }
  ],
  "blocks": { "hello.count": "Xaraya\\Module\\Hello\\GreetingCountBlock" },
  "blockDefaults": [
    { "type": "hello.count", "region": "sidebar", "title": "Greetings", "visibility": { "routes": ["hello.index"] } }
  ]
}
```

`examples/hello/src/Routes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/hello', [HelloController::class, 'index'], 'hello.index')->middleware('secureHtml', 'conditional');
        $routes->get('/hello.json', [HelloController::class, 'feed'], 'hello.feed')->middleware('cors', 'conditional');
        $routes->get('/hello/{id:[0-9a-hjkmnp-tv-z]{26}}', [HelloController::class, 'show'], 'hello.show')->middleware('secureHtml', 'conditional');
        $routes->post('/hello/{name:[a-z]+}', [HelloController::class, 'create'], 'hello.create');
    }
}
```

`examples/hello/src/HelloController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Kernel\View\Page;

final class HelloController extends Controller
{
    public function __construct(
        private readonly Connection $db,
        private readonly EventDispatcher $events,
        private readonly DisplayHooks $hooks,
    ) {}

    /** @param array<string, string> $params */
    public function index(ServerRequestInterface $request, array $params): Page
    {
        return $this->view('hello::index', ['greetings' => $this->greetings()], 'Hello');
    }

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): Page
    {
        $row = $this->db->select('greetings')->where('id', '=', $params['id'])->first() ?? $this->notFound('No such greeting');
        $greeting = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'created' => (string) $row['created']];

        return $this->view('hello::show', ['greeting' => $greeting], 'Hello, ' . $greeting['name']);
    }

    /** @param array<string, string> $params */
    public function feed(ServerRequestInterface $request, array $params): ResponseInterface
    {
        return $this->json(['greetings' => array_column($this->greetings(), 'name')]);
    }

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $id = Ulid::generate();
        $this->db->insert('greetings', ['id' => $id, 'name' => $params['name'], 'created' => new DateTimeImmutable()]);
        $this->events->dispatch(new ItemCreated('hello', 'greeting', $id, ['name' => $params['name']]));
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $input */
        $input = is_array($body) ? $body : [];
        $this->hooks->call('item.form.save', ['module' => 'hello', 'itemtype' => 'greeting', 'id' => $id, 'name' => $params['name']], $input);

        return $this->json(['id' => $id], 201);
    }

    /** @return list<array{id: string, name: string}> */
    private function greetings(): array
    {
        $rows = $this->db->select('greetings')->columns('id', 'name')->orderBy('created')->orderBy('id')->all();

        return array_map(static fn(array $row): array => ['id' => (string) $row['id'], 'name' => (string) $row['name']], $rows);
    }
}
```

`examples/hello/src/GreetingCountBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Db\Connection;

final class GreetingCountBlock implements Block
{
    public function __construct(private readonly Connection $db) {}

    public function render(array $config, BlockContext $context): string
    {
        $x = $context->helpers;

        return '<p class="greeting-count">' . $x->e($x->t('Greetings so far: {count}', ['count' => $this->db->select('greetings')->count()])) . '</p>';
    }
}
```

`examples/hello/templates/index.php`:
```php
<h1><?= $x->e($x->t('Greetings')) ?></h1>
<?php if ($greetings === []): ?>
<p><?= $x->e($x->t('Nobody has been greeted yet.')) ?></p>
<?php else: ?>
<ul class="greetings">
<?php foreach ($greetings as $greeting): ?>
<?= $x->include('hello::partials/greeting', ['greeting' => $greeting]) ?>
<?php endforeach ?>
</ul>
<?php endif ?>
```

`examples/hello/templates/index.twig`:
```twig
<h1>{{ t('Greetings') }}</h1>
{% if greetings is empty %}
<p>{{ t('Nobody has been greeted yet.') }}</p>
{% else %}
<ul class="greetings">
{% for greeting in greetings %}
{% include 'hello::partials/greeting' with {greeting: greeting} only %}
{% endfor %}
</ul>
{% endif %}
```

`examples/hello/templates/partials/greeting.php`:
```php
<li><a href="<?= $x->e($x->url('hello.show', ['id' => $greeting['id']])) ?>"><?= $x->e($x->t('Hello, {name}!', ['name' => $greeting['name']])) ?></a></li>
```

`examples/hello/templates/partials/greeting.twig`:
```twig
<li><a href="{{ url('hello.show', {id: greeting.id}) }}">{{ t('Hello, {name}!', {name: greeting.name}) }}</a></li>
```

`examples/hello/templates/show.php`:
```php
<article class="greeting">
<h1><?= $x->e($x->t('Hello, {name}!', ['name' => $greeting['name']])) ?></h1>
<p><time datetime="<?= $x->e($greeting['created']) ?>"><?= $x->e($greeting['created']) ?></time></p>
<?= $x->hooks('item.display', ['module' => 'hello', 'itemtype' => 'greeting', 'id' => $greeting['id'], 'name' => $greeting['name']]) ?>
<p><a href="<?= $x->e($x->url('hello.index')) ?>"><?= $x->e($x->t('All greetings')) ?></a></p>
</article>
```

`examples/hello/templates/show.twig`:
```twig
<article class="greeting">
<h1>{{ t('Hello, {name}!', {name: greeting.name}) }}</h1>
<p><time datetime="{{ greeting.created }}">{{ greeting.created }}</time></p>
{{ hooks('item.display', {module: 'hello', itemtype: 'greeting', id: greeting.id, name: greeting.name}) }}
<p><a href="{{ url('hello.index') }}">{{ t('All greetings') }}</a></p>
</article>
```

`examples/hello/lang/fr.php`:
```php
<?php

declare(strict_types=1);

return [
    'Greetings' => 'Salutations',
    'Hello, {name}!' => 'Bonjour, {name} !',
    'Nobody has been greeted yet.' => 'Personne n’a encore été salué.',
    'All greetings' => 'Toutes les salutations',
    'Greetings so far: {count}' => 'Salutations jusqu’ici : {count}',
];
```

- [ ] **Step 4: Add `hello-hooks`**

`examples/hello-hooks/module.json`:
```json
{
  "name": "hello-hooks",
  "version": "0.1.0",
  "requires": { "kernel": "^0.1", "modules": {} },
  "displayHooks": {
    "item.display": "Xaraya\\Module\\HelloHooks\\NoteHook",
    "item.form": "Xaraya\\Module\\HelloHooks\\NoteHook",
    "item.form.save": "Xaraya\\Module\\HelloHooks\\NoteHook"
  },
  "hookDefaults": [ { "subject": "hello", "itemtype": "greeting" } ]
}
```

`examples/hello-hooks/src/NoteHook.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\HelloHooks;

use Xaraya\Kernel\Hooks\DisplayHook;
use Xaraya\Kernel\Settings\Settings;

/** Lets any hooked item carry a short note, stored in xar_settings (scope "hello-hooks"). */
final class NoteHook implements DisplayHook
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(string $hook, array $item, array $input = []): string
    {
        $id = is_string($item['id'] ?? null) ? $item['id'] : '';

        return match ($hook) {
            'item.display' => $this->display($id),
            'item.form' => '<p><label for="hello-hooks-note">Note</label> <input id="hello-hooks-note" name="note" maxlength="200" value="' . self::e($this->note($id)) . '"></p>',
            'item.form.save' => $this->save($id, $input),
            default => '',
        };
    }

    private function display(string $id): string
    {
        $note = $this->note($id);

        return '<aside class="hello-hooks" aria-label="Note">' . ($note === '' ? 'No note yet.' : 'Note: ' . self::e($note)) . '</aside>';
    }

    /** @param array<string, mixed> $input */
    private function save(string $id, array $input): string
    {
        $note = is_string($input['note'] ?? null) ? trim(mb_substr($input['note'], 0, 200)) : '';
        if ($id !== '' && $note !== '') {
            $this->settings->set('hello-hooks', 'note.' . $id, $note);
        }

        return '';
    }

    private function note(string $id): string
    {
        $note = $id === '' ? null : $this->settings->get('hello-hooks', 'note.' . $id);

        return is_string($note) ? $note : '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 5: Document views, themes and blocks**

In `README.md`'s Quick start block, add `bin/xar asset:publish` on the line after `bin/xar migrate`. Then insert this section immediately before `## Development`:
````markdown
## Themes, templates and blocks

Pages render through the active theme (`APP_THEME`, default `phoenix`, found under `THEME_PATHS`).
Templates exist as `.php` or `.twig` (Twig is optional: `composer require twig/twig`); the theme's
`engine` setting, or `VIEW_ENGINE`, picks one when both exist.

- `module::name` is a module template: `themes/<theme>/templates/modules/<module>/<name>.*` overrides
  `modules/<module>/templates/<name>.*`. Plain names (`layout`, `block`, `home`, `error/404`) are theme templates.
- PHP templates get variables directly and helpers as `$x`: `$x->e()` (escape: always use it), `$x->t()`,
  `$x->url()`, `$x->asset()`, `$x->blocks('sidebar')`, `$x->hooks('item.display', $item)`, `$x->render()`.
  Twig has the same helpers as functions and escapes automatically.
- Blocks live in `xar_blocks` and render in theme regions. Modules ship block types (`"blocks"`) and
  seed instances on first install (`"blockDefaults"`). Built-in types: `text`, `menu`, `recent-items`.
- Display hooks: a module's `"displayHooks"` add HTML to other modules' items, bound in `xar_hooks`.
  Toggle a binding with `bin/xar hook:disable <observer> <subject> [itemtype]` / `hook:enable`.
- `bin/xar asset:publish` links `modules/*/assets` and `themes/*/assets` into `public/assets`.

Try it with the examples: `MODULE_PATHS=modules,examples bin/xar module:enable hello`, then
`module:enable hello-hooks`, and open `/hello`.
````

- [ ] **Step 6: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Kernel/HelloModuleTest.php`, then repeat with the MySQL env vars.
Expected: PASS (10 tests) on both.

- [ ] **Step 7: Run the final verification**

Run each of these:
- `composer test` on SQLite, then with the MySQL env vars: both PASS. This includes the blog module's `AthenaParityTest`, whose JSON output this plan never touches.
- `composer stan`: `[OK] No errors`. Templates are excluded (Task 7).
- `composer cs`: exit 0.
- `find src/Kernel -name '*.php' | xargs wc -l | tail -1`: under about 6,000.
- Manual smoke test:
  - Run `rm -f var/database.sqlite && bin/xar migrate && bin/xar asset:publish && bin/xar serve`.
  - Open `http://localhost:8080/`. You should see the phoenix home page, styled, with no console errors.
  - Run `MODULE_PATHS=modules,examples bin/xar module:enable hello` and `… module:enable hello-hooks`, then open `/hello`.
- `git status --short`: empty after the commit.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(examples): render hello through templates with a sidebar block and a hello-hooks display hook" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Do not push. The controller pushes and checks CI (Postgres runs only there).

---

## Self-review

**1. Spec coverage**

| Requirement | Task |
|---|---|
| Kernel §5.1: `View::render(name, data)`, the extension picks the engine | 7 |
| Kernel §5.1: a clear exception when Twig is missing | 7 (`TwigEngine`), 6 (a `.php` sibling wins without Twig) |
| Kernel §5.1: lookup order: active theme, then parent theme, then module; theme-level templates in the theme chain | 6 |
| Kernel §5.1: the theme's `engine` breaks ties | 6, plus the `view.engine` override |
| Kernel §5.1: layout wrapping with `content`, `title`, `meta` and `feeds`; layout and body may use different engines | 8 |
| Kernel §5.1: `include` stays in its engine, `render()` crosses engines | 7 |
| Kernel §5.2: helpers `url`, `asset`, `can`, `hooks`, `blocks`, `csrf`, `t`, `e`, `user`, `render`, `config` in both engines | 7 and 9 |
| Kernel §5.2: Twig auto-escapes, PHP uses `$x->e()` | 7 (with a documented global constraint) |
| Kernel §5.3: `t()`, catalogs in `modules/*/lang` and `themes/*/lang`, `{name}` placeholders, English fallback | 4 |
| Kernel §5.4: `theme.json` (`name`, `version`, `parent`, `engine`, `regions`, `settings`) | 3 |
| Kernel §5.4: settings stored in `xar_settings`, also the general key-value store | 2 (store), 8 (theme settings in the layout) |
| Kernel §5.4: `xar asset:publish` with symlinks or copies; no build step | 12 |
| Kernel §5.5: the `Block` interface with `render`, plus optional `form`/`validate` | 9 (`Block`, `ConfigurableBlock`) |
| Kernel §5.5: the `xar_blocks` schema with visibility routes and roles | 9 |
| Kernel §5.5: built-in blocks: text, menu, recent-items through `RecentItemsQuery` | 10. The login block is Plan 3. |
| Kernel §5.6: `phoenix` ships both engines, with a parity test | 13 (theme), 14 (hello pages) |
| Kernel §3.4: `hooks('item.display')`, `hooks('item.form')`, `item.form.save` | 5 (service), 7 (helper), 14 (hello and hello-hooks) |
| Kernel §3.4: the `xar_hooks` table, `hookDefaults` seeded on enable, `hook:enable` and `hook:disable` | 5 |
| Kernel §3.4: non-display subscribers ignore bindings | Unchanged. `EventDispatcher` is not touched. |
| Kernel §6.1: `hook:enable`, `hook:disable`, `asset:publish` and `cache:clear` | 5, 12, 1 |
| Kernel §6.3: `ErrorHandler` renders `error/<status>` or the built-in fallback, and JSON for JSON clients | 11 |
| Kernel §7, item 3 (view parts): Twig and PHP give identical HTML | 14 |
| Kernel §7, item 3 (view parts): a hello-hooks fragment that goes away when the binding is disabled | 14 |
| Kernel §7, item 3 (view parts): a sidebar block that hides by visibility | 14 |
| Kernel §7, item 1: the phoenix home page | 13. `xar install` is Plan 3. |
| Blog spec §3.3: a file cache under `var/cache`, cleared by `cache:clear` | 1 |
| Blog spec §3.4: the generic `secureHtml` alias with the exact headers | 11 |
| Blog spec §7.1: the renderHtml hook rendering `error/{status}` | 11 |
| Blog spec §2.2 and §1: overridable module partials, seeded sidebar blocks, `styles` and OG `meta` | 6, 9 (`blockDefaults`), 8 and 13 (layout contract) |
| Follow-up: `ModuleRegistry::enabled()` ran twice per request | 5 (memoised) |
| Follow-up: `wantsJson` path match was case-sensitive | 11 |
| Follow-up: `reason()` lacked 502 and 504 | 11 |
| Follow-up: readonly test properties break `tearDown` | Global Constraints |
| Users, auth, roles, CSRF, the login block, grants seeding and `xar install` | Out of scope (Plan 3). `GuestAccess` and `csrf()` are the documented stubs. |

**2. Placeholder scan:** every code step holds complete code. There is no "TBD", no "similar to Task N", and no step without its commands.

**3. Type consistency:** these names and signatures are used the same way in every task where they appear:
- `Fixtures::theme/module`, `ViewFixtures::themes/shop`, `Html::normalize` and `AppTestCase::migrateKernel`.
- `TemplateLocator(ThemeRegistry, ModuleRegistry, ?string $engine, ?bool $twigInstalled)` and `TwigEngine(..., ?bool $installed)`.
- `View::render/renderWith/exists/helpers/page/themeSettings`.
- `Helpers::TWIG`, with `blocks` added in Task 9.
- `BlockRepository::create(type, region, title, config, visibility, sort, enabled)`.
- `BlockInstance::visibleFor(?string, list<string>)`.
- `DisplayHooks::call(hook, item, input)`.
- `ModuleRegistry::onEnable(Closure(Manifest, bool))`.
- `ErrorPages(View, bool)`.
- `RecentItemsQuery(limit, module, itemtype)`.
- `Page` constructor argument order and names.

**4. Known limits, recorded rather than built:**
- Twig helpers take positional arguments only.
- `Html::normalize()` also collapses whitespace inside text, not only between tags, which keeps parity tests stable.
- Error responses for router-level 404 and 405 do not carry `secureHtml` headers, because no route middleware runs. Pages and errors thrown inside `secureHtml` routes do carry them.
- `text` blocks with `format: html` are trusted admin HTML.
