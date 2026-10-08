# Xaraya Phoenix Kernel Foundation (Plan 1 of 3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Phoenix kernel foundation from a fresh clone. When it's done, `bin/xar module:enable hello` runs that module's migrations, and its routes serve HTML and JSON through a PSR-15 pipeline on SQLite, MySQL or Postgres.

**Architecture:** This is a new PSR-4 kernel under `src/Kernel`, and the legacy Xaraya tree moves to `legacy/`. The kernel has its own small subsystems: config/env, an autowiring container, a PDO database layer with dialects, a schema builder and migrations, an event dispatcher, a module registry driven by `module.json`, FastRoute routing, PSR-15 middleware, a file logger and a CLI. The sample module in `examples/hello` exercises all of it end to end.

**Tech Stack:** PHP ≥ 8.3, nyholm/psr7 and psr7-server, nikic/fast-route ^1.3, psr/http-server-middleware, psr/log ^3, PHPUnit 11, PHPStan 2 (level 8), php-cs-fixer (PER-CS).

**Spec:** `docs/superpowers/specs/2026-10-08-xaraya-phoenix-kernel-design.md`. This plan covers spec §1, §2.1–2.3, §3.1–3.4 (events only; display hooks and bindings are Plan 2), §6.1 (kernel commands listed here), §6.2, §6.3, §6.4 and §6.5.

- **Plan 2** (views, themes, blocks, display hooks, translation, settings, assets) follows this plan.
- **Plan 3** (users, roles, grants, auth, sessions, CSRF, throttle, tokens, mailer, `xar install`) follows Plan 2.

## Global Constraints

- PHP `>=8.3`. Every PHP file starts with `<?php` + blank line + `declare(strict_types=1);`.
- Namespace root `Xaraya\` maps to `src/`. Modules use `Xaraya\Module\<StudlyName>\` and map to `<module dir>/src/`.
- Direct runtime `require` packages: exactly `nikic/fast-route`, `nyholm/psr7`, `nyholm/psr7-server`, `psr/http-server-middleware`, `psr/log`. `twig/twig` goes only under `suggest` and `require-dev`.
- The default table prefix is `xar_`. Tests use the prefix `xt_`.
- IDs are lower-case Crockford ULIDs, 26 characters, matching `/^[0-7][0-9a-hjkmnp-tv-z]{25}$/`.
- Datetimes are stored in UTC as `Y-m-d H:i:s`.
- The DB layer has no ORM. Identifiers must match `/^[A-Za-z_][A-Za-z0-9_]*$/`.
- PHPStan level 8 must pass on `src/` (and on `examples/` once it exists). Code style is PER-CS.
- License: GPL-2.0-or-later.
- Every commit message ends with a blank line and then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. The commit commands below show only the subject line; add the trailer with a second `-m`.
- Test DB selection comes from env `XAR_TEST_DSN` (default `sqlite::memory:`), plus `XAR_TEST_DB_USER` and `XAR_TEST_DB_PASSWORD`.
- Local machine notes: PHP 8.5 is installed, and php-cs-fixer needs `PHP_CS_FIXER_IGNORE_ENV=1` (the composer script sets it). MySQL is available locally and Postgres isn't. CI covers the full matrix.

## File Map

```
composer.json, composer.lock, phpunit.xml.dist, phpstan.neon.dist, .php-cs-fixer.dist.php, .gitignore, README.md
.github/workflows/ci.yml
.env.example, config/app.php, bin/xar, public/index.php, var/.gitkeep, modules/.gitkeep
legacy/                               (moved upstream tree, untouched)
src/functions.php                     Xaraya\env()
src/Kernel/App.php                    boot, container wiring, module boot, handle(), run()
src/Kernel/Config/{Env,Config}.php
src/Kernel/Container/{Container,ContainerException,ServiceProvider}.php
src/Kernel/Support/Ulid.php
src/Kernel/Db/{Connection,ConnectionFactory,Select}.php
src/Kernel/Db/Dialect/{Dialect,AbstractDialect,Sqlite,Mysql,Pgsql}.php
src/Kernel/Db/Schema/{Schema,Blueprint,Column}.php
src/Kernel/Db/Migrations/{Migration,Migrator}.php
src/Kernel/migrations/2026_10_08_000001_create_modules.php
src/Kernel/Events/{Event,EventDispatcher,ItemEvent,ItemCreated,ItemUpdated,ItemDeleted,ItemDisplayed}.php
src/Kernel/Module/{Manifest,ModuleRegistry,ModuleException}.php
src/Kernel/Routing/{Route,RouteCollector,RouteProvider,Router,RouteMatch,UrlGenerator}.php
src/Kernel/Http/{Pipeline,CallableHandler,MiddlewareRegistry,RouteHandler,Controller,Emitter}.php
src/Kernel/Http/Exception/{HttpException,NotFound,Forbidden,Unauthorized,MethodNotAllowed,TooManyRequests}.php
src/Kernel/Http/Middleware/{ErrorHandler,Cors,ConditionalGet}.php
src/Kernel/Log/FileLogger.php
src/Kernel/Cli/{Command,Input,Output,Application}.php
src/Kernel/Cli/Commands/{Migrate,MigrateRollback,MigrateStatus,ModuleList,ModuleEnable,ModuleDisable,Serve,CacheClear}Command.php
examples/hello/{module.json, migrations/…, src/{Routes,HelloController,CountGreetings}.php}
tests/Support/{DbTestCase,AppTestCase}.php
tests/fixtures/modules/{alpha,beta}/…
tests/Kernel/…                        one test file per unit, mirroring src/
```

---

### Task 1: Move legacy tree and set up tooling and CI

**Files:**
- Move: `html/`, `templates/`, `developer/`, `composer.json`, `phpstan-baseline.neon`, `phpstan-bootstrap.php`, `phpstan.neon.dist`, `phpunit.xml.dist`, `sonar-project.properties`, `README.md` → `legacy/`; `.github/workflows/main.yml` → `legacy/github-workflow-main.yml`
- Create: `composer.json`, `phpunit.xml.dist`, `phpstan.neon.dist`, `.php-cs-fixer.dist.php`, `.gitignore` (replace), `README.md`, `.github/workflows/ci.yml`, `src/Kernel/.gitkeep`, `modules/.gitkeep`, `var/.gitkeep`
- Test: `tests/SmokeTest.php`

**Interfaces:** Produces the composer autoload `Xaraya\` → `src/` and `Xaraya\Tests\` → `tests/`, plus the scripts `composer test`, `composer stan`, `composer cs` and `composer cs:fix`.

- [ ] **Step 1: Move the legacy tree**

```bash
mkdir legacy
git mv html templates developer composer.json phpstan-baseline.neon phpstan-bootstrap.php phpstan.neon.dist phpunit.xml.dist sonar-project.properties README.md legacy/
git mv .github/workflows/main.yml legacy/github-workflow-main.yml
mkdir -p src/Kernel modules var tests && touch src/Kernel/.gitkeep modules/.gitkeep var/.gitkeep
```

- [ ] **Step 2: Write `composer.json`**

```json
{
    "name": "xaraya/phoenix",
    "description": "Xaraya Phoenix: a small, modern Xaraya for blogging and JSON content",
    "type": "project",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.3",
        "ext-json": "*",
        "ext-mbstring": "*",
        "ext-pdo": "*",
        "nikic/fast-route": "^1.3",
        "nyholm/psr7": "^1.8",
        "nyholm/psr7-server": "^1.1",
        "psr/http-server-middleware": "^1.0",
        "psr/log": "^3.0"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.64",
        "phpstan/phpstan": "^2.1",
        "phpunit/phpunit": "^11.5",
        "twig/twig": "^3.14"
    },
    "suggest": {
        "twig/twig": "Render .twig templates with the Twig engine"
    },
    "autoload": {
        "psr-4": { "Xaraya\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Xaraya\\Tests\\": "tests/" }
    },
    "scripts": {
        "test": "phpunit",
        "stan": "phpstan analyse --memory-limit=1G",
        "cs": ["@putenv PHP_CS_FIXER_IGNORE_ENV=1", "php-cs-fixer check --diff"],
        "cs:fix": ["@putenv PHP_CS_FIXER_IGNORE_ENV=1", "php-cs-fixer fix"]
    },
    "config": { "sort-packages": true, "optimize-autoloader": true },
    "minimum-stability": "stable"
}
```

- [ ] **Step 3: Write the tool configs**

`phpunit.xml.dist`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheDirectory=".phpunit.cache"
         colors="true"
         failOnWarning="true"
         failOnRisky="true">
    <testsuites>
        <testsuite name="phoenix">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`phpstan.neon.dist`:
```neon
parameters:
    level: 8
    paths:
        - src
```

`.php-cs-fixer.dist.php`:
```php
<?php

declare(strict_types=1);

$dirs = array_values(array_filter(
    [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/examples', __DIR__ . '/modules', __DIR__ . '/config', __DIR__ . '/public'],
    'is_dir',
));
$finder = (new PhpCsFixer\Finder())->in($dirs);
if (is_file(__DIR__ . '/bin/xar')) {
    $finder->append([__DIR__ . '/bin/xar']);
}

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder);
```

`.gitignore` (replace the whole file):
```
/vendor/
/var/*
!/var/.gitkeep
/.env
/.phpunit.cache/
/.php-cs-fixer.cache
/phpstan.neon
/.vscode/
```

- [ ] **Step 4: Write the CI workflow `.github/workflows/ci.yml`**

```yaml
name: CI
on:
  push:
    branches: [next]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4']
        db: [sqlite, mysql, pgsql]
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: xaraya_test
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -proot"
          --health-interval=5s --health-timeout=5s --health-retries=20
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: xaraya_test
        ports: ['5432:5432']
        options: >-
          --health-cmd="pg_isready -U postgres"
          --health-interval=5s --health-timeout=5s --health-retries=20
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo, pdo_sqlite, pdo_mysql, pdo_pgsql, mbstring
          coverage: none
      - run: composer install --no-interaction --no-progress
      - name: Select database
        run: |
          case "${{ matrix.db }}" in
            mysql)
              echo "XAR_TEST_DSN=mysql:host=127.0.0.1;port=3306;dbname=xaraya_test;charset=utf8mb4" >> "$GITHUB_ENV"
              echo "XAR_TEST_DB_USER=root" >> "$GITHUB_ENV"
              echo "XAR_TEST_DB_PASSWORD=root" >> "$GITHUB_ENV" ;;
            pgsql)
              echo "XAR_TEST_DSN=pgsql:host=127.0.0.1;port=5432;dbname=xaraya_test" >> "$GITHUB_ENV"
              echo "XAR_TEST_DB_USER=postgres" >> "$GITHUB_ENV"
              echo "XAR_TEST_DB_PASSWORD=postgres" >> "$GITHUB_ENV" ;;
            *)
              echo "XAR_TEST_DSN=sqlite::memory:" >> "$GITHUB_ENV" ;;
          esac
      - run: vendor/bin/phpunit

  static:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - run: composer install --no-interaction --no-progress
      - run: composer stan
      - run: composer cs
```

- [ ] **Step 5: Write `README.md`**

````markdown
# Xaraya Phoenix

A small, modern fork of [Xaraya](https://github.com/xaraya/core): a blogging engine that speaks
Athena's JSON Feed format natively, and a content manager that can import other JSON sources.

Status: early development on the `next` branch. Release codenames follow the X-Men
(1.0 *Cyclops*). The original Xaraya tree is kept read-only in `legacy/` until 1.0.

## Requirements

- PHP 8.3+ with pdo, mbstring, json
- SQLite (default), MySQL 8 / MariaDB 10.6+, or Postgres 14+

## Quick start

```sh
composer install
bin/xar migrate
bin/xar serve
```

## Development

```sh
composer test   # PHPUnit (XAR_TEST_DSN selects the database)
composer stan   # PHPStan level 8
composer cs     # coding style check
```

License: GPL-2.0-or-later.
````

- [ ] **Step 6: Write the smoke test `tests/SmokeTest.php`**

```php
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
```

- [ ] **Step 7: Install dependencies and run the checks**

Run: `composer install && composer test && composer stan && composer cs`

Expected: PHPUnit reports `OK (1 test, 3 assertions)`. PHPStan reports no errors (src only contains `.gitkeep`; if PHPStan says "No files found to analyse", that is acceptable for this task only). The cs check exits 0.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "chore: move legacy tree aside and set up Phoenix tooling and CI"
```

---

### Task 2: Env and Config

**Files:**
- Create: `src/Kernel/Config/Env.php`, `src/Kernel/Config/Config.php`, `src/functions.php`, `config/app.php`, `.env.example`
- Modify: `composer.json` (add `"files": ["src/functions.php"]` under `autoload`)
- Test: `tests/Kernel/Config/EnvTest.php`, `tests/Kernel/Config/ConfigTest.php`

**Interfaces:**
- Produces:
  - `Env::load(string $file): void`
  - `Env::get(string $key, mixed $default = null): mixed`. Real environment variables win over `.env`. The strings `true`, `false` and `null` are converted to their PHP values.
  - `Env::reset(): void`
  - `Xaraya\env(string $key, mixed $default = null): mixed`
  - `new Config(array $items)`
  - `Config::load(string $file, ?string $cacheFile = null): Config`
  - `get(string $key, mixed $default = null): mixed` (dot notation)
  - `set(string $key, mixed $value): void`
  - `all(): array`
  - `writeCache(string $file): void`

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Config/EnvTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Config;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Config\Env;

use function Xaraya\env;

final class EnvTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        Env::reset();
        $this->file = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($this->file, <<<'ENV'
            # comment line
            APP_NAME="Phoenix Test"
            SINGLE='quoted # not a comment'
            PLAIN=value # trailing comment
            FLAG=true
            OFF=false
            NOTHING=null
            EMPTY=

            ENV);
        Env::load($this->file);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        putenv('XAR_ENV_TEST_REAL');
        Env::reset();
    }

    public function testParsesValuesQuotesAndComments(): void
    {
        self::assertSame('Phoenix Test', Env::get('APP_NAME'));
        self::assertSame('quoted # not a comment', Env::get('SINGLE'));
        self::assertSame('value', Env::get('PLAIN'));
        self::assertSame('', Env::get('EMPTY'));
    }

    public function testConvertsBooleansAndNull(): void
    {
        self::assertTrue(Env::get('FLAG'));
        self::assertFalse(Env::get('OFF'));
        self::assertSame('fallback', Env::get('NOTHING', 'fallback'));
    }

    public function testDefaultForMissingKey(): void
    {
        self::assertSame(42, Env::get('MISSING_KEY', 42));
        self::assertSame(42, env('MISSING_KEY', 42));
    }

    public function testRealEnvironmentWins(): void
    {
        putenv('XAR_ENV_TEST_REAL=from-process');
        file_put_contents($this->file, "XAR_ENV_TEST_REAL=from-file\n");
        Env::load($this->file);
        self::assertSame('from-process', Env::get('XAR_ENV_TEST_REAL'));
    }

    public function testMissingFileIsIgnored(): void
    {
        Env::load('/nonexistent/.env');
        self::assertSame('Phoenix Test', Env::get('APP_NAME'));
    }
}
```

`tests/Kernel/Config/ConfigTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Config;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Config\Config;

final class ConfigTest extends TestCase
{
    public function testDotNotationGetAndSet(): void
    {
        $c = new Config(['db' => ['dsn' => 'sqlite::memory:', 'prefix' => 'xar_'], 'app' => ['debug' => false]]);
        self::assertSame('sqlite::memory:', $c->get('db.dsn'));
        self::assertSame(['dsn' => 'sqlite::memory:', 'prefix' => 'xar_'], $c->get('db'));
        self::assertSame('x', $c->get('db.missing', 'x'));
        self::assertSame('x', $c->get('app.debug.deeper', 'x'));

        $c->set('mail.smtp.host', 'localhost');
        $c->set('db.prefix', 'xt_');
        self::assertSame('localhost', $c->get('mail.smtp.host'));
        self::assertSame('xt_', $c->get('db.prefix'));
        self::assertSame('sqlite::memory:', $c->get('db.dsn'));
    }

    public function testLoadAndCache(): void
    {
        $dir = sys_get_temp_dir() . '/xar-config-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = $dir . '/app.php';
        $cache = $dir . '/cache/config.php';
        file_put_contents($file, "<?php return ['app' => ['debug' => false, 'name' => 'one']];");

        self::assertSame('one', Config::load($file, $cache)->get('app.name'));
        self::assertFileExists($cache);

        file_put_contents($file, "<?php return ['app' => ['debug' => false, 'name' => 'two']];");
        self::assertSame('one', Config::load($file, $cache)->get('app.name'), 'cache is used while it exists');

        unlink($cache);
        file_put_contents($file, "<?php return ['app' => ['debug' => true, 'name' => 'three']];");
        self::assertSame('three', Config::load($file, $cache)->get('app.name'));
        self::assertFileDoesNotExist($cache, 'debug mode never writes the cache');

        unlink($file);
        rmdir($dir . '/cache');
        rmdir($dir);
    }

    public function testNonArrayConfigFileThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'cfg');
        file_put_contents($file, '<?php return "nope";');
        $this->expectException(\RuntimeException::class);
        try {
            Config::load($file);
        } finally {
            unlink($file);
        }
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Config`
Expected: FAIL / errors with `Class "Xaraya\Kernel\Config\Env" not found`.

- [ ] **Step 3: Implement**

`src/Kernel/Config/Env.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Config;

final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'") && strlen($value) >= 2 && str_ends_with($value, $quote)) {
                $value = substr($value, 1, -1);
            } elseif (($hash = strpos($value, ' #')) !== false) {
                $value = rtrim(substr($value, 0, $hash));
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $real = getenv($key);
        $value = $real !== false ? $real : (self::$values[$key] ?? null);
        if ($value === null) {
            return $default;
        }

        return match (strtolower($value)) {
            'true' => true,
            'false' => false,
            'null' => $default,
            default => $value,
        };
    }

    public static function reset(): void
    {
        self::$values = [];
    }
}
```

`src/Kernel/Config/Config.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Config;

use RuntimeException;

final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private array $items = []) {}

    public static function load(string $file, ?string $cacheFile = null): self
    {
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = require $cacheFile;
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                return new self($cached);
            }
        }
        $items = is_file($file) ? require $file : [];
        if (!is_array($items)) {
            throw new RuntimeException("Config file {$file} must return an array");
        }
        /** @var array<string, mixed> $items */
        $config = new self($items);
        if ($cacheFile !== null && $config->get('app.debug', false) !== true) {
            $config->writeCache($cacheFile);
        }

        return $config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }
        $node = $this->items;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return $default;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    public function set(string $key, mixed $value): void
    {
        $node = &$this->items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
        $node = $value;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function writeCache(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, '<?php return ' . var_export($this->items, true) . ";\n");
        rename($tmp, $file);
    }
}
```

`src/functions.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya;

use Xaraya\Kernel\Config\Env;

function env(string $key, mixed $default = null): mixed
{
    return Env::get($key, $default);
}
```

`config/app.php`:
```php
<?php

declare(strict_types=1);

use function Xaraya\env;

$root = dirname(__DIR__);

return [
    'app' => [
        'name' => env('APP_NAME', 'Xaraya Phoenix'),
        'url' => env('APP_URL', 'http://localhost:8080'),
        'debug' => env('APP_DEBUG', false) === true,
        'timezone' => env('APP_TIMEZONE', 'UTC'),
        'locale' => env('APP_LOCALE', 'en'),
        'theme' => env('APP_THEME', 'phoenix'),
        'cache' => $root . '/var/cache',
    ],
    'db' => [
        'dsn' => env('DB_DSN', 'sqlite:' . $root . '/var/database.sqlite'),
        'user' => env('DB_USER'),
        'password' => env('DB_PASSWORD'),
        'prefix' => env('DB_PREFIX', 'xar_'),
    ],
    'modules' => [
        'paths' => ['modules'],
    ],
    'log' => [
        'path' => $root . '/var/logs',
        'level' => env('LOG_LEVEL', 'info'),
    ],
];
```

`.env.example`:
```
APP_URL=http://localhost:8080
APP_DEBUG=true
# DB_DSN=mysql:host=127.0.0.1;dbname=xaraya;charset=utf8mb4
# DB_USER=xaraya
# DB_PASSWORD=secret
DB_PREFIX=xar_
LOG_LEVEL=info
```

Modify `composer.json` so `autoload` becomes:
```json
"autoload": {
    "psr-4": { "Xaraya\\": "src/" },
    "files": ["src/functions.php"]
},
```

- [ ] **Step 4: Regenerate the autoloader and run the tests**

Run: `composer dump-autoload && vendor/bin/phpunit tests/Kernel/Config`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add Env and Config with dot access and production cache"
```

---
### Task 3: Container

**Files:**
- Create: `src/Kernel/Container/Container.php`, `src/Kernel/Container/ContainerException.php`, `src/Kernel/Container/ServiceProvider.php`
- Test: `tests/Kernel/Container/ContainerTest.php`

**Interfaces:**
- Produces:
  - `Container::set(string $id, callable $factory, bool $shared = true): void`. The factory receives the `Container`.
  - `instance(string $id, mixed $value): void`
  - `has(string $id): bool`
  - `get(string $id): mixed`. `@template T of object`; it returns `T` for a `class-string<T>`. Autowired classes are shared.
  - `make(string $class, array<string, mixed> $params = []): object`. Always a new instance; `$params` override constructor arguments by name.
  - `interface ServiceProvider { public function register(Container $container): void; }`
  - `ContainerException extends \RuntimeException`.

- [ ] **Step 1: Write the failing test `tests/Kernel/Container/ContainerTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Container;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ContainerException;

interface Clock
{
    public function now(): string;
}

final class FixedClock implements Clock
{
    public function now(): string
    {
        return '2026-10-08';
    }
}

final class Leaf {}

final class Branch
{
    public function __construct(public readonly Leaf $leaf, public readonly string $label = 'default') {}
}

final class NeedsClock
{
    public function __construct(public readonly Clock $clock) {}
}

final class MaybeClock
{
    public function __construct(public readonly ?Clock $clock) {}
}

final class NeedsScalar
{
    public function __construct(public readonly string $name) {}
}

final class LoopA
{
    public function __construct(public readonly LoopB $b) {}
}

final class LoopB
{
    public function __construct(public readonly LoopA $a) {}
}

final class ContainerTest extends TestCase
{
    public function testAutowiresAndSharesByDefault(): void
    {
        $c = new Container();
        $branch = $c->get(Branch::class);
        self::assertInstanceOf(Leaf::class, $branch->leaf);
        self::assertSame('default', $branch->label);
        self::assertSame($branch, $c->get(Branch::class));
        self::assertSame($branch->leaf, $c->get(Leaf::class));
    }

    public function testMakeAlwaysBuildsNewAndAcceptsNamedParams(): void
    {
        $c = new Container();
        $a = $c->make(Branch::class, ['label' => 'custom']);
        self::assertInstanceOf(Branch::class, $a);
        self::assertSame('custom', $a->label);
        self::assertNotSame($a, $c->make(Branch::class));
    }

    public function testFactoriesSharedAndUnshared(): void
    {
        $c = new Container();
        $c->set(Clock::class, fn (): Clock => new FixedClock());
        $c->set('counter', function (): \stdClass {
            static $n = 0;
            $o = new \stdClass();
            $o->n = ++$n;

            return $o;
        }, shared: false);

        self::assertSame($c->get(Clock::class), $c->get(Clock::class));
        self::assertSame('2026-10-08', $c->get(NeedsClock::class)->clock->now());
        self::assertNotSame($c->get('counter'), $c->get('counter'));
    }

    public function testInstancesAndSelf(): void
    {
        $c = new Container();
        $c->instance('answer', 42);
        self::assertSame(42, $c->get('answer'));
        self::assertSame($c, $c->get(Container::class));
        self::assertTrue($c->has('answer'));
        self::assertFalse($c->has('nope'));
    }

    public function testUnboundInterfaceIsNullWhenNullable(): void
    {
        self::assertNull((new Container())->get(MaybeClock::class)->clock);
    }

    public function testUnboundInterfaceThrows(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get(NeedsClock::class);
    }

    public function testUnresolvableScalarThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');
        (new Container())->get(NeedsScalar::class);
    }

    public function testCircularDependencyThrows(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');
        (new Container())->get(LoopA::class);
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get('no.such.service');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Container`
Expected: errors with `Class "Xaraya\Kernel\Container\Container" not found`.

- [ ] **Step 3: Implement**

`src/Kernel/Container/ContainerException.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Container;

final class ContainerException extends \RuntimeException {}
```

`src/Kernel/Container/ServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Container;

interface ServiceProvider
{
    public function register(Container $container): void;
}
```

`src/Kernel/Container/Container.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Container;

use ReflectionClass;
use ReflectionNamedType;

final class Container
{
    /** @var array<string, array{factory: callable, shared: bool}> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    public function __construct()
    {
        $this->instances[self::class] = $this;
    }

    public function set(string $id, callable $factory, bool $shared = true): void
    {
        unset($this->instances[$id]);
        $this->factories[$id] = ['factory' => $factory, 'shared' => $shared];
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }
        if (isset($this->resolving[$id])) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', array_keys($this->resolving)) . " -> {$id}");
        }
        $this->resolving[$id] = true;
        try {
            if (isset($this->factories[$id])) {
                $value = ($this->factories[$id]['factory'])($this);
                if ($this->factories[$id]['shared']) {
                    $this->instances[$id] = $value;
                }

                return $value;
            }

            return $this->instances[$id] = $this->make($id);
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * @template T of object
     * @param class-string<T>|string $class
     * @param array<string, mixed> $params
     * @return ($class is class-string<T> ? T : object)
     */
    public function make(string $class, array $params = []): object
    {
        if (!class_exists($class)) {
            throw new ContainerException("Cannot resolve '{$class}': no binding and no such class");
        }
        $ref = new ReflectionClass($class);
        if (!$ref->isInstantiable()) {
            throw new ContainerException("Cannot instantiate {$class} without a binding");
        }
        $ctor = $ref->getConstructor();
        if ($ctor === null) {
            return $ref->newInstance();
        }
        $args = [];
        foreach ($ctor->getParameters() as $p) {
            $name = $p->getName();
            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
                continue;
            }
            $type = $p->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin() && $this->has($type->getName())) {
                $args[] = $this->get($type->getName());
                continue;
            }
            if ($p->isDefaultValueAvailable()) {
                $args[] = $p->getDefaultValue();
                continue;
            }
            if ($p->allowsNull()) {
                $args[] = null;
                continue;
            }
            throw new ContainerException("Cannot resolve parameter \${$name} of {$class}");
        }

        return $ref->newInstanceArgs($args);
    }
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Kernel/Container`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add autowiring container and ServiceProvider"
```

---

### Task 4: ULID generator

**Files:**
- Create: `src/Kernel/Support/Ulid.php`
- Test: `tests/Kernel/Support/UlidTest.php`

**Interfaces:**
- Produces:
  - `Ulid::generate(?int $milliseconds = null): string` (26 lower-case characters)
  - `Ulid::isValid(string $value): bool`
  - `Ulid::timestamp(string $ulid): int` (milliseconds)

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Support\Ulid;

final class UlidTest extends TestCase
{
    public function testGeneratesValidLowerCaseUlids(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $id = Ulid::generate();
            self::assertSame(26, strlen($id));
            self::assertTrue(Ulid::isValid($id), $id);
            self::assertSame(strtolower($id), $id);
            $ids[$id] = true;
        }
        self::assertCount(200, $ids, 'ids are unique');
    }

    public function testTimestampRoundTripAndOrdering(): void
    {
        self::assertSame(1_760_000_000_123, Ulid::timestamp(Ulid::generate(1_760_000_000_123)));
        self::assertLessThan(0, strcmp(Ulid::generate(1000), Ulid::generate(2000)));
    }

    public function testAcceptsAthenaIds(): void
    {
        self::assertTrue(Ulid::isValid('01m3t19wn8reaww81zg1m47tjq'));
    }

    public function testRejectsInvalid(): void
    {
        self::assertFalse(Ulid::isValid('01M3T19WN8REAWW81ZG1M47TJQ'), 'upper case');
        self::assertFalse(Ulid::isValid('01m3t19wn8reaww81zg1m47tj'), 'too short');
        self::assertFalse(Ulid::isValid('01m3t19wn8reaww81zg1m47tji'), 'contains i');
        self::assertFalse(Ulid::isValid('81m3t19wn8reaww81zg1m47tjq'), 'overflows 48 bits');
    }

    public function testRejectsOutOfRangeTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ulid::generate(-1);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Support`
Expected: errors, class not found.

- [ ] **Step 3: Implement `src/Kernel/Support/Ulid.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Support;

use InvalidArgumentException;

final class Ulid
{
    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function generate(?int $milliseconds = null): string
    {
        $ms = $milliseconds ?? (int) floor(microtime(true) * 1000);
        if ($ms < 0 || $ms > 0xFFFFFFFFFFFF) {
            throw new InvalidArgumentException('ULID timestamp out of range');
        }
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$ms % 32] . $time;
            $ms = intdiv($ms, 32);
        }
        $bits = '';
        foreach (str_split(random_bytes(10)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $random = '';
        foreach (str_split($bits, 5) as $chunk) {
            $random .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $time . $random;
    }

    public static function isValid(string $value): bool
    {
        return preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $value) === 1;
    }

    public static function timestamp(string $ulid): int
    {
        if (!self::isValid($ulid)) {
            throw new InvalidArgumentException("Invalid ULID '{$ulid}'");
        }
        $ms = 0;
        for ($i = 0; $i < 10; $i++) {
            $ms = $ms * 32 + (int) strpos(self::ALPHABET, $ulid[$i]);
        }

        return $ms;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `vendor/bin/phpunit tests/Kernel/Support`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add lower-case ULID generator"
```

---

### Task 5: Database connection, dialects and schema builder

**Files:**
- Create: `src/Kernel/Db/Connection.php`, `src/Kernel/Db/ConnectionFactory.php`, `src/Kernel/Db/Dialect/{Dialect,AbstractDialect,Sqlite,Mysql,Pgsql}.php`, `src/Kernel/Db/Schema/{Schema,Blueprint,Column}.php` (`Connection::select()` is added in Task 6)
- Test: `tests/Support/DbTestCase.php`, `tests/Kernel/Db/ConnectionTest.php`, `tests/Kernel/Db/SchemaTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - **`Connection`**: `__construct(PDO $pdo, Dialect $dialect, string $prefix = 'xar_')`, plus:
    - accessors `pdo(): PDO`, `dialect(): Dialect`, `prefix(): string`
    - `ident(string $name): string` (validated and quoted)
    - `table(string $name): string` (prefixed and quoted)
    - `sql(string $sql): string` (replaces `{name}` with the prefixed, quoted table)
    - `query(string $sql, array $params = []): PDOStatement`
    - `fetchAll(...)`, which returns `list<array<string,mixed>>`
    - `fetchOne(...)`, which returns `?array<string,mixed>`
    - `fetchValue(...)`, which returns `mixed`
    - `insert(string $table, array $row): void`
    - `upsert(string $table, array $row, list<string> $conflict): void`
    - `update(string $table, array $values, array $where): int`
    - `delete(string $table, array $where): int`
    - `transaction(callable $fn): mixed`
    - `tables(): list<string>` (unprefixed names that carry this prefix)
    - `hasTable(string $name): bool`
    - `schema(): Schema`
    - `static normalize(mixed $v): mixed`. Booleans become int, arrays become JSON, `DateTimeInterface` becomes UTC `Y-m-d H:i:s`.
  - **`ConnectionFactory::make(array<string,mixed> $config): Connection`**. Keys: `dsn` (required), `user`, `password`, `prefix`.
  - **`Dialect` interface**:
    - `name(): string` (`sqlite`, `mysql` or `pgsql`)
    - `quote(string $identifier): string`
    - `onConnect(PDO $pdo): void`
    - `listTablesSql(): string`
    - `transactionalDdl(): bool`
    - `insertSql(string $table, list<string> $columns): string`
    - `upsertSql(string $table, list<string> $columns, list<string> $conflict): string`
    - `createTable(Blueprint $b, string $prefix): list<string>`
    - `alterTable(Blueprint $b, string $prefix): list<string>`
    - `dropTableSql(string $table): string`
    - `renameTableSql(string $from, string $to): string`

    The `$table` arguments are full, already-prefixed, unquoted names.
  - **`Schema`**: `create(string $table, callable(Blueprint): void $define)`, `table(...)` (adds columns and indexes only), `drop(string)`, `rename(string, string)`, `has(string): bool`.
  - **`Blueprint`**:
    - column methods: `increments(name='id')`, `ulid(name='id')`, `string(name, length=255)`, `text`, `int`, `bigint`, `bool`, `datetime`, `json`. Each returns a `Column`.
    - table-level methods: `index(string ...$cols)`, `unique(string ...$cols)`, `primary(string ...$cols)`, `foreign(column, table, references='id', onDelete='cascade')`.
  - **`Column`**: `nullable()`, `default(mixed)`, `unique()`, `primary()`, all fluent.
  - **Test support**:
    - `DbTestCase::dbConfig(): array{dsn: string, user: ?string, password: ?string, prefix: string}`
    - `DbTestCase::connect(): Connection`
    - `DbTestCase::dropAll(Connection $db): void`
    - `protected Connection $db`

- [ ] **Step 1: Write the test support and failing tests**

`tests/Support/DbTestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\ConnectionFactory;

abstract class DbTestCase extends TestCase
{
    public const PREFIX = 'xt_';

    protected Connection $db;

    protected function setUp(): void
    {
        $this->db = self::connect();
        self::dropAll($this->db);
    }

    protected function tearDown(): void
    {
        self::dropAll($this->db);
    }

    /** @return array{dsn: string, user: ?string, password: ?string, prefix: string} */
    public static function dbConfig(): array
    {
        return [
            'dsn' => getenv('XAR_TEST_DSN') ?: 'sqlite::memory:',
            'user' => getenv('XAR_TEST_DB_USER') ?: null,
            'password' => getenv('XAR_TEST_DB_PASSWORD') ?: null,
            'prefix' => self::PREFIX,
        ];
    }

    public static function connect(): Connection
    {
        return ConnectionFactory::make(self::dbConfig());
    }

    public static function dropAll(Connection $db): void
    {
        $mysql = $db->dialect()->name() === 'mysql';
        if ($mysql) {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        }
        foreach ($db->tables() as $table) {
            $db->pdo()->exec($db->dialect()->dropTableSql($db->prefix() . $table));
        }
        if ($mysql) {
            $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
```

`tests/Kernel/Db/SchemaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use PDOException;
use Xaraya\Kernel\Db\Dialect\Sqlite;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Tests\Support\DbTestCase;

final class SchemaTest extends DbTestCase
{
    private function createAllTypes(): void
    {
        $this->db->schema()->create('things', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('slug', 64)->unique();
            $t->text('body')->nullable();
            $t->int('views')->default(0);
            $t->bigint('bytes')->default(0);
            $t->bool('published')->default(false);
            $t->datetime('created');
            $t->json('extra')->nullable();
            $t->index('created');
        });
    }

    public function testCreateInsertAndReadAllTypes(): void
    {
        $this->createAllTypes();
        self::assertTrue($this->db->schema()->has('things'));

        $this->db->insert('things', [
            'id' => '01m3t19wn8reaww81zg1m47tjq',
            'slug' => 'hello',
            'created' => new \DateTimeImmutable('2026-10-08 15:07:16', new \DateTimeZone('UTC')),
            'extra' => ['kind' => 'note'],
        ]);
        $row = $this->db->fetchOne('SELECT * FROM {things} WHERE id = ?', ['01m3t19wn8reaww81zg1m47tjq']);

        self::assertNotNull($row);
        self::assertSame('hello', $row['slug']);
        self::assertNull($row['body']);
        self::assertSame(0, (int) $row['views']);
        self::assertFalse((bool) $row['published']);
        self::assertSame('2026-10-08 15:07:16', $row['created']);
        self::assertEquals(['kind' => 'note'], json_decode((string) $row['extra'], true));
    }

    public function testUniqueConstraintIsEnforced(): void
    {
        $this->createAllTypes();
        $row = ['slug' => 'dup', 'created' => '2026-10-08 00:00:00'];
        $this->db->insert('things', ['id' => '01m3t19wn8reaww81zg1m47tja'] + $row);
        $this->expectException(PDOException::class);
        $this->db->insert('things', ['id' => '01m3t19wn8reaww81zg1m47tjb'] + $row);
    }

    public function testAlterAddsColumnsAndIndexes(): void
    {
        $this->createAllTypes();
        $this->db->schema()->table('things', function (Blueprint $t): void {
            $t->string('title')->nullable();
            $t->index('title');
        });
        $this->db->insert('things', ['id' => '01m3t19wn8reaww81zg1m47tjq', 'slug' => 's', 'created' => '2026-10-08 00:00:00', 'title' => 'T']);
        self::assertSame('T', $this->db->fetchValue('SELECT title FROM {things}'));
    }

    public function testRenameAndDrop(): void
    {
        $this->createAllTypes();
        $this->db->schema()->rename('things', 'items');
        self::assertFalse($this->db->schema()->has('things'));
        self::assertTrue($this->db->schema()->has('items'));
        $this->db->schema()->drop('items');
        self::assertFalse($this->db->schema()->has('items'));
    }

    public function testIncrementsAndForeignKeyCascade(): void
    {
        $s = $this->db->schema();
        $s->create('parents', function (Blueprint $t): void {
            $t->increments();
            $t->string('name');
        });
        $s->create('children', function (Blueprint $t): void {
            $t->increments();
            $t->int('parent_id');
            $t->foreign('parent_id', 'parents');
        });
        $this->db->insert('parents', ['name' => 'p']);
        $pid = $this->db->fetchValue('SELECT id FROM {parents}');
        $this->db->insert('children', ['parent_id' => $pid]);
        $this->db->delete('parents', ['id' => $pid]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM {children}'));
    }

    public function testCompositePrimaryKey(): void
    {
        $this->db->schema()->create('pairs', function (Blueprint $t): void {
            $t->string('a', 32);
            $t->string('b', 32);
            $t->primary('a', 'b');
        });
        $this->db->insert('pairs', ['a' => 'x', 'b' => 'y']);
        $this->expectException(PDOException::class);
        $this->db->insert('pairs', ['a' => 'x', 'b' => 'y']);
    }

    public function testForeignKeysOnlyInCreate(): void
    {
        $this->createAllTypes();
        $this->expectException(\LogicException::class);
        $this->db->schema()->table('things', function (Blueprint $t): void {
            $t->foreign('slug', 'other');
        });
    }

    public function testLongIndexNamesAreShortened(): void
    {
        $b = new Blueprint('a_really_long_table_name_for_testing_index_names');
        $b->string('an_equally_long_column_name_here');
        $b->index('an_equally_long_column_name_here');
        $sql = (new Sqlite())->createTable($b, 'xar_');
        self::assertCount(2, $sql);
        self::assertMatchesRegularExpression('/INDEX "([^"]{1,60})" ON/', $sql[1]);
    }
}
```

`tests/Kernel/Db/ConnectionTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use InvalidArgumentException;
use Xaraya\Kernel\Db\ConnectionFactory;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Tests\Support\DbTestCase;

final class ConnectionTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->schema()->create('kv', function (Blueprint $t): void {
            $t->string('k', 64)->primary();
            $t->text('v')->nullable();
            $t->int('n')->default(0);
        });
    }

    public function testFetchHelpers(): void
    {
        $this->db->insert('kv', ['k' => 'a', 'v' => 'one', 'n' => 1]);
        $this->db->insert('kv', ['k' => 'b', 'v' => 'two', 'n' => 2]);

        self::assertCount(2, $this->db->fetchAll('SELECT * FROM {kv} ORDER BY k'));
        self::assertSame('two', $this->db->fetchOne('SELECT v FROM {kv} WHERE k = ?', ['b'])['v'] ?? null);
        self::assertNull($this->db->fetchOne('SELECT v FROM {kv} WHERE k = ?', ['zzz']));
        self::assertSame(3, (int) $this->db->fetchValue('SELECT SUM(n) FROM {kv}'));
        self::assertNull($this->db->fetchValue('SELECT v FROM {kv} WHERE k = ?', ['zzz']));
    }

    public function testUpdateDeleteAndGuard(): void
    {
        $this->db->insert('kv', ['k' => 'a', 'n' => 1]);
        self::assertSame(1, $this->db->update('kv', ['n' => 5], ['k' => 'a']));
        self::assertSame(5, (int) $this->db->fetchValue('SELECT n FROM {kv} WHERE k = ?', ['a']));
        self::assertSame(1, $this->db->delete('kv', ['k' => 'a']));

        $this->expectException(InvalidArgumentException::class);
        $this->db->update('kv', ['n' => 1], []);
    }

    public function testWhereNullMatchesIsNull(): void
    {
        $this->db->insert('kv', ['k' => 'a', 'v' => null]);
        self::assertSame(1, $this->db->update('kv', ['n' => 9], ['v' => null]));
    }

    public function testUpsertInsertsThenUpdates(): void
    {
        $this->db->upsert('kv', ['k' => 'a', 'v' => 'first', 'n' => 1], ['k']);
        $this->db->upsert('kv', ['k' => 'a', 'v' => 'second', 'n' => 2], ['k']);
        self::assertSame('second', $this->db->fetchValue('SELECT v FROM {kv} WHERE k = ?', ['a']));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM {kv}'));
    }

    public function testTransactionRollsBack(): void
    {
        try {
            $this->db->transaction(function ($db): void {
                $db->insert('kv', ['k' => 'a']);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM {kv}'));
        self::assertSame('ok', $this->db->transaction(fn () => 'ok'));
    }

    public function testTablesAreFilteredByPrefix(): void
    {
        self::assertSame(['kv'], $this->db->tables());
        self::assertTrue($this->db->hasTable('kv'));
        self::assertFalse($this->db->hasTable('nope'));
    }

    public function testRejectsUnsafeIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->insert('kv', ['k; DROP TABLE x' => 'a']);
    }

    public function testNormalize(): void
    {
        $c = $this->db::class;
        self::assertSame(1, $c::normalize(true));
        self::assertSame('{"a":"é/b"}', $c::normalize(['a' => 'é/b']));
        self::assertSame('2026-10-08 13:00:00', $c::normalize(new \DateTimeImmutable('2026-10-08 15:00:00', new \DateTimeZone('+02:00'))));
        self::assertSame('x', $c::normalize('x'));
    }

    public function testFactoryRejectsUnknownDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ConnectionFactory::make(['dsn' => 'oracle:whatever']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Db`
Expected: errors, `Xaraya\Kernel\Db\ConnectionFactory` not found.

- [ ] **Step 3: Implement the schema value objects**

`src/Kernel/Db/Schema/Column.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Schema;

final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $unique = false;
    public bool $primary = false;

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?int $length = null,
    ) {}

    public function nullable(): self
    {
        $this->nullable = true;

        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }
}
```

`src/Kernel/Db/Schema/Blueprint.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Schema;

use InvalidArgumentException;

final class Blueprint
{
    /** @var list<Column> */
    public array $columns = [];

    /** @var list<array{columns: list<string>, unique: bool}> */
    public array $indexes = [];

    /** @var list<array{column: string, table: string, references: string, onDelete: string}> */
    public array $foreignKeys = [];

    /** @var list<string> */
    public array $primary = [];

    public function __construct(public readonly string $table) {}

    public function increments(string $name = 'id'): Column
    {
        return $this->add($name, 'increments');
    }

    public function ulid(string $name = 'id'): Column
    {
        return $this->add($name, 'ulid');
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add($name, 'string', $length);
    }

    public function text(string $name): Column
    {
        return $this->add($name, 'text');
    }

    public function int(string $name): Column
    {
        return $this->add($name, 'int');
    }

    public function bigint(string $name): Column
    {
        return $this->add($name, 'bigint');
    }

    public function bool(string $name): Column
    {
        return $this->add($name, 'bool');
    }

    public function datetime(string $name): Column
    {
        return $this->add($name, 'datetime');
    }

    public function json(string $name): Column
    {
        return $this->add($name, 'json');
    }

    public function index(string ...$columns): void
    {
        $this->indexes[] = ['columns' => array_values($columns), 'unique' => false];
    }

    public function unique(string ...$columns): void
    {
        $this->indexes[] = ['columns' => array_values($columns), 'unique' => true];
    }

    public function primary(string ...$columns): void
    {
        $this->primary = array_values($columns);
    }

    public function foreign(string $column, string $table, string $references = 'id', string $onDelete = 'cascade'): void
    {
        $onDelete = strtolower($onDelete);
        if (!in_array($onDelete, ['cascade', 'restrict', 'set null', 'no action'], true)) {
            throw new InvalidArgumentException("Unsupported ON DELETE action '{$onDelete}'");
        }
        $this->foreignKeys[] = ['column' => $column, 'table' => $table, 'references' => $references, 'onDelete' => $onDelete];
    }

    private function add(string $name, string $type, ?int $length = null): Column
    {
        $column = new Column($name, $type, $length);
        $this->columns[] = $column;

        return $column;
    }
}
```

- [ ] **Step 4: Implement the dialects**

`src/Kernel/Db/Dialect/Dialect.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use PDO;
use Xaraya\Kernel\Db\Schema\Blueprint;

interface Dialect
{
    public function name(): string;

    public function quote(string $identifier): string;

    public function onConnect(PDO $pdo): void;

    public function listTablesSql(): string;

    public function transactionalDdl(): bool;

    /** @param list<string> $columns */
    public function insertSql(string $table, array $columns): string;

    /**
     * @param list<string> $columns
     * @param list<string> $conflict
     */
    public function upsertSql(string $table, array $columns, array $conflict): string;

    /** @return list<string> */
    public function createTable(Blueprint $blueprint, string $prefix): array;

    /** @return list<string> */
    public function alterTable(Blueprint $blueprint, string $prefix): array;

    public function dropTableSql(string $table): string;

    public function renameTableSql(string $from, string $to): string;
}
```

`src/Kernel/Db/Dialect/AbstractDialect.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Column;

abstract class AbstractDialect implements Dialect
{
    abstract protected function typeSql(Column $column): string;

    abstract protected function incrementsSql(): string;

    protected function boolLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function tableSuffix(): string
    {
        return '';
    }

    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    public function transactionalDdl(): bool
    {
        return true;
    }

    public function insertSql(string $table, array $columns): string
    {
        return 'INSERT INTO ' . $this->quote($table) . ' (' . $this->quoteList($columns) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')';
    }

    public function upsertSql(string $table, array $columns, array $conflict): string
    {
        $update = array_values(array_diff($columns, $conflict));
        $sql = $this->insertSql($table, $columns) . ' ON CONFLICT (' . $this->quoteList($conflict) . ') DO ';
        if ($update === []) {
            return $sql . 'NOTHING';
        }

        return $sql . 'UPDATE SET ' . implode(', ', array_map(
            fn (string $c): string => $this->quote($c) . ' = excluded.' . $this->quote($c),
            $update,
        ));
    }

    public function createTable(Blueprint $blueprint, string $prefix): array
    {
        $table = $prefix . $blueprint->table;
        $defs = [];
        $primary = $blueprint->primary;
        foreach ($blueprint->columns as $column) {
            $defs[] = $this->columnSql($column);
            if ($column->primary) {
                $primary[] = $column->name;
            }
        }
        if ($primary !== []) {
            $defs[] = 'PRIMARY KEY (' . $this->quoteList($primary) . ')';
        }
        foreach ($blueprint->foreignKeys as $fk) {
            $defs[] = sprintf(
                'FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s',
                $this->quote($fk['column']),
                $this->quote($prefix . $fk['table']),
                $this->quote($fk['references']),
                strtoupper($fk['onDelete']),
            );
        }
        $create = 'CREATE TABLE ' . $this->quote($table) . " (\n  " . implode(",\n  ", $defs) . "\n)" . $this->tableSuffix();

        return [$create, ...$this->indexSql($blueprint, $table)];
    }

    public function alterTable(Blueprint $blueprint, string $prefix): array
    {
        $table = $prefix . $blueprint->table;
        $sql = [];
        foreach ($blueprint->columns as $column) {
            $sql[] = 'ALTER TABLE ' . $this->quote($table) . ' ADD COLUMN ' . $this->columnSql($column);
        }

        return [...$sql, ...$this->indexSql($blueprint, $table)];
    }

    public function dropTableSql(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($table);
    }

    public function renameTableSql(string $from, string $to): string
    {
        return 'ALTER TABLE ' . $this->quote($from) . ' RENAME TO ' . $this->quote($to);
    }

    /** @param list<string> $columns */
    protected function quoteList(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    protected function columnSql(Column $column): string
    {
        if ($column->type === 'increments') {
            return $this->quote($column->name) . ' ' . $this->incrementsSql();
        }
        $sql = $this->quote($column->name) . ' ' . $this->typeSql($column) . ($column->nullable ? ' NULL' : ' NOT NULL');
        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->literal($column->default);
        }

        return $sql;
    }

    protected function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $this->boolLiteral($value),
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'" . str_replace("'", "''", $value) . "'",
            default => throw new \InvalidArgumentException('Unsupported default value type ' . get_debug_type($value)),
        };
    }

    /** @return list<string> */
    protected function indexSql(Blueprint $blueprint, string $table): array
    {
        $indexes = $blueprint->indexes;
        foreach ($blueprint->columns as $column) {
            if ($column->unique) {
                $indexes[] = ['columns' => [$column->name], 'unique' => true];
            }
        }
        $sql = [];
        foreach ($indexes as $index) {
            $sql[] = sprintf(
                'CREATE %sINDEX %s ON %s (%s)',
                $index['unique'] ? 'UNIQUE ' : '',
                $this->quote($this->indexName($table, $index['columns'], $index['unique'])),
                $this->quote($table),
                $this->quoteList($index['columns']),
            );
        }

        return $sql;
    }

    /** @param list<string> $columns */
    protected function indexName(string $table, array $columns, bool $unique): string
    {
        $name = $table . '_' . implode('_', $columns) . ($unique ? '_uniq' : '_idx');

        return strlen($name) <= 60 ? $name : substr($name, 0, 51) . '_' . substr(md5($name), 0, 8);
    }
}
```

`src/Kernel/Db/Dialect/Sqlite.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use PDO;
use Xaraya\Kernel\Db\Schema\Column;

final class Sqlite extends AbstractDialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function onConnect(PDO $pdo): void
    {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    public function listTablesSql(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
    }

    protected function incrementsSql(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'ulid' => 'CHAR(26)',
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text', 'json' => 'TEXT',
            'int', 'bool' => 'INTEGER',
            'bigint' => 'BIGINT',
            'datetime' => 'DATETIME',
            default => throw new \InvalidArgumentException("Unknown column type '{$column->type}'"),
        };
    }
}
```

`src/Kernel/Db/Dialect/Mysql.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use PDO;
use Xaraya\Kernel\Db\Schema\Column;

final class Mysql extends AbstractDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function onConnect(PDO $pdo): void
    {
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec("SET time_zone = '+00:00'");
    }

    public function listTablesSql(): string
    {
        return 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name';
    }

    public function transactionalDdl(): bool
    {
        return false;
    }

    public function upsertSql(string $table, array $columns, array $conflict): string
    {
        $update = array_values(array_diff($columns, $conflict));
        if ($update === []) {
            $update = [$conflict[0] ?? $columns[0]];
        }

        return $this->insertSql($table, $columns) . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
            fn (string $c): string => $this->quote($c) . ' = VALUES(' . $this->quote($c) . ')',
            $update,
        ));
    }

    protected function tableSuffix(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    protected function incrementsSql(): string
    {
        return 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'ulid' => 'CHAR(26)',
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'LONGTEXT',
            'json' => 'JSON',
            'int' => 'INT',
            'bigint' => 'BIGINT',
            'bool' => 'TINYINT(1)',
            'datetime' => 'DATETIME',
            default => throw new \InvalidArgumentException("Unknown column type '{$column->type}'"),
        };
    }
}
```

`src/Kernel/Db/Dialect/Pgsql.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Dialect;

use PDO;
use Xaraya\Kernel\Db\Schema\Column;

final class Pgsql extends AbstractDialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function onConnect(PDO $pdo): void
    {
        $pdo->exec("SET TIME ZONE 'UTC'");
    }

    public function listTablesSql(): string
    {
        return 'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename';
    }

    public function dropTableSql(string $table): string
    {
        return parent::dropTableSql($table) . ' CASCADE';
    }

    protected function boolLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    protected function incrementsSql(): string
    {
        return 'INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY';
    }

    protected function typeSql(Column $column): string
    {
        return match ($column->type) {
            'ulid' => 'CHAR(26)',
            'string' => 'VARCHAR(' . ($column->length ?? 255) . ')',
            'text' => 'TEXT',
            'json' => 'JSONB',
            'int' => 'INTEGER',
            'bigint' => 'BIGINT',
            'bool' => 'BOOLEAN',
            'datetime' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            default => throw new \InvalidArgumentException("Unknown column type '{$column->type}'"),
        };
    }
}
```

- [ ] **Step 5: Implement `Schema`, `Connection` and `ConnectionFactory`**

`src/Kernel/Db/Schema/Schema.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Schema;

use LogicException;
use Xaraya\Kernel\Db\Connection;

final class Schema
{
    public function __construct(private readonly Connection $db) {}

    /** @param callable(Blueprint): void $define */
    public function create(string $table, callable $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        $this->run($this->db->dialect()->createTable($blueprint, $this->db->prefix()));
    }

    /** @param callable(Blueprint): void $define */
    public function table(string $table, callable $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        if ($blueprint->foreignKeys !== []) {
            throw new LogicException('Foreign keys can only be declared in Schema::create()');
        }
        $this->run($this->db->dialect()->alterTable($blueprint, $this->db->prefix()));
    }

    public function drop(string $table): void
    {
        $this->run([$this->db->dialect()->dropTableSql($this->db->prefix() . $table)]);
    }

    public function rename(string $from, string $to): void
    {
        $prefix = $this->db->prefix();
        $this->run([$this->db->dialect()->renameTableSql($prefix . $from, $prefix . $to)]);
    }

    public function has(string $table): bool
    {
        return $this->db->hasTable($table);
    }

    /** @param list<string> $statements */
    private function run(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->db->pdo()->exec($sql);
        }
    }
}
```

`src/Kernel/Db/Connection.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use Throwable;
use Xaraya\Kernel\Db\Dialect\Dialect;
use Xaraya\Kernel\Db\Schema\Schema;

final class Connection
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialect,
        private readonly string $prefix = 'xar_',
    ) {
        $dialect->onConnect($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier '{$name}'");
        }

        return $this->dialect->quote($name);
    }

    public function table(string $name): string
    {
        $this->ident($name);

        return $this->dialect->quote($this->prefix . $name);
    }

    public function sql(string $sql): string
    {
        return preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', fn (array $m): string => $this->table($m[1]), $sql) ?? $sql;
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($this->sql($sql));
        $statement->execute(array_map(self::normalize(...), $params));

        return $statement;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<int|string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): void
    {
        $columns = $this->columns($row);
        $this->query($this->dialect->insertSql($this->prefixed($table), $columns), array_values($row));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $conflict
     */
    public function upsert(string $table, array $row, array $conflict): void
    {
        $columns = $this->columns($row);
        $this->query($this->dialect->upsertSql($this->prefixed($table), $columns, $conflict), array_values($row));
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $values, array $where): int
    {
        [$whereSql, $whereParams] = $this->whereSql($where);
        $set = implode(', ', array_map(fn (string $c): string => $this->ident($c) . ' = ?', $this->columns($values)));

        return $this->query(
            'UPDATE ' . $this->table($table) . " SET {$set} WHERE {$whereSql}",
            [...array_values($values), ...$whereParams],
        )->rowCount();
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        [$whereSql, $whereParams] = $this->whereSql($where);

        return $this->query('DELETE FROM ' . $this->table($table) . " WHERE {$whereSql}", $whereParams)->rowCount();
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<string> */
    public function tables(): array
    {
        $names = [];
        foreach ($this->query($this->dialect->listTablesSql())->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name) && str_starts_with($name, $this->prefix)) {
                $names[] = substr($name, strlen($this->prefix));
            }
        }

        return $names;
    }

    public function hasTable(string $name): bool
    {
        return in_array($name, $this->tables(), true);
    }

    public function schema(): Schema
    {
        return new Schema($this);
    }

    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            is_bool($value) => (int) $value,
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    private function prefixed(string $table): string
    {
        $this->ident($table);

        return $this->prefix . $table;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<string>
     */
    private function columns(array $row): array
    {
        $columns = array_map('strval', array_keys($row));
        array_map($this->ident(...), $columns);

        return $columns;
    }

    /**
     * @param array<string, mixed> $where
     * @return array{0: string, 1: list<mixed>}
     */
    private function whereSql(array $where): array
    {
        if ($where === []) {
            throw new InvalidArgumentException('Refusing to UPDATE/DELETE without a WHERE clause');
        }
        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $parts[] = $this->ident($column) . ' IS NULL';
            } else {
                $parts[] = $this->ident($column) . ' = ?';
                $params[] = $value;
            }
        }

        return [implode(' AND ', $parts), $params];
    }
}
```

`src/Kernel/Db/ConnectionFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db;

use InvalidArgumentException;
use PDO;
use Xaraya\Kernel\Db\Dialect\Mysql;
use Xaraya\Kernel\Db\Dialect\Pgsql;
use Xaraya\Kernel\Db\Dialect\Sqlite;

final class ConnectionFactory
{
    /** @param array<string, mixed> $config */
    public static function make(array $config): Connection
    {
        $dsn = $config['dsn'] ?? null;
        if (!is_string($dsn) || $dsn === '') {
            throw new InvalidArgumentException('Database config needs a "dsn" string');
        }
        $driver = strtolower((string) strstr($dsn, ':', true));
        $dialect = match ($driver) {
            'sqlite' => new Sqlite(),
            'mysql' => new Mysql(),
            'pgsql' => new Pgsql(),
            default => throw new InvalidArgumentException("Unsupported database DSN '{$dsn}'"),
        };
        if ($driver === 'sqlite') {
            $path = substr($dsn, 7);
            if ($path !== '' && $path !== ':memory:' && !is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($driver === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }
        $user = $config['user'] ?? null;
        $password = $config['password'] ?? null;
        $pdo = new PDO($dsn, is_string($user) ? $user : null, is_string($password) ? $password : null, $options);
        $prefix = $config['prefix'] ?? 'xar_';

        return new Connection($pdo, $dialect, is_string($prefix) ? $prefix : 'xar_');
    }
}
```

- [ ] **Step 6: Run the tests on SQLite, then on local MySQL**

Run: `vendor/bin/phpunit tests/Kernel/Db`
Expected: PASS (18 tests).

Create a scratch MySQL database, then run the tests against it:

```bash
mysql -uroot -e 'CREATE DATABASE IF NOT EXISTS xaraya_test'
XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Kernel/Db
```

Expected: PASS. If local MySQL needs a password, set `XAR_TEST_DB_PASSWORD`. If MySQL isn't running, skip this check and note it in the report; CI covers it.

- [ ] **Step 7: Run static analysis and commit**

Run: `composer stan`
Expected: `[OK] No errors`. Fix any type errors without changing behaviour.

```bash
git add -A
git commit -m "feat(kernel): add PDO connection, SQLite/MySQL/Postgres dialects and schema builder"
```

---

### Task 6: Select builder with keyset cursors

**Files:**
- Create: `src/Kernel/Db/Select.php`
- Modify: `src/Kernel/Db/Connection.php` (add `select()`)
- Test: `tests/Kernel/Db/SelectTest.php`

**Interfaces:**
- Consumes: `Connection::ident()`, `table()`, `fetchAll()`, `fetchOne()`, `fetchValue()` and `normalize()` from Task 5.
- Produces:
  - `Connection::select(string $table): Select`
  - `Select::columns(string ...$columns): self`
  - `where(string $column, string $op, mixed $value): self`. `$op` is one of `= != <> < <= > >= like`.
  - `whereNull(string $column, bool $not = false): self`
  - `whereIn(string $column, array $values): self`
  - `cursor(array<string,mixed> $cursor, string $op = '<'): self`. Keyset comparison of `(c1, c2, …)` against `(v1, v2, …)`, written out portably.
  - `orderBy(string $column, string $direction = 'asc'): self`
  - `limit(int $limit, int $offset = 0): self`
  - `toSql(): array{0: string, 1: list<mixed>}`
  - `all(): list<array<string,mixed>>`
  - `first(): ?array<string,mixed>`
  - `count(): int`

- [ ] **Step 1: Write the failing test `tests/Kernel/Db/SelectTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Tests\Support\DbTestCase;

final class SelectTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->schema()->create('posts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('title');
            $t->datetime('published');
            $t->int('views')->nullable();
        });
        foreach ([
            ['01m3t19wn8reaww81zg1m47tja', 'A', '2026-10-03 00:00:00', 10],
            ['01m3t19wn8reaww81zg1m47tjc', 'C', '2026-10-02 00:00:00', null],
            ['01m3t19wn8reaww81zg1m47tjb', 'B', '2026-10-02 00:00:00', 30],
            ['01m3t19wn8reaww81zg1m47tjd', 'D', '2026-10-01 00:00:00', 40],
        ] as [$id, $title, $published, $views]) {
            $this->db->insert('posts', compact('id', 'title', 'published', 'views'));
        }
    }

    /** @param list<array<string, mixed>> $rows @return list<mixed> */
    private static function titles(array $rows): array
    {
        return array_column($rows, 'title');
    }

    public function testWhereOrderAndLimit(): void
    {
        $rows = $this->db->select('posts')->where('views', '>=', 20)->orderBy('views', 'desc')->all();
        self::assertSame(['D', 'B'], self::titles($rows));

        $rows = $this->db->select('posts')->orderBy('title')->limit(2, 1)->all();
        self::assertSame(['B', 'C'], self::titles($rows));

        self::assertSame(['A'], self::titles($this->db->select('posts')->where('title', 'like', 'A%')->all()));
    }

    public function testWhereNullAndIn(): void
    {
        self::assertSame(['C'], self::titles($this->db->select('posts')->whereNull('views')->all()));
        self::assertSame(3, $this->db->select('posts')->whereNull('views', not: true)->count());
        self::assertSame(['A', 'D'], self::titles($this->db->select('posts')->whereIn('title', ['D', 'A'])->orderBy('title')->all()));
        self::assertSame([], $this->db->select('posts')->whereIn('title', [])->all());
    }

    public function testKeysetPagingAcrossTies(): void
    {
        $page1 = $this->db->select('posts')->orderBy('published', 'desc')->orderBy('id', 'desc')->limit(2)->all();
        self::assertSame(['A', 'C'], self::titles($page1));

        $last = $page1[1];
        $page2 = $this->db->select('posts')
            ->cursor(['published' => $last['published'], 'id' => $last['id']], '<')
            ->orderBy('published', 'desc')->orderBy('id', 'desc')->limit(2)->all();
        self::assertSame(['B', 'D'], self::titles($page2));
    }

    public function testFirstCountAndColumns(): void
    {
        $first = $this->db->select('posts')->columns('id', 'title')->orderBy('title', 'desc')->first();
        self::assertSame(['id' => '01m3t19wn8reaww81zg1m47tjd', 'title' => 'D'], $first);
        self::assertNull($this->db->select('posts')->where('title', '=', 'Z')->first());
        self::assertSame(2, $this->db->select('posts')->where('published', '=', '2026-10-02 00:00:00')->count());
    }

    public function testRejectsBadOperatorAndIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select('posts')->where('title', 'OR 1=1 --', 'x');
    }

    public function testRejectsInjectedColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->select('posts')->orderBy('title; DROP TABLE posts');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Db/SelectTest.php`
Expected: errors with `Call to undefined method Xaraya\Kernel\Db\Connection::select()`.

- [ ] **Step 3: Implement `src/Kernel/Db/Select.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db;

use InvalidArgumentException;

final class Select
{
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'like'];

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<string> */
    private array $wheres = [];

    /** @var list<mixed> */
    private array $params = [];

    /** @var list<string> */
    private array $orders = [];

    private ?int $limit = null;
    private int $offset = 0;

    public function __construct(private readonly Connection $db, private readonly string $table) {}

    public function columns(string ...$columns): self
    {
        $this->columns = array_values(array_map($this->db->ident(...), $columns));

        return $this;
    }

    public function where(string $column, string $op, mixed $value): self
    {
        $op = strtolower($op);
        if (!in_array($op, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported operator '{$op}'");
        }
        $this->wheres[] = $this->db->ident($column) . ' ' . strtoupper($op) . ' ?';
        $this->params[] = Connection::normalize($value);

        return $this;
    }

    public function whereNull(string $column, bool $not = false): self
    {
        $this->wheres[] = $this->db->ident($column) . ($not ? ' IS NOT NULL' : ' IS NULL');

        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        if ($values === []) {
            $this->wheres[] = '1 = 0';

            return $this;
        }
        $this->wheres[] = $this->db->ident($column) . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        foreach ($values as $value) {
            $this->params[] = Connection::normalize($value);
        }

        return $this;
    }

    /** @param array<string, mixed> $cursor ordered column => value */
    public function cursor(array $cursor, string $op = '<'): self
    {
        if (!in_array($op, ['<', '>'], true) || $cursor === []) {
            throw new InvalidArgumentException('cursor() needs columns and an operator of < or >');
        }
        $columns = array_keys($cursor);
        $values = array_values(array_map(Connection::normalize(...), $cursor));
        $alternatives = [];
        foreach ($columns as $i => $column) {
            $terms = [];
            for ($j = 0; $j < $i; $j++) {
                $terms[] = $this->db->ident($columns[$j]) . ' = ?';
                $this->params[] = $values[$j];
            }
            $terms[] = $this->db->ident($column) . " {$op} ?";
            $this->params[] = $values[$i];
            $alternatives[] = '(' . implode(' AND ', $terms) . ')';
        }
        $this->wheres[] = '(' . implode(' OR ', $alternatives) . ')';

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orders[] = $this->db->ident($column) . (strtolower($direction) === 'desc' ? ' DESC' : ' ASC');

        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = max(0, $limit);
        $this->offset = max(0, $offset);

        return $this;
    }

    /** @return array{0: string, 1: list<mixed>} */
    public function toSql(): array
    {
        return [$this->build(implode(', ', $this->columns), true), $this->params];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        [$sql, $params] = $this->toSql();

        return $this->db->fetchAll($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        [$sql, $params] = (clone $this)->limit(1)->toSql();

        return $this->db->fetchOne($sql, $params);
    }

    public function count(): int
    {
        return (int) $this->db->fetchValue($this->build('COUNT(*)', false), $this->params);
    }

    private function build(string $columns, bool $withOrderAndLimit): string
    {
        $sql = "SELECT {$columns} FROM " . $this->db->table($this->table);
        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }
        if ($withOrderAndLimit && $this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }
        if ($withOrderAndLimit && $this->limit !== null) {
            $sql .= " LIMIT {$this->limit} OFFSET {$this->offset}";
        }

        return $sql;
    }
}
```

Add to `Connection` (after `schema()`):
```php
    public function select(string $table): Select
    {
        return new Select($this, $table);
    }
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Db`
Expected: PASS (24 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add portable select builder with keyset cursor paging"
```

---

### Task 7: Migrations

**Files:**
- Create: `src/Kernel/Db/Migrations/Migration.php`, `src/Kernel/Db/Migrations/Migrator.php`, `src/Kernel/migrations/2026_10_08_000001_create_modules.php`
- Test: `tests/Kernel/Db/MigratorTest.php`

**Interfaces:**
- Consumes: `Connection`, `Schema` and `Blueprint` (Task 5) and `Select` (Task 6).
- Produces:
  - `abstract class Migration { abstract public function up(Schema $schema): void; public function down(Schema $schema): void {} }`. Each migration file is named `YYYY_MM_DD_NNNNNN_description.php` and returns `new class extends Migration {…}`.
  - `Migrator::__construct(Connection $db)`
  - `ensureTable(): void`
  - `migrate(array<string,string> $paths): list<string>`. `$paths` maps module to directory, in run order. It returns `"module/name"` for each migration run, and everything in one call shares one batch number.
  - `rollback(array<string,string> $paths, int $steps = 1): list<string>`
  - `status(array<string,string> $paths): list<array{module: string, name: string, batch: ?int}>`
  - The kernel migration creates `xar_modules` (`name` string(64) PK, `version` string(32), `enabled` bool default true, `installed_at` datetime).

- [ ] **Step 1: Write the failing test `tests/Kernel/Db/MigratorTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Db;

use RuntimeException;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Tests\Support\DbTestCase;

final class MigratorTest extends DbTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/xar-mig-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->writeMigration('2026_01_01_000001_create_alpha', 'alpha');
        $this->writeMigration('2026_01_01_000002_create_beta', 'beta');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        parent::tearDown();
    }

    private function writeMigration(string $name, string $table): void
    {
        file_put_contents("{$this->dir}/{$name}.php", <<<PHP
            <?php
            declare(strict_types=1);
            use Xaraya\\Kernel\\Db\\Migrations\\Migration;
            use Xaraya\\Kernel\\Db\\Schema\\Blueprint;
            use Xaraya\\Kernel\\Db\\Schema\\Schema;
            return new class extends Migration {
                public function up(Schema \$schema): void
                {
                    \$schema->create('{$table}', function (Blueprint \$t): void { \$t->increments(); });
                }
                public function down(Schema \$schema): void
                {
                    \$schema->drop('{$table}');
                }
            };
            PHP);
    }

    public function testMigrateIsIdempotentAndBatched(): void
    {
        $m = new Migrator($this->db);
        self::assertSame(['demo/2026_01_01_000001_create_alpha', 'demo/2026_01_01_000002_create_beta'], $m->migrate(['demo' => $this->dir]));
        self::assertTrue($this->db->hasTable('alpha'));
        self::assertTrue($this->db->hasTable('beta'));
        self::assertSame([], $m->migrate(['demo' => $this->dir]));

        $this->writeMigration('2026_01_02_000001_create_gamma', 'gamma');
        self::assertSame(['demo/2026_01_02_000001_create_gamma'], $m->migrate(['demo' => $this->dir]));

        $status = $m->status(['demo' => $this->dir]);
        self::assertSame([1, 1, 2], array_column($status, 'batch'));
    }

    public function testRollbackLastBatchOnly(): void
    {
        $m = new Migrator($this->db);
        $m->migrate(['demo' => $this->dir]);
        $this->writeMigration('2026_01_02_000001_create_gamma', 'gamma');
        $m->migrate(['demo' => $this->dir]);

        self::assertSame(['demo/2026_01_02_000001_create_gamma'], $m->rollback(['demo' => $this->dir]));
        self::assertFalse($this->db->hasTable('gamma'));
        self::assertTrue($this->db->hasTable('alpha'));

        self::assertSame(
            ['demo/2026_01_01_000002_create_beta', 'demo/2026_01_01_000001_create_alpha'],
            $m->rollback(['demo' => $this->dir]),
        );
        self::assertFalse($this->db->hasTable('alpha'));
        self::assertSame([null, null, null], array_column($m->status(['demo' => $this->dir]), 'batch'));
    }

    public function testMissingDirectoryIsEmpty(): void
    {
        self::assertSame([], (new Migrator($this->db))->migrate(['none' => '/nonexistent/dir']));
    }

    public function testFileMustReturnMigration(): void
    {
        file_put_contents("{$this->dir}/2026_01_03_000001_bad.php", '<?php return 42;');
        $this->expectException(RuntimeException::class);
        (new Migrator($this->db))->migrate(['demo' => $this->dir]);
    }

    public function testKernelMigrationCreatesModulesTable(): void
    {
        $kernel = dirname(__DIR__, 3) . '/src/Kernel/migrations';
        (new Migrator($this->db))->migrate(['kernel' => $kernel]);
        $this->db->insert('modules', ['name' => 'blog', 'version' => '0.1.0', 'installed_at' => '2026-10-08 00:00:00']);
        self::assertTrue((bool) $this->db->fetchValue('SELECT enabled FROM {modules} WHERE name = ?', ['blog']));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Db/MigratorTest.php`
Expected: errors, class `Migrator` not found.

- [ ] **Step 3: Implement**

`src/Kernel/Db/Migrations/Migration.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Migrations;

use Xaraya\Kernel\Db\Schema\Schema;

abstract class Migration
{
    abstract public function up(Schema $schema): void;

    public function down(Schema $schema): void {}
}
```

`src/Kernel/Db/Migrations/Migrator.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Db\Migrations;

use DateTimeImmutable;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Schema\Blueprint;

final class Migrator
{
    /** @var array<string, Migration> */
    private array $loaded = [];

    public function __construct(private readonly Connection $db) {}

    public function ensureTable(): void
    {
        if ($this->db->hasTable('migrations')) {
            return;
        }
        $this->db->schema()->create('migrations', function (Blueprint $t): void {
            $t->increments();
            $t->string('module', 64);
            $t->string('name', 191);
            $t->int('batch');
            $t->datetime('ran_at');
            $t->unique('module', 'name');
        });
    }

    /**
     * @param array<string, string> $paths module => directory
     * @return list<string>
     */
    public function migrate(array $paths): array
    {
        $this->ensureTable();
        $ran = $this->ran();
        $batch = (int) $this->db->fetchValue('SELECT MAX(batch) FROM {migrations}') + 1;
        $done = [];
        foreach ($paths as $module => $dir) {
            foreach ($this->files($dir) as $name => $file) {
                if (isset($ran["{$module}/{$name}"])) {
                    continue;
                }
                $migration = $this->load($file);
                $this->atomically(function () use ($migration, $module, $name, $batch): void {
                    $migration->up($this->db->schema());
                    $this->db->insert('migrations', [
                        'module' => $module,
                        'name' => $name,
                        'batch' => $batch,
                        'ran_at' => new DateTimeImmutable(),
                    ]);
                });
                $done[] = "{$module}/{$name}";
            }
        }

        return $done;
    }

    /**
     * @param array<string, string> $paths module => directory
     * @return list<string>
     */
    public function rollback(array $paths, int $steps = 1): array
    {
        $this->ensureTable();
        $batches = array_map(
            'intval',
            array_column($this->db->fetchAll('SELECT DISTINCT batch FROM {migrations} ORDER BY batch DESC'), 'batch'),
        );
        $batches = array_slice($batches, 0, max(1, $steps));
        $rows = $this->db->select('migrations')->whereIn('batch', $batches)->orderBy('id', 'desc')->all();
        $done = [];
        foreach ($rows as $row) {
            $module = (string) $row['module'];
            $name = (string) $row['name'];
            $dir = $paths[$module] ?? throw new RuntimeException("No migration path known for module '{$module}'");
            $migration = $this->load("{$dir}/{$name}.php");
            $this->atomically(function () use ($migration, $row): void {
                $migration->down($this->db->schema());
                $this->db->delete('migrations', ['id' => $row['id']]);
            });
            $done[] = "{$module}/{$name}";
        }

        return $done;
    }

    /**
     * @param array<string, string> $paths
     * @return list<array{module: string, name: string, batch: ?int}>
     */
    public function status(array $paths): array
    {
        $this->ensureTable();
        $ran = $this->ran();
        $status = [];
        foreach ($paths as $module => $dir) {
            foreach (array_keys($this->files($dir)) as $name) {
                $status[] = ['module' => $module, 'name' => $name, 'batch' => $ran["{$module}/{$name}"] ?? null];
            }
        }

        return $status;
    }

    /** @return array<string, int> "module/name" => batch */
    private function ran(): array
    {
        $ran = [];
        foreach ($this->db->fetchAll('SELECT module, name, batch FROM {migrations}') as $row) {
            $ran[$row['module'] . '/' . $row['name']] = (int) $row['batch'];
        }

        return $ran;
    }

    /** @return array<string, string> name => file, sorted */
    private function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $out[basename($file, '.php')] = $file;
        }

        return $out;
    }

    private function load(string $file): Migration
    {
        if (isset($this->loaded[$file])) {
            return $this->loaded[$file];
        }
        if (!is_file($file)) {
            throw new RuntimeException("Migration file {$file} not found");
        }
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration {$file} must return a Migration instance");
        }

        return $this->loaded[$file] = $migration;
    }

    private function atomically(callable $fn): void
    {
        if ($this->db->dialect()->transactionalDdl()) {
            $this->db->transaction(fn () => $fn());
        } else {
            $fn();
        }
    }
}
```

`src/Kernel/migrations/2026_10_08_000001_create_modules.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('modules', function (Blueprint $t): void {
            $t->string('name', 64)->primary();
            $t->string('version', 32);
            $t->bool('enabled')->default(true);
            $t->datetime('installed_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('modules');
    }
};
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Db`
Expected: PASS (29 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add migrator with batches, rollback, status and modules table"
```

---

### Task 8: Events

**Files:**
- Create: `src/Kernel/Events/{Event,EventDispatcher,ItemEvent,ItemCreated,ItemUpdated,ItemDeleted,ItemDisplayed}.php`
- Test: `tests/Kernel/Events/EventDispatcherTest.php`

**Interfaces:**
- Consumes: `Container::get()` (Task 3).
- Produces:
  - `abstract class Event` with `stopPropagation(): void` and `isPropagationStopped(): bool`.
  - `EventDispatcher::__construct(Container $container)`.
  - `listen(string $event, callable|string $listener, int $priority = 0): void`. A string listener is an invokable class resolved from the container. A listener for a parent class or interface also receives subclasses.
  - `dispatch(object $event): object` (returns the same event). Higher priority runs first; ties run in registration order.
  - `abstract class ItemEvent extends Event` with readonly `string $module`, `string $itemtype`, `string $id` and `array $item = []`.
  - `final` subclasses `ItemCreated`, `ItemUpdated`, `ItemDeleted` and `ItemDisplayed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Events;

use LogicException;
use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Kernel\Events\ItemUpdated;

final class RecordingListener
{
    /** @var list<string> */
    public array $seen = [];

    public function __invoke(ItemEvent $event): void
    {
        $this->seen[] = $event::class . ':' . $event->id;
    }
}

final class EventDispatcherTest extends TestCase
{
    public function testPriorityThenRegistrationOrder(): void
    {
        $d = new EventDispatcher(new Container());
        $log = [];
        $d->listen(ItemCreated::class, function () use (&$log): void { $log[] = 'low'; }, -5);
        $d->listen(ItemCreated::class, function () use (&$log): void { $log[] = 'first-zero'; });
        $d->listen(ItemCreated::class, function () use (&$log): void { $log[] = 'high'; }, 10);
        $d->listen(ItemCreated::class, function () use (&$log): void { $log[] = 'second-zero'; });

        $event = new ItemCreated('blog', 'post', '01m3t19wn8reaww81zg1m47tjq', ['title' => 'T']);
        self::assertSame($event, $d->dispatch($event));
        self::assertSame(['high', 'first-zero', 'second-zero', 'low'], $log);
        self::assertSame('T', $event->item['title']);
    }

    public function testParentClassListenersReceiveSubclasses(): void
    {
        $d = new EventDispatcher(new Container());
        $count = 0;
        $d->listen(ItemEvent::class, function () use (&$count): void { $count++; });
        $d->dispatch(new ItemCreated('a', 'b', '1'));
        $d->dispatch(new ItemUpdated('a', 'b', '1'));
        self::assertSame(2, $count);
    }

    public function testStopPropagation(): void
    {
        $d = new EventDispatcher(new Container());
        $ran = [];
        $d->listen(ItemCreated::class, function (ItemCreated $e) use (&$ran): void { $ran[] = 1; $e->stopPropagation(); }, 1);
        $d->listen(ItemCreated::class, function () use (&$ran): void { $ran[] = 2; });
        $event = $d->dispatch(new ItemCreated('a', 'b', '1'));
        self::assertSame([1], $ran);
        self::assertTrue($event->isPropagationStopped());
    }

    public function testClassStringListenersResolveFromContainer(): void
    {
        $c = new Container();
        $d = new EventDispatcher($c);
        $d->listen(ItemCreated::class, RecordingListener::class);
        $d->dispatch(new ItemCreated('a', 'b', 'x1'));
        self::assertSame([ItemCreated::class . ':x1'], $c->get(RecordingListener::class)->seen);
    }

    public function testNonInvokableListenerThrows(): void
    {
        $d = new EventDispatcher(new Container());
        $d->listen(ItemCreated::class, \stdClass::class);
        $this->expectException(LogicException::class);
        $d->dispatch(new ItemCreated('a', 'b', '1'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Events`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`src/Kernel/Events/Event.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

abstract class Event
{
    private bool $stopped = false;

    public function stopPropagation(): void
    {
        $this->stopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
```

`src/Kernel/Events/ItemEvent.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

abstract class ItemEvent extends Event
{
    /** @param array<string, mixed> $item */
    public function __construct(
        public readonly string $module,
        public readonly string $itemtype,
        public readonly string $id,
        public readonly array $item = [],
    ) {}
}
```

`ItemCreated.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

final class ItemCreated extends ItemEvent {}
```

`ItemUpdated.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

final class ItemUpdated extends ItemEvent {}
```

`ItemDeleted.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

final class ItemDeleted extends ItemEvent {}
```

`ItemDisplayed.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

final class ItemDisplayed extends ItemEvent {}
```

`src/Kernel/Events/EventDispatcher.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Events;

use LogicException;
use Xaraya\Kernel\Container\Container;

final class EventDispatcher
{
    /** @var array<string, list<array{priority: int, order: int, listener: callable|string}>> */
    private array $listeners = [];

    private int $order = 0;

    public function __construct(private readonly Container $container) {}

    public function listen(string $event, callable|string $listener, int $priority = 0): void
    {
        $this->listeners[$event][] = ['priority' => $priority, 'order' => $this->order++, 'listener' => $listener];
    }

    /**
     * @template T of object
     * @param T $event
     * @return T
     */
    public function dispatch(object $event): object
    {
        foreach ($this->listenersFor($event) as $listener) {
            if ($event instanceof Event && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }

    /** @return list<callable> */
    private function listenersFor(object $event): array
    {
        $types = [$event::class, ...array_values(class_parents($event) ?: []), ...array_values(class_implements($event) ?: [])];
        $matched = [];
        foreach ($types as $type) {
            foreach ($this->listeners[$type] ?? [] as $entry) {
                $matched[] = $entry;
            }
        }
        usort($matched, fn (array $a, array $b): int => [$b['priority'], $a['order']] <=> [$a['priority'], $b['order']]);

        return array_map(fn (array $entry): callable => $this->resolve($entry['listener']), $matched);
    }

    private function resolve(callable|string $listener): callable
    {
        if (is_callable($listener)) {
            return $listener;
        }
        $object = $this->container->get($listener);
        if (!is_callable($object)) {
            throw new LogicException("Event listener {$listener} is not invokable");
        }

        return $object;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Events`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add event dispatcher and item events"
```

---

### Task 9: Module manifests and registry

**Files:**
- Create: `src/Kernel/Module/{Manifest,ModuleRegistry,ModuleException}.php`
- Create fixtures:
  - `tests/fixtures/modules/alpha/module.json`
  - `tests/fixtures/modules/alpha/migrations/2026_10_08_000001_create_alpha_items.php`
  - `tests/fixtures/modules/alpha/src/Thing.php`
  - `tests/fixtures/modules/beta/module.json`
- Test: `tests/Kernel/Module/ManifestTest.php`, `tests/Kernel/Module/ModuleRegistryTest.php`

**Interfaces:**
- Consumes: `Connection` (Tasks 5–6), `Migrator` (Task 7).
- Produces:
  - **`Manifest::fromDirectory(string $dir): Manifest`**
    - readonly `name`, `version` and `path` (real path)
    - `namespace(): string`, ending in `\`. Defaults to `Xaraya\Module\<Studly>\`.
    - `provider(): ?string`, `routes(): ?string`
    - `migrationsPath(): ?string` (absolute)
    - `subscribers(): list<array{event: string, listener: string, priority: int}>`
    - `commands(): list<string>`
    - `requiredModules(): array<string,string>`
    - `isContent(): bool`
    - `get(string $key, mixed $default = null): mixed`
  - **`ModuleRegistry::__construct(Connection $db, Migrator $migrator, list<string> $paths, string $kernelMigrations)`**
    - `discover(): array<string, Manifest>`
    - `get(string $name): Manifest`
    - `enabled(): array<string, Manifest>`, in dependency order
    - `isEnabled(string $name): bool`
    - `enable(string $name): void`. Runs the kernel migrations, then the module's migrations as a separate batch, then upserts its `modules` row.
    - `disable(string $name): void`
    - `migrationPaths(bool $all = false): array<string,string>`. Kernel first; covers the enabled modules, or every discovered module when `$all` is true.
    - `registerAutoloader(): void`
  - **`ModuleException extends \RuntimeException`**.

- [ ] **Step 1: Create the fixtures**

`tests/fixtures/modules/alpha/module.json`:
```json
{
  "name": "alpha",
  "version": "1.2.0",
  "content": true,
  "migrations": "migrations",
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Alpha\\Thing", "priority": 5 }
  ],
  "commands": ["Xaraya\\Module\\Alpha\\Thing"]
}
```

`tests/fixtures/modules/alpha/migrations/2026_10_08_000001_create_alpha_items.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('alpha_items', function (Blueprint $t): void {
            $t->increments();
            $t->string('label');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('alpha_items');
    }
};
```

`tests/fixtures/modules/alpha/src/Thing.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Alpha;

final class Thing
{
    public function hello(): string
    {
        return 'alpha';
    }
}
```

`tests/fixtures/modules/beta/module.json`:
```json
{
  "name": "beta",
  "version": "0.1.0",
  "requires": { "kernel": "^0.1", "modules": { "alpha": "*" } }
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Kernel/Module/ManifestTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Module;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;

final class ManifestTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/modules';

    public function testReadsAlpha(): void
    {
        $m = Manifest::fromDirectory(self::FIXTURES . '/alpha');
        self::assertSame('alpha', $m->name);
        self::assertSame('1.2.0', $m->version);
        self::assertSame('Xaraya\\Module\\Alpha\\', $m->namespace());
        self::assertSame(realpath(self::FIXTURES . '/alpha') . '/migrations', $m->migrationsPath());
        self::assertTrue($m->isContent());
        self::assertSame([['event' => 'Xaraya\\Kernel\\Events\\ItemCreated', 'listener' => 'Xaraya\\Module\\Alpha\\Thing', 'priority' => 5]], $m->subscribers());
        self::assertSame(['Xaraya\\Module\\Alpha\\Thing'], $m->commands());
        self::assertNull($m->routes());
        self::assertNull($m->provider());
        self::assertSame([], $m->requiredModules());
    }

    public function testReadsBetaRequirements(): void
    {
        $m = Manifest::fromDirectory(self::FIXTURES . '/beta');
        self::assertSame(['alpha' => '*'], $m->requiredModules());
        self::assertNull($m->migrationsPath());
        self::assertFalse($m->isContent());
    }

    public function testStudlyNamespaceForHyphenatedNames(): void
    {
        $dir = $this->tempModule('{"name": "hello-hooks", "version": "0.1.0"}');
        self::assertSame('Xaraya\\Module\\HelloHooks\\', Manifest::fromDirectory($dir)->namespace());
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ModuleException::class);
        Manifest::fromDirectory($this->tempModule('{nope'));
    }

    public function testBadNameThrows(): void
    {
        $this->expectException(ModuleException::class);
        Manifest::fromDirectory($this->tempModule('{"name": "Bad Name", "version": "1"}'));
    }

    public function testMissingVersionThrows(): void
    {
        $this->expectException(ModuleException::class);
        Manifest::fromDirectory($this->tempModule('{"name": "ok"}'));
    }

    private function tempModule(string $json): string
    {
        $dir = sys_get_temp_dir() . '/xar-mod-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/module.json', $json);

        return $dir;
    }
}
```

`tests/Kernel/Module/ModuleRegistryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Module;

use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\DbTestCase;

final class ModuleRegistryTest extends DbTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/modules';

    private function registry(array $paths = [self::FIXTURES]): ModuleRegistry
    {
        return new ModuleRegistry($this->db, new Migrator($this->db), $paths, dirname(__DIR__, 3) . '/src/Kernel/migrations');
    }

    public function testDiscoverAndEmptyEnabledBeforeInstall(): void
    {
        $r = $this->registry();
        self::assertSame(['alpha', 'beta'], array_keys($r->discover()));
        self::assertSame([], $r->enabled());
        self::assertFalse($r->isEnabled('alpha'));
    }

    public function testEnableRunsMigrationsAndRecordsModule(): void
    {
        $r = $this->registry();
        $r->enable('alpha');
        self::assertTrue($this->db->hasTable('modules'));
        self::assertTrue($this->db->hasTable('alpha_items'));
        self::assertTrue($r->isEnabled('alpha'));
        self::assertSame('1.2.0', $this->db->fetchValue('SELECT version FROM {modules} WHERE name = ?', ['alpha']));
    }

    public function testDependenciesAreEnforcedAndOrdered(): void
    {
        $r = $this->registry();
        try {
            $r->enable('beta');
            self::fail('beta should require alpha');
        } catch (ModuleException $e) {
            self::assertStringContainsString("requires 'alpha'", $e->getMessage());
        }
        $r->enable('alpha');
        $r->enable('beta');
        self::assertSame(['alpha', 'beta'], array_keys($r->enabled()));

        try {
            $r->disable('alpha');
            self::fail('alpha is required by beta');
        } catch (ModuleException $e) {
            self::assertStringContainsString("'beta' requires it", $e->getMessage());
        }
        $r->disable('beta');
        $r->disable('alpha');
        self::assertSame([], $r->enabled());
    }

    public function testMigrationPaths(): void
    {
        $r = $this->registry();
        $kernel = dirname(__DIR__, 3) . '/src/Kernel/migrations';
        self::assertSame(['kernel' => $kernel], $r->migrationPaths());
        self::assertSame(['kernel' => $kernel, 'alpha' => realpath(self::FIXTURES . '/alpha') . '/migrations'], $r->migrationPaths(all: true));
    }

    public function testUnknownModuleThrows(): void
    {
        $this->expectException(ModuleException::class);
        $this->registry()->enable('zeta');
    }

    public function testDuplicateModuleNamesThrow(): void
    {
        $this->expectException(ModuleException::class);
        $this->registry([self::FIXTURES, self::FIXTURES . '/../modules'])->discover();
    }

    public function testAutoloaderLoadsModuleClasses(): void
    {
        $this->registry()->registerAutoloader();
        self::assertSame('alpha', (new \Xaraya\Module\Alpha\Thing())->hello());
    }
}
```

- [ ] **Step 3: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Module`
Expected: errors, classes not found.

- [ ] **Step 4: Implement**

`src/Kernel/Module/ModuleException.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Module;

final class ModuleException extends \RuntimeException {}
```

`src/Kernel/Module/Manifest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Module;

use JsonException;

final class Manifest
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $path,
        private readonly array $data,
    ) {}

    public static function fromDirectory(string $dir): self
    {
        $file = $dir . '/module.json';
        if (!is_file($file)) {
            throw new ModuleException("No module.json in {$dir}");
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ModuleException("Invalid JSON in {$file}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data)) {
            throw new ModuleException("{$file} must contain a JSON object");
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/', $name) !== 1) {
            throw new ModuleException("{$file}: \"name\" must be a lower-case identifier");
        }
        $version = $data['version'] ?? null;
        if (!is_string($version) || $version === '') {
            throw new ModuleException("{$file}: \"version\" is required");
        }

        /** @var array<string, mixed> $data */
        return new self($name, $version, realpath($dir) ?: $dir, $data);
    }

    public function namespace(): string
    {
        $ns = $this->data['namespace'] ?? null;
        if (is_string($ns) && $ns !== '') {
            return rtrim($ns, '\\') . '\\';
        }

        return 'Xaraya\\Module\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $this->name))) . '\\';
    }

    public function provider(): ?string
    {
        return $this->string('provider');
    }

    public function routes(): ?string
    {
        return $this->string('routes');
    }

    public function migrationsPath(): ?string
    {
        $dir = $this->string('migrations');

        return $dir === null ? null : $this->path . '/' . trim($dir, '/');
    }

    /** @return list<array{event: string, listener: string, priority: int}> */
    public function subscribers(): array
    {
        $out = [];
        foreach ((array) ($this->data['subscribers'] ?? []) as $i => $s) {
            if (!is_array($s) || !is_string($s['event'] ?? null) || !is_string($s['listener'] ?? null)) {
                throw new ModuleException("{$this->name}: subscribers[{$i}] needs string \"event\" and \"listener\"");
            }
            $out[] = ['event' => $s['event'], 'listener' => $s['listener'], 'priority' => (int) ($s['priority'] ?? 0)];
        }

        return $out;
    }

    /** @return list<string> */
    public function commands(): array
    {
        return array_values(array_filter((array) ($this->data['commands'] ?? []), 'is_string'));
    }

    /** @return array<string, string> */
    public function requiredModules(): array
    {
        $requires = $this->data['requires'] ?? [];
        $modules = is_array($requires) ? ($requires['modules'] ?? []) : [];
        $out = [];
        foreach ((array) $modules as $name => $constraint) {
            $out[(string) $name] = is_string($constraint) ? $constraint : '*';
        }

        return $out;
    }

    public function isContent(): bool
    {
        return ($this->data['content'] ?? false) === true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    private function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
```

`src/Kernel/Module/ModuleRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Module;

use DateTimeImmutable;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Migrations\Migrator;

final class ModuleRegistry
{
    /** @var array<string, Manifest>|null */
    private ?array $discovered = null;

    /** @param list<string> $paths directories that contain module folders */
    public function __construct(
        private readonly Connection $db,
        private readonly Migrator $migrator,
        private readonly array $paths,
        private readonly string $kernelMigrations,
    ) {}

    /** @return array<string, Manifest> */
    public function discover(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }
        $found = [];
        foreach ($this->paths as $base) {
            foreach (glob(rtrim($base, '/') . '/*/module.json') ?: [] as $file) {
                $manifest = Manifest::fromDirectory(dirname($file));
                if (isset($found[$manifest->name])) {
                    throw new ModuleException("Module '{$manifest->name}' found twice: {$found[$manifest->name]->path} and {$manifest->path}");
                }
                $found[$manifest->name] = $manifest;
            }
        }
        ksort($found);

        return $this->discovered = $found;
    }

    public function get(string $name): Manifest
    {
        return $this->discover()[$name] ?? throw new ModuleException("Unknown module '{$name}'");
    }

    /** @return array<string, Manifest> */
    public function enabled(): array
    {
        if (!$this->db->hasTable('modules')) {
            return [];
        }
        $discovered = $this->discover();
        $enabled = [];
        foreach ($this->db->select('modules')->where('enabled', '=', true)->orderBy('name')->all() as $row) {
            $name = (string) $row['name'];
            if (isset($discovered[$name])) {
                $enabled[$name] = $discovered[$name];
            }
        }

        return $this->sortByDependencies($enabled);
    }

    public function isEnabled(string $name): bool
    {
        return isset($this->enabled()[$name]);
    }

    public function enable(string $name): void
    {
        $manifest = $this->get($name);
        foreach (array_keys($manifest->requiredModules()) as $dependency) {
            if (!$this->isEnabled($dependency)) {
                throw new ModuleException("Module '{$name}' requires '{$dependency}'; enable it first");
            }
        }
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
        $this->migrator->migrate(['kernel' => $this->kernelMigrations]);
        if ($manifest->migrationsPath() !== null) {
            $this->migrator->migrate([$name => $manifest->migrationsPath()]);
        }
        $this->db->upsert('modules', [
            'name' => $name,
            'version' => $manifest->version,
            'enabled' => true,
            'installed_at' => new DateTimeImmutable(),
        ], ['name']);
    }

    public function disable(string $name): void
    {
        $this->get($name);
        foreach ($this->enabled() as $other) {
            if ($other->name !== $name && array_key_exists($name, $other->requiredModules())) {
                throw new ModuleException("Cannot disable '{$name}': '{$other->name}' requires it");
            }
        }
        if ($this->db->hasTable('modules')) {
            $this->db->update('modules', ['enabled' => false], ['name' => $name]);
        }
    }

    /** @return array<string, string> */
    public function migrationPaths(bool $all = false): array
    {
        $paths = ['kernel' => $this->kernelMigrations];
        foreach ($all ? $this->discover() : $this->enabled() as $manifest) {
            if ($manifest->migrationsPath() !== null) {
                $paths[$manifest->name] = $manifest->migrationsPath();
            }
        }

        return $paths;
    }

    public function registerAutoloader(): void
    {
        spl_autoload_register(function (string $class): void {
            foreach ($this->discover() as $manifest) {
                $ns = $manifest->namespace();
                if (!str_starts_with($class, $ns)) {
                    continue;
                }
                $file = $manifest->path . '/src/' . str_replace('\\', '/', substr($class, strlen($ns))) . '.php';
                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        });
    }

    /**
     * @param array<string, Manifest> $modules
     * @return array<string, Manifest>
     */
    private function sortByDependencies(array $modules): array
    {
        $sorted = [];
        $visit = function (string $name, array $stack) use (&$visit, &$sorted, $modules): void {
            if (isset($sorted[$name]) || !isset($modules[$name])) {
                return;
            }
            if (in_array($name, $stack, true)) {
                throw new ModuleException('Circular module dependency: ' . implode(' -> ', [...$stack, $name]));
            }
            foreach (array_keys($modules[$name]->requiredModules()) as $dependency) {
                $visit($dependency, [...$stack, $name]);
            }
            $sorted[$name] = $modules[$name];
        };
        foreach (array_keys($modules) as $name) {
            $visit($name, []);
        }

        return $sorted;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Module`
Expected: PASS (13 tests).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(kernel): add module manifests, registry, dependency order and autoloader"
```

---

### Task 10: Routing and URL generation

**Files:**
- Create: `src/Kernel/Routing/{Route,RouteCollector,RouteProvider,Router,RouteMatch,UrlGenerator}.php` and `src/Kernel/Http/Exception/{HttpException,NotFound,Forbidden,Unauthorized,MethodNotAllowed,TooManyRequests}.php`. The router throws these exceptions, so they're created here.
- Test: `tests/Kernel/Routing/RouterTest.php`, `tests/Kernel/Routing/UrlGeneratorTest.php`

**Interfaces:**
- Produces:
  - **`HttpException extends \RuntimeException`**
    - `__construct(int $status, string $message = '', array<string,string> $headers = [], ?\Throwable $previous = null)`
    - `status(): int`, `headers(): array<string,string>`
    - `static reason(int $status): string`
  - **HTTP exception subclasses**:
    - `NotFound(string $message = '')` (404)
    - `Forbidden(string $message = '')` (403)
    - `Unauthorized(string $message = '')` (401)
    - `MethodNotAllowed(list<string> $allowed)` (405, with an `Allow` header)
    - `TooManyRequests(int $retryAfter)` (429, with a `Retry-After` header)
  - **`Route`**
    - `__construct(list<string> $methods, string $path, string|array|\Closure $handler, ?string $name = null)`, with readonly props of the same names
    - `middleware(string ...$aliases): self`
    - `getMiddleware(): list<string>`
    - `hasMiddleware(string $alias): bool`. Matches `alias` or `alias:args`.
  - **`RouteCollector`**
    - `get`, `post`, `put`, `patch`, `delete` and `any`, each `(string $path, string|array|\Closure $handler, ?string $name = null): Route`
    - `group(string $prefix, list<string> $middleware, callable(RouteCollector): void $define): void`
    - `routes(): list<Route>`
  - **`interface RouteProvider { public function routes(RouteCollector $routes): void; }`**
  - **`Router`**
    - `__construct(list<Route> $routes, ?string $cacheFile = null)`. It adds an `OPTIONS` route for every path that has a route with `cors` middleware.
    - `match(string $method, string $path): RouteMatch`. Throws `NotFound` or `MethodNotAllowed`.
    - `routes(): list<Route>`
  - **`RouteMatch`**: readonly `Route $route` and `array<string,string> $params`.
  - **`UrlGenerator`**
    - `__construct(list<Route> $routes, string $baseUrl)`
    - `generate(string $name, array<string,string|int> $params = [], bool $absolute = false): string`. Extra params become the query string.
    - `has(string $name): bool`

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Routing/RouterTest.php`:
```php
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
```

`tests/Kernel/Routing/UrlGeneratorTest.php`:
```php
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Routing`
Expected: errors, classes not found.

- [ ] **Step 3: Implement the HTTP exceptions**

`src/Kernel/Http/Exception/HttpException.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

use RuntimeException;
use Throwable;

class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::reason($status), $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public static function reason(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
            500 => 'Server Error',
            503 => 'Service Unavailable',
            default => 'Error',
        };
    }
}
```

`NotFound.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

final class NotFound extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(404, $message);
    }
}
```

`Forbidden.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

final class Forbidden extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(403, $message);
    }
}
```

`Unauthorized.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

final class Unauthorized extends HttpException
{
    public function __construct(string $message = '')
    {
        parent::__construct(401, $message);
    }
}
```

`MethodNotAllowed.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

final class MethodNotAllowed extends HttpException
{
    /** @param list<string> $allowed */
    public function __construct(array $allowed)
    {
        parent::__construct(405, '', ['Allow' => implode(', ', $allowed)]);
    }
}
```

`TooManyRequests.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

final class TooManyRequests extends HttpException
{
    public function __construct(int $retryAfter)
    {
        parent::__construct(429, '', ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
```

- [ ] **Step 4: Implement routing**

`src/Kernel/Routing/Route.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use Closure;

final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    /**
     * @param list<string> $methods
     * @param string|array{0: string, 1: string}|Closure $handler
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $path,
        public readonly string|array|Closure $handler,
        public readonly ?string $name = null,
    ) {}

    public function middleware(string ...$aliases): self
    {
        array_push($this->middleware, ...array_values($aliases));

        return $this;
    }

    /** @return list<string> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function hasMiddleware(string $alias): bool
    {
        foreach ($this->middleware as $m) {
            if ($m === $alias || str_starts_with($m, $alias . ':')) {
                return true;
            }
        }

        return false;
    }
}
```

`src/Kernel/Routing/RouteCollector.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use Closure;

final class RouteCollector
{
    /** @var list<Route> */
    private array $routes = [];

    private string $prefix = '';

    /** @var list<string> */
    private array $groupMiddleware = [];

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function get(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['GET'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function post(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['POST'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function put(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['PUT'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function patch(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['PATCH'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function delete(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['DELETE'], $path, $handler, $name);
    }

    /** @param string|array{0: string, 1: string}|Closure $handler */
    public function any(string $path, string|array|Closure $handler, ?string $name = null): Route
    {
        return $this->add(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, $handler, $name);
    }

    /**
     * @param list<string> $middleware
     * @param callable(self): void $define
     */
    public function group(string $prefix, array $middleware, callable $define): void
    {
        $previous = [$this->prefix, $this->groupMiddleware];
        $this->prefix .= rtrim($prefix, '/');
        $this->groupMiddleware = [...$this->groupMiddleware, ...$middleware];
        try {
            $define($this);
        } finally {
            [$this->prefix, $this->groupMiddleware] = $previous;
        }
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param list<string> $methods
     * @param string|array{0: string, 1: string}|Closure $handler
     */
    private function add(array $methods, string $path, string|array|Closure $handler, ?string $name): Route
    {
        $route = new Route($methods, $this->prefix . $path, $handler, $name);
        if ($this->groupMiddleware !== []) {
            $route->middleware(...$this->groupMiddleware);
        }
        $this->routes[] = $route;

        return $route;
    }
}
```

`src/Kernel/Routing/RouteProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

interface RouteProvider
{
    public function routes(RouteCollector $routes): void;
}
```

`src/Kernel/Routing/RouteMatch.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

final class RouteMatch
{
    /** @param array<string, string> $params */
    public function __construct(public readonly Route $route, public readonly array $params) {}
}
```

`src/Kernel/Routing/Router.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector as FastCollector;
use Nyholm\Psr7\Response;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Exception\NotFound;

use function FastRoute\cachedDispatcher;
use function FastRoute\simpleDispatcher;

final class Router
{
    /** @var list<Route> */
    private array $routes;

    private Dispatcher $dispatcher;

    /** @param list<Route> $routes */
    public function __construct(array $routes, ?string $cacheFile = null)
    {
        $this->routes = $this->withPreflight($routes);
        $define = function (FastCollector $collector): void {
            foreach ($this->routes as $index => $route) {
                $collector->addRoute($route->methods, $route->path, $index);
            }
        };
        if ($cacheFile !== null && !is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0775, true);
        }
        $this->dispatcher = $cacheFile === null
            ? simpleDispatcher($define)
            : cachedDispatcher($define, ['cacheFile' => $cacheFile]);
    }

    public function match(string $method, string $path): RouteMatch
    {
        $result = $this->dispatcher->dispatch(strtoupper($method), $path);

        return match ($result[0]) {
            Dispatcher::FOUND => new RouteMatch($this->routes[$result[1]], $result[2]),
            Dispatcher::METHOD_NOT_ALLOWED => throw new MethodNotAllowed(array_values($result[1])),
            default => throw new NotFound(),
        };
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * @param list<Route> $routes
     * @return list<Route>
     */
    private function withPreflight(array $routes): array
    {
        $seen = [];
        foreach ($routes as $route) {
            if (in_array('OPTIONS', $route->methods, true)) {
                $seen[$route->path] = true;
            }
        }
        $extra = [];
        foreach ($routes as $route) {
            if ($route->hasMiddleware('cors') && !isset($seen[$route->path])) {
                $seen[$route->path] = true;
                $extra[] = (new Route(['OPTIONS'], $route->path, static fn () => new Response(204)))->middleware('cors');
            }
        }

        return [...$routes, ...$extra];
    }
}
```

`src/Kernel/Routing/UrlGenerator.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Routing;

use InvalidArgumentException;
use LogicException;

final class UrlGenerator
{
    /** @var array<string, Route> */
    private array $named = [];

    /** @param list<Route> $routes */
    public function __construct(array $routes, private readonly string $baseUrl)
    {
        foreach ($routes as $route) {
            if ($route->name === null) {
                continue;
            }
            if (isset($this->named[$route->name])) {
                throw new LogicException("Duplicate route name '{$route->name}'");
            }
            $this->named[$route->name] = $route;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /** @param array<string, string|int> $params */
    public function generate(string $name, array $params = [], bool $absolute = false): string
    {
        $route = $this->named[$name] ?? throw new InvalidArgumentException("Unknown route '{$name}'");
        $used = [];
        $path = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)(?::[^{}]*(?:\{[^{}]*\}[^{}]*)*)?\}/',
            function (array $m) use ($params, $name, &$used): string {
                $key = $m[1];
                if (!array_key_exists($key, $params)) {
                    throw new InvalidArgumentException("Route '{$name}' needs parameter '{$key}'");
                }
                $used[$key] = true;

                return rawurlencode((string) $params[$key]);
            },
            $route->path,
        ) ?? $route->path;
        $query = array_diff_key($params, $used);
        $url = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return $absolute ? rtrim($this->baseUrl, '/') . $url : $url;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Routing`
Expected: PASS (11 tests).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(kernel): add FastRoute router, CORS preflight routes, URL generator and HTTP exceptions"
```

---

### Task 11: HTTP pipeline, controllers and error handling

**Files:**
- Create: `src/Kernel/Http/{Pipeline,CallableHandler,MiddlewareRegistry,RouteHandler,Controller,Emitter}.php`, `src/Kernel/Http/Middleware/ErrorHandler.php`
- Test: `tests/Kernel/Http/RouteHandlerTest.php`, `tests/Kernel/Http/ErrorHandlerTest.php`

**Interfaces:**
- Consumes: `Container` (Task 3); `Router`, `RouteMatch`, `RouteCollector` and the HTTP exceptions (Task 10).
- Produces:
  - **`Pipeline`** implements `RequestHandlerInterface`: `__construct(list<MiddlewareInterface> $middleware, RequestHandlerInterface $final)`.
  - **`CallableHandler`** implements `RequestHandlerInterface`: `__construct(\Closure(ServerRequestInterface): ResponseInterface $fn)`.
  - **`MiddlewareRegistry`**
    - `__construct(Container $container)`
    - `register(string $alias, string|\Closure $factory): void`. A class-string is built with `Container::make`. A `Closure(Container, list<string> $args): MiddlewareInterface` receives the args from `alias:a,b`.
    - `resolve(string $spec): MiddlewareInterface`
  - **`RouteHandler`** implements `RequestHandlerInterface`
    - `__construct(Router $router, MiddlewareRegistry $middleware, Container $container)`
    - Sets request attributes for each route param, plus `RouteMatch::class`.
    - Handlers can be `Closure`, `[class, method]`, `"Class::method"` or an invokable class-string, called as `($request, $params)`. A handler may return a `ResponseInterface` or a `string`, which is wrapped as HTML.
  - **`abstract class Controller`**
    - protected helpers: `json(mixed $data, int $status = 200, string $contentType = 'application/json')`, `html(string, int = 200)`, `redirect(string, int = 302)`, `notFound(string = ''): never`
    - public static `jsonResponse(...)` and `htmlResponse(...)`
  - **`Emitter::emit(ResponseInterface $response, bool $withBody = true): void`**.
  - **`ErrorHandler`** implements `MiddlewareInterface`
    - `__construct(LoggerInterface $logger, bool $debug = false, ?\Closure $renderHtml = null)`. `$renderHtml` is `Closure(int $status, string $message, ServerRequestInterface): ?string`; Plan 2 plugs theme error pages in here.
    - The JSON error shape is `{"error": {"status": int, "message": string, "trace"?: string}}`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Http/RouteHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteMatch;
use Xaraya\Kernel\Routing\Router;

final class EchoController extends Controller
{
    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        return $this->json(['id' => $params['id'], 'attr' => $request->getAttribute('id'), 'matched' => $request->getAttribute(RouteMatch::class) instanceof RouteMatch]);
    }

    /** @param array<string, string> $params */
    public function __invoke(ServerRequestInterface $request, array $params): string
    {
        return '<p>invoked</p>';
    }
}

final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $tag = 'x') {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response->withAddedHeader('X-Tags', $this->tag);
    }
}

final class RouteHandlerTest extends TestCase
{
    private function handler(): RouteHandler
    {
        $container = new Container();
        $registry = new MiddlewareRegistry($container);
        $registry->register('tag', fn (Container $c, array $args) => new TagMiddleware($args[0] ?? 'none'));
        $registry->register('plain', TagMiddleware::class);

        $r = new RouteCollector();
        $r->get('/items/{id}', [EchoController::class, 'show']);
        $r->get('/static/{id}', EchoController::class . '::show');
        $r->get('/invoke', EchoController::class);
        $r->get('/closure', fn (ServerRequestInterface $req, array $p) => new Response(201))->middleware('tag:outer', 'tag:inner', 'plain');

        return new RouteHandler(new Router($r->routes()), $registry, $container);
    }

    public function testArrayAndStaticStringHandlers(): void
    {
        foreach (['/items/42', '/static/42'] as $path) {
            $response = $this->handler()->handle(new ServerRequest('GET', $path));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame(['id' => '42', 'attr' => '42', 'matched' => true], json_decode((string) $response->getBody(), true));
        }
    }

    public function testInvokableReturningStringIsHtml(): void
    {
        $response = $this->handler()->handle(new ServerRequest('GET', '/invoke'));
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('<p>invoked</p>', (string) $response->getBody());
    }

    public function testRouteMiddlewareRunsInDeclaredOrderWithArgs(): void
    {
        $response = $this->handler()->handle(new ServerRequest('GET', '/closure'));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['x', 'inner', 'outer'], $response->getHeader('X-Tags'), 'innermost adds its header first');
    }

    public function testUnknownMiddlewareAliasThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new MiddlewareRegistry(new Container()))->resolve('nope');
    }
}
```

`tests/Kernel/Http/ErrorHandlerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\AbstractLogger;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Pipeline;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->lines[] = "{$level}: {$message}";
    }
}

final class ErrorHandlerTest extends TestCase
{
    private function send(ErrorHandler $eh, ServerRequestInterface $request, \Throwable $error): \Psr\Http\Message\ResponseInterface
    {
        return (new Pipeline([$eh], new CallableHandler(fn () => throw $error)))->handle($request);
    }

    public function testPassesThroughSuccess(): void
    {
        $eh = new ErrorHandler(new MemoryLogger());
        $response = (new Pipeline([$eh], new CallableHandler(fn () => new Response(204))))->handle(new ServerRequest('GET', '/'));
        self::assertSame(204, $response->getStatusCode());
    }

    public function testHttpExceptionAsHtml(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger()), new ServerRequest('GET', '/missing'), new NotFound());
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Not Found', (string) $response->getBody());
    }

    public function testJsonForJsonPathsAndAcceptHeader(): void
    {
        $eh = new ErrorHandler(new MemoryLogger());
        $r1 = $this->send($eh, new ServerRequest('GET', '/x/feed.json'), new NotFound());
        self::assertSame(['error' => ['status' => 404, 'message' => 'Not Found']], json_decode((string) $r1->getBody(), true));

        $r2 = $this->send($eh, (new ServerRequest('GET', '/x'))->withHeader('Accept', 'application/json'), new NotFound());
        self::assertStringContainsString('application/json', $r2->getHeaderLine('Content-Type'));
    }

    public function testExceptionHeadersArePreserved(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger()), new ServerRequest('PUT', '/x'), new MethodNotAllowed(['GET', 'POST']));
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testUnexpectedErrorIsLoggedAndHiddenUnlessDebug(): void
    {
        $logger = new MemoryLogger();
        $response = $this->send(new ErrorHandler($logger), new ServerRequest('GET', '/x.json'), new \RuntimeException('db password leaked'));
        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('leaked', (string) $response->getBody());
        self::assertSame(['error: db password leaked'], $logger->lines);

        $debug = $this->send(new ErrorHandler(new MemoryLogger(), debug: true), new ServerRequest('GET', '/x.json'), new \RuntimeException('visible'));
        $body = json_decode((string) $debug->getBody(), true);
        self::assertSame('visible', $body['error']['message']);
        self::assertArrayHasKey('trace', $body['error']);
    }

    public function testCustomHtmlRenderer(): void
    {
        $eh = new ErrorHandler(new MemoryLogger(), false, fn (int $status, string $message) => "<h1>Custom {$status}</h1>");
        $response = $this->send($eh, new ServerRequest('GET', '/x'), new NotFound());
        self::assertSame('<h1>Custom 404</h1>', (string) $response->getBody());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Http`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`src/Kernel/Http/Pipeline.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Pipeline implements RequestHandlerInterface
{
    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(
        private readonly array $middleware,
        private readonly RequestHandlerInterface $final,
        private readonly int $index = 0,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            return $this->final->handle($request);
        }

        return $this->middleware[$this->index]->process($request, new self($this->middleware, $this->final, $this->index + 1));
    }
}
```

`src/Kernel/Http/CallableHandler.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CallableHandler implements RequestHandlerInterface
{
    /** @param Closure(ServerRequestInterface): ResponseInterface $fn */
    public function __construct(private readonly Closure $fn) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->fn)($request);
    }
}
```

`src/Kernel/Http/MiddlewareRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Closure;
use LogicException;
use Psr\Http\Server\MiddlewareInterface;
use Xaraya\Kernel\Container\Container;

final class MiddlewareRegistry
{
    /** @var array<string, string|Closure> */
    private array $aliases = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $alias, string|Closure $factory): void
    {
        $this->aliases[$alias] = $factory;
    }

    public function resolve(string $spec): MiddlewareInterface
    {
        [$alias, $argString] = array_pad(explode(':', $spec, 2), 2, '');
        $factory = $this->aliases[$alias] ?? throw new LogicException("Unknown middleware '{$alias}'");
        $args = $argString === '' ? [] : explode(',', $argString);
        $middleware = $factory instanceof Closure ? $factory($this->container, $args) : $this->container->make($factory);
        if (!$middleware instanceof MiddlewareInterface) {
            throw new LogicException("Middleware '{$alias}' did not produce a MiddlewareInterface");
        }

        return $middleware;
    }
}
```

`src/Kernel/Http/Controller.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\Http\Exception\NotFound;

abstract class Controller
{
    protected function json(mixed $data, int $status = 200, string $contentType = 'application/json'): ResponseInterface
    {
        return self::jsonResponse($data, $status, $contentType);
    }

    protected function html(string $html, int $status = 200): ResponseInterface
    {
        return self::htmlResponse($html, $status);
    }

    protected function redirect(string $url, int $status = 302): ResponseInterface
    {
        return new Response($status, ['Location' => $url]);
    }

    protected function notFound(string $message = ''): never
    {
        throw new NotFound($message);
    }

    public static function jsonResponse(mixed $data, int $status = 200, string $contentType = 'application/json'): ResponseInterface
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new Response($status, ['Content-Type' => $contentType], $body);
    }

    public static function htmlResponse(string $html, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
```

`src/Kernel/Http/RouteHandler.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Closure;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Routing\RouteMatch;
use Xaraya\Kernel\Routing\Router;

final class RouteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Router $router,
        private readonly MiddlewareRegistry $middleware,
        private readonly Container $container,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $match = $this->router->match($request->getMethod(), rawurldecode($request->getUri()->getPath()));
        foreach ($match->params as $key => $value) {
            $request = $request->withAttribute($key, $value);
        }
        $request = $request->withAttribute(RouteMatch::class, $match);
        $stack = array_map($this->middleware->resolve(...), $match->route->getMiddleware());

        return (new Pipeline($stack, new CallableHandler(fn (ServerRequestInterface $r): ResponseInterface => $this->invoke($match, $r))))
            ->handle($request);
    }

    private function invoke(RouteMatch $match, ServerRequestInterface $request): ResponseInterface
    {
        $handler = $match->route->handler;
        if (is_string($handler) && str_contains($handler, '::')) {
            $handler = explode('::', $handler, 2);
        }
        $callable = match (true) {
            $handler instanceof Closure => $handler,
            is_array($handler) => [$this->container->get($handler[0]), $handler[1]],
            default => $this->container->get($handler),
        };
        if (!is_callable($callable)) {
            throw new LogicException('Route handler for ' . $match->route->path . ' is not callable');
        }
        $result = $callable($request, $match->params);
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if (is_string($result)) {
            return Controller::htmlResponse($result);
        }
        throw new LogicException('Controllers must return a ResponseInterface or a string');
    }
}
```

`src/Kernel/Http/Emitter.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ResponseInterface;

final class Emitter
{
    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        if (!headers_sent()) {
            foreach ($response->getHeaders() as $name => $values) {
                foreach ($values as $i => $value) {
                    header("{$name}: {$value}", $i === 0 && strtolower((string) $name) !== 'set-cookie');
                }
            }
            http_response_code($response->getStatusCode());
        }
        if ($withBody) {
            echo $response->getBody();
        }
    }
}
```

`src/Kernel/Http/Middleware/ErrorHandler.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;

final class ErrorHandler implements MiddlewareInterface
{
    /** @param (Closure(int, string, ServerRequestInterface): ?string)|null $renderHtml */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
        private readonly ?Closure $renderHtml = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            return $this->respond($request, $e->status(), $e->getMessage(), $e->headers(), $e);
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage(), ['exception' => $e]);

            return $this->respond($request, 500, $this->debug ? $e->getMessage() : HttpException::reason(500), [], $e);
        }
    }

    /** @param array<string, string> $headers */
    private function respond(ServerRequestInterface $request, int $status, string $message, array $headers, Throwable $e): ResponseInterface
    {
        $trace = $this->debug && $status >= 500
            ? $e::class . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
            : null;
        if ($this->wantsJson($request)) {
            $error = ['status' => $status, 'message' => $message];
            if ($trace !== null) {
                $error['trace'] = $trace;
            }
            $response = Controller::jsonResponse(['error' => $error], $status);
        } else {
            $html = $this->renderHtml !== null ? ($this->renderHtml)($status, $message, $request) : null;
            $response = Controller::htmlResponse($html ?? $this->fallback($status, $message, $trace), $status);
        }
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        if (str_ends_with($request->getUri()->getPath(), '.json')) {
            return true;
        }
        $accept = $request->getHeaderLine('Accept');

        return str_contains($accept, 'json') && !str_contains($accept, 'text/html');
    }

    private function fallback(int $status, string $message, ?string $trace): string
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $status . ' ' . $e(HttpException::reason($status));

        return "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>{$title}</title></head>"
            . "<body><h1>{$title}</h1><p>{$e($message)}</p>"
            . ($trace !== null ? '<pre>' . $e($trace) . '</pre>' : '')
            . "</body></html>\n";
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Http`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add PSR-15 pipeline, route handler, controllers and error handler"
```

---

### Task 12: CORS and conditional GET middleware

**Files:**
- Create: `src/Kernel/Http/Middleware/Cors.php`, `src/Kernel/Http/Middleware/ConditionalGet.php`
- Test: `tests/Kernel/Http/CorsTest.php`, `tests/Kernel/Http/ConditionalGetTest.php`

**Interfaces:**
- Consumes: `Pipeline` and `CallableHandler` (Task 11).
- Produces:
  - **`Cors`** (middleware alias `cors`). It answers `OPTIONS` with 204 plus `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Methods: GET, HEAD, OPTIONS`, the requested headers echoed in `Access-Control-Allow-Headers`, and `Access-Control-Max-Age: 86400`. Every other response gets `Access-Control-Allow-Origin: *`.
  - **`ConditionalGet`** (alias `conditional`). It only acts on GET or HEAD requests with a 200 response:
    - It adds a weak `ETag` of the form `W/"sha1(body)"` unless the response already has one.
    - It returns 304 on a matching `If-None-Match` (weak comparison, `*` supported).
    - When there is no `If-None-Match`, it returns 304 when `If-Modified-Since` is at or after `Last-Modified`.
    - A 304 keeps `ETag`, `Last-Modified`, `Cache-Control`, `Access-Control-Allow-Origin` and `Vary`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Http/CorsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Pipeline;

final class CorsTest extends TestCase
{
    public function testAddsOriginHeaderToResponses(): void
    {
        $response = (new Pipeline([new Cors()], new CallableHandler(fn () => new Response(200))))->handle(new ServerRequest('GET', '/feed.json'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testAnswersPreflightWithoutCallingHandler(): void
    {
        $request = (new ServerRequest('OPTIONS', '/feed.json'))->withHeader('Access-Control-Request-Headers', 'If-None-Match');
        $response = (new Pipeline([new Cors()], new CallableHandler(fn () => throw new \LogicException('not reached'))))->handle($request);
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('GET, HEAD, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertSame('If-None-Match', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }
}
```

`tests/Kernel/Http/ConditionalGetTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Pipeline;

final class ConditionalGetTest extends TestCase
{
    private function send(ServerRequestInterface $request, ?ResponseInterface $response = null): ResponseInterface
    {
        $response ??= new Response(200, ['Last-Modified' => 'Wed, 08 Oct 2026 15:07:16 GMT', 'Cache-Control' => 'max-age=60'], '{"items":[]}');

        return (new Pipeline([new ConditionalGet()], new CallableHandler(fn () => $response)))->handle($request);
    }

    public function testAddsWeakEtag(): void
    {
        $r = $this->send(new ServerRequest('GET', '/f'));
        self::assertSame('W/"' . sha1('{"items":[]}') . '"', $r->getHeaderLine('ETag'));
        self::assertSame('{"items":[]}', (string) $r->getBody());
    }

    public function testIfNoneMatchGives304(): void
    {
        $etag = $this->send(new ServerRequest('GET', '/f'))->getHeaderLine('ETag');
        $r = $this->send((new ServerRequest('GET', '/f'))->withHeader('If-None-Match', '"other", ' . substr($etag, 2)));
        self::assertSame(304, $r->getStatusCode());
        self::assertSame('', (string) $r->getBody());
        self::assertSame($etag, $r->getHeaderLine('ETag'));
        self::assertSame('max-age=60', $r->getHeaderLine('Cache-Control'));
    }

    public function testNonMatchingEtagGives200EvenIfModifiedSinceMatches(): void
    {
        $r = $this->send((new ServerRequest('GET', '/f'))
            ->withHeader('If-None-Match', '"stale"')
            ->withHeader('If-Modified-Since', 'Thu, 09 Oct 2026 00:00:00 GMT'));
        self::assertSame(200, $r->getStatusCode());
    }

    public function testIfModifiedSince(): void
    {
        self::assertSame(304, $this->send((new ServerRequest('GET', '/f'))->withHeader('If-Modified-Since', 'Wed, 08 Oct 2026 15:07:16 GMT'))->getStatusCode());
        self::assertSame(200, $this->send((new ServerRequest('GET', '/f'))->withHeader('If-Modified-Since', 'Tue, 07 Oct 2026 00:00:00 GMT'))->getStatusCode());
    }

    public function testIgnoresNonGetAndNon200(): void
    {
        self::assertFalse($this->send(new ServerRequest('POST', '/f'))->hasHeader('ETag'));
        self::assertFalse($this->send(new ServerRequest('GET', '/f'), new Response(404, [], 'x'))->hasHeader('ETag'));
    }

    public function testKeepsExistingEtag(): void
    {
        $r = $this->send(new ServerRequest('GET', '/f'), new Response(200, ['ETag' => '"v1"'], 'x'));
        self::assertSame('"v1"', $r->getHeaderLine('ETag'));
        self::assertSame(304, $this->send((new ServerRequest('GET', '/f'))->withHeader('If-None-Match', 'W/"v1"'), new Response(200, ['ETag' => '"v1"'], 'x'))->getStatusCode());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Http/CorsTest.php tests/Kernel/Http/ConditionalGetTest.php`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`src/Kernel/Http/Middleware/Cors.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Cors implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            $response = (new Response(204))
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, HEAD, OPTIONS')
                ->withHeader('Access-Control-Max-Age', '86400');
            $requested = $request->getHeaderLine('Access-Control-Request-Headers');

            return $requested === '' ? $response : $response->withHeader('Access-Control-Allow-Headers', $requested);
        }

        return $handler->handle($request)->withHeader('Access-Control-Allow-Origin', '*');
    }
}
```

`src/Kernel/Http/Middleware/ConditionalGet.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ConditionalGet implements MiddlewareInterface
{
    private const KEEP = ['ETag', 'Last-Modified', 'Cache-Control', 'Access-Control-Allow-Origin', 'Vary'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return $response;
        }
        if (!$response->hasHeader('ETag')) {
            $response = $response->withHeader('ETag', 'W/"' . sha1((string) $response->getBody()) . '"');
        }
        if (!$this->notModified($request, $response)) {
            return $response;
        }
        $notModified = new Response(304);
        foreach (self::KEEP as $header) {
            if ($response->hasHeader($header)) {
                $notModified = $notModified->withHeader($header, $response->getHeaderLine($header));
            }
        }

        return $notModified;
    }

    private function notModified(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '') {
            $etag = self::weak($response->getHeaderLine('ETag'));
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || self::weak($candidate) === $etag) {
                    return true;
                }
            }

            return false;
        }
        $since = strtotime($request->getHeaderLine('If-Modified-Since'));
        $modified = strtotime($response->getHeaderLine('Last-Modified'));

        return $since !== false && $modified !== false
            && $request->getHeaderLine('If-Modified-Since') !== '' && $response->getHeaderLine('Last-Modified') !== ''
            && $modified <= $since;
    }

    private static function weak(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Http`
Expected: PASS (18 tests).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat(kernel): add CORS and conditional GET middleware"
```

---

### Task 13: Logger, App kernel and front controller

**Files:**
- Create: `src/Kernel/Log/FileLogger.php`, `src/Kernel/App.php`, `public/index.php`
- Test: `tests/Kernel/Log/FileLoggerTest.php`, `tests/Support/AppTestCase.php`, `tests/Kernel/AppTest.php`

**Interfaces:**
- Consumes: everything from Tasks 2–12.
- Produces:
  - **`FileLogger extends Psr\Log\AbstractLogger`**: `__construct(string $directory, string $minLevel = 'info')`. Writes to `<dir>/xaraya-YYYY-MM-DD.log` and interpolates `{key}` placeholders.
  - **`App`**
    - `static boot(string $root, array<string,mixed> $overrides = []): App`. Overrides are dot keys applied after config load; any override disables the config cache.
    - `container(): Container`, `config(): Config`
    - `root(): string`, `path(string $relative): string`
    - `debug(): bool`
    - `routes(): list<Route>`
    - `pushMiddleware(string $class): void` (global middleware after `ErrorHandler`; used by Plan 3)
    - `handle(ServerRequestInterface): ResponseInterface`
    - `run(): void`
    - `clearCache(): int`
  - **Container bindings** registered by `App`: `App`, `Config`, `Connection`, `LoggerInterface`, `Migrator`, `ModuleRegistry`, `EventDispatcher`, `MiddlewareRegistry` (with `cors` and `conditional`), `Router`, `UrlGenerator`, `ErrorHandler`.
  - **Test support**: `AppTestCase` with `protected string $tmp`, `boot(list<string> $modulePaths = [], array $overrides = []): App` and `static rmdir(string $dir): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Log/FileLoggerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Log;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\Log\FileLogger;

final class FileLoggerTest extends TestCase
{
    public function testWritesInterpolatedLinesAboveMinimumLevel(): void
    {
        $dir = sys_get_temp_dir() . '/xar-log-' . bin2hex(random_bytes(4));
        $logger = new FileLogger($dir, 'info');
        $logger->debug('hidden');
        $logger->info('Imported {count} items from {source}', ['count' => 3, 'source' => 'athena']);
        $logger->error('Failed', ['exception' => new \RuntimeException('boom')]);

        $file = $dir . '/xaraya-' . gmdate('Y-m-d') . '.log';
        $log = (string) file_get_contents($file);
        self::assertStringNotContainsString('hidden', $log);
        self::assertStringContainsString('INFO: Imported 3 items from athena', $log);
        self::assertStringContainsString('ERROR: Failed', $log);
        self::assertStringContainsString('RuntimeException in', $log);
        unlink($file);
        rmdir($dir);
    }

    public function testUnknownLevelThrows(): void
    {
        $this->expectException(\Psr\Log\InvalidArgumentException::class);
        (new FileLogger(sys_get_temp_dir()))->log('loud', 'x');
    }
}
```

`tests/Support/AppTestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\App;

abstract class AppTestCase extends TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/xar-app-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0775, true);
        if (!$this->usesSqlite()) {
            DbTestCase::dropAll(DbTestCase::connect());
        }
    }

    protected function tearDown(): void
    {
        if (!$this->usesSqlite()) {
            DbTestCase::dropAll(DbTestCase::connect());
        }
        self::rmdir($this->tmp);
    }

    /**
     * @param list<string> $modulePaths
     * @param array<string, mixed> $overrides
     */
    protected function boot(array $modulePaths = [], array $overrides = []): App
    {
        $db = DbTestCase::dbConfig();
        if ($this->usesSqlite()) {
            $db['dsn'] = 'sqlite:' . $this->tmp . '/test.sqlite';
        }

        return App::boot(dirname(__DIR__, 2), [
            'app.debug' => true,
            'app.url' => 'http://xar.test',
            'app.cache' => $this->tmp . '/cache',
            'db' => $db,
            'modules.paths' => $modulePaths,
            'log.path' => $this->tmp . '/logs',
            ...$overrides,
        ]);
    }

    private function usesSqlite(): bool
    {
        return str_starts_with(DbTestCase::dbConfig()['dsn'], 'sqlite:');
    }

    public static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
```

`tests/Kernel/AppTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Psr\Log\LoggerInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Tests\Support\AppTestCase;

final class AppTest extends AppTestCase
{
    public function testBootWiresCoreServices(): void
    {
        $app = $this->boot();
        $c = $app->container();
        self::assertSame($app, $c->get(App::class));
        self::assertSame('http://xar.test', $c->get(Config::class)->get('app.url'));
        self::assertInstanceOf(Connection::class, $c->get(Connection::class));
        self::assertInstanceOf(LoggerInterface::class, $c->get(LoggerInterface::class));
        self::assertInstanceOf(EventDispatcher::class, $c->get(EventDispatcher::class));
        self::assertSame([], $c->get(ModuleRegistry::class)->enabled());
        self::assertFalse($c->get(UrlGenerator::class)->has('anything'));
        self::assertTrue($app->debug());
        self::assertSame(dirname(__DIR__, 2) . '/public', $app->path('public'));
        self::assertSame('/abs', $app->path('/abs'));
    }

    public function testUnknownRoutesGiveHtmlOrJson404(): void
    {
        $app = $this->boot();
        $html = $app->handle(new ServerRequest('GET', '/'));
        self::assertSame(404, $html->getStatusCode());
        self::assertStringContainsString('text/html', $html->getHeaderLine('Content-Type'));

        $json = $app->handle(new ServerRequest('GET', '/nope.json'));
        self::assertSame(404, $json->getStatusCode());
        self::assertSame(404, json_decode((string) $json->getBody(), true)['error']['status']);
    }

    public function testClearCacheRemovesPhpFiles(): void
    {
        $app = $this->boot();
        mkdir($this->tmp . '/cache');
        file_put_contents($this->tmp . '/cache/routes.php', '<?php return [];');
        file_put_contents($this->tmp . '/cache/keep.txt', 'x');
        self::assertSame(1, $app->clearCache());
        self::assertFileDoesNotExist($this->tmp . '/cache/routes.php');
        self::assertFileExists($this->tmp . '/cache/keep.txt');
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Log tests/Kernel/AppTest.php`
Expected: errors, classes not found.

- [ ] **Step 3: Implement `src/Kernel/Log/FileLogger.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

final class FileLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public function __construct(private readonly string $directory, private readonly string $minLevel = LogLevel::INFO) {}

    /** @param array<mixed> $context */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? $level : '';
        if (!isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException("Unknown log level '{$level}'");
        }
        if (self::LEVELS[$level] < (self::LEVELS[$this->minLevel] ?? 1)) {
            return;
        }
        $replace = [];
        foreach ($context as $key => $value) {
            if ($key !== 'exception' && (is_scalar($value) || $value instanceof Stringable)) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }
        $line = sprintf('[%s] %s: %s', gmdate('Y-m-d\TH:i:s\Z'), strtoupper($level), strtr((string) $message, $replace));
        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            $line .= "\n" . $exception::class . ' in ' . $exception->getFile() . ':' . $exception->getLine() . "\n" . $exception->getTraceAsString();
        }
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        file_put_contents($this->directory . '/xaraya-' . gmdate('Y-m-d') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
```

- [ ] **Step 4: Implement `src/Kernel/App.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel;

use LogicException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Config\Env;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\ConnectionFactory;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Log\FileLogger;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Kernel\Routing\Router;
use Xaraya\Kernel\Routing\UrlGenerator;

final class App
{
    private readonly Container $container;

    /** @var list<Route>|null */
    private ?array $routes = null;

    /** @var list<string> */
    private array $globalMiddleware = [];

    /** @param array<string, mixed> $overrides */
    public static function boot(string $root, array $overrides = []): self
    {
        return new self(rtrim($root, '/'), $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function __construct(private readonly string $root, array $overrides)
    {
        Env::load($root . '/.env');
        $config = Config::load($root . '/config/app.php', $overrides === [] ? $root . '/var/cache/config.php' : null);
        foreach ($overrides as $key => $value) {
            $config->set($key, $value);
        }
        date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));

        $c = $this->container = new Container();
        $c->instance(self::class, $this);
        $c->instance(Config::class, $config);
        $c->set(Connection::class, fn (): Connection => ConnectionFactory::make((array) $config->get('db', [])));
        $c->set(LoggerInterface::class, fn (): LoggerInterface => new FileLogger(
            (string) $config->get('log.path', $this->path('var/logs')),
            (string) $config->get('log.level', 'info'),
        ));
        $c->set(Migrator::class, fn (Container $c): Migrator => new Migrator($c->get(Connection::class)));
        $c->set(ModuleRegistry::class, fn (Container $c): ModuleRegistry => new ModuleRegistry(
            $c->get(Connection::class),
            $c->get(Migrator::class),
            array_values(array_map(fn (mixed $p): string => $this->path((string) $p), (array) $config->get('modules.paths', ['modules']))),
            __DIR__ . '/migrations',
        ));
        $c->set(EventDispatcher::class, fn (Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
            $registry = new MiddlewareRegistry($c);
            $registry->register('cors', Cors::class);
            $registry->register('conditional', ConditionalGet::class);

            return $registry;
        });
        $c->set(Router::class, fn (): Router => new Router($this->routes(), $this->debug() ? null : $this->cacheDir() . '/routes.php'));
        $c->set(UrlGenerator::class, fn (Container $c): UrlGenerator => new UrlGenerator(
            $c->get(Router::class)->routes(),
            (string) $config->get('app.url', ''),
        ));
        $c->set(ErrorHandler::class, fn (Container $c): ErrorHandler => new ErrorHandler($c->get(LoggerInterface::class), $this->debug()));

        $this->bootModules();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->container->get(Config::class);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        return str_starts_with($relative, '/') ? $relative : $this->root . '/' . $relative;
    }

    public function debug(): bool
    {
        return $this->config()->get('app.debug', false) === true;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }
        $collector = new RouteCollector();
        foreach ($this->container->get(ModuleRegistry::class)->enabled() as $manifest) {
            $class = $manifest->routes();
            if ($class === null) {
                continue;
            }
            $provider = $this->container->make($class);
            if (!$provider instanceof RouteProvider) {
                throw new ModuleException("{$manifest->name}: routes class {$class} must implement RouteProvider");
            }
            $provider->routes($collector);
        }

        return $this->routes = $collector->routes();
    }

    public function pushMiddleware(string $class): void
    {
        $this->globalMiddleware[] = $class;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $stack = [$this->container->get(ErrorHandler::class)];
        foreach ($this->globalMiddleware as $class) {
            $middleware = $this->container->get($class);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new LogicException("{$class} is not a MiddlewareInterface");
            }
            $stack[] = $middleware;
        }
        $final = new RouteHandler(
            $this->container->get(Router::class),
            $this->container->get(MiddlewareRegistry::class),
            $this->container,
        );

        return (new Pipeline($stack, $final))->handle($request);
    }

    public function run(): void
    {
        $factory = new Psr17Factory();
        $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
        (new Emitter())->emit($this->handle($request), $request->getMethod() !== 'HEAD');
    }

    public function clearCache(): int
    {
        $removed = 0;
        foreach ([...(glob($this->cacheDir() . '/*.php') ?: []), ...(glob($this->root . '/var/cache/config.php') ?: [])] as $file) {
            if (is_file($file) && unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function cacheDir(): string
    {
        return (string) $this->config()->get('app.cache', $this->path('var/cache'));
    }

    private function bootModules(): void
    {
        $registry = $this->container->get(ModuleRegistry::class);
        $registry->registerAutoloader();
        $events = $this->container->get(EventDispatcher::class);
        foreach ($registry->enabled() as $manifest) {
            $providerClass = $manifest->provider();
            if ($providerClass !== null) {
                $provider = $this->container->make($providerClass);
                if (!$provider instanceof ServiceProvider) {
                    throw new ModuleException("{$manifest->name}: provider {$providerClass} must implement ServiceProvider");
                }
                $provider->register($this->container);
            }
            foreach ($manifest->subscribers() as $subscriber) {
                $events->listen($subscriber['event'], $subscriber['listener'], $subscriber['priority']);
            }
        }
    }
}
```

Note: `clearCache()` also removes the root config cache, but only when the configured cache dir *is* `var/cache`. In tests, `app.cache` points to a temp dir and `var/cache/config.php` normally doesn't exist. If it does exist in a developer checkout, deleting it is harmless.

- [ ] **Step 5: Write `public/index.php`**

```php
<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

Xaraya\Kernel\App::boot(dirname(__DIR__))->run();
```

- [ ] **Step 6: Run the tests**

Run: `vendor/bin/phpunit tests/Kernel/Log tests/Kernel/AppTest.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(kernel): add App kernel, file logger and front controller"
```

---

### Task 14: CLI

**Files:**
- Create: `src/Kernel/Cli/{Command,Input,Output,Application}.php`, `src/Kernel/Cli/Commands/{MigrateCommand,MigrateRollbackCommand,MigrateStatusCommand,ModuleListCommand,ModuleEnableCommand,ModuleDisableCommand,ServeCommand,CacheClearCommand}.php`, `bin/xar`
- Test: `tests/Kernel/Cli/ApplicationTest.php`

**Interfaces:**
- Consumes: `App` (Task 13), `ModuleRegistry` (Task 9), `Migrator` (Task 7).
- Produces:
  - **`abstract class Command`**: `name(): string`, `description(): string`, `usage(): string` (default `name()`), `run(Input, Output): int`.
  - **`Input`**
    - `__construct(list<string> $tokens)`. Tokens are `--key=value`, `--flag` or positional arguments.
    - `argument(int $i, ?string $default = null): ?string`
    - `option(string $name, ?string $default = null): ?string`
    - `flag(string $name): bool`
  - **`Output`**
    - `__construct($stdout = null, $stderr = null)`, taking resources
    - `line(string $text = '')`, `error(string $text)`
    - `table(list<string> $headers, list<list<string>> $rows)`
  - **`Application`**
    - `__construct(App $app)`. Registers the kernel commands and the commands of enabled modules.
    - `add(string $class): void`
    - `run(list<string> $argv, ?Output $output = null): int`
  - **Commands**: `migrate`, `migrate:rollback [--step=N]`, `migrate:status`, `module:list`, `module:enable <name>`, `module:disable <name>`, `serve [--host=127.0.0.1:8080]`, `cache:clear`.
    - `ServeCommand::commandLine(string $host): string` is public so it can be tested.

- [ ] **Step 1: Write the failing test `tests/Kernel/Cli/ApplicationTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Cli;

use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Commands\ServeCommand;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Tests\Support\AppTestCase;

final class ApplicationTest extends AppTestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/modules';

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /** @param list<string> $args */
    private function xar(array $args): int
    {
        $this->out = fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $this->err = fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        $app = $this->boot([self::FIXTURES]);

        return (new Application($app))->run(['xar', ...$args], new Output($this->out, $this->err));
    }

    private function stdout(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    private function stderr(): string
    {
        rewind($this->err);

        return (string) stream_get_contents($this->err);
    }

    public function testHelpListsCommands(): void
    {
        self::assertSame(0, $this->xar(['help']));
        foreach (['migrate', 'migrate:rollback', 'migrate:status', 'module:list', 'module:enable', 'module:disable', 'serve', 'cache:clear'] as $name) {
            self::assertStringContainsString($name, $this->stdout());
        }
    }

    public function testModuleLifecycle(): void
    {
        self::assertSame(0, $this->xar(['module:list']));
        self::assertMatchesRegularExpression('/alpha\s+1\.2\.0\s+no/', $this->stdout());

        self::assertSame(0, $this->xar(['module:enable', 'alpha']));
        self::assertStringContainsString("Enabled module 'alpha'", $this->stdout());

        self::assertSame(0, $this->xar(['migrate:status']));
        self::assertMatchesRegularExpression('/alpha\s+2026_10_08_000001_create_alpha_items\s+\d+/', $this->stdout());

        self::assertSame(1, $this->xar(['module:enable', 'zeta']));
        self::assertStringContainsString("Unknown module 'zeta'", $this->stderr());

        self::assertSame(0, $this->xar(['module:disable', 'alpha']));
        self::assertSame(0, $this->xar(['module:list']));
        self::assertMatchesRegularExpression('/alpha\s+1\.2\.0\s+no/', $this->stdout());
    }

    public function testMigrateAndRollback(): void
    {
        $this->xar(['module:enable', 'alpha']);
        self::assertSame(0, $this->xar(['migrate']));
        self::assertStringContainsString('Nothing to migrate', $this->stdout());

        self::assertSame(0, $this->xar(['migrate:rollback', '--step=1']));
        self::assertStringContainsString('Rolled back: alpha/2026_10_08_000001_create_alpha_items', $this->stdout());
        self::assertStringNotContainsString('kernel/', $this->stdout(), 'kernel tables survive a module rollback');
        $db = $this->boot([self::FIXTURES])->container()->get(Connection::class);
        self::assertFalse($db->hasTable('alpha_items'));
    }

    public function testErrorsAndCommandHelp(): void
    {
        self::assertSame(1, $this->xar(['nope']));
        self::assertStringContainsString("Unknown command 'nope'", $this->stderr());

        self::assertSame(1, $this->xar(['module:enable']));
        self::assertStringContainsString('Usage: xar module:enable <name>', $this->stderr());

        self::assertSame(0, $this->xar(['cache:clear', '--help']));
        self::assertStringContainsString('Usage: xar cache:clear', $this->stdout());
    }

    public function testInputParsing(): void
    {
        $in = new Input(['blog', '--step=2', '--force', 'extra']);
        self::assertSame('blog', $in->argument(0));
        self::assertSame('extra', $in->argument(1));
        self::assertNull($in->argument(2));
        self::assertSame('2', $in->option('step'));
        self::assertSame('d', $in->option('missing', 'd'));
        self::assertTrue($in->flag('force'));
        self::assertFalse($in->flag('quiet'));
    }

    public function testServeCommandLine(): void
    {
        $app = $this->boot();
        $line = (new ServeCommand($app))->commandLine('127.0.0.1:9000');
        self::assertStringContainsString("-S '127.0.0.1:9000'", $line);
        self::assertStringContainsString("-t '" . $app->path('public') . "'", $line);
        self::assertStringEndsWith("'" . $app->path('public/index.php') . "'", $line);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Cli`
Expected: errors, classes not found.

- [ ] **Step 3: Implement the CLI core**

`src/Kernel/Cli/Command.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

abstract class Command
{
    abstract public function name(): string;

    abstract public function description(): string;

    public function usage(): string
    {
        return $this->name();
    }

    abstract public function run(Input $input, Output $output): int;
}
```

`src/Kernel/Cli/Input.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

final class Input
{
    /** @var list<string> */
    private array $arguments = [];

    /** @var array<string, string|true> */
    private array $options = [];

    /** @param list<string> $tokens */
    public function __construct(array $tokens)
    {
        foreach ($tokens as $token) {
            if (!str_starts_with($token, '--')) {
                $this->arguments[] = $token;
                continue;
            }
            $token = substr($token, 2);
            if (str_contains($token, '=')) {
                [$key, $value] = explode('=', $token, 2);
                $this->options[$key] = $value;
            } else {
                $this->options[$token] = true;
            }
        }
    }

    public function argument(int $index, ?string $default = null): ?string
    {
        return $this->arguments[$index] ?? $default;
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return isset($this->options[$name]);
    }
}
```

`src/Kernel/Cli/Output.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $text . PHP_EOL);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }
        $format = fn (array $cells): string => rtrim(implode('  ', array_map(
            fn (string $cell, int $i): string => str_pad($cell, $widths[$i]),
            $cells,
            array_keys($cells),
        )));
        $this->line($format($headers));
        $this->line(implode('  ', array_map(fn (int $w): string => str_repeat('-', $w), $widths)));
        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }
}
```

`src/Kernel/Cli/Application.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli;

use LogicException;
use Throwable;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Commands\CacheClearCommand;
use Xaraya\Kernel\Cli\Commands\MigrateCommand;
use Xaraya\Kernel\Cli\Commands\MigrateRollbackCommand;
use Xaraya\Kernel\Cli\Commands\MigrateStatusCommand;
use Xaraya\Kernel\Cli\Commands\ModuleDisableCommand;
use Xaraya\Kernel\Cli\Commands\ModuleEnableCommand;
use Xaraya\Kernel\Cli\Commands\ModuleListCommand;
use Xaraya\Kernel\Cli\Commands\ServeCommand;
use Xaraya\Kernel\Module\ModuleRegistry;

final class Application
{
    private const KERNEL_COMMANDS = [
        MigrateCommand::class,
        MigrateRollbackCommand::class,
        MigrateStatusCommand::class,
        ModuleListCommand::class,
        ModuleEnableCommand::class,
        ModuleDisableCommand::class,
        ServeCommand::class,
        CacheClearCommand::class,
    ];

    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(private readonly App $app)
    {
        foreach (self::KERNEL_COMMANDS as $class) {
            $this->add($class);
        }
        foreach ($app->container()->get(ModuleRegistry::class)->enabled() as $manifest) {
            foreach ($manifest->commands() as $class) {
                $this->add($class);
            }
        }
    }

    public function add(string $class): void
    {
        $command = $this->app->container()->make($class);
        if (!$command instanceof Command) {
            throw new LogicException("{$class} is not a CLI Command");
        }
        $this->commands[$command->name()] = $command;
    }

    /** @param list<string> $argv */
    public function run(array $argv, ?Output $output = null): int
    {
        $output ??= new Output();
        $name = $argv[1] ?? 'help';
        if (in_array($name, ['help', 'list', '--help', '-h'], true)) {
            $this->help($output);

            return 0;
        }
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $output->error("Unknown command '{$name}'. Run 'xar help'.");

            return 1;
        }
        $input = new Input(array_slice($argv, 2));
        if ($input->flag('help')) {
            $output->line('Usage: xar ' . $command->usage());
            $output->line($command->description());

            return 0;
        }
        try {
            return $command->run($input, $output);
        } catch (Throwable $e) {
            $output->error($e->getMessage());

            return 1;
        }
    }

    private function help(Output $output): void
    {
        $output->line('Xaraya Phoenix');
        $output->line();
        $output->line('Usage: xar <command> [arguments] [--options]');
        $output->line();
        $commands = $this->commands;
        ksort($commands);
        foreach ($commands as $name => $command) {
            $output->line(sprintf('  %-20s %s', $name, $command->description()));
        }
    }
}
```

- [ ] **Step 4: Implement the commands**

`src/Kernel/Cli/Commands/MigrateCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;

final class MigrateCommand extends Command
{
    public function __construct(private readonly Migrator $migrator, private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Run pending kernel and module migrations';
    }

    public function run(Input $input, Output $output): int
    {
        $ran = $this->migrator->migrate($this->modules->migrationPaths());
        if ($ran === []) {
            $output->line('Nothing to migrate.');
        }
        foreach ($ran as $name) {
            $output->line("Migrated: {$name}");
        }

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/MigrateRollbackCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;

final class MigrateRollbackCommand extends Command
{
    public function __construct(private readonly Migrator $migrator, private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'migrate:rollback';
    }

    public function description(): string
    {
        return 'Roll back the last migration batch (or --step=N batches)';
    }

    public function usage(): string
    {
        return 'migrate:rollback [--step=N]';
    }

    public function run(Input $input, Output $output): int
    {
        $rolled = $this->migrator->rollback($this->modules->migrationPaths(all: true), (int) $input->option('step', '1'));
        if ($rolled === []) {
            $output->line('Nothing to roll back.');
        }
        foreach ($rolled as $name) {
            $output->line("Rolled back: {$name}");
        }

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/MigrateStatusCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;

final class MigrateStatusCommand extends Command
{
    public function __construct(private readonly Migrator $migrator, private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'migrate:status';
    }

    public function description(): string
    {
        return 'Show which migrations have run';
    }

    public function run(Input $input, Output $output): int
    {
        $rows = array_map(
            fn (array $s): array => [$s['module'], $s['name'], $s['batch'] === null ? 'pending' : (string) $s['batch']],
            $this->migrator->status($this->modules->migrationPaths(all: true)),
        );
        $output->table(['Module', 'Migration', 'Batch'], $rows);

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/ModuleListCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Module\ModuleRegistry;

final class ModuleListCommand extends Command
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function name(): string
    {
        return 'module:list';
    }

    public function description(): string
    {
        return 'List discovered modules and whether they are enabled';
    }

    public function run(Input $input, Output $output): int
    {
        $enabled = $this->modules->enabled();
        $rows = [];
        foreach ($this->modules->discover() as $name => $manifest) {
            $rows[] = [$name, $manifest->version, isset($enabled[$name]) ? 'yes' : 'no', $manifest->path];
        }
        $output->table(['Module', 'Version', 'Enabled', 'Path'], $rows);

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/ModuleEnableCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Module\ModuleRegistry;

final class ModuleEnableCommand extends Command
{
    public function __construct(private readonly ModuleRegistry $modules, private readonly App $app) {}

    public function name(): string
    {
        return 'module:enable';
    }

    public function description(): string
    {
        return 'Enable a module and run its migrations';
    }

    public function usage(): string
    {
        return 'module:enable <name>';
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument(0);
        if ($name === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $this->modules->enable($name);
        $this->app->clearCache();
        $output->line("Enabled module '{$name}'.");

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/ModuleDisableCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Module\ModuleRegistry;

final class ModuleDisableCommand extends Command
{
    public function __construct(private readonly ModuleRegistry $modules, private readonly App $app) {}

    public function name(): string
    {
        return 'module:disable';
    }

    public function description(): string
    {
        return 'Disable a module (its tables are kept)';
    }

    public function usage(): string
    {
        return 'module:disable <name>';
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->argument(0);
        if ($name === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $this->modules->disable($name);
        $this->app->clearCache();
        $output->line("Disabled module '{$name}'.");

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/ServeCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class ServeCommand extends Command
{
    public function __construct(private readonly App $app) {}

    public function name(): string
    {
        return 'serve';
    }

    public function description(): string
    {
        return 'Run the PHP development server';
    }

    public function usage(): string
    {
        return 'serve [--host=127.0.0.1:8080]';
    }

    public function commandLine(string $host): string
    {
        return sprintf(
            '%s -S %s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            escapeshellarg($this->app->path('public')),
            escapeshellarg($this->app->path('public/index.php')),
        );
    }

    public function run(Input $input, Output $output): int
    {
        $host = $input->option('host', '127.0.0.1:8080') ?? '127.0.0.1:8080';
        $output->line("Xaraya Phoenix running at http://{$host} (Ctrl+C to stop)");
        passthru($this->commandLine($host), $code);

        return $code;
    }
}
```

`src/Kernel/Cli/Commands/CacheClearCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class CacheClearCommand extends Command
{
    public function __construct(private readonly App $app) {}

    public function name(): string
    {
        return 'cache:clear';
    }

    public function description(): string
    {
        return 'Delete cached config and routes';
    }

    public function run(Input $input, Output $output): int
    {
        $output->line(sprintf('Cleared %d cache file(s).', $this->app->clearCache()));

        return 0;
    }
}
```

`bin/xar`:
```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = Xaraya\Kernel\App::boot(dirname(__DIR__));
exit((new Xaraya\Kernel\Cli\Application($app))->run(array_values($argv)));
```

Then run `chmod +x bin/xar`.

- [ ] **Step 5: Run the tests and try the binary**

Run: `vendor/bin/phpunit tests/Kernel/Cli && bin/xar help && bin/xar migrate`
Expected: PASS (6 tests). Then the help listing prints, and `migrate` prints `Migrated: kernel/2026_10_08_000001_create_modules` (it creates `var/database.sqlite`).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(kernel): add xar CLI with migrate, module and serve commands"
```

---

### Task 15: `examples/hello` end to end, plus the final verification

**Files:**
- Create: `examples/hello/module.json`, `examples/hello/migrations/2026_10_08_000001_create_greetings.php`, `examples/hello/src/{Routes,HelloController,CountGreetings}.php`
- Modify: `phpstan.neon.dist` (add `examples` to `paths`)
- Test: `tests/Kernel/HelloModuleTest.php`

**Interfaces:**
- Consumes: the whole kernel. In particular `App::boot`/`handle` (Task 13), the CLI `Application` (Task 14), `RouteProvider` (Task 10), `Controller` (Task 11), `EventDispatcher`/`ItemCreated` (Task 8), `Ulid` (Task 4) and `Connection::select` (Task 6).
- Produces the routes `hello.index` (`GET /hello`), `hello.feed` (`GET /hello.json`, with the `cors` and `conditional` middleware) and `hello.create` (`POST /hello/{name:[a-z]+}`, which returns 201 with `{"id": ulid}`). It also produces the shared service `Xaraya\Module\Hello\CountGreetings`, whose `int $count` goes up by one for each `ItemCreated` event from module `hello`.

- [ ] **Step 1: Write the failing end-to-end test `tests/Kernel/HelloModuleTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Hello\CountGreetings;
use Xaraya\Tests\Support\AppTestCase;

final class HelloModuleTest extends AppTestCase
{
    private function app(): App
    {
        return $this->boot([dirname(__DIR__, 2) . '/examples']);
    }

    private function enableHello(): void
    {
        $out = fopen('php://memory', 'w+') ?: throw new \RuntimeException();
        self::assertSame(0, (new Application($this->app()))->run(['xar', 'module:enable', 'hello'], new Output($out, $out)));
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
        $created = $app->handle(new ServerRequest('POST', '/hello/ada'));
        self::assertSame(201, $created->getStatusCode());
        $id = json_decode((string) $created->getBody(), true)['id'];
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
        $app->handle(new ServerRequest('POST', '/hello/grace'));

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
        $urls = $this->app()->container()->get(\Xaraya\Kernel\Routing\UrlGenerator::class);
        self::assertSame('http://xar.test/hello/ada', $urls->generate('hello.create', ['name' => 'ada'], true));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/HelloModuleTest.php`
Expected: the first test passes (a 404 is the correct result before the module exists). The others fail because `module:enable hello` returns 1 (`Unknown module 'hello'`).

- [ ] **Step 3: Create the module**

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
  ]
}
```

`examples/hello/migrations/2026_10_08_000001_create_greetings.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('greetings', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->datetime('created');
            $t->index('created');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('greetings');
    }
};
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
        $routes->get('/hello', [HelloController::class, 'index'], 'hello.index');
        $routes->get('/hello.json', [HelloController::class, 'feed'], 'hello.feed')->middleware('cors', 'conditional');
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
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Support\Ulid;

final class HelloController extends Controller
{
    public function __construct(private readonly Connection $db, private readonly EventDispatcher $events) {}

    /** @param array<string, string> $params */
    public function index(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $items = '';
        foreach ($this->names() as $name) {
            $items .= '<li>Hello, ' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '!</li>';
        }

        return $this->html("<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>Hello</title></head><body><ul>{$items}</ul></body></html>\n");
    }

    /** @param array<string, string> $params */
    public function feed(ServerRequestInterface $request, array $params): ResponseInterface
    {
        return $this->json(['greetings' => $this->names()]);
    }

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $id = Ulid::generate();
        $this->db->insert('greetings', ['id' => $id, 'name' => $params['name'], 'created' => new DateTimeImmutable()]);
        $this->events->dispatch(new ItemCreated('hello', 'greeting', $id, ['name' => $params['name']]));

        return $this->json(['id' => $id], 201);
    }

    /** @return list<string> */
    private function names(): array
    {
        $rows = $this->db->select('greetings')->columns('name')->orderBy('created')->orderBy('id')->all();

        return array_map(fn (array $row): string => (string) $row['name'], $rows);
    }
}
```

`examples/hello/src/CountGreetings.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Events\ItemCreated;

final class CountGreetings
{
    public int $count = 0;

    public function __invoke(ItemCreated $event): void
    {
        if ($event->module === 'hello') {
            $this->count++;
        }
    }
}
```

Modify `phpstan.neon.dist`:
```neon
parameters:
    level: 8
    paths:
        - src
        - examples
```

- [ ] **Step 4: Run the end-to-end test**

Run: `vendor/bin/phpunit tests/Kernel/HelloModuleTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Smoke-test outside PHPUnit**

```bash
php -r '
require "vendor/autoload.php";
$o = ["app.debug" => true, "app.cache" => getcwd() . "/var/smoke-cache", "modules.paths" => ["examples"], "db.dsn" => "sqlite:" . getcwd() . "/var/smoke.sqlite"];
(new Xaraya\Kernel\Cli\Application(Xaraya\Kernel\App::boot(getcwd(), $o)))->run(["xar", "module:enable", "hello"]);
$app = Xaraya\Kernel\App::boot(getcwd(), $o);
$app->handle(new Nyholm\Psr7\ServerRequest("POST", "/hello/smoke"));
$r = $app->handle(new Nyholm\Psr7\ServerRequest("GET", "/hello.json"));
echo $r->getStatusCode(), " ", $r->getBody(), PHP_EOL;
'
rm -rf var/smoke.sqlite var/smoke-cache
```

Expected output:
```
Enabled module 'hello'.
200 {"greetings":["smoke"]}
```

- [ ] **Step 6: Run the full verification**

Run each of these and confirm the expected result:
- `composer test`: PASS for every test. Expect roughly 130 tests; the exact count doesn't matter, but there must be 0 failures and 0 errors.
- `composer stan`: `[OK] No errors`.
- `composer cs`: exit code 0. If it reports style issues, run `composer cs:fix`, re-run the tests and commit the fixes.
- MySQL, if available locally: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root composer test` passes.
- `wc -l $(git ls-files 'src/Kernel/*.php')`: the total is under 6,000.
- `composer show --self --format=json | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo count(array_filter(array_keys($j["requires"]), fn($k)=>!str_starts_with($k,"php") && !str_starts_with($k,"ext-"))), PHP_EOL;'`: prints `5`.

- [ ] **Step 7: Commit and push**

```bash
git add -A
git commit -m "feat(examples): add hello module exercising routes, migrations, events, CORS and ETags"
git push origin next
```

Then check that the CI workflow on `next` is green across all 6 matrix cells plus the static job: `gh run list --branch next --limit 1`.

---

## Kernel definition-of-done coverage (spec §7) after Plan 1

| Spec item | Status after Plan 1 |
|---|---|
| 1. Fresh clone → install → serve | `migrate` and `serve` work. `xar install` and the phoenix home page come in Plans 2 and 3. |
| 2. Login, throttle, CSRF, reset | Plan 3 |
| 3. hello / hello-hooks | Migrations on enable and routes are done. Twig and PHP parity, display hooks and blocks come in Plan 2; grants and tokens come in Plan 3. |
| 4. CI matrix green, PHPStan 8 | Done (Task 15, Step 7) |
| 5. Size budget | Checked in Task 15, Step 6 |
