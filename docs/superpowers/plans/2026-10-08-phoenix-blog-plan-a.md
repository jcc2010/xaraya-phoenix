# Phoenix Blog — Plan A: Mirror, Sync and Serve

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `blog` module that mirrors any JSON Feed source, Athena included, into Phoenix and serves Athena-compatible feeds from it. When it's done, `xar blog:create wyome … && xar blog:sync wyome --full` lets wyome.com point at Phoenix by changing only the hostname.

**Architecture:**

- **Module:** `modules/blog`, built on the kernel.
- **Data:**
  - Blogs and posts sit in tables with real columns for queried fields.
  - Each post also keeps a lossless JSON `doc` for every other source field.
- **Sync:**
  - Source *adapters* turn feed items into `PostRecord`s, and `PostRepository::save()` upserts them, keyed on `(blog_id, item_id)`.
  - `Syncer` walks `next_url` pages, does quick or full syncs and tombstones deleted items, with a 50% safety valve.
- **Serving:**
  - `FeedController` and `PostController` serve keyset-paged JSON Feed and RSS with Athena's exact cursor format, through the kernel's `cors` and `conditional` middleware.
  - An optional `media=local` mode downloads images and serves them from `/media/{id}.{ext}`.

**Tech stack:** PHP ≥ 8.3, Phoenix kernel (Plan 1), PHPUnit 11, PHPStan level 8. No new Composer runtime dependency.

**Spec:** `docs/superpowers/specs/2026-10-08-phoenix-blog-design.md`, §0–§4, §6–§8 (Plan A parts). §5 is Plan B.

**Deviations from the spec, decided while planning:**

1. Tests live in `tests/Module/Blog/` with fixtures in `tests/fixtures/athena/`, not `modules/blog/tests/`. PHPUnit only scans `tests/`.
2. `xar_post_tags` gets a `position` int column so tag order survives the round trip. Athena's tag order matters for parity.
3. `xar_media` indexes `source_url_hash` (sha1 of the URL), with unique `(blog_id, source_url_hash)` and index `(blog_id, sha256)`. MySQL can't index a `VARCHAR(2048)` utf8mb4 column. The file is still deduplicated by sha256.
4. Phoenix's served items always carry `_athenana.kind` and `_athenana.tags`, which is Phoenix's native format. Exact round-trip is therefore guaranteed for `athena` sources. For generic `jsonfeed` sources the source fields are preserved and those two keys are added.

## Global Constraints

- Every PHP file starts with `<?php`, a blank line, then `declare(strict_types=1);`.
- **Namespaces:** module classes are `Xaraya\Module\Blog\…` under `modules/blog/src/`. Tests are `Xaraya\Tests\Module\Blog\…` under `tests/Module/Blog/`.
- **Database:**
  - Tables use the kernel prefix through `Connection`/`Schema`/`Select`. No raw table names; use `{name}` in raw SQL.
  - Identifiers match `/^[A-Za-z_][A-Za-z0-9_]*$/D`.
  - Datetimes are stored in UTC as `Y-m-d H:i:s`, with no fractional seconds.
- **Ids:** lower-case ULIDs, 26 characters, validated with `Xaraya\Kernel\Support\Ulid::isValid()`.
- **Feed constants:**
  - JSON Feed version string: `https://jsonfeed.org/version/1.1`.
  - Page size 50, newest first by `date_published` then `id`.
  - Cursor `before = base64url-unpadded("Y-m-d H:i:s|<id>")` in UTC.
- **Response headers:**
  - `feed.json` is sent as `application/feed+json`; `/s/{id}.json` as `application/json`; RSS as `application/rss+xml; charset=utf-8`.
  - Feeds and items carry `Cache-Control: public, max-age=60`.
  - Media carries `Cache-Control: public, max-age=31536000, immutable`.
- **Cleaning:** null, `""` and `[]` are left out of served items and envelopes (Athena's `clean()` rule). `false` is kept.
- **Commits:** every commit message ends with a blank line, then `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, exactly.
- **Checks:** `composer test`, `composer stan` and `composer cs` must pass before each commit.
  - Database tests run on SQLite by default.
  - Also run them on MySQL: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root`, with local root and no password.
  - Postgres runs in CI.
- **Kernel APIs this plan relies on, all existing:**
  - `App::boot(string $root, array $overrides = [], bool $safe = false, bool $useConfigCache = true)`, `->container()`, `->config()`, `->path()`, `->handle()`.
  - `Container::get/set/instance/make`.
  - `Connection`: `insert`, `upsert`, `update`, `delete`, `transaction` (nestable), `select`, `fetchValue`, `query`, `hasTable`, `normalize`.
  - `Select`: `columns`, `where`, `whereIn`, `whereNull`, `cursor`, `orderBy`, `limit`, `all`, `first`, `count`.
  - `Schema::create/table` with `Blueprint` methods `increments`, `ulid`, `string`, `text`, `int`, `bigint`, `bool`, `datetime`, `json`, `index`, `unique`, `primary`, `foreign`, and `Column::nullable/default/unique/primary`.
  - `Migration` (anonymous class returned from the file).
  - `RouteProvider` and `RouteCollector` (`get(...)->middleware('cors', 'conditional')`).
  - `Controller` (`json`, `notFound`, static `jsonResponse`).
  - `HttpException(int $status, string $message = '', array $headers = [], ?Throwable $previous = null)` and `NotFound`.
  - `UrlGenerator::generate(name, params, absolute)`. Extra params become a query string.
  - `EventDispatcher::dispatch`, plus `ItemCreated`/`ItemUpdated`/`ItemDeleted(module, itemtype, id, item)`.
  - `ServiceProvider::register(Container)`.
  - CLI: `Command` (`name`, `description`, `usage`, `run(Input, Output): int`), `Input` (`argument(i)`, `option(name)`, `flag(name)`), `Output` (`line`, `error`, `table`).
  - Test support: `Cli\Application`, `AppTestCase` (`boot(modulePaths, overrides)`, `$this->tmp`; `app.url` is `http://xar.test`).

## File Map

```
modules/blog/module.json
modules/blog/migrations/2026_10_08_100001_create_blogs.php
modules/blog/migrations/2026_10_08_100002_create_posts.php        posts, post_tags, media
modules/blog/src/Blog.php                     blog value object
modules/blog/src/BlogRepository.php           blog CRUD + validation
modules/blog/src/BlogServiceProvider.php      HttpFetcher, SyncLock, MediaStore bindings
modules/blog/src/Routes.php
modules/blog/src/Support/Text.php             plain text, title derivation, slugs, canonical JSON
modules/blog/src/Post/PostRecord.php          adapter output
modules/blog/src/Post/Post.php                stored post value object
modules/blog/src/Post/SaveResult.php
modules/blog/src/Post/PostRepository.php      save/find/page/tombstone
modules/blog/src/Source/SourceAdapter.php
modules/blog/src/Source/JsonFeedAdapter.php
modules/blog/src/Source/AthenaAdapter.php
modules/blog/src/Source/AdapterRegistry.php
modules/blog/src/Source/HttpFetcher.php
modules/blog/src/Source/FetchResult.php
modules/blog/src/Source/StreamFetcher.php
modules/blog/src/Sync/Syncer.php
modules/blog/src/Sync/SyncReport.php
modules/blog/src/Sync/SyncLock.php
modules/blog/src/Feed/Cursor.php
modules/blog/src/Feed/ItemSerializer.php
modules/blog/src/Feed/FeedBuilder.php
modules/blog/src/Feed/RssRenderer.php
modules/blog/src/Media/MediaStore.php
modules/blog/src/Media/MediaLocalizer.php
modules/blog/src/Http/CacheHeaders.php
modules/blog/src/Http/MediaUrls.php
modules/blog/src/Http/FeedController.php
modules/blog/src/Http/PostController.php
modules/blog/src/Http/MediaController.php
modules/blog/src/Cli/{BlogCreate,BlogList,BlogSync,BlogMode}Command.php
tests/Module/Blog/BlogTestCase.php
tests/Module/Blog/Support/{FixtureFetcher,FeedFactory}.php
tests/Module/Blog/...Test.php                 one per unit
tests/fixtures/athena/{page-1,page-2,page-3,item}.json + README.md
```

---

### Task 1: Module skeleton, migrations, blogs and the `blog:create`/`blog:list` commands

**Files:**
- Create:
  - `modules/blog/module.json`
  - `modules/blog/migrations/2026_10_08_100001_create_blogs.php`
  - `modules/blog/migrations/2026_10_08_100002_create_posts.php`
  - `modules/blog/src/Blog.php`
  - `modules/blog/src/BlogRepository.php`
  - `modules/blog/src/Cli/BlogCreateCommand.php`
  - `modules/blog/src/Cli/BlogListCommand.php`
  - `tests/Module/Blog/BlogTestCase.php`
- Modify: `composer.json` (autoload-dev), `phpstan.neon.dist` (add `modules`), `modules/.gitkeep` (delete it)
- Test: `tests/Module/Blog/BlogRepositoryTest.php`, `tests/Module/Blog/Cli/BlogCommandsTest.php`

**Interfaces:**
- **Produces `Blog`:**
  - The constants:
    - `MODES = ['mirror', 'native']`
    - `FORMATS = ['jsonfeed', 'athena']`
    - `MEDIA = ['remote', 'local']`
  - These readonly properties:
    - `int $id`, `string $handle`, `string $title`, `?string $descriptionHtml`
    - `string $authorName`, `?string $authorUrl`, `?string $homePageUrl`, `?string $postUrlPattern`
    - `string $language`, `?string $icon`, `?string $favicon`
    - `string $mode`, `?string $sourceUrl`, `?string $sourceFormat`, `string $media`
    - `?string $pinnedItemId`, `?string $sourceEtag`, `?string $lastSyncedAt`, `array $extra`
  - `static fromRow(array): Blog` and `isMirror(): bool`.
- **Produces `BlogRepository(Connection)`:**
  - `find(string $handle): ?Blog`
  - `findById(int): ?Blog`
  - `all(): list<Blog>`, ordered by handle
  - `create(array<string,mixed> $fields, DateTimeImmutable $now): Blog`
  - `update(Blog, array<string,mixed> $fields, DateTimeImmutable $now): Blog`
  - Fields are column names. Validation failures throw `InvalidArgumentException`.
- **Produces these tables:**
  - `blogs`, `posts`, `post_tags` and `media`, exactly as in Step 3.
  - Later tasks never change the schema.
- **Produces the test base `BlogTestCase extends AppTestCase`:**
  - `app(array $overrides = []): App`
  - `enableBlog(): App`
  - `xar(list<string> $args, ?App $app = null): array{0: int, 1: string, 2: string}`, returning the exit code, stdout and stderr
  - `db(App): Connection`
  - `blogs(App): BlogRepository`
  - `now(): DateTimeImmutable`, in UTC
  - The default overrides are `blog.locks` = `$tmp/locks` and `blog.uploads` = `$tmp/uploads`.

- [ ] **Step 1: Wire autoloading and static analysis**

In `composer.json`, change `autoload-dev` to:
```json
"autoload-dev": {
    "psr-4": {
        "Xaraya\\Tests\\": "tests/",
        "Xaraya\\Module\\Blog\\": "modules/blog/src/"
    }
},
```
In production the kernel's module autoloader loads module classes. This entry lets unit tests and PHPStan see them without booting an App.

In `phpstan.neon.dist`, set `paths` to `src`, `examples` and `modules`.

Then run `git rm modules/.gitkeep` and `composer dump-autoload`.

- [ ] **Step 2: Write `modules/blog/module.json`**

```json
{
  "name": "blog",
  "version": "0.1.0",
  "content": true,
  "requires": { "kernel": "^0.1", "modules": {} },
  "migrations": "migrations",
  "commands": [
    "Xaraya\\Module\\Blog\\Cli\\BlogCreateCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogListCommand"
  ]
}
```

- [ ] **Step 3: Write the migrations**

`modules/blog/migrations/2026_10_08_100001_create_blogs.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('blogs', function (Blueprint $t): void {
            $t->increments();
            $t->string('handle', 32)->unique();
            $t->string('title');
            $t->text('description_html')->nullable();
            $t->string('author_name');
            $t->string('author_url', 1024)->nullable();
            $t->string('home_page_url', 1024)->nullable();
            $t->string('post_url_pattern', 1024)->nullable();
            $t->string('language', 16)->default('en');
            $t->string('icon', 1024)->nullable();
            $t->string('favicon', 1024)->nullable();
            $t->string('mode', 16);
            $t->string('source_url', 1024)->nullable();
            $t->string('source_format', 32)->nullable();
            $t->string('media', 16)->default('remote');
            $t->string('pinned_item_id', 512)->nullable();
            $t->string('source_etag')->nullable();
            $t->datetime('last_synced_at')->nullable();
            $t->datetime('last_full_sync_at')->nullable();
            $t->json('extra')->nullable();
            $t->datetime('created_at');
            $t->datetime('updated_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('blogs');
    }
};
```

`modules/blog/migrations/2026_10_08_100002_create_posts.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->int('blog_id');
            $t->string('item_id', 512);
            $t->string('kind', 16);
            $t->text('title');
            $t->string('url', 1024)->nullable();
            $t->string('external_url', 2048)->nullable();
            $t->text('content_html')->nullable();
            $t->string('image', 2048)->nullable();
            $t->datetime('date_published');
            $t->datetime('date_modified')->nullable();
            $t->string('status', 16);
            $t->string('source_hash', 40)->nullable();
            $t->json('doc')->nullable();
            $t->datetime('created_at');
            $t->datetime('updated_at');
            $t->foreign('blog_id', 'blogs');
            $t->unique('blog_id', 'item_id');
            $t->index('blog_id', 'status', 'date_published', 'id');
        });
        $schema->create('post_tags', function (Blueprint $t): void {
            $t->ulid('post_id');
            $t->int('blog_id');
            $t->int('position');
            $t->string('name', 128);
            $t->string('slug', 128);
            $t->primary('post_id', 'slug');
            $t->foreign('post_id', 'posts');
            $t->index('blog_id', 'slug');
        });
        $schema->create('media', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->int('blog_id');
            $t->string('source_url', 2048)->nullable();
            $t->string('source_url_hash', 40)->nullable();
            $t->string('path', 512);
            $t->string('mime', 64);
            $t->bigint('bytes');
            $t->string('sha256', 64);
            $t->int('width')->nullable();
            $t->int('height')->nullable();
            $t->datetime('created_at');
            $t->foreign('blog_id', 'blogs');
            $t->unique('blog_id', 'source_url_hash');
            $t->index('blog_id', 'sha256');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('media');
        $schema->drop('post_tags');
        $schema->drop('posts');
    }
};
```

- [ ] **Step 4: Write the test base and the failing tests**

`tests/Module/Blog/BlogTestCase.php`:
```php
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
```

`tests/Module/Blog/BlogRepositoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class BlogRepositoryTest extends BlogTestCase
{
    public function testMigrationsCreateTables(): void
    {
        $db = $this->db($this->enableBlog());
        foreach (['blogs', 'posts', 'post_tags', 'media'] as $table) {
            self::assertTrue($db->hasTable($table), $table);
        }
    }

    public function testCreateMirrorBlog(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $blog = $repo->create([
            'handle' => 'wyome',
            'mode' => 'mirror',
            'source_url' => 'https://athenana.com/blog/wyome/feed.json',
            'source_format' => 'athena',
            'post_url_pattern' => 'https://www.wyome.com/blog/post.html?id={id}',
        ], $this->now());

        self::assertGreaterThan(0, $blog->id);
        self::assertSame('wyome', $blog->handle);
        self::assertSame('wyome', $blog->title, 'title defaults to the handle');
        self::assertSame('wyome', $blog->authorName);
        self::assertSame('en', $blog->language);
        self::assertSame('remote', $blog->media);
        self::assertTrue($blog->isMirror());
        self::assertSame([], $blog->extra);
        self::assertSame($blog->id, $repo->find('wyome')?->id);
        self::assertSame('wyome', $repo->findById($blog->id)?->handle);
        self::assertNull($repo->find('nope'));
    }

    public function testCreateNativeBlogAndList(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $repo->create(['handle' => 'zed', 'mode' => 'native', 'title' => 'Zed'], $this->now());
        $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
        self::assertSame(['amy', 'zed'], array_map(fn ($b) => $b->handle, $repo->all()));
        self::assertFalse($repo->find('zed')?->isMirror());
    }

    public function testUpdateMergesAndValidates(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $blog = $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
        $updated = $repo->update($blog, ['title' => 'Amy', 'extra' => ['_athenana' => ['subscribe_url' => 'https://x.test/s']]], $this->now());
        self::assertSame('Amy', $updated->title);
        self::assertSame(['_athenana' => ['subscribe_url' => 'https://x.test/s']], $updated->extra);

        $this->expectException(InvalidArgumentException::class);
        $repo->update($updated, ['mode' => 'mirror'], $this->now());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidBlogs(): array
    {
        return [
            'bad handle' => [['handle' => 'No Caps', 'mode' => 'native'], 'Handle'],
            'bad mode' => [['handle' => 'ok', 'mode' => 'remote'], 'Mode'],
            'mirror without source' => [['handle' => 'ok', 'mode' => 'mirror', 'source_format' => 'athena'], 'source URL'],
            'mirror with file url' => [['handle' => 'ok', 'mode' => 'mirror', 'source_url' => 'file:///etc/passwd', 'source_format' => 'athena'], 'source URL'],
            'mirror bad format' => [['handle' => 'ok', 'mode' => 'mirror', 'source_url' => 'https://x.test/f.json', 'source_format' => 'rss'], 'format'],
            'pattern without id' => [['handle' => 'ok', 'mode' => 'native', 'post_url_pattern' => 'https://x.test/p'], 'pattern'],
            'pattern with two ids' => [['handle' => 'ok', 'mode' => 'native', 'post_url_pattern' => 'https://x.test/{id}/{id}'], 'pattern'],
            'bad media' => [['handle' => 'ok', 'mode' => 'native', 'media' => 'cdn'], 'Media'],
            'unknown field' => [['handle' => 'ok', 'mode' => 'native', 'colour' => 'red'], 'Unknown'],
        ];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('invalidBlogs')]
    public function testRejectsInvalidBlogs(array $fields, string $message): void
    {
        $repo = $this->blogs($this->enableBlog());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $repo->create($fields, $this->now());
    }

    public function testRejectsDuplicateHandle(): void
    {
        $repo = $this->blogs($this->enableBlog());
        $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $repo->create(['handle' => 'amy', 'mode' => 'native'], $this->now());
    }
}
```

`tests/Module/Blog/Cli/BlogCommandsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Xaraya\Tests\Module\Blog\BlogTestCase;

final class BlogCommandsTest extends BlogTestCase
{
    public function testCreateAndList(): void
    {
        $app = $this->enableBlog();
        [$code, $out] = $this->xar([
            'blog:create', 'wyome', '--mode=mirror', '--format=athena',
            '--source=https://athenana.com/blog/wyome/feed.json',
            '--post-url=https://www.wyome.com/blog/post.html?id={id}',
        ], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString("Created mirror blog 'wyome'.", $out);

        [$code, $out] = $this->xar(['blog:create', 'notes', '--mode=native', '--title=My Notes'], $app);
        self::assertSame(0, $code);

        [$code, $out] = $this->xar(['blog:list'], $app);
        self::assertSame(0, $code);
        self::assertMatchesRegularExpression('/notes\s+native\s+-\s+0\s+never/', $out);
        self::assertMatchesRegularExpression('#wyome\s+mirror\s+https://athenana\.com/blog/wyome/feed\.json\s+0\s+never#', $out);
    }

    public function testErrors(): void
    {
        $app = $this->enableBlog();
        [$code, , $err] = $this->xar(['blog:create'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar blog:create <handle>', $err);

        [$code, , $err] = $this->xar(['blog:create', 'x!', '--mode=native'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Handle must match', $err);
    }
}
```

- [ ] **Step 5: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog`
Expected: errors, either `Class "Xaraya\Module\Blog\BlogRepository" not found` or a failure to enable the module.

- [ ] **Step 6: Implement `Blog`, `BlogRepository` and the two commands**

`modules/blog/src/Blog.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

final class Blog
{
    public const MODES = ['mirror', 'native'];
    public const FORMATS = ['jsonfeed', 'athena'];
    public const MEDIA = ['remote', 'local'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public readonly int $id,
        public readonly string $handle,
        public readonly string $title,
        public readonly ?string $descriptionHtml,
        public readonly string $authorName,
        public readonly ?string $authorUrl,
        public readonly ?string $homePageUrl,
        public readonly ?string $postUrlPattern,
        public readonly string $language,
        public readonly ?string $icon,
        public readonly ?string $favicon,
        public readonly string $mode,
        public readonly ?string $sourceUrl,
        public readonly ?string $sourceFormat,
        public readonly string $media,
        public readonly ?string $pinnedItemId,
        public readonly ?string $sourceEtag,
        public readonly ?string $lastSyncedAt,
        public readonly array $extra,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $s = static fn (string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;
        $extra = is_string($row['extra'] ?? null) ? json_decode($row['extra'], true) : null;

        return new self(
            (int) $row['id'],
            (string) $row['handle'],
            (string) $row['title'],
            $s('description_html'),
            (string) $row['author_name'],
            $s('author_url'),
            $s('home_page_url'),
            $s('post_url_pattern'),
            $s('language') ?? 'en',
            $s('icon'),
            $s('favicon'),
            (string) $row['mode'],
            $s('source_url'),
            $s('source_format'),
            $s('media') ?? 'remote',
            $s('pinned_item_id'),
            $s('source_etag'),
            $s('last_synced_at'),
            is_array($extra) ? $extra : [],
        );
    }

    public function isMirror(): bool
    {
        return $this->mode === 'mirror';
    }
}
```

`modules/blog/src/BlogRepository.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;

final class BlogRepository
{
    private const COLUMNS = [
        'handle', 'title', 'description_html', 'author_name', 'author_url', 'home_page_url', 'post_url_pattern',
        'language', 'icon', 'favicon', 'mode', 'source_url', 'source_format', 'media', 'pinned_item_id',
        'source_etag', 'last_synced_at', 'last_full_sync_at', 'extra',
    ];

    public function __construct(private readonly Connection $db) {}

    public function find(string $handle): ?Blog
    {
        $row = $this->db->select('blogs')->where('handle', '=', $handle)->first();

        return $row === null ? null : Blog::fromRow($row);
    }

    public function findById(int $id): ?Blog
    {
        $row = $this->db->select('blogs')->where('id', '=', $id)->first();

        return $row === null ? null : Blog::fromRow($row);
    }

    /** @return list<Blog> */
    public function all(): array
    {
        return array_map(Blog::fromRow(...), $this->db->select('blogs')->orderBy('handle')->all());
    }

    /** @param array<string, mixed> $fields column => value; null values are ignored */
    public function create(array $fields, DateTimeImmutable $now): Blog
    {
        $fields = array_filter($fields, static fn (mixed $v): bool => $v !== null);
        $handle = $fields['handle'] ?? null;
        $row = array_merge(['title' => $handle, 'author_name' => $handle, 'language' => 'en', 'media' => 'remote'], $fields);
        $this->only($row);
        $this->validate($row);
        if ($this->find((string) $row['handle']) !== null) {
            throw new InvalidArgumentException("Blog '{$row['handle']}' already exists");
        }
        $this->db->insert('blogs', [...$row, 'created_at' => $now, 'updated_at' => $now]);

        return $this->find((string) $row['handle']) ?? throw new RuntimeException('Blog insert failed');
    }

    /** @param array<string, mixed> $fields column => value; null clears a nullable column */
    public function update(Blog $blog, array $fields, DateTimeImmutable $now): Blog
    {
        $this->only($fields);
        $current = $this->db->select('blogs')->where('id', '=', $blog->id)->first()
            ?? throw new RuntimeException("Blog {$blog->id} no longer exists");
        $this->validate(array_merge($current, $fields));
        if ($fields !== []) {
            $this->db->update('blogs', [...$fields, 'updated_at' => $now], ['id' => $blog->id]);
        }

        return $this->findById($blog->id) ?? throw new RuntimeException("Blog {$blog->id} no longer exists");
    }

    /** @param array<string, mixed> $row */
    private function validate(array $row): void
    {
        $handle = $row['handle'] ?? null;
        if (!is_string($handle) || preg_match('/^[a-z0-9_-]{2,32}$/D', $handle) !== 1) {
            throw new InvalidArgumentException('Handle must match [a-z0-9_-]{2,32}');
        }
        if (!in_array($row['mode'] ?? null, Blog::MODES, true)) {
            throw new InvalidArgumentException('Mode must be mirror or native');
        }
        if (!in_array($row['media'] ?? null, Blog::MEDIA, true)) {
            throw new InvalidArgumentException('Media must be remote or local');
        }
        if (!is_string($row['title'] ?? null) || $row['title'] === '') {
            throw new InvalidArgumentException('Title is required');
        }
        if ($row['mode'] === 'mirror') {
            if (!self::isHttpUrl($row['source_url'] ?? null)) {
                throw new InvalidArgumentException('A mirror blog needs an http(s) source URL');
            }
            if (!in_array($row['source_format'] ?? null, Blog::FORMATS, true)) {
                throw new InvalidArgumentException('Source format must be one of: ' . implode(', ', Blog::FORMATS));
            }
        }
        $pattern = $row['post_url_pattern'] ?? null;
        if ($pattern !== null && $pattern !== '') {
            $pattern = (string) $pattern;
            if (substr_count($pattern, '{id}') !== 1 || !self::isHttpUrl(str_replace('{id}', 'x', $pattern))) {
                throw new InvalidArgumentException('Post URL pattern must be an http(s) URL containing {id} exactly once');
            }
        }
    }

    /** @param array<string, mixed> $fields */
    private function only(array $fields): void
    {
        $unknown = array_diff(array_keys($fields), self::COLUMNS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown blog fields: ' . implode(', ', $unknown));
        }
    }

    private static function isHttpUrl(mixed $url): bool
    {
        return is_string($url) && preg_match('#^https?://[^\s/?\#]+#i', $url) === 1;
    }
}
```

`modules/blog/src/Cli/BlogCreateCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Blog\BlogRepository;

final class BlogCreateCommand extends Command
{
    public function __construct(private readonly BlogRepository $blogs) {}

    public function name(): string
    {
        return 'blog:create';
    }

    public function description(): string
    {
        return 'Create a mirror or native blog';
    }

    public function usage(): string
    {
        return 'blog:create <handle> --mode=mirror|native [--source=<url> --format=athena|jsonfeed]'
            . ' [--title=] [--author=] [--author-url=] [--home=] [--post-url=<pattern with {id}>]'
            . ' [--media=remote|local] [--language=]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $blog = $this->blogs->create([
            'handle' => $handle,
            'mode' => $input->option('mode'),
            'source_url' => $input->option('source'),
            'source_format' => $input->option('format'),
            'title' => $input->option('title'),
            'author_name' => $input->option('author'),
            'author_url' => $input->option('author-url'),
            'home_page_url' => $input->option('home'),
            'post_url_pattern' => $input->option('post-url'),
            'media' => $input->option('media'),
            'language' => $input->option('language'),
        ], new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $output->line("Created {$blog->mode} blog '{$blog->handle}'.");

        return 0;
    }
}
```

`modules/blog/src/Cli/BlogListCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\BlogRepository;

final class BlogListCommand extends Command
{
    public function __construct(private readonly BlogRepository $blogs, private readonly Connection $db) {}

    public function name(): string
    {
        return 'blog:list';
    }

    public function description(): string
    {
        return 'List blogs with their mode, source and live post count';
    }

    public function run(Input $input, Output $output): int
    {
        $rows = [];
        foreach ($this->blogs->all() as $blog) {
            $live = $this->db->select('posts')->where('blog_id', '=', $blog->id)->where('status', '=', 'published')->count();
            $rows[] = [$blog->handle, $blog->mode, $blog->sourceUrl ?? '-', (string) $live, $blog->lastSyncedAt ?? 'never'];
        }
        $output->table(['Handle', 'Mode', 'Source', 'Posts', 'Last sync'], $rows);

        return 0;
    }
}
```

- [ ] **Step 7: Run the tests**

Run `vendor/bin/phpunit tests/Module/Blog`, then the same on MySQL with the env vars from the Global Constraints.
Expected: PASS (15 tests) on both.

- [ ] **Step 8: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`
Expected: all green.

```bash
git add -A
git commit -m "feat(blog): add blog module skeleton, schema, blog repository and create/list commands" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Source adapters (JSON Feed and Athena)

**Files:**
- Create:
  - `modules/blog/src/Support/Text.php`
  - `modules/blog/src/Post/PostRecord.php`
  - `modules/blog/src/Source/SourceAdapter.php`
  - `modules/blog/src/Source/JsonFeedAdapter.php`
  - `modules/blog/src/Source/AthenaAdapter.php`
  - `modules/blog/src/Source/AdapterRegistry.php`
- Test:
  - `tests/Module/Blog/Support/TextTest.php`
  - `tests/Module/Blog/Source/JsonFeedAdapterTest.php`
  - `tests/Module/Blog/Source/AthenaAdapterTest.php`

These are pure unit tests. Extend `PHPUnit\Framework\TestCase`; no database is needed.

**Interfaces:**
- **Consumes:** `Blog::FORMATS` (Task 1).
- **Produces `Text`** (all static):
  - `plain(string $html): string`
  - `deriveTitle(string $kind, ?string $contentHtml, ?string $externalUrl): string`
  - `slug(string $name): string`
  - `canonicalJson(array $value): string`
- **Produces `PostRecord`** with these readonly fields:
  - `string $itemId`, `?string $ulid`, `string $kind`, `string $title`
  - `?string $url`, `?string $externalUrl`, `?string $contentHtml`, `?string $image`
  - `DateTimeImmutable $datePublished` (UTC), `?DateTimeImmutable $dateModified` (UTC)
  - `array<string,mixed> $doc`, `list<array{name: string, slug: string}> $tags`, `string $sourceHash` (sha1)
- **Produces `interface SourceAdapter`:**
  - `toRecord(array $item, DateTimeImmutable $now): PostRecord`, which throws `InvalidArgumentException` for an unusable item
  - `feedMeta(array $feed): array<string,mixed>`, returning blog column => value; always includes the `pinned_item_id` and `extra` keys
- **Produces `JsonFeedAdapter`** (non-final; `KINDS = ['note', 'photo', 'link', 'highlight']`) and **`AthenaAdapter extends JsonFeedAdapter`**.
- **Produces `AdapterRegistry`:**
  - `get(string $format): SourceAdapter`, which throws `InvalidArgumentException` for an unknown format
  - `formats(): list<string>`

**Rules the code implements:**
- **Columns vs `doc`:**
  - `doc` = the item minus the column keys `id`, `url`, `external_url`, `title`, `content_html`, `image`, `date_published` and `date_modified`.
  - `tags` is also removed, but kept in `doc` verbatim if it differs from the tag names.
  - `_athenana.kind` and `_athenana.tags` are removed too, and `_athenana` is dropped if that leaves it empty.
- **`source_hash`** = sha1 of the canonical JSON of the whole source item, with keys sorted recursively.
- **`ulid`** = the last 26 characters of the item id when they form a valid ULID preceded by a non-alphanumeric character or the start of the string; otherwise null.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Support/TextTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Support\Text;

final class TextTest extends TestCase
{
    public function testPlain(): void
    {
        self::assertSame('Hello & welcome to the blog', Text::plain("<p>Hello &amp; <b>welcome</b></p>\n<p>to   the blog</p>"));
        self::assertSame('', Text::plain('<img src="x">'));
    }

    public function testDeriveTitle(): void
    {
        self::assertSame(str_repeat('é', 80), Text::deriveTitle('note', '<p>' . str_repeat('é', 100) . '</p>', null));
        self::assertSame('example.com/a/b', Text::deriveTitle('link', null, 'https://www.example.com/a/b/?x=1'));
        self::assertSame('Photo', Text::deriveTitle('photo', null, null));
        self::assertSame('Untitled', Text::deriveTitle('note', '<p> </p>', null));
    }

    public function testSlug(): void
    {
        self::assertSame('rock-roll', Text::slug('Rock & Roll'));
        self::assertSame('1990s', Text::slug('1990s'));
        self::assertSame(substr(sha1('日本'), 0, 8), Text::slug('日本'));
    }

    public function testCanonicalJsonSortsKeysButNotLists(): void
    {
        self::assertSame(Text::canonicalJson(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]), Text::canonicalJson(['a' => ['x' => 1, 'y' => 2], 'b' => 1]));
        self::assertNotSame(Text::canonicalJson(['l' => [1, 2]]), Text::canonicalJson(['l' => [2, 1]]));
        self::assertSame('{"f":2.0,"u":"é/x"}', Text::canonicalJson(['u' => 'é/x', 'f' => 2.0]));
    }
}
```

`tests/Module/Blog/Source/AthenaAdapterTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Source\AdapterRegistry;
use Xaraya\Module\Blog\Source\AthenaAdapter;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;
use Xaraya\Module\Blog\Support\Text;

final class AthenaAdapterTest extends TestCase
{
    public const SONG = <<<'JSON'
        {
          "id": "https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq",
          "url": "https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq",
          "external_url": "https://music.apple.com/us/album/flagpole-sitta/1440923485?i=1440923493",
          "title": "Flagpole Sitta by Harvey Danger on Apple Music",
          "content_html": "<p>Song · 1998 · Duration 3:37</p>",
          "date_published": "2026-09-30T20:50:08+00:00",
          "date_modified": "2026-10-04T03:40:43+00:00",
          "tags": ["music", "comedy", "1990s", "rock"],
          "_video": {"provider": "youtube", "id": "sVt1Dy_LblQ", "thumbnail": "https://athenana.com/p/01m3t1bb3wrk4azp4tth96cezk?v=48888177", "source": "matched"},
          "_source": {"name": "Apple Music - Web Player", "url": "https://music.apple.com/us/album/flagpole-sitta/1440923485?i=1440923493"},
          "_athenana": {
            "kind": "link",
            "song": {"channel": "Harvey Danger - Topic", "duration_seconds": 218},
            "tags": [{"name": "music", "slug": "music"}, {"name": "comedy", "slug": "comedy"}, {"name": "1990s", "slug": "1990s"}, {"name": "rock", "slug": "rock"}]
          }
        }
        JSON;

    /** @return array<string, mixed> */
    public static function song(): array
    {
        return json_decode(self::SONG, true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSongLinkItem(): void
    {
        $r = (new AthenaAdapter())->toRecord(self::song(), new DateTimeImmutable('2026-10-08T00:00:00Z'));

        self::assertSame('https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq', $r->itemId);
        self::assertSame('01m3t19wn8reaww81zg1m47tjq', $r->ulid);
        self::assertSame('link', $r->kind);
        self::assertSame('Flagpole Sitta by Harvey Danger on Apple Music', $r->title);
        self::assertSame('https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq', $r->url);
        self::assertSame('<p>Song · 1998 · Duration 3:37</p>', $r->contentHtml);
        self::assertNull($r->image);
        self::assertSame('2026-09-30 20:50:08', $r->datePublished->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $r->datePublished->getTimezone()->getName());
        self::assertSame('2026-10-04 03:40:43', $r->dateModified?->format('Y-m-d H:i:s'));
        self::assertSame(['music', 'comedy', '1990s', 'rock'], array_column($r->tags, 'name'));
        self::assertSame(['_video', '_source', '_athenana'], array_keys($r->doc));
        self::assertSame(['song' => ['channel' => 'Harvey Danger - Topic', 'duration_seconds' => 218]], $r->doc['_athenana']);
        self::assertSame(sha1(Text::canonicalJson(self::song())), $r->sourceHash);
    }

    public function testHashIgnoresKeyOrder(): void
    {
        $reordered = array_reverse(self::song(), true);
        $now = new DateTimeImmutable();
        self::assertSame((new AthenaAdapter())->toRecord(self::song(), $now)->sourceHash, (new AthenaAdapter())->toRecord($reordered, $now)->sourceHash);
    }

    public function testNoteWithoutContentAndTagListThatDiffers(): void
    {
        $item = [
            'id' => 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjr',
            'title' => 'A note',
            'date_published' => '2026-10-01T10:00:00-04:00',
            'tags' => ['x'],
            '_athenana' => ['kind' => 'note', 'tags' => [['name' => 'Y', 'slug' => 'y']]],
        ];
        $r = (new AthenaAdapter())->toRecord($item, new DateTimeImmutable());
        self::assertSame('note', $r->kind);
        self::assertNull($r->contentHtml);
        self::assertSame('2026-10-01 14:00:00', $r->datePublished->format('Y-m-d H:i:s'));
        self::assertSame([['name' => 'Y', 'slug' => 'y']], $r->tags);
        self::assertSame(['x'], $r->doc['tags'], 'differing tags list kept verbatim');
        self::assertArrayNotHasKey('_athenana', $r->doc, 'empty _athenana dropped');
    }

    public function testUnknownKindFallsBackToInference(): void
    {
        $item = ['id' => 'x-1', 'title' => 'T', 'external_url' => 'https://e.test/', '_athenana' => ['kind' => 'hologram']];
        self::assertSame('link', (new AthenaAdapter())->toRecord($item, new DateTimeImmutable())->kind);
    }

    public function testRegistry(): void
    {
        $registry = new AdapterRegistry();
        self::assertSame(Blog::FORMATS, $registry->formats());
        self::assertInstanceOf(AthenaAdapter::class, $registry->get('athena'));
        self::assertInstanceOf(JsonFeedAdapter::class, $registry->get('jsonfeed'));
        $this->expectException(InvalidArgumentException::class);
        $registry->get('rss');
    }
}
```

`tests/Module/Blog/Source/JsonFeedAdapterTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;

final class JsonFeedAdapterTest extends TestCase
{
    private function record(array $item): \Xaraya\Module\Blog\Post\PostRecord
    {
        return (new JsonFeedAdapter())->toRecord($item, new DateTimeImmutable('2026-10-08T12:00:00Z'));
    }

    public function testKindInference(): void
    {
        self::assertSame('link', $this->record(['id' => '1', 'external_url' => 'https://e.test/a'])->kind);
        self::assertSame('photo', $this->record(['id' => '2', 'image' => 'https://e.test/i.png'])->kind);
        self::assertSame('note', $this->record(['id' => '3', 'image' => 'https://e.test/i.png', 'content_html' => '<p>Words</p>'])->kind);
        self::assertSame('note', $this->record(['id' => '4', 'content_text' => 'Plain'])->kind);
    }

    public function testDerivedTitlesAndDates(): void
    {
        $note = $this->record(['id' => '1', 'content_text' => 'Hello <world>', 'date_modified' => '2026-10-02T00:00:00Z']);
        self::assertSame('Hello <world>', $note->title);
        self::assertSame('2026-10-02 00:00:00', $note->datePublished->format('Y-m-d H:i:s'), 'falls back to date_modified');
        self::assertSame(['content_text' => 'Hello <world>'], $note->doc);

        $link = $this->record(['id' => '2', 'external_url' => 'https://www.e.test/path/']);
        self::assertSame('e.test/path', $link->title);
        self::assertSame('2026-10-08 12:00:00', $link->datePublished->format('Y-m-d H:i:s'), 'falls back to now');
        self::assertNull($link->ulid);
    }

    public function testTagsAreSluggedAndDeduplicated(): void
    {
        $r = $this->record(['id' => '1', 'tags' => ['Rock & Roll', 'rock-roll', ' ', 'Jazz']]);
        self::assertSame([['name' => 'Rock & Roll', 'slug' => 'rock-roll'], ['name' => 'Jazz', 'slug' => 'jazz']], $r->tags);
        self::assertSame(['Rock & Roll', 'rock-roll', ' ', 'Jazz'], $r->doc['tags'], 'source list kept when it differs');

        self::assertArrayNotHasKey('tags', $this->record(['id' => '2', 'tags' => ['a', 'b']])->doc);
    }

    public function testUlidExtraction(): void
    {
        self::assertSame('01m3t19wn8reaww81zg1m47tjq', $this->record(['id' => 'tag:x,2026:01m3t19wn8reaww81zg1m47tjq'])->ulid);
        self::assertNull($this->record(['id' => 'x01m3t19wn8reaww81zg1m47tjq'])->ulid, 'must not be glued to other text');
        self::assertSame('42', $this->record(['id' => 42])->itemId);
    }

    public function testRejectsBadItems(): void
    {
        foreach ([['title' => 'no id'], ['id' => ''], ['id' => '1', 'date_published' => 'not a date'], ['id' => str_repeat('x', 513)]] as $item) {
            try {
                $this->record($item);
                self::fail('expected rejection: ' . json_encode($item));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFeedMeta(): void
    {
        $meta = (new JsonFeedAdapter())->feedMeta([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'John Cox',
            'description' => 'Notes, photos and the things worth saying something about.',
            'home_page_url' => 'https://www.wyome.com/blog/',
            'feed_url' => 'https://athenana.com/blog/wyome/feed.json',
            'language' => 'en',
            'icon' => 'https://athenana.com/img/apple-touch-icon.png',
            'favicon' => 'https://athenana.com/favicon.ico',
            'authors' => [['name' => 'John Cox', 'url' => 'https://www.wyome.com/blog/']],
            '_athenana' => ['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe', 'pinned_id' => 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq'],
            '_other' => ['x' => 1],
            'items' => [],
        ]);
        self::assertSame([
            'title' => 'John Cox',
            'description_html' => 'Notes, photos and the things worth saying something about.',
            'home_page_url' => 'https://www.wyome.com/blog/',
            'icon' => 'https://athenana.com/img/apple-touch-icon.png',
            'favicon' => 'https://athenana.com/favicon.ico',
            'language' => 'en',
            'author_name' => 'John Cox',
            'author_url' => 'https://www.wyome.com/blog/',
            'pinned_item_id' => 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq',
            'extra' => ['_athenana' => ['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe'], '_other' => ['x' => 1]],
        ], $meta);

        $bare = (new JsonFeedAdapter())->feedMeta(['version' => 'https://jsonfeed.org/version/1.1', 'items' => []]);
        self::assertSame(['pinned_item_id' => null, 'extra' => []], $bare);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Support tests/Module/Blog/Source`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`modules/blog/src/Support/Text.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Support;

final class Text
{
    public static function plain(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?>|</p>|</li>|</h[1-6]>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function deriveTitle(string $kind, ?string $contentHtml, ?string $externalUrl): string
    {
        if ($kind === 'link' && $externalUrl !== null) {
            $parts = parse_url($externalUrl);
            $host = (string) preg_replace('/^www\./i', '', (string) ($parts['host'] ?? ''));
            $label = $host . rtrim((string) ($parts['path'] ?? ''), '/');
            if ($label !== '') {
                return $label;
            }
        }
        $plain = $contentHtml === null ? '' : self::plain($contentHtml);
        if ($plain !== '') {
            return mb_substr($plain, 0, 80);
        }

        return match ($kind) {
            'photo' => 'Photo',
            'link' => (string) $externalUrl,
            default => 'Untitled',
        };
    }

    public static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        return $slug !== '' ? substr($slug, 0, 128) : substr(sha1($name), 0, 8);
    }

    /** @param array<mixed> $value */
    public static function canonicalJson(array $value): string
    {
        return json_encode(
            self::sortKeys($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
```

`modules/blog/src/Post/PostRecord.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;

final class PostRecord
{
    /**
     * @param array<string, mixed> $doc
     * @param list<array{name: string, slug: string}> $tags
     */
    public function __construct(
        public readonly string $itemId,
        public readonly ?string $ulid,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $url,
        public readonly ?string $externalUrl,
        public readonly ?string $contentHtml,
        public readonly ?string $image,
        public readonly DateTimeImmutable $datePublished,
        public readonly ?DateTimeImmutable $dateModified,
        public readonly array $doc,
        public readonly array $tags,
        public readonly string $sourceHash,
    ) {}
}
```

`modules/blog/src/Source/SourceAdapter.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use DateTimeImmutable;
use InvalidArgumentException;
use Xaraya\Module\Blog\Post\PostRecord;

interface SourceAdapter
{
    /**
     * @param array<string, mixed> $item one JSON Feed item
     * @throws InvalidArgumentException when the item can't be stored
     */
    public function toRecord(array $item, DateTimeImmutable $now): PostRecord;

    /**
     * @param array<string, mixed> $feed a page envelope
     * @return array<string, mixed> blog column => value; always has 'pinned_item_id' and 'extra'
     */
    public function feedMeta(array $feed): array;
}
```

`modules/blog/src/Source/JsonFeedAdapter.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Support\Text;

class JsonFeedAdapter implements SourceAdapter
{
    public const KINDS = ['note', 'photo', 'link', 'highlight'];

    private const COLUMN_KEYS = ['id', 'url', 'external_url', 'title', 'content_html', 'image', 'date_published', 'date_modified'];

    private const FEED_COLUMNS = [
        'title' => 'title',
        'description' => 'description_html',
        'home_page_url' => 'home_page_url',
        'icon' => 'icon',
        'favicon' => 'favicon',
        'language' => 'language',
    ];

    public function toRecord(array $item, DateTimeImmutable $now): PostRecord
    {
        $itemId = $item['id'] ?? null;
        if (is_int($itemId)) {
            $itemId = (string) $itemId;
        }
        if (!is_string($itemId) || $itemId === '') {
            throw new InvalidArgumentException('Item has no id');
        }
        if (strlen($itemId) > 512) {
            throw new InvalidArgumentException('Item id is longer than 512 bytes');
        }
        $contentHtml = self::str($item, 'content_html');
        $contentText = self::str($item, 'content_text');
        $externalUrl = self::str($item, 'external_url');
        $image = self::str($item, 'image');
        $kind = $this->kind($item, $contentHtml ?? $contentText, $externalUrl, $image);
        $title = self::str($item, 'title')
            ?? Text::deriveTitle($kind, $contentHtml ?? ($contentText === null ? null : htmlspecialchars($contentText)), $externalUrl);
        $modified = self::date($item, 'date_modified');
        $published = self::date($item, 'date_published') ?? $modified ?? $now;
        $tags = $this->tags($item);

        $doc = array_diff_key($item, array_flip(self::COLUMN_KEYS));
        unset($doc['tags']);
        if (array_key_exists('tags', $item) && $item['tags'] !== array_column($tags, 'name')) {
            $doc['tags'] = $item['tags'];
        }
        if (is_array($doc['_athenana'] ?? null)) {
            $athenana = $doc['_athenana'];
            unset($athenana['kind'], $athenana['tags']);
            if ($athenana === []) {
                unset($doc['_athenana']);
            } else {
                $doc['_athenana'] = $athenana;
            }
        }

        return new PostRecord(
            $itemId,
            self::ulidFrom($itemId),
            $kind,
            $title,
            self::str($item, 'url'),
            $externalUrl,
            $contentHtml,
            $image,
            $published,
            $modified,
            $doc,
            $tags,
            sha1(Text::canonicalJson($item)),
        );
    }

    public function feedMeta(array $feed): array
    {
        $meta = [];
        foreach (self::FEED_COLUMNS as $key => $column) {
            $value = self::str($feed, $key);
            if ($value !== null) {
                $meta[$column] = $column === 'language' ? substr($value, 0, 16) : $value;
            }
        }
        $authors = $feed['authors'] ?? null;
        $author = is_array($authors) && is_array($authors[0] ?? null) ? $authors[0] : ($feed['author'] ?? null);
        if (is_array($author)) {
            if (($name = self::str($author, 'name')) !== null) {
                $meta['author_name'] = $name;
            }
            if (($url = self::str($author, 'url')) !== null) {
                $meta['author_url'] = $url;
            }
        }
        $extra = [];
        foreach ($feed as $key => $value) {
            if (is_string($key) && str_starts_with($key, '_')) {
                $extra[$key] = $value;
            }
        }
        $pinned = null;
        if (is_array($extra['_athenana'] ?? null)) {
            $athenana = $extra['_athenana'];
            $pinned = self::str($athenana, 'pinned_id');
            unset($athenana['pinned_id']);
            if ($athenana === []) {
                unset($extra['_athenana']);
            } else {
                $extra['_athenana'] = $athenana;
            }
        }
        $meta['pinned_item_id'] = $pinned;
        $meta['extra'] = $extra;

        return $meta;
    }

    /** @param array<string, mixed> $item */
    protected function kind(array $item, ?string $content, ?string $externalUrl, ?string $image): string
    {
        if ($externalUrl !== null) {
            return 'link';
        }
        if ($image !== null && ($content === null || Text::plain($content) === '')) {
            return 'photo';
        }

        return 'note';
    }

    /**
     * @param array<string, mixed> $item
     * @return list<array{name: string, slug: string}>
     */
    protected function tags(array $item): array
    {
        $tags = [];
        foreach (is_array($item['tags'] ?? null) ? $item['tags'] : [] as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $name = mb_substr(trim($name), 0, 128);
            $slug = Text::slug($name);
            $tags[$slug] ??= ['name' => $name, 'slug' => $slug];
        }

        return array_values($tags);
    }

    /** @param array<mixed> $data */
    protected static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $item */
    private static function date(array $item, string $key): ?DateTimeImmutable
    {
        $value = self::str($item, $key);
        if ($value === null) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
        } catch (Exception $e) {
            throw new InvalidArgumentException("Item {$key} '{$value}' is not a date", 0, $e);
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    private static function ulidFrom(string $itemId): ?string
    {
        return preg_match('/(?:^|[^0-9a-z])([0-7][0-9a-hjkmnp-tv-z]{25})$/D', $itemId, $m) === 1 ? $m[1] : null;
    }
}
```

`modules/blog/src/Source/AthenaAdapter.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use Xaraya\Module\Blog\Support\Text;

final class AthenaAdapter extends JsonFeedAdapter
{
    protected function kind(array $item, ?string $content, ?string $externalUrl, ?string $image): string
    {
        $athenana = $item['_athenana'] ?? null;
        $kind = is_array($athenana) ? ($athenana['kind'] ?? null) : null;

        return is_string($kind) && in_array($kind, self::KINDS, true)
            ? $kind
            : parent::kind($item, $content, $externalUrl, $image);
    }

    protected function tags(array $item): array
    {
        $athenana = $item['_athenana'] ?? null;
        if (!is_array($athenana) || !is_array($athenana['tags'] ?? null)) {
            return parent::tags($item);
        }
        $tags = [];
        foreach ($athenana['tags'] as $tag) {
            if (!is_array($tag) || ($name = self::str($tag, 'name')) === null) {
                continue;
            }
            $slug = self::str($tag, 'slug') ?? Text::slug($name);
            $tags[$slug] ??= ['name' => $name, 'slug' => $slug];
        }

        return array_values($tags);
    }
}
```

`modules/blog/src/Source/AdapterRegistry.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use InvalidArgumentException;

final class AdapterRegistry
{
    /** @var array<string, class-string<SourceAdapter>> */
    private const ADAPTERS = [
        'jsonfeed' => JsonFeedAdapter::class,
        'athena' => AthenaAdapter::class,
    ];

    public function get(string $format): SourceAdapter
    {
        $class = self::ADAPTERS[$format] ?? throw new InvalidArgumentException("Unknown source format '{$format}'");

        return new $class();
    }

    /** @return list<string> */
    public function formats(): array
    {
        return array_keys(self::ADAPTERS);
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Module/Blog/Support tests/Module/Blog/Source`
Expected: PASS.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): add JSON Feed and Athena source adapters" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Post repository

**Files:**
- Create: `modules/blog/src/Post/Post.php`, `modules/blog/src/Post/SaveResult.php`, `modules/blog/src/Post/PostRepository.php`
- Test: `tests/Module/Blog/Post/PostRepositoryTest.php`

**Interfaces:**
- **Consumes:** `PostRecord` (Task 2); `BlogRepository` and the `posts`/`post_tags` tables (Task 1).
- **Produces `Post`** with these readonly fields:
  - `string $id`, `int $blogId`, `string $itemId`, `string $kind`, `string $title`
  - `?string $url`, `?string $externalUrl`, `?string $contentHtml`, `?string $image`
  - `DateTimeImmutable $datePublished` (UTC), `?DateTimeImmutable $dateModified` (UTC)
  - `string $status`, `array<string,mixed> $doc`, `list<array{name: string, slug: string}> $tags`
  - It also has `static fromRow(array $row, array $tags): Post`.
- **Produces `SaveResult`:** `CREATED = 'created'`, `UPDATED = 'updated'`, `UNCHANGED = 'unchanged'`, plus readonly `string $status` and `string $id`.
- **Produces `PostRepository(Connection)`:**
  - `save(int $blogId, PostRecord $record, DateTimeImmutable $now): SaveResult`, which runs in a transaction and restores tombstoned posts
  - `find(string $id): ?Post`
  - `page(int $blogId, DateTimeImmutable $now, ?array $before, int $limit): list<Post>`, where `$before` is `array{0: DateTimeImmutable, 1: string}|null`. It returns only published posts with `date_published <= $now`, ordered by `date_published DESC, id DESC`.
  - `liveItemIds(int $blogId): list<string>`
  - `tombstone(int $blogId, list<string> $itemIds, DateTimeImmutable $now): list<string>`, returning the ids of posts that were live and are now deleted
  - `countLive(int $blogId): int`
- **Rules:**
  - A post's `id` is the record's ULID unless another post already uses it, in which case a new ULID is generated.
  - An `UNCHANGED` result means the same `source_hash` on a published post. Anything else (a hash change, or restoring a tombstoned post) is `UPDATED`.
  - Tags are rewritten in order, using the `position` column.

- [ ] **Step 1: Write the failing test `tests/Module/Blog/Post/PostRepositoryTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Post;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Post\SaveResult;
use Xaraya\Tests\Module\Blog\BlogTestCase;

final class PostRepositoryTest extends BlogTestCase
{
    private App $app;
    private PostRepository $posts;
    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->posts = $this->app->container()->get(PostRepository::class);
        $this->blog = $this->blogs($this->app)->create(['handle' => 'one', 'mode' => 'native'], $this->now());
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /** @param list<array{name: string, slug: string}> $tags */
    private static function record(string $itemId, ?string $ulid, string $published, string $hash = 'h1', array $tags = [], array $doc = []): PostRecord
    {
        return new PostRecord($itemId, $ulid, 'note', "Title {$itemId}", null, null, '<p>Body</p>', null, self::at($published), null, $doc, $tags, sha1($hash));
    }

    public function testCreateUnchangedUpdated(): void
    {
        $ulid = Ulid::generate();
        $record = self::record('src:1', $ulid, '2026-10-01 10:00:00', 'h1', [['name' => 'B', 'slug' => 'b'], ['name' => 'A', 'slug' => 'a']], ['_x' => ['k' => 1]]);

        $created = $this->posts->save($this->blog->id, $record, $this->now());
        self::assertSame(SaveResult::CREATED, $created->status);
        self::assertSame($ulid, $created->id);
        self::assertSame(SaveResult::UNCHANGED, $this->posts->save($this->blog->id, $record, $this->now())->status);

        $changed = self::record('src:1', $ulid, '2026-10-01 10:00:00', 'h2', [['name' => 'C', 'slug' => 'c']]);
        $updated = $this->posts->save($this->blog->id, $changed, $this->now());
        self::assertSame(SaveResult::UPDATED, $updated->status);
        self::assertSame($ulid, $updated->id);

        $post = $this->posts->find($ulid);
        self::assertNotNull($post);
        self::assertSame('src:1', $post->itemId);
        self::assertSame([['name' => 'C', 'slug' => 'c']], $post->tags);
        self::assertSame([], $post->doc);
        self::assertSame('2026-10-01 10:00:00', $post->datePublished->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $post->datePublished->getTimezone()->getName());
        self::assertSame('published', $post->status);
    }

    public function testTagOrderAndDocRoundTrip(): void
    {
        $ulid = Ulid::generate();
        $tags = [['name' => 'zeta', 'slug' => 'zeta'], ['name' => 'alpha', 'slug' => 'alpha'], ['name' => 'mid', 'slug' => 'mid']];
        $doc = ['_athenana' => ['song' => ['duration_seconds' => 218]], 'attachments' => [['url' => 'https://a.test/x.mp3']]];
        $this->posts->save($this->blog->id, self::record('src:2', $ulid, '2026-10-01 10:00:00', 'h', $tags, $doc), $this->now());
        $post = $this->posts->find($ulid);
        self::assertSame($tags, $post?->tags);
        self::assertEquals($doc, $post?->doc);
    }

    public function testUlidCollisionAcrossBlogsGetsNewId(): void
    {
        $other = $this->blogs($this->app)->create(['handle' => 'two', 'mode' => 'native'], $this->now());
        $ulid = Ulid::generate();
        $first = $this->posts->save($this->blog->id, self::record('src:1', $ulid, '2026-10-01 10:00:00'), $this->now());
        $second = $this->posts->save($other->id, self::record('src:1', $ulid, '2026-10-01 10:00:00'), $this->now());
        self::assertSame($ulid, $first->id);
        self::assertNotSame($ulid, $second->id);
        self::assertTrue(Ulid::isValid($second->id));
        self::assertNull($this->posts->find('01aaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function testPagingHidesScheduledAndDeleted(): void
    {
        $times = ['2026-10-05 00:00:00', '2026-10-04 00:00:00', '2026-10-04 00:00:00', '2026-10-03 00:00:00', '2026-10-02 00:00:00'];
        $ids = [];
        foreach ($times as $i => $time) {
            $ids[] = $this->posts->save($this->blog->id, self::record("src:{$i}", Ulid::generate(), $time), $this->now())->id;
        }
        $this->posts->save($this->blog->id, self::record('future', Ulid::generate(), '2099-01-01 00:00:00'), $this->now());
        $now = self::at('2026-10-08 00:00:00');

        $page1 = $this->posts->page($this->blog->id, $now, null, 2);
        self::assertCount(2, $page1);
        $last = $page1[1];
        $page2 = $this->posts->page($this->blog->id, $now, [$last->datePublished, $last->id], 2);
        $page3 = $this->posts->page($this->blog->id, $now, [$page2[1]->datePublished, $page2[1]->id], 2);
        $walked = array_map(fn ($p) => $p->id, [...$page1, ...$page2, ...$page3]);
        self::assertCount(5, $walked);
        self::assertSame(count($walked), count(array_unique($walked)));
        self::assertNotContains('future', array_map(fn ($p) => $p->itemId, [...$page1, ...$page2, ...$page3]));

        $deleted = $this->posts->tombstone($this->blog->id, ['src:0', 'src:missing'], $this->now());
        self::assertSame([$ids[0]], $deleted);
        self::assertSame([], $this->posts->tombstone($this->blog->id, ['src:0'], $this->now()), 'already deleted');
        self::assertNotContains($ids[0], array_map(fn ($p) => $p->id, $this->posts->page($this->blog->id, $now, null, 10)));
        self::assertSame(5, $this->posts->countLive($this->blog->id), '4 past + 1 scheduled are live');
        self::assertCount(5, $this->posts->liveItemIds($this->blog->id));
        self::assertSame('deleted', $this->posts->find($ids[0])?->status);
    }

    public function testTombstonedPostIsRestoredBySave(): void
    {
        $record = self::record('src:1', Ulid::generate(), '2026-10-01 10:00:00');
        $id = $this->posts->save($this->blog->id, $record, $this->now())->id;
        $this->posts->tombstone($this->blog->id, ['src:1'], $this->now());
        $result = $this->posts->save($this->blog->id, $record, $this->now());
        self::assertSame(SaveResult::UPDATED, $result->status);
        self::assertSame($id, $result->id);
        self::assertSame('published', $this->posts->find($id)?->status);
    }

    public function testTombstoneHandlesLargeLists(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->posts->save($this->blog->id, self::record("src:{$i}", Ulid::generate(), '2026-10-01 10:00:00'), $this->now());
        }
        $many = array_map(fn (int $i): string => "src:{$i}", range(0, 1200));
        self::assertCount(3, $this->posts->tombstone($this->blog->id, $many, $this->now()));
        self::assertSame(0, $this->posts->countLive($this->blog->id));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Post`
Expected: errors, `PostRepository` not found.

- [ ] **Step 3: Implement**

`modules/blog/src/Post/SaveResult.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

final class SaveResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const UNCHANGED = 'unchanged';

    public function __construct(public readonly string $status, public readonly string $id) {}
}
```

`modules/blog/src/Post/Post.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;
use DateTimeZone;

final class Post
{
    /**
     * @param array<string, mixed> $doc
     * @param list<array{name: string, slug: string}> $tags
     */
    public function __construct(
        public readonly string $id,
        public readonly int $blogId,
        public readonly string $itemId,
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $url,
        public readonly ?string $externalUrl,
        public readonly ?string $contentHtml,
        public readonly ?string $image,
        public readonly DateTimeImmutable $datePublished,
        public readonly ?DateTimeImmutable $dateModified,
        public readonly string $status,
        public readonly array $doc,
        public readonly array $tags,
    ) {}

    /**
     * @param array<string, mixed> $row
     * @param list<array{name: string, slug: string}> $tags
     */
    public static function fromRow(array $row, array $tags): self
    {
        $utc = new DateTimeZone('UTC');
        $s = static fn (string $key): ?string => isset($row[$key]) && $row[$key] !== '' ? (string) $row[$key] : null;
        $doc = is_string($row['doc'] ?? null) ? json_decode($row['doc'], true) : null;
        $modified = $s('date_modified');

        return new self(
            (string) $row['id'],
            (int) $row['blog_id'],
            (string) $row['item_id'],
            (string) $row['kind'],
            (string) $row['title'],
            $s('url'),
            $s('external_url'),
            $s('content_html'),
            $s('image'),
            new DateTimeImmutable((string) $row['date_published'], $utc),
            $modified === null ? null : new DateTimeImmutable($modified, $utc),
            (string) $row['status'],
            is_array($doc) ? $doc : [],
            $tags,
        );
    }
}
```

`modules/blog/src/Post/PostRepository.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Post;

use DateTimeImmutable;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;

final class PostRepository
{
    private const CHUNK = 500;

    public function __construct(private readonly Connection $db) {}

    public function save(int $blogId, PostRecord $record, DateTimeImmutable $now): SaveResult
    {
        return $this->db->transaction(function () use ($blogId, $record, $now): SaveResult {
            $existing = $this->db->select('posts')
                ->columns('id', 'status', 'source_hash')
                ->where('blog_id', '=', $blogId)
                ->where('item_id', '=', $record->itemId)
                ->first();
            $columns = [
                'kind' => $record->kind,
                'title' => $record->title,
                'url' => $record->url,
                'external_url' => $record->externalUrl,
                'content_html' => $record->contentHtml,
                'image' => $record->image,
                'date_published' => $record->datePublished,
                'date_modified' => $record->dateModified,
                'status' => 'published',
                'source_hash' => $record->sourceHash,
                'doc' => $record->doc === [] ? null : $record->doc,
                'updated_at' => $now,
            ];
            if ($existing !== null) {
                $id = (string) $existing['id'];
                if ($existing['source_hash'] === $record->sourceHash && $existing['status'] === 'published') {
                    return new SaveResult(SaveResult::UNCHANGED, $id);
                }
                $this->db->update('posts', $columns, ['id' => $id]);
                $this->writeTags($id, $blogId, $record->tags);

                return new SaveResult(SaveResult::UPDATED, $id);
            }
            $id = $record->ulid !== null && $this->db->select('posts')->where('id', '=', $record->ulid)->count() === 0
                ? $record->ulid
                : Ulid::generate();
            $this->db->insert('posts', ['id' => $id, 'blog_id' => $blogId, 'item_id' => $record->itemId, ...$columns, 'created_at' => $now]);
            $this->writeTags($id, $blogId, $record->tags);

            return new SaveResult(SaveResult::CREATED, $id);
        });
    }

    public function find(string $id): ?Post
    {
        $row = $this->db->select('posts')->where('id', '=', $id)->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /**
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return list<Post>
     */
    public function page(int $blogId, DateTimeImmutable $now, ?array $before, int $limit): array
    {
        $query = $this->db->select('posts')
            ->where('blog_id', '=', $blogId)
            ->where('status', '=', 'published')
            ->where('date_published', '<=', $now);
        if ($before !== null) {
            $query->cursor(['date_published' => $before[0], 'id' => $before[1]], '<');
        }

        return $this->hydrate($query->orderBy('date_published', 'desc')->orderBy('id', 'desc')->limit($limit)->all());
    }

    /** @return list<string> */
    public function liveItemIds(int $blogId): array
    {
        $rows = $this->db->select('posts')->columns('item_id')->where('blog_id', '=', $blogId)->where('status', '=', 'published')->all();

        return array_map(static fn (array $row): string => (string) $row['item_id'], $rows);
    }

    public function countLive(int $blogId): int
    {
        return $this->db->select('posts')->where('blog_id', '=', $blogId)->where('status', '=', 'published')->count();
    }

    /**
     * @param list<string> $itemIds
     * @return list<string> ids of the posts that were tombstoned
     */
    public function tombstone(int $blogId, array $itemIds, DateTimeImmutable $now): array
    {
        $deleted = [];
        foreach (array_chunk($itemIds, self::CHUNK) as $chunk) {
            $rows = $this->db->select('posts')->columns('id')
                ->where('blog_id', '=', $blogId)
                ->where('status', '=', 'published')
                ->whereIn('item_id', $chunk)
                ->all();
            foreach ($rows as $row) {
                $this->db->update('posts', ['status' => 'deleted', 'updated_at' => $now], ['id' => (string) $row['id']]);
                $deleted[] = (string) $row['id'];
            }
        }

        return $deleted;
    }

    /** @param list<array{name: string, slug: string}> $tags */
    private function writeTags(string $postId, int $blogId, array $tags): void
    {
        $this->db->delete('post_tags', ['post_id' => $postId]);
        foreach (array_values($tags) as $position => $tag) {
            $this->db->insert('post_tags', [
                'post_id' => $postId,
                'blog_id' => $blogId,
                'position' => $position,
                'name' => $tag['name'],
                'slug' => $tag['slug'],
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Post>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        $tags = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $tagRows = $this->db->select('post_tags')->whereIn('post_id', $chunk)->orderBy('post_id')->orderBy('position')->all();
            foreach ($tagRows as $tag) {
                $tags[(string) $tag['post_id']][] = ['name' => (string) $tag['name'], 'slug' => (string) $tag['slug']];
            }
        }

        return array_map(static fn (array $row): Post => Post::fromRow($row, $tags[(string) $row['id']] ?? []), $rows);
    }
}
```

- [ ] **Step 4: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Module/Blog/Post`, then repeat with the MySQL env vars.
Expected: PASS (6 tests) on both.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): add post repository with keyed upserts, keyset paging and tombstones" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Feed formatting (cursor, item serialiser, envelope)

**Files:**
- Create: `modules/blog/src/Feed/Cursor.php`, `modules/blog/src/Feed/ItemSerializer.php`, `modules/blog/src/Feed/FeedBuilder.php`
- Test: `tests/Module/Blog/Feed/CursorTest.php`, `tests/Module/Blog/Feed/ItemSerializerTest.php`, `tests/Module/Blog/Feed/FeedBuilderTest.php` (pure unit tests)

**Interfaces:**
- **Consumes:** `Blog` (Task 1), `Post` (Task 3), `AthenaAdapter` and the `AthenaAdapterTest::song()` fixture (Task 2).
- **Produces `Cursor`:**
  - `static encode(DateTimeImmutable $published, string $id): string`
  - `static decode(string $cursor): array{0: DateTimeImmutable, 1: string}`, which throws `InvalidArgumentException` on malformed input
- **Produces `ItemSerializer`:**
  - `item(Blog $blog, Post $post, string $appUrl, array<string,string> $media = []): array<string,mixed>`
  - `static clean(array $value): array`
  - `url(Blog $blog, Post $post, string $appUrl): string`
- **Produces `FeedBuilder(ItemSerializer)`:** `feed(Blog $blog, list<Post> $posts, string $feedUrl, ?string $nextUrl, string $appUrl, array<string,string> $media = []): array<string,mixed>`.
- **Item key order:**
  1. `id`, `url`, `external_url`, `title`, `content_html`, `image`, `date_published`, `date_modified`, `tags`, `attachments`
  2. the remaining `doc` keys
  3. `_athenana`, laid out as `{kind, …doc._athenana, tags}`
- **Item URL:**
  - the blog's `post_url_pattern` with `{id}` → the post id, if set;
  - otherwise the stored source `url`;
  - otherwise `{appUrl}/s/{id}`.
- **Media rewrite:** replaces exact string matches anywhere in the item except the top-level `id`, `url` and `external_url`.
- **Envelope key order:** `version`, `title`, `description`, `home_page_url`, `feed_url`, `next_url`, `language`, `icon`, `favicon`, `authors`, `_athenana` (`{pinned_id, …extra._athenana}`), any other `_` keys from `extra`, then `items`. The envelope is cleaned, but `items` is always present.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Feed/CursorTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Feed\Cursor;

final class CursorTest extends TestCase
{
    /** Taken from Athena's live next_url for the wyome blog. */
    private const ATHENA = 'MjAyNi0xMC0wNyAxNjo0OToxN3wwMW0yZGdqMnptcHRxZWRhYTBjYjdobXRkdg';

    public function testDecodesAthenaCursor(): void
    {
        [$date, $id] = Cursor::decode(self::ATHENA);
        self::assertSame('2026-10-07 16:49:17', $date->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $date->getTimezone()->getName());
        self::assertSame('01m2dgj2zmptqedaa0cb7hmtdv', $id);
    }

    public function testEncodeMatchesAthenaAndConvertsToUtc(): void
    {
        self::assertSame(self::ATHENA, Cursor::encode(new DateTimeImmutable('2026-10-07 16:49:17', new DateTimeZone('UTC')), '01m2dgj2zmptqedaa0cb7hmtdv'));
        self::assertSame(self::ATHENA, Cursor::encode(new DateTimeImmutable('2026-10-07 12:49:17', new DateTimeZone('America/New_York')), '01m2dgj2zmptqedaa0cb7hmtdv'));
    }

    public function testRejectsMalformed(): void
    {
        $bad = [
            '',
            'not base64!',
            rtrim(strtr(base64_encode('2026-10-07 16:49:17'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode('2026-13-45 99:00:00|01m2dgj2zmptqedaa0cb7hmtdv'), '+/', '-_'), '='),
            rtrim(strtr(base64_encode('2026-10-07 16:49:17|NOTAULID'), '+/', '-_'), '='),
            str_repeat('A', 300),
        ];
        foreach ($bad as $cursor) {
            try {
                Cursor::decode($cursor);
                self::fail("accepted '{$cursor}'");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
```

`tests/Module/Blog/Feed/ItemSerializerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Source\AthenaAdapter;
use Xaraya\Tests\Module\Blog\Source\AthenaAdapterTest;

final class ItemSerializerTest extends TestCase
{
    public static function blog(?string $pattern = null, string $mode = 'mirror'): Blog
    {
        return new Blog(1, 'wyome', 'John Cox', '<p>About</p>', 'John Cox', 'https://www.wyome.com/blog/', 'https://www.wyome.com/blog/',
            $pattern, 'en', null, null, $mode, 'https://athenana.com/blog/wyome/feed.json', 'athena', 'remote', null, null, null, []);
    }

    public static function postFrom(PostRecord $r, string $id): Post
    {
        return new Post($id, 1, $r->itemId, $r->kind, $r->title, $r->url, $r->externalUrl, $r->contentHtml, $r->image,
            $r->datePublished, $r->dateModified, 'published', $r->doc, $r->tags);
    }

    public function testAthenaItemRoundTripsExactly(): void
    {
        $source = AthenaAdapterTest::song();
        $record = (new AthenaAdapter())->toRecord($source, new DateTimeImmutable());
        $post = self::postFrom($record, (string) $record->ulid);
        $item = (new ItemSerializer())->item(self::blog('https://www.wyome.com/blog/post.html?id={id}'), $post, 'http://xar.test');

        self::assertEquals($source, $item);
        self::assertSame(['id', 'url', 'external_url', 'title', 'content_html', 'date_published', 'date_modified', 'tags', '_video', '_source', '_athenana'], array_keys($item));
        self::assertSame(['kind', 'song', 'tags'], array_keys($item['_athenana']));
    }

    public function testUrlFallbacks(): void
    {
        $s = new ItemSerializer();
        $record = (new AthenaAdapter())->toRecord(AthenaAdapterTest::song(), new DateTimeImmutable());
        $post = self::postFrom($record, '01m3t19wn8reaww81zg1m47tjq');
        self::assertSame('https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq', $s->url(self::blog(), $post, 'http://xar.test'), 'stored source url');

        $native = new Post('01m3t19wn8reaww81zg1m47tjq', 1, 'http://xar.test/s/01m3t19wn8reaww81zg1m47tjq', 'note', 'N', null, null, '<p>x</p>', null,
            new DateTimeImmutable('2026-10-01T00:00:00Z'), null, 'published', [], []);
        self::assertSame('http://xar.test/s/01m3t19wn8reaww81zg1m47tjq', $s->url(self::blog(null, 'native'), $native, 'http://xar.test/'));
    }

    public function testCleanDropsEmptiesButKeepsFalseAndZero(): void
    {
        self::assertSame(
            ['a' => false, 'b' => 0, 'list' => ['x'], 'nested' => ['keep' => '0']],
            ItemSerializer::clean(['a' => false, 'b' => 0, 'n' => null, 'e' => '', 'empty' => [], 'list' => [null, 'x', ''], 'nested' => ['gone' => [], 'keep' => '0']]),
        );
    }

    public function testMediaRewriteSkipsIdUrlAndExternalUrl(): void
    {
        $img = 'https://athenana.com/p/abc';
        $post = new Post('01m3t19wn8reaww81zg1m47tjq', 1, $img, 'photo', 'P', $img, $img, null, $img,
            new DateTimeImmutable('2026-10-01T00:00:00Z'), null, 'published', ['_athenana' => ['images' => [['url' => $img, 'width' => 10]]]], []);
        $item = (new ItemSerializer())->item(self::blog(), $post, 'http://xar.test', [$img => 'http://xar.test/media/x.png']);
        self::assertSame($img, $item['id']);
        self::assertSame($img, $item['url']);
        self::assertSame($img, $item['external_url']);
        self::assertSame('http://xar.test/media/x.png', $item['image']);
        self::assertSame('http://xar.test/media/x.png', $item['_athenana']['images'][0]['url']);
    }
}
```

`tests/Module/Blog/Feed/FeedBuilderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\FeedBuilder;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;

final class FeedBuilderTest extends TestCase
{
    public function testEnvelope(): void
    {
        $blog = new Blog(1, 'wyome', 'John Cox', 'Notes, photos and the things worth saying something about.', 'John Cox',
            'https://www.wyome.com/blog/', 'https://www.wyome.com/blog/', null, 'en', 'https://athenana.com/img/apple-touch-icon.png',
            'https://athenana.com/favicon.ico', 'mirror', 'https://athenana.com/blog/wyome/feed.json', 'athena', 'remote', null, null, null,
            ['_athenana' => ['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe'], '_other' => 1]);
        $post = new Post('01m3t19wn8reaww81zg1m47tjq', 1, 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq', 'note', 'Hi', null, null, null, null,
            new DateTimeImmutable('2026-10-01T00:00:00Z'), null, 'published', [], []);

        $feed = (new FeedBuilder(new ItemSerializer()))->feed($blog, [$post], 'http://xar.test/blog/wyome/feed.json', 'http://xar.test/blog/wyome/feed.json?before=abc', 'http://xar.test');

        self::assertSame(
            ['version', 'title', 'description', 'home_page_url', 'feed_url', 'next_url', 'language', 'icon', 'favicon', 'authors', '_athenana', '_other', 'items'],
            array_keys($feed),
        );
        self::assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        self::assertSame([['name' => 'John Cox', 'url' => 'https://www.wyome.com/blog/']], $feed['authors']);
        self::assertSame(['subscribe_url' => 'https://athenana.com/blog/wyome/subscribe'], $feed['_athenana']);
        self::assertSame('https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq', $feed['items'][0]['id']);
        self::assertSame(['kind' => 'note'], $feed['items'][0]['_athenana']);
    }

    public function testEmptyFeedKeepsItemsAndDropsNextUrl(): void
    {
        $blog = new Blog(2, 'empty', 'Empty', null, 'Empty', null, null, null, 'en', null, null, 'native', null, null, 'remote', 'https://x/s/1', null, null, []);
        $feed = (new FeedBuilder(new ItemSerializer()))->feed($blog, [], 'http://xar.test/blog/empty/feed.json', null, 'http://xar.test');
        self::assertSame([], $feed['items']);
        self::assertArrayNotHasKey('next_url', $feed);
        self::assertSame(['pinned_id' => 'https://x/s/1'], $feed['_athenana']);
        self::assertSame([['name' => 'Empty']], $feed['authors']);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Feed`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`modules/blog/src/Feed/Cursor.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Support\Ulid;

/** Athena's keyset cursor: base64url, unpadded, of "Y-m-d H:i:s|<ulid>" in UTC. */
final class Cursor
{
    public static function encode(DateTimeImmutable $published, string $id): string
    {
        $raw = $published->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') . '|' . $id;

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{0: DateTimeImmutable, 1: string} */
    public static function decode(string $cursor): array
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $cursor) !== 1) {
            throw new InvalidArgumentException('Malformed cursor');
        }
        $padded = strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $raw = base64_decode($padded, true);
        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([0-9a-z]{26})$/D', $raw, $m) !== 1 || !Ulid::isValid($m[2])) {
            throw new InvalidArgumentException('Malformed cursor');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $m[1], new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $m[1]) {
            throw new InvalidArgumentException('Malformed cursor');
        }

        return [$date, $m[2]];
    }
}
```

`modules/blog/src/Feed/ItemSerializer.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;

final class ItemSerializer
{
    private const NEVER_REWRITE = ['id', 'url', 'external_url'];

    /**
     * @param array<string, string> $media source media URL => replacement URL
     * @return array<string, mixed>
     */
    public function item(Blog $blog, Post $post, string $appUrl, array $media = []): array
    {
        $doc = $post->doc;
        $athenana = is_array($doc['_athenana'] ?? null) ? $doc['_athenana'] : [];
        $tags = $doc['tags'] ?? array_column($post->tags, 'name');
        $attachments = $doc['attachments'] ?? null;
        unset($doc['_athenana'], $doc['tags'], $doc['attachments']);

        $item = [
            'id' => $post->itemId,
            'url' => $this->url($blog, $post, $appUrl),
            'external_url' => $post->externalUrl,
            'title' => $post->title,
            'content_html' => $post->contentHtml,
            'image' => $post->image,
            'date_published' => $post->datePublished->format(DATE_ATOM),
            'date_modified' => $post->dateModified?->format(DATE_ATOM),
            'tags' => $tags,
            'attachments' => $attachments,
        ] + $doc + ['_athenana' => ['kind' => $post->kind] + $athenana + ['tags' => $post->tags]];

        $item = self::clean($item);
        if ($media !== []) {
            foreach ($item as $key => $value) {
                if (!in_array($key, self::NEVER_REWRITE, true)) {
                    $item[$key] = self::rewrite($value, $media);
                }
            }
        }

        return $item;
    }

    public function url(Blog $blog, Post $post, string $appUrl): string
    {
        if ($blog->postUrlPattern !== null) {
            return str_replace('{id}', $post->id, $blog->postUrlPattern);
        }

        return $post->url ?? rtrim($appUrl, '/') . '/s/' . $post->id;
    }

    /**
     * Drops null, "" and [] recursively (Athena's clean()); keeps false and 0. Lists stay lists.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function clean(array $value): array
    {
        $out = [];
        foreach ($value as $key => $v) {
            if (is_array($v)) {
                $v = self::clean($v);
            }
            if ($v === null || $v === '' || $v === []) {
                continue;
            }
            $out[$key] = $v;
        }

        return array_is_list($value) ? array_values($out) : $out;
    }

    /** @param array<string, string> $media */
    private static function rewrite(mixed $value, array $media): mixed
    {
        if (is_string($value)) {
            return $media[$value] ?? $value;
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::rewrite($v, $media), $value);
        }

        return $value;
    }
}
```

`modules/blog/src/Feed/FeedBuilder.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;

final class FeedBuilder
{
    public const VERSION = 'https://jsonfeed.org/version/1.1';

    public function __construct(private readonly ItemSerializer $items) {}

    /**
     * @param list<Post> $posts
     * @param array<string, string> $media
     * @return array<string, mixed>
     */
    public function feed(Blog $blog, array $posts, string $feedUrl, ?string $nextUrl, string $appUrl, array $media = []): array
    {
        $extra = $blog->extra;
        $athenana = is_array($extra['_athenana'] ?? null) ? $extra['_athenana'] : [];
        unset($extra['_athenana']);

        $envelope = ItemSerializer::clean([
            'version' => self::VERSION,
            'title' => $blog->title,
            'description' => $blog->descriptionHtml,
            'home_page_url' => $blog->homePageUrl,
            'feed_url' => $feedUrl,
            'next_url' => $nextUrl,
            'language' => $blog->language,
            'icon' => $blog->icon,
            'favicon' => $blog->favicon,
            'authors' => [['name' => $blog->authorName, 'url' => $blog->authorUrl]],
            '_athenana' => ['pinned_id' => $blog->pinnedItemId] + $athenana,
        ] + $extra);
        $envelope['items'] = array_map(fn (Post $post): array => $this->items->item($blog, $post, $appUrl, $media), $posts);

        return $envelope;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Module/Blog/Feed`
Expected: PASS (9 tests). The round-trip test is the unit-level parity check. If it fails, fix the serialiser, never the expected source.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): add Athena cursor codec, item serialiser and feed envelope builder" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: HTTP fetcher, service provider and test feed helpers

**Files:**
- Create:
  - `modules/blog/src/Source/HttpFetcher.php`
  - `modules/blog/src/Source/FetchResult.php`
  - `modules/blog/src/Source/StreamFetcher.php`
  - `modules/blog/src/BlogServiceProvider.php`
  - `tests/Module/Blog/Support/FixtureFetcher.php`
  - `tests/Module/Blog/Support/FeedFactory.php`
  - `tests/Module/Blog/Source/fixture-server.php`
- Modify: `modules/blog/module.json` (add `"provider"`)
- Test: `tests/Module/Blog/Source/StreamFetcherTest.php`, `tests/Module/Blog/Support/FeedFactoryTest.php`

**Interfaces:**
- **Produces `interface HttpFetcher`:** `get(string $url, ?string $etag = null): FetchResult`. It sends `If-None-Match` when given an etag, and throws `RuntimeException` on a transport failure. An HTTP error status is a result, not an exception.
- **Produces `FetchResult`:**
  - readonly `int $status`, `string $body`, `?string $etag`, `?string $contentType`
  - `json(): array<string,mixed>`, which throws `RuntimeException` unless the body is a JSON object
- **Produces `StreamFetcher(string $userAgent = 'XarayaPhoenix/0.1', float $timeout = 15.0, int $maxBytes = 20_000_000)`.** It accepts only `http`/`https` URLs, throwing `InvalidArgumentException` for anything else, follows up to 5 redirects, and throws `RuntimeException` when the body is larger than `$maxBytes`.
- **Produces `BlogServiceProvider`**, which binds `HttpFetcher::class` to `StreamFetcher`. Tasks 6 and 8 add more bindings.
- **Produces the test-only `FixtureFetcher implements HttpFetcher`:**
  - constructor `array<string, FetchResult|callable(?string): FetchResult> $responses`
  - `on(string $url, FetchResult|callable $r): self`
  - public `array $requests` (`list<array{url: string, etag: ?string}>`)
  - `static json(array $feed, ?string $etag = null): FetchResult`
  - Unknown URLs return a 404 `FetchResult`.
- **Produces the test-only `FeedFactory`:**
  - `static items(int $count, string $base = 'https://src.test', string $newest = '2026-10-01 12:00:00'): list<array>` builds Athena-style items, newest first and one hour apart. Item `n` has id `{base}/s/{ulid}`, title `Post n`, `content_html` `<p>Body n</p>`, tags `['t' . (n % 3)]` and `_athenana.kind` `note`.
  - `static pages(list<array> $items, string $base = 'https://src.test', int $perPage = 50): array<string, array>` returns feed URL => page. Page 1 is `{base}/blog/src/feed.json` and page n is `…?page=n`. Every page has `title` `Source Blog` and `next_url` except the last.
  - `static fetcher(array $pages): FixtureFetcher`.

- [ ] **Step 1: Write the fixture server and the failing tests**

`tests/Module/Blog/Source/fixture-server.php` (a router script for `php -S`):
```php
<?php

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

switch ($path) {
    case '/feed.json':
        header('Content-Type: application/feed+json');
        header('ETag: "v1"');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"v1"') {
            http_response_code(304);

            return true;
        }
        echo json_encode([
            'version' => 'https://jsonfeed.org/version/1.1',
            'title' => 'Fixture',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'items' => [],
        ]);

        return true;
    case '/moved':
        header('Location: /feed.json', true, 301);

        return true;
    case '/boom':
        http_response_code(500);
        echo 'nope';

        return true;
    case '/big':
        echo str_repeat('x', 4096);

        return true;
}
http_response_code(404);

return true;
```

`tests/Module/Blog/Source/StreamFetcherTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\StreamFetcher;

final class StreamFetcherTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('no free port');
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        self::$base = "http://127.0.0.1:{$port}";
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixture-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::$server = is_resource($server) ? $server : null;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('fixture server did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    public function testFetchesJsonWithEtagAndUserAgent(): void
    {
        $result = (new StreamFetcher('PhoenixTest/1'))->get(self::$base . '/feed.json');
        self::assertSame(200, $result->status);
        self::assertSame('"v1"', $result->etag);
        self::assertSame('application/feed+json', $result->contentType);
        self::assertSame('PhoenixTest/1', $result->json()['ua']);
    }

    public function testConditionalRequestGets304(): void
    {
        $result = (new StreamFetcher())->get(self::$base . '/feed.json', '"v1"');
        self::assertSame(304, $result->status);
        self::assertSame('', $result->body);
    }

    public function testFollowsRedirectsAndReturnsErrorStatuses(): void
    {
        self::assertSame('Fixture', (new StreamFetcher())->get(self::$base . '/moved')->json()['title']);
        self::assertSame(500, (new StreamFetcher())->get(self::$base . '/boom')->status);
        self::assertSame(404, (new StreamFetcher())->get(self::$base . '/missing')->status);
    }

    public function testRejectsOversizedBodies(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds');
        (new StreamFetcher(maxBytes: 1024))->get(self::$base . '/big');
    }

    public function testRejectsNonHttpUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new StreamFetcher())->get('file:///etc/passwd');
    }

    public function testUnreachableHostThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new StreamFetcher(timeout: 2.0))->get('http://127.0.0.1:1/feed.json');
    }

    public function testJsonRejectsNonObjects(): void
    {
        foreach (['not json', '[1,2]', '"text"'] as $body) {
            try {
                (new FetchResult(200, $body))->json();
                self::fail("accepted {$body}");
            } catch (RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], (new FetchResult(200, '{}'))->json());
    }
}
```

`tests/Module/Blog/Support/FeedFactoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use PHPUnit\Framework\TestCase;

final class FeedFactoryTest extends TestCase
{
    public function testPagesChainAndFetcherServesThem(): void
    {
        $items = FeedFactory::items(120);
        self::assertCount(120, $items);
        self::assertSame('2026-10-01T12:00:00+00:00', $items[0]['date_published']);
        self::assertSame('2026-10-01T11:00:00+00:00', $items[1]['date_published']);

        $pages = FeedFactory::pages($items);
        self::assertSame(['https://src.test/blog/src/feed.json', 'https://src.test/blog/src/feed.json?page=2', 'https://src.test/blog/src/feed.json?page=3'], array_keys($pages));
        self::assertSame('https://src.test/blog/src/feed.json?page=2', $pages['https://src.test/blog/src/feed.json']['next_url']);
        self::assertArrayNotHasKey('next_url', $pages['https://src.test/blog/src/feed.json?page=3']);
        self::assertCount(20, $pages['https://src.test/blog/src/feed.json?page=3']['items']);

        $fetcher = FeedFactory::fetcher($pages);
        self::assertSame(200, $fetcher->get('https://src.test/blog/src/feed.json')->status);
        self::assertSame(404, $fetcher->get('https://src.test/other')->status);
        self::assertSame([['url' => 'https://src.test/blog/src/feed.json', 'etag' => null], ['url' => 'https://src.test/other', 'etag' => null]], $fetcher->requests);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Source/StreamFetcherTest.php tests/Module/Blog/Support/FeedFactoryTest.php`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`modules/blog/src/Source/HttpFetcher.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use RuntimeException;

interface HttpFetcher
{
    /** @throws RuntimeException on transport failure (HTTP error statuses are returned, not thrown) */
    public function get(string $url, ?string $etag = null): FetchResult;
}
```

`modules/blog/src/Source/FetchResult.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use JsonException;
use RuntimeException;

final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?string $etag = null,
        public readonly ?string $contentType = null,
    ) {}

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Response is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new RuntimeException('Response JSON is not an object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
```

`modules/blog/src/Source/StreamFetcher.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Source;

use InvalidArgumentException;
use RuntimeException;

final class StreamFetcher implements HttpFetcher
{
    public function __construct(
        private readonly string $userAgent = 'XarayaPhoenix/0.1',
        private readonly float $timeout = 15.0,
        private readonly int $maxBytes = 20_000_000,
    ) {}

    public function get(string $url, ?string $etag = null): FetchResult
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new InvalidArgumentException("Only http(s) URLs can be fetched: {$url}");
        }
        $headers = [
            'User-Agent: ' . $this->userAgent,
            'Accept: application/feed+json, application/json;q=0.9, */*;q=0.1',
        ];
        if ($etag !== null) {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $this->timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 5,
        ]]);

        $body = @file_get_contents($url, false, $context, 0, $this->maxBytes + 1);
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        if ($body === false) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new RuntimeException("Fetching {$url} failed: {$error}");
        }
        if (strlen($body) > $this->maxBytes) {
            throw new RuntimeException("Response from {$url} exceeds {$this->maxBytes} bytes");
        }
        [$status, $parsed] = self::parseHeaders($responseHeaders);

        return new FetchResult($status, $body, $parsed['etag'] ?? null, $parsed['content-type'] ?? null);
    }

    /**
     * Uses the last status line, so headers from redirect hops are discarded.
     *
     * @param array<int, string> $lines
     * @return array{0: int, 1: array<string, string>}
     */
    private static function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        if ($status === 0) {
            throw new RuntimeException('Response had no HTTP status line');
        }

        return [$status, $headers];
    }
}
```

`modules/blog/src/BlogServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\StreamFetcher;

final class BlogServiceProvider implements ServiceProvider
{
    public function register(Container $container): void
    {
        $container->set(HttpFetcher::class, static fn (): HttpFetcher => new StreamFetcher());
    }
}
```

In `modules/blog/module.json`, add after `"requires"`:
```json
  "provider": "Xaraya\\Module\\Blog\\BlogServiceProvider",
```

`tests/Module/Blog/Support/FixtureFetcher.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;

final class FixtureFetcher implements HttpFetcher
{
    /** @var list<array{url: string, etag: ?string}> */
    public array $requests = [];

    /** @param array<string, FetchResult|callable(?string): FetchResult> $responses */
    public function __construct(private array $responses = []) {}

    public function on(string $url, FetchResult|callable $response): self
    {
        $this->responses[$url] = $response;

        return $this;
    }

    public function get(string $url, ?string $etag = null): FetchResult
    {
        $this->requests[] = ['url' => $url, 'etag' => $etag];
        $response = $this->responses[$url] ?? new FetchResult(404, '');

        return $response instanceof FetchResult ? $response : $response($etag);
    }

    /** @param array<string, mixed> $feed */
    public static function json(array $feed, ?string $etag = null): FetchResult
    {
        return new FetchResult(200, json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $etag, 'application/feed+json');
    }
}
```

`tests/Module/Blog/Support/FeedFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Support\Ulid;

final class FeedFactory
{
    /** @return list<array<string, mixed>> newest first, one hour apart */
    public static function items(int $count, string $base = 'https://src.test', string $newest = '2026-10-01 12:00:00'): array
    {
        $start = new DateTimeImmutable($newest, new DateTimeZone('UTC'));
        $items = [];
        for ($n = 0; $n < $count; $n++) {
            $published = $start->modify("-{$n} hours");
            $ulid = Ulid::generate($published->getTimestamp() * 1000);
            $tag = 't' . ($n % 3);
            $items[] = [
                'id' => "{$base}/s/{$ulid}",
                'url' => "{$base}/s/{$ulid}",
                'title' => "Post {$n}",
                'content_html' => "<p>Body {$n}</p>",
                'date_published' => $published->format(DATE_ATOM),
                'date_modified' => $published->format(DATE_ATOM),
                'tags' => [$tag],
                '_athenana' => ['kind' => 'note', 'tags' => [['name' => $tag, 'slug' => $tag]]],
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, array<string, mixed>> page URL => feed page
     */
    public static function pages(array $items, string $base = 'https://src.test', int $perPage = 50): array
    {
        $first = "{$base}/blog/src/feed.json";
        $chunks = array_chunk($items, $perPage) ?: [[]];
        $pages = [];
        foreach ($chunks as $i => $chunk) {
            $url = $i === 0 ? $first : $first . '?page=' . ($i + 1);
            $page = [
                'version' => 'https://jsonfeed.org/version/1.1',
                'title' => 'Source Blog',
                'home_page_url' => "{$base}/blog/src",
                'feed_url' => $first,
                'authors' => [['name' => 'Source Author', 'url' => "{$base}/blog/src"]],
            ];
            if ($i < count($chunks) - 1) {
                $page['next_url'] = $first . '?page=' . ($i + 2);
            }
            $page['items'] = $chunk;
            $pages[$url] = $page;
        }

        return $pages;
    }

    /** @param array<string, array<string, mixed>> $pages */
    public static function fetcher(array $pages): FixtureFetcher
    {
        $fetcher = new FixtureFetcher();
        foreach ($pages as $url => $page) {
            $fetcher->on($url, FixtureFetcher::json($page));
        }

        return $fetcher;
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Module/Blog/Source tests/Module/Blog/Support`
Expected: PASS. Also run `vendor/bin/phpunit tests/Module/Blog` to confirm the provider boots.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): add stream-based HTTP fetcher, service provider and feed test helpers" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Syncer and `blog:sync`

**Files:**
- Create: `modules/blog/src/Sync/SyncReport.php`, `modules/blog/src/Sync/SyncLock.php`, `modules/blog/src/Sync/Syncer.php`, `modules/blog/src/Cli/BlogSyncCommand.php`
- Modify:
  - `modules/blog/src/BlogServiceProvider.php` (bind `SyncLock`)
  - `modules/blog/module.json` (add the command)
- Test: `tests/Module/Blog/Sync/SyncerTest.php`, `tests/Module/Blog/Sync/SyncLockTest.php`, `tests/Module/Blog/Cli/BlogSyncCommandTest.php`

**Interfaces:**
- **Consumes:**
  - `BlogRepository` (`find`, `all`, `update`) and `PostRepository` (`save`, `liveItemIds`, `tombstone`)
  - `AdapterRegistry::get`, `HttpFetcher`/`FetchResult`
  - from the kernel: `Connection::transaction`, `EventDispatcher::dispatch`, `ItemCreated`/`ItemUpdated`/`ItemDeleted`, `LoggerInterface`
  - for tests: `FeedFactory` and `FixtureFetcher`
- **Produces `SyncReport`:**
  - public counters `pages`, `created`, `updated`, `unchanged`, `skipped`, `deleted` (int)
  - public flags `notModified`, `alreadyRunning`, `valveTripped` (bool)
  - readonly `string $handle` and `bool $full`
  - `ok(): bool` (false only when the valve tripped) and `summary(): string`
- **Produces `SyncLock(string $directory)`:** `acquire(string $name): bool` (non-blocking `flock`) and `release(string $name): void`.
- **Produces `Syncer`:**
  - Constructor (autowired): `Connection`, `BlogRepository`, `PostRepository`, `AdapterRegistry`, `HttpFetcher`, `EventDispatcher`, `LoggerInterface`, `SyncLock`.
  - `sync(Blog $blog, bool $full, DateTimeImmutable $now): SyncReport`. It throws `LogicException` for a non-mirror blog, and `RuntimeException` on an HTTP error or an invalid feed; in those cases nothing is tombstoned.
  - Events: module `blog`, itemtype `post`, the post id, and item `['blog' => handle, 'item_id' => …]`.
- **Produces the provider binding:** `SyncLock` uses directory `config('blog.locks')`, defaulting to `App::path('var/locks')`.
- **Produces the `blog:sync <handle>|--all [--full]` command.** It prints one summary line per blog, and exits 1 if any blog failed or tripped the safety valve.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Sync/SyncLockTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Sync;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Sync\SyncLock;

final class SyncLockTest extends TestCase
{
    public function testSecondHolderIsRefusedUntilRelease(): void
    {
        $dir = sys_get_temp_dir() . '/xar-locks-' . bin2hex(random_bytes(4));
        $a = new SyncLock($dir);
        $b = new SyncLock($dir);
        self::assertTrue($a->acquire('wyome'));
        self::assertFalse($b->acquire('wyome'));
        self::assertTrue($b->acquire('other'));
        $a->release('wyome');
        self::assertTrue($b->acquire('wyome'));
        $b->release('wyome');
        $b->release('other');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
```

`tests/Module/Blog/Sync/SyncerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Sync;

use LogicException;
use RuntimeException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\SyncLock;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class SyncerTest extends BlogTestCase
{
    private const SOURCE = 'https://src.test/blog/src/feed.json';

    private App $app;
    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->blog = $this->blogs($this->app)->create([
            'handle' => 'mirror', 'mode' => 'mirror', 'source_url' => self::SOURCE, 'source_format' => 'athena',
        ], $this->now());
    }

    /** Fresh app per sync so the container picks up the given fetcher. */
    private function syncer(FixtureFetcher $fetcher): Syncer
    {
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, $fetcher);

        return $app->container()->get(Syncer::class);
    }

    private function blog(): Blog
    {
        return $this->blogs($this->app)->find('mirror') ?? throw new RuntimeException('blog missing');
    }

    private function posts(): PostRepository
    {
        return $this->app->container()->get(PostRepository::class);
    }

    public function testFullSyncImportsEveryPageAndUpdatesMeta(): void
    {
        $items = FeedFactory::items(120);
        $fetcher = FeedFactory::fetcher(FeedFactory::pages($items));
        $report = $this->syncer($fetcher)->sync($this->blog(), true, $this->now());

        self::assertSame(3, $report->pages);
        self::assertSame(120, $report->created);
        self::assertSame(0, $report->deleted);
        self::assertTrue($report->ok());
        self::assertSame(120, $this->posts()->countLive($this->blog->id));
        $blog = $this->blog();
        self::assertSame('Source Blog', $blog->title);
        self::assertSame('Source Author', $blog->authorName);
        self::assertNotNull($blog->lastSyncedAt);
        self::assertSame(3, count($fetcher->requests));
        self::assertNull($fetcher->requests[0]['etag'], 'full sync never sends the etag');
    }

    public function testQuickSyncStopsAtFirstUnchangedPage(): void
    {
        $items = FeedFactory::items(120);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());

        $fresh = FeedFactory::items(1, newest: '2026-10-02 12:00:00');
        $fetcher = FeedFactory::fetcher(FeedFactory::pages([...$fresh, ...$items]));
        $report = $this->syncer($fetcher)->sync($this->blog(), false, $this->now());
        self::assertSame(1, $report->created);
        self::assertSame(2, $report->pages, 'page 1 changed, page 2 was all unchanged, so it stopped');
        self::assertSame(2, count($fetcher->requests));

        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages([...$fresh, ...$items])))->sync($this->blog(), false, $this->now());
        self::assertSame(1, $report->pages);
        self::assertSame(0, $report->created + $report->updated);
    }

    public function testEditsDeepInTheFeedNeedAFullSync(): void
    {
        $items = FeedFactory::items(120);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        $items[110]['title'] = 'Edited';
        $pages = FeedFactory::pages($items);

        self::assertSame(0, $this->syncer(FeedFactory::fetcher($pages))->sync($this->blog(), false, $this->now())->updated);
        $report = $this->syncer(FeedFactory::fetcher($pages))->sync($this->blog(), true, $this->now());
        self::assertSame(1, $report->updated);
    }

    public function testNotModifiedFirstPageEndsQuickSync(): void
    {
        $items = FeedFactory::items(3);
        $page = FeedFactory::pages($items)[self::SOURCE];
        $fetcher = (new FixtureFetcher())->on(self::SOURCE, fn (?string $etag): FetchResult => $etag === '"e1"'
            ? new FetchResult(304, '', '"e1"')
            : FixtureFetcher::json($page, '"e1"'));
        $this->syncer($fetcher)->sync($this->blog(), false, $this->now());
        self::assertSame('"e1"', $this->blog()->sourceEtag);

        $report = $this->syncer($fetcher)->sync($this->blog(), false, $this->now());
        self::assertTrue($report->notModified);
        self::assertSame('"e1"', $fetcher->requests[1]['etag']);
    }

    public function testFullSyncTombstonesMissingItemsAndRestoresThem(): void
    {
        $items = FeedFactory::items(10);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());

        $without = $items;
        unset($without[4]);
        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages(array_values($without))))->sync($this->blog(), true, $this->now());
        self::assertSame(1, $report->deleted);
        self::assertSame(9, $this->posts()->countLive($this->blog->id));

        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        self::assertSame(1, $report->updated);
        self::assertSame(10, $this->posts()->countLive($this->blog->id));
    }

    public function testSafetyValveRefusesMassDeletion(): void
    {
        $items = FeedFactory::items(120);
        $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());

        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages(array_slice($items, 0, 50))))->sync($this->blog(), true, $this->now());
        self::assertTrue($report->valveTripped);
        self::assertFalse($report->ok());
        self::assertSame(0, $report->deleted);
        self::assertSame(120, $this->posts()->countLive($this->blog->id));
        self::assertStringContainsString('SAFETY VALVE', $report->summary());
    }

    public function testHttpErrorMidWalkKeepsEarlierPagesAndDeletesNothing(): void
    {
        $items = FeedFactory::items(120);
        $pages = FeedFactory::pages($items);
        $fetcher = FeedFactory::fetcher($pages)->on(self::SOURCE . '?page=2', new FetchResult(500, 'down'));
        try {
            $this->syncer($fetcher)->sync($this->blog(), true, $this->now());
            self::fail('expected failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
        }
        self::assertSame(50, $this->posts()->countLive($this->blog->id), 'page 1 was committed');
    }

    public function testMalformedItemsAreSkipped(): void
    {
        $items = FeedFactory::items(3);
        $items[1] = ['title' => 'no id'];
        $report = $this->syncer(FeedFactory::fetcher(FeedFactory::pages($items)))->sync($this->blog(), true, $this->now());
        self::assertSame(2, $report->created);
        self::assertSame(1, $report->skipped);
    }

    public function testRejectsNonJsonFeed(): void
    {
        $fetcher = (new FixtureFetcher())->on(self::SOURCE, FixtureFetcher::json(['hello' => 'world']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a JSON Feed');
        $this->syncer($fetcher)->sync($this->blog(), true, $this->now());
    }

    public function testLockedBlogIsSkipped(): void
    {
        $lock = new SyncLock($this->tmp . '/locks');
        self::assertTrue($lock->acquire('mirror'));
        $report = $this->syncer(FeedFactory::fetcher([]))->sync($this->blog(), true, $this->now());
        self::assertTrue($report->alreadyRunning);
        $lock->release('mirror');
    }

    public function testEventsAreDispatched(): void
    {
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $seen = [];
        $events = $app->container()->get(EventDispatcher::class);
        $events->listen(ItemCreated::class, function (ItemCreated $e) use (&$seen): void { $seen[] = "created:{$e->module}:{$e->itemtype}"; });
        $events->listen(ItemDeleted::class, function () use (&$seen): void { $seen[] = 'deleted'; });
        $app->container()->get(Syncer::class)->sync($this->blog(), true, $this->now());
        self::assertSame(['created:blog:post', 'created:blog:post', 'created:blog:post'], $seen);
    }

    public function testNativeBlogCannotSync(): void
    {
        $native = $this->blogs($this->app)->create(['handle' => 'native', 'mode' => 'native'], $this->now());
        $this->expectException(LogicException::class);
        $this->syncer(new FixtureFetcher())->sync($native, false, $this->now());
    }
}
```

`tests/Module/Blog/Cli/BlogSyncCommandTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class BlogSyncCommandTest extends BlogTestCase
{
    public function testSyncAllMirrors(): void
    {
        $this->enableBlog();
        $app = $this->app();
        $this->xar(['blog:create', 'src', '--mode=mirror', '--format=athena', '--source=https://src.test/blog/src/feed.json'], $app);
        $this->xar(['blog:create', 'mine', '--mode=native'], $app);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(5))));

        [$code, $out] = $this->xar(['blog:sync', '--all', '--full'], $app);
        self::assertSame(0, $code);
        self::assertStringContainsString('src: 1 page(s), 5 created, 0 updated, 0 unchanged, 0 skipped, 0 deleted', $out);
        self::assertStringNotContainsString('mine', $out);
    }

    public function testErrors(): void
    {
        $app = $this->enableBlog();
        [$code, , $err] = $this->xar(['blog:sync'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar blog:sync', $err);

        [$code, , $err] = $this->xar(['blog:sync', 'ghost'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'ghost'", $err);

        $this->xar(['blog:create', 'mine', '--mode=native'], $app);
        [$code, , $err] = $this->xar(['blog:sync', 'mine'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('not a mirror blog', $err);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Sync tests/Module/Blog/Cli/BlogSyncCommandTest.php`
Expected: errors, classes not found.

- [ ] **Step 3: Implement**

`modules/blog/src/Sync/SyncReport.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Sync;

final class SyncReport
{
    public int $pages = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $skipped = 0;
    public int $deleted = 0;
    public bool $notModified = false;
    public bool $alreadyRunning = false;
    public bool $valveTripped = false;

    public function __construct(public readonly string $handle, public readonly bool $full) {}

    public function ok(): bool
    {
        return !$this->valveTripped;
    }

    public function summary(): string
    {
        if ($this->alreadyRunning) {
            return "{$this->handle}: already running, skipped";
        }
        if ($this->notModified) {
            return "{$this->handle}: not modified";
        }
        $line = sprintf(
            '%s: %d page(s), %d created, %d updated, %d unchanged, %d skipped, %d deleted',
            $this->handle, $this->pages, $this->created, $this->updated, $this->unchanged, $this->skipped, $this->deleted,
        );

        return $this->valveTripped
            ? $line . ' - SAFETY VALVE: the full walk saw under half of the live items, so nothing was deleted'
            : $line;
    }
}
```

`modules/blog/src/Sync/SyncLock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Sync;

use RuntimeException;

final class SyncLock
{
    /** @var array<string, resource> */
    private array $handles = [];

    public function __construct(private readonly string $directory) {}

    public function acquire(string $name): bool
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Cannot create lock directory {$this->directory}");
        }
        $handle = @fopen($this->directory . '/blog-' . $name . '.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open lock file for '{$name}'");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handles[$name] = $handle;

        return true;
    }

    public function release(string $name): void
    {
        if (!isset($this->handles[$name])) {
            return;
        }
        flock($this->handles[$name], LOCK_UN);
        fclose($this->handles[$name]);
        unset($this->handles[$name]);
    }
}
```

`modules/blog/src/Sync/Syncer.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Sync;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Post\SaveResult;
use Xaraya\Module\Blog\Source\AdapterRegistry;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\SourceAdapter;

final class Syncer
{
    public const MAX_PAGES = 10_000;

    public function __construct(
        private readonly Connection $db,
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly AdapterRegistry $adapters,
        private readonly HttpFetcher $fetcher,
        private readonly EventDispatcher $events,
        private readonly LoggerInterface $logger,
        private readonly SyncLock $lock,
    ) {}

    public function sync(Blog $blog, bool $full, DateTimeImmutable $now): SyncReport
    {
        if (!$blog->isMirror() || $blog->sourceUrl === null || $blog->sourceFormat === null) {
            throw new LogicException("Blog '{$blog->handle}' is not a mirror blog");
        }
        $report = new SyncReport($blog->handle, $full);
        if (!$this->lock->acquire($blog->handle)) {
            $report->alreadyRunning = true;

            return $report;
        }
        try {
            $adapter = $this->adapters->get($blog->sourceFormat);
            $url = $blog->sourceUrl;
            $result = $this->fetcher->get($url, $full ? null : $blog->sourceEtag);
            if ($result->status === 304) {
                $report->notModified = true;
                $this->blogs->update($blog, ['last_synced_at' => $now], $now);

                return $report;
            }
            /** @var array<string, true> $seen */
            $seen = [];
            $visited = [];
            while (true) {
                if ($result->status !== 200) {
                    throw new RuntimeException("Fetching {$url} returned HTTP {$result->status}");
                }
                $feed = $result->json();
                $version = $feed['version'] ?? null;
                $items = $feed['items'] ?? null;
                if (!is_string($version) || !str_starts_with($version, 'https://jsonfeed.org/version/1') || !is_array($items)) {
                    throw new RuntimeException("{$url} is not a JSON Feed");
                }
                if ($report->pages === 0) {
                    $blog = $this->blogs->update($blog, $adapter->feedMeta($feed) + ['source_etag' => $result->etag], $now);
                }
                $changed = $this->syncPage($blog, $adapter, $items, $now, $report, $seen);
                $report->pages++;
                $visited[$url] = true;
                $next = $feed['next_url'] ?? null;
                if ((!$full && $changed === 0) || !is_string($next) || $next === '' || isset($visited[$next]) || $report->pages >= self::MAX_PAGES) {
                    break;
                }
                $url = $next;
                $result = $this->fetcher->get($url);
            }
            if ($full) {
                $this->tombstoneMissing($blog, $seen, $now, $report);
            }
            $this->blogs->update($blog, ['last_synced_at' => $now] + ($full ? ['last_full_sync_at' => $now] : []), $now);

            return $report;
        } finally {
            $this->lock->release($blog->handle);
        }
    }

    /**
     * @param array<mixed> $items
     * @param array<string, true> $seen
     * @return int how many items were created or updated
     */
    private function syncPage(Blog $blog, SourceAdapter $adapter, array $items, DateTimeImmutable $now, SyncReport $report, array &$seen): int
    {
        /** @var array<string, PostRecord> $records */
        $records = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $report->skipped++;
                $this->logger->warning('Skipping item {index} from {blog}: not an object', ['index' => $index, 'blog' => $blog->handle]);
                continue;
            }
            try {
                $record = $adapter->toRecord($item, $now);
            } catch (InvalidArgumentException $e) {
                $report->skipped++;
                $this->logger->warning('Skipping item {index} from {blog}: {reason}', ['index' => $index, 'blog' => $blog->handle, 'reason' => $e->getMessage()]);
                continue;
            }
            if (isset($records[$record->itemId])) {
                $this->logger->warning('Item {id} appears twice on one page of {blog}; the last one wins', ['id' => $record->itemId, 'blog' => $blog->handle]);
            }
            $records[$record->itemId] = $record;
        }

        /** @var array<string, SaveResult> $results */
        $results = $this->db->transaction(function () use ($blog, $records, $now): array {
            $out = [];
            foreach ($records as $itemId => $record) {
                $out[(string) $itemId] = $this->posts->save($blog->id, $record, $now);
            }

            return $out;
        });

        $changed = 0;
        foreach ($results as $itemId => $result) {
            $itemId = (string) $itemId;
            $seen[$itemId] = true;
            if ($result->status === SaveResult::UNCHANGED) {
                $report->unchanged++;
                continue;
            }
            $changed++;
            $payload = ['blog' => $blog->handle, 'item_id' => $itemId];
            if ($result->status === SaveResult::CREATED) {
                $report->created++;
                $this->events->dispatch(new ItemCreated('blog', 'post', $result->id, $payload));
            } else {
                $report->updated++;
                $this->events->dispatch(new ItemUpdated('blog', 'post', $result->id, $payload));
            }
        }

        return $changed;
    }

    /** @param array<string, true> $seen */
    private function tombstoneMissing(Blog $blog, array $seen, DateTimeImmutable $now, SyncReport $report): void
    {
        $live = $this->posts->liveItemIds($blog->id);
        if ($live !== [] && count($seen) < 0.5 * count($live)) {
            $report->valveTripped = true;
            $this->logger->error('Full sync of {blog} saw {seen} of {live} live items; deleting nothing', [
                'blog' => $blog->handle, 'seen' => count($seen), 'live' => count($live),
            ]);

            return;
        }
        $missing = array_values(array_diff($live, array_map('strval', array_keys($seen))));
        foreach ($this->posts->tombstone($blog->id, $missing, $now) as $id) {
            $report->deleted++;
            $this->events->dispatch(new ItemDeleted('blog', 'post', $id, ['blog' => $blog->handle]));
        }
    }
}
```

`modules/blog/src/Cli/BlogSyncCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Sync\Syncer;

final class BlogSyncCommand extends Command
{
    public function __construct(private readonly BlogRepository $blogs, private readonly Syncer $syncer) {}

    public function name(): string
    {
        return 'blog:sync';
    }

    public function description(): string
    {
        return 'Sync mirror blogs from their source feeds (quick by default, --full walks every page)';
    }

    public function usage(): string
    {
        return 'blog:sync <handle>|--all [--full]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null && !$input->flag('all')) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $targets = $handle !== null
            ? [$this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'")]
            : array_values(array_filter($this->blogs->all(), static fn (Blog $blog): bool => $blog->isMirror()));
        $code = 0;
        foreach ($targets as $blog) {
            try {
                $report = $this->syncer->sync($blog, $input->flag('full'), new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $output->line($report->summary());
                if (!$report->ok()) {
                    $code = 1;
                }
            } catch (Throwable $e) {
                $output->error("{$blog->handle}: {$e->getMessage()}");
                $code = 1;
            }
        }

        return $code;
    }
}
```

In `modules/blog/src/BlogServiceProvider.php`, add this inside `register()`, together with the imports `Xaraya\Kernel\App`, `Xaraya\Kernel\Config\Config` and `Xaraya\Module\Blog\Sync\SyncLock`:
```php
        $container->set(SyncLock::class, static fn (Container $c): SyncLock => new SyncLock(
            (string) $c->get(Config::class)->get('blog.locks', $c->get(App::class)->path('var/locks')),
        ));
```

In `modules/blog/module.json`, add `"Xaraya\\Module\\Blog\\Cli\\BlogSyncCommand"` to `commands`.

- [ ] **Step 4: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Module/Blog`, then repeat with the MySQL env vars.
Expected: PASS on both.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): add mirror sync with quick/full walks, tombstones, safety valve and locking" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Serving: JSON Feed, single item and RSS

**Files:**
- Create:
  - `modules/blog/src/Routes.php`
  - `modules/blog/src/Http/CacheHeaders.php`
  - `modules/blog/src/Http/FeedController.php`
  - `modules/blog/src/Http/PostController.php`
  - `modules/blog/src/Feed/RssRenderer.php`
- Modify: `modules/blog/module.json` (add `"routes"`)
- Test: `tests/Module/Blog/Feed/RssRendererTest.php`, `tests/Module/Blog/Http/FeedHttpTest.php`

**Interfaces:**
- **Consumes:**
  - `BlogRepository`, `PostRepository::page/find`, `Cursor`, `FeedBuilder`, `ItemSerializer`, `Syncer` (seeding in tests)
  - from the kernel: `UrlGenerator`, `Config`, `Controller::json`, `HttpException`, `NotFound`
- **Produces these routes:**

  | Name | Method and path | Action | Middleware |
  |---|---|---|---|
  | `blog.feed.json` | `GET /blog/{handle:[a-z0-9_-]{2,32}}/feed.json` | `FeedController::feedJson` | `cors`, `conditional` |
  | `blog.feed.xml` | `GET /blog/{handle:[a-z0-9_-]{2,32}}/feed.xml` | `FeedController::feedXml` | `cors`, `conditional` |
  | `blog.post.json` | `GET /s/{id:[0-9a-hjkmnp-tv-z]{26}}.json` | `PostController::show` | `cors`, `conditional` |

  Action names avoid clashing with `Controller::json()`.
- **Produces `CacheHeaders::apply(ResponseInterface, list<Post>): ResponseInterface`.** It sets `Cache-Control: public, max-age=60`, plus `Last-Modified` (RFC 7231, GMT) from the newest `date_modified ?? date_published`.
- **Produces `RssRenderer(ItemSerializer)`:**
  - `render(Blog $blog, list<Post> $posts, string $selfUrl, string $appUrl, array<string,string> $media = []): string`
  - `static xmlSafe(string): string`
- **Produces `FeedController::PAGE_SIZE = 50`.**
- **Errors:** a malformed `before` returns 400 (`HttpException(400, 'Invalid before cursor')`). An unknown handle, or a post that is unknown, deleted or scheduled for the future, returns 404.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Feed/RssRendererTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Feed;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Feed\RssRenderer;
use Xaraya\Module\Blog\Post\Post;

final class RssRendererTest extends TestCase
{
    public function testRendersValidRss(): void
    {
        $blog = ItemSerializerTest::blog('https://www.wyome.com/blog/post.html?id={id}');
        $post = new Post('01m3t19wn8reaww81zg1m47tjq', 1, 'https://athenana.com/s/01m3t19wn8reaww81zg1m47tjq', 'photo',
            "Fish & chips \x01", null, null, '<p>Tasty</p>', 'https://athenana.com/p/img',
            new DateTimeImmutable('2026-10-01T12:00:00Z'), null, 'published', [], [['name' => 'Food', 'slug' => 'food']]);

        $xml = (new RssRenderer(new ItemSerializer()))->render($blog, [$post], 'http://xar.test/blog/wyome/feed.xml', 'http://xar.test');
        $rss = simplexml_load_string($xml);
        self::assertNotFalse($rss);
        $channel = $rss->channel;
        self::assertSame('John Cox', (string) $channel->title);
        self::assertSame('https://www.wyome.com/blog/', (string) $channel->link);
        self::assertSame('About', (string) $channel->description);
        $item = $channel->item[0];
        self::assertSame('Fish & chips ', (string) $item->title, 'control characters stripped');
        self::assertSame('https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq', (string) $item->link);
        self::assertSame('true', (string) $item->guid['isPermaLink']);
        self::assertStringContainsString('<p>Tasty</p><p><a href="https://www.wyome.com/blog/post.html?id=01m3t19wn8reaww81zg1m47tjq">Permalink</a></p>', (string) $item->description);
        self::assertSame('Thu, 01 Oct 2026 12:00:00 +0000', (string) $item->pubDate);
        self::assertSame('https://www.wyome.com/blog/tag/food', (string) $item->category['domain']);
        self::assertSame('Food', (string) $item->category);
        self::assertSame('John Cox', (string) $item->children('http://purl.org/dc/elements/1.1/')->creator);
        self::assertSame('https://athenana.com/p/img', (string) $item->children('http://search.yahoo.com/mrss/')->content->attributes()['url']);
        self::assertSame('http://xar.test/blog/wyome/feed.xml', (string) $channel->children('http://www.w3.org/2005/Atom')->link->attributes()['href']);
    }
}
```

`tests/Module/Blog/Http/FeedHttpTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class FeedHttpTest extends BlogTestCase
{
    private function seeded(int $count): App
    {
        $this->enableBlog();
        $app = $this->app();
        $blog = $this->blogs($app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena',
        ], $this->now());
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items($count))));
        $app->container()->get(Syncer::class)->sync($blog, true, $this->now());

        return $this->app();
    }

    /** @param array<string, string> $headers */
    private function get(App $app, string $uri, array $headers = [], string $method = 'GET'): ResponseInterface
    {
        $request = new ServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    /** @return array<string, mixed> */
    private static function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPagingWalksEveryItemOnceNewestFirst(): void
    {
        $app = $this->seeded(120);
        $response = $this->get($app, '/blog/src/feed.json');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/feed+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertSame('Thu, 01 Oct 2026 12:00:00 GMT', $response->getHeaderLine('Last-Modified'));

        $feed = self::body($response);
        self::assertSame('https://jsonfeed.org/version/1.1', $feed['version']);
        self::assertSame('Source Blog', $feed['title']);
        self::assertSame('http://xar.test/blog/src/feed.json', $feed['feed_url']);
        self::assertStringStartsWith('http://xar.test/blog/src/feed.json?before=', $feed['next_url']);

        $titles = [];
        $pages = 0;
        while (true) {
            $pages++;
            foreach ($feed['items'] as $item) {
                $titles[] = $item['title'];
            }
            if (!isset($feed['next_url'])) {
                break;
            }
            $next = parse_url($feed['next_url']);
            $feed = self::body($this->get($app, $next['path'] . '?' . $next['query']));
        }
        self::assertSame(3, $pages);
        self::assertSame(array_map(fn (int $n): string => "Post {$n}", range(0, 119)), $titles);
    }

    public function testConditionalGetAndPreflight(): void
    {
        $app = $this->seeded(3);
        $first = $this->get($app, '/blog/src/feed.json');
        $again = $this->get($app, '/blog/src/feed.json', ['If-None-Match' => $first->getHeaderLine('ETag')]);
        self::assertSame(304, $again->getStatusCode());

        $preflight = $this->get($app, '/blog/src/feed.json', ['Access-Control-Request-Method' => 'GET'], 'OPTIONS');
        self::assertSame(204, $preflight->getStatusCode());
        self::assertSame('*', $preflight->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testErrorsAreJsonWithCors(): void
    {
        $app = $this->seeded(3);
        $bad = $this->get($app, '/blog/src/feed.json?before=not-a-cursor');
        self::assertSame(400, $bad->getStatusCode());
        self::assertSame('*', $bad->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(400, self::body($bad)['error']['status']);

        $missing = $this->get($app, '/blog/nope/feed.json');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('*', $missing->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testSingleItemAndHiddenPosts(): void
    {
        $app = $this->seeded(3);
        $feed = self::body($this->get($app, '/blog/src/feed.json'));
        $first = $feed['items'][0];
        $id = substr($first['id'], -26);

        $single = $this->get($app, "/s/{$id}.json");
        self::assertSame(200, $single->getStatusCode());
        self::assertSame('application/json', $single->getHeaderLine('Content-Type'));
        self::assertSame('*', $single->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertEquals($first, self::body($single));

        $posts = $app->container()->get(PostRepository::class);
        $blog = $this->blogs($app)->find('src');
        self::assertNotNull($blog);
        $future = Ulid::generate();
        $posts->save($blog->id, new PostRecord("https://src.test/s/{$future}", $future, 'note', 'Later', null, null, null, null,
            new DateTimeImmutable('2099-01-01', new DateTimeZone('UTC')), null, [], [], sha1('later')), $this->now());
        $posts->tombstone($blog->id, [$first['id']], $this->now());

        $titles = array_column(self::body($this->get($app, '/blog/src/feed.json'))['items'], 'title');
        self::assertSame(['Post 1', 'Post 2'], $titles);
        self::assertSame(404, $this->get($app, "/s/{$future}.json")->getStatusCode());
        self::assertSame(404, $this->get($app, "/s/{$id}.json")->getStatusCode());
        self::assertSame(404, $this->get($app, '/s/' . Ulid::generate() . '.json')->getStatusCode());
    }

    public function testRss(): void
    {
        $app = $this->seeded(60);
        $response = $this->get($app, '/blog/src/feed.xml');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/rss+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $rss = simplexml_load_string((string) $response->getBody());
        self::assertNotFalse($rss);
        self::assertCount(50, $rss->channel->item);
        self::assertSame('Post 0', (string) $rss->channel->item[0]->title);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Feed/RssRendererTest.php tests/Module/Blog/Http`
Expected: errors (classes missing) and 404s.

- [ ] **Step 3: Implement**

`modules/blog/src/Http/CacheHeaders.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Module\Blog\Post\Post;

final class CacheHeaders
{
    /** @param list<Post> $posts */
    public static function apply(ResponseInterface $response, array $posts): ResponseInterface
    {
        $latest = null;
        foreach ($posts as $post) {
            $time = $post->dateModified ?? $post->datePublished;
            if ($latest === null || $time > $latest) {
                $latest = $time;
            }
        }
        $response = $response->withHeader('Cache-Control', 'public, max-age=60');

        return $latest === null
            ? $response
            : $response->withHeader('Last-Modified', $latest->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s') . ' GMT');
    }
}
```

`modules/blog/src/Feed/RssRenderer.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Feed;

use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Support\Text;

final class RssRenderer
{
    public function __construct(private readonly ItemSerializer $items) {}

    /**
     * @param list<Post> $posts
     * @param array<string, string> $media
     */
    public function render(Blog $blog, array $posts, string $selfUrl, string $appUrl, array $media = []): string
    {
        $home = $blog->homePageUrl ?? rtrim($appUrl, '/') . '/blog/' . $blog->handle;
        $x = static fn (string $s): string => htmlspecialchars(self::xmlSafe($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:media="http://search.yahoo.com/mrss/">' . "\n<channel>\n"
            . '<title>' . $x($blog->title) . "</title>\n"
            . '<link>' . $x($home) . "</link>\n"
            . '<description>' . $x(Text::plain($blog->descriptionHtml ?? '')) . "</description>\n"
            . '<language>' . $x($blog->language) . "</language>\n"
            . '<atom:link href="' . $x($selfUrl) . '" rel="self" type="application/rss+xml"/>' . "\n";
        if ($posts !== []) {
            $out .= '<lastBuildDate>' . $posts[0]->datePublished->format(DATE_RSS) . "</lastBuildDate>\n";
        }
        foreach ($posts as $post) {
            $item = $this->items->item($blog, $post, $appUrl, $media);
            $url = $this->items->url($blog, $post, $appUrl);
            $permalink = '<p><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">Permalink</a></p>';
            $out .= "<item>\n"
                . '<title>' . $x($post->title) . "</title>\n"
                . '<link>' . $x($url) . "</link>\n"
                . '<guid isPermaLink="true">' . $x($url) . "</guid>\n"
                . '<description>' . $x(($post->contentHtml ?? '') . $permalink) . "</description>\n"
                . '<pubDate>' . $post->datePublished->format(DATE_RSS) . "</pubDate>\n"
                . '<dc:creator>' . $x($blog->authorName) . "</dc:creator>\n";
            foreach ($post->tags as $tag) {
                $out .= '<category domain="' . $x(rtrim($home, '/') . '/tag/' . $tag['slug']) . '">' . $x($tag['name']) . "</category>\n";
            }
            $image = $item['image'] ?? null;
            if (is_string($image)) {
                $out .= '<media:content url="' . $x($image) . '" medium="image"/>' . "\n";
            }
            $out .= "</item>\n";
        }

        return $out . "</channel>\n</rss>\n";
    }

    public static function xmlSafe(string $value): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);
    }
}
```

`modules/blog/src/Http/FeedController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\Cursor;
use Xaraya\Module\Blog\Feed\FeedBuilder;
use Xaraya\Module\Blog\Feed\RssRenderer;
use Xaraya\Module\Blog\Post\PostRepository;

final class FeedController extends Controller
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly FeedBuilder $feeds,
        private readonly RssRenderer $rss,
        private readonly UrlGenerator $urls,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function feedJson(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blog($params['handle']);
        $rows = $this->posts->page($blog->id, self::now(), self::before($request), self::PAGE_SIZE + 1);
        $page = array_slice($rows, 0, self::PAGE_SIZE);
        $next = null;
        if (count($rows) > self::PAGE_SIZE) {
            $last = $page[self::PAGE_SIZE - 1];
            $next = $this->urls->generate('blog.feed.json', [
                'handle' => $blog->handle,
                'before' => Cursor::encode($last->datePublished, $last->id),
            ], true);
        }
        $feedUrl = $this->urls->generate('blog.feed.json', ['handle' => $blog->handle], true);
        $feed = $this->feeds->feed($blog, $page, $feedUrl, $next, $this->appUrl());

        return CacheHeaders::apply($this->json($feed, 200, 'application/feed+json'), $page);
    }

    /** @param array<string, string> $params */
    public function feedXml(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blog($params['handle']);
        $page = $this->posts->page($blog->id, self::now(), null, self::PAGE_SIZE);
        $self = $this->urls->generate('blog.feed.xml', ['handle' => $blog->handle], true);
        $xml = $this->rss->render($blog, $page, $self, $this->appUrl());

        return CacheHeaders::apply(new Response(200, ['Content-Type' => 'application/rss+xml; charset=utf-8'], $xml), $page);
    }

    private function blog(string $handle): Blog
    {
        return $this->blogs->find($handle) ?? throw new NotFound("No blog '{$handle}'");
    }

    /** @return array{0: DateTimeImmutable, 1: string}|null */
    private static function before(ServerRequestInterface $request): ?array
    {
        parse_str($request->getUri()->getQuery(), $query);
        if (!array_key_exists('before', $query)) {
            return null;
        }
        if (!is_string($query['before'])) {
            throw new HttpException(400, 'Invalid before cursor');
        }
        try {
            return Cursor::decode($query['before']);
        } catch (InvalidArgumentException) {
            throw new HttpException(400, 'Invalid before cursor');
        }
    }

    private function appUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
```

`modules/blog/src/Http/PostController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\PostRepository;

final class PostController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $post = $this->posts->find($params['id']);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($post === null || $post->status !== 'published' || $post->datePublished > $now) {
            throw new NotFound();
        }
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound();
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');

        return CacheHeaders::apply($this->json($this->items->item($blog, $post, $appUrl), 200, 'application/json'), [$post]);
    }
}
```

`modules/blog/src/Routes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\PostController;

final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/feed.json', [FeedController::class, 'feedJson'], 'blog.feed.json')
            ->middleware('cors', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/feed.xml', [FeedController::class, 'feedXml'], 'blog.feed.xml')
            ->middleware('cors', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}.json', [PostController::class, 'show'], 'blog.post.json')
            ->middleware('cors', 'conditional');
    }
}
```

In `modules/blog/module.json`, add after `"provider"`:
```json
  "routes": "Xaraya\\Module\\Blog\\Routes",
```

- [ ] **Step 4: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Module/Blog`, then repeat with the MySQL env vars.
Expected: PASS on both.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): serve Athena-compatible JSON Feed, single items and RSS with paging and caching" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Local media mode

**Files:**
- Create:
  - `modules/blog/src/Media/MediaStore.php`
  - `modules/blog/src/Media/MediaLocalizer.php`
  - `modules/blog/src/Http/MediaUrls.php`
  - `modules/blog/src/Http/MediaController.php`
- Modify:
  - `modules/blog/src/Sync/Syncer.php` (localise after each page)
  - `modules/blog/src/Http/FeedController.php` and `PostController.php` (rewrite media URLs)
  - `modules/blog/src/Routes.php` (media route)
  - `modules/blog/src/BlogServiceProvider.php` (`MediaStore` binding)
- Test: `tests/Module/Blog/Media/MediaStoreTest.php`, `tests/Module/Blog/Media/LocalMediaTest.php`

**Interfaces:**
- **Consumes:** `HttpFetcher`, `Blog`, `PostRecord`, `Post`, the `media` table (Task 1), `UrlGenerator`, `Syncer` (Task 6), and the controllers (Task 7).
- **Produces `MediaStore`:**
  - Constructor: `Connection`, `HttpFetcher`, `LoggerInterface`, `string $directory`.
  - `TYPES` maps MIME types to extensions: `image/jpeg` → `jpg`, `image/png` → `png`, `image/gif` → `gif`, `image/webp` → `webp`, `image/avif` → `avif`, `audio/mpeg` → `mp3`.
  - `MAX_BYTES = 10_000_000`.
  - `localize(Blog, string $url, DateTimeImmutable $now): ?array` returns the media row, or null on any failure, and logs a warning.
  - `store(Blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): ?array`.
  - `find(string $id): ?array`.
  - `lookup(int $blogId, list<string> $urls): array<string, array{id: string, ext: string}>`, keyed by source URL.
  - `absolutePath(array $row): string`.
- **Produces `MediaLocalizer(MediaStore)`:**
  - `static urls(PostRecord|Post $post): list<string>` collects `image`, `_athenana.images[].url`, `_video.thumbnail`, `_athenana.podcast.cover` and `attachments[].url`, keeping only http(s) URLs, de-duplicated.
  - `localize(Blog, PostRecord, DateTimeImmutable): int` returns the number of URLs now stored locally.
- **Produces `MediaUrls(MediaStore, UrlGenerator)`:** `map(Blog, list<Post>): array<string, string>`, mapping source URL to the absolute `blog.media` URL. It returns `[]` unless `blog.media === 'local'`.
- **Produces the route** `blog.media`: `GET /media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}` → `MediaController::show`. It has no `cors` or `conditional` middleware and sends `Cache-Control: public, max-age=31536000, immutable`.
- **Produces the provider binding:** `MediaStore` uses directory `config('blog.uploads')`, defaulting to `App::path('var/uploads')`.
- **File layout:** `<directory>/<handle>/<ulid>.<ext>`, with the relative path stored in `media.path`. Identical bytes (same sha256) reuse the existing file.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Media/MediaStoreTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Media;

use RuntimeException;
use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Media\MediaLocalizer;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class MediaStoreTest extends BlogTestCase
{
    public const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private Blog $blog;
    private FixtureFetcher $fetcher;
    private MediaStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blogs($this->enableBlog())->create(['handle' => 'pics', 'mode' => 'native'], $this->now());
        $app = $this->app();
        $png = new FetchResult(200, (string) base64_decode(self::PNG, true));
        $this->fetcher = (new FixtureFetcher())
            ->on('https://img.test/a.png', $png)
            ->on('https://img.test/same.png', $png)
            ->on('https://img.test/page.html', new FetchResult(200, '<html>nope</html>'))
            ->on('https://img.test/huge.png', new FetchResult(200, (string) base64_decode(self::PNG, true) . str_repeat("\0", MediaStore::MAX_BYTES)))
            ->on('https://img.test/boom', fn (): FetchResult => throw new RuntimeException('connection reset'));
        $app->container()->instance(HttpFetcher::class, $this->fetcher);
        $this->store = $app->container()->get(MediaStore::class);
        $this->blog = $this->blogs($app)->find('pics') ?? throw new RuntimeException();
    }

    public function testLocalizeStoresOnceAndDeduplicatesFiles(): void
    {
        $row = $this->store->localize($this->blog, 'https://img.test/a.png', $this->now());
        self::assertNotNull($row);
        self::assertSame('image/png', $row['mime']);
        self::assertSame(1, (int) $row['width']);
        self::assertSame(1, (int) $row['height']);
        self::assertMatchesRegularExpression('#^pics/[0-9a-z]{26}\.png$#', (string) $row['path']);
        self::assertFileExists($this->tmp . '/uploads/' . $row['path']);
        self::assertSame($this->tmp . '/uploads/' . $row['path'], $this->store->absolutePath($row));

        self::assertSame($row['id'], $this->store->localize($this->blog, 'https://img.test/a.png', $this->now())['id'] ?? null);
        self::assertCount(1, $this->fetcher->requests, 'second localize does not refetch');

        $same = $this->store->localize($this->blog, 'https://img.test/same.png', $this->now());
        self::assertNotSame($row['id'], $same['id'] ?? null);
        self::assertSame($row['path'], $same['path'] ?? null, 'identical bytes share one file');

        self::assertSame(
            ['https://img.test/a.png' => ['id' => (string) $row['id'], 'ext' => 'png']],
            $this->store->lookup($this->blog->id, ['https://img.test/a.png', 'https://img.test/unknown.png']),
        );
        self::assertSame((string) $row['id'], $this->store->find((string) $row['id'])['id'] ?? null);
    }

    public function testFailuresReturnNull(): void
    {
        foreach (['https://img.test/page.html', 'https://img.test/huge.png', 'https://img.test/boom', 'https://img.test/missing.png'] as $url) {
            self::assertNull($this->store->localize($this->blog, $url, $this->now()), $url);
        }
    }

    public function testUrlsCollectsEveryMediaField(): void
    {
        $record = new PostRecord('i', null, 'photo', 'T', 'https://site.test/p', 'https://site.test/ext', null, 'https://img.test/a.png',
            new \DateTimeImmutable(), null, [
                '_athenana' => ['images' => [['url' => 'https://img.test/b.png'], ['url' => 'https://img.test/a.png']], 'podcast' => ['cover' => 'https://img.test/c.jpg']],
                '_video' => ['thumbnail' => 'https://img.test/d.jpg'],
                'attachments' => [['url' => 'https://cdn.test/e.mp3'], ['url' => 'data:xyz']],
            ], [], 'h');
        self::assertSame(
            ['https://img.test/a.png', 'https://img.test/b.png', 'https://img.test/d.jpg', 'https://img.test/c.jpg', 'https://cdn.test/e.mp3'],
            MediaLocalizer::urls($record),
        );
    }
}
```

`tests/Module/Blog/Media/LocalMediaTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Media;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class LocalMediaTest extends BlogTestCase
{
    private function seeded(string $media): App
    {
        $this->enableBlog();
        $app = $this->app();
        $blog = $this->blogs($app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json',
            'source_format' => 'athena', 'media' => $media,
        ], $this->now());
        $items = FeedFactory::items(2);
        $items[0]['image'] = 'https://img.test/a.png';
        $fetcher = FeedFactory::fetcher(FeedFactory::pages($items))
            ->on('https://img.test/a.png', new FetchResult(200, (string) base64_decode(MediaStoreTest::PNG, true)));
        $app->container()->instance(HttpFetcher::class, $fetcher);
        $app->container()->get(Syncer::class)->sync($blog, true, $this->now());

        return $this->app();
    }

    public function testLocalBlogServesRewrittenMedia(): void
    {
        $app = $this->seeded('local');
        $feed = json_decode((string) $app->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true);
        $image = $feed['items'][0]['image'];
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-z]{26}\.png$#', $image);

        $single = json_decode((string) $app->handle(new ServerRequest('GET', '/s/' . substr($feed['items'][0]['id'], -26) . '.json'))->getBody(), true);
        self::assertSame($image, $single['image']);

        $rss = (string) $app->handle(new ServerRequest('GET', '/blog/src/feed.xml'))->getBody();
        self::assertStringContainsString('<media:content url="' . $image . '"', $rss);

        $file = $app->handle(new ServerRequest('GET', (string) parse_url($image, PHP_URL_PATH)));
        self::assertSame(200, $file->getStatusCode());
        self::assertSame('image/png', $file->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=31536000, immutable', $file->getHeaderLine('Cache-Control'));
        self::assertSame(base64_decode(MediaStoreTest::PNG, true), (string) $file->getBody());

        $id = substr((string) parse_url($image, PHP_URL_PATH), 7, 26);
        self::assertSame(404, $app->handle(new ServerRequest('GET', "/media/{$id}.jpg"))->getStatusCode(), 'wrong extension');
        self::assertSame(404, $app->handle(new ServerRequest('GET', '/media/01aaaaaaaaaaaaaaaaaaaaaaaa.png'))->getStatusCode());
    }

    public function testRemoteBlogKeepsSourceUrls(): void
    {
        $app = $this->seeded('remote');
        $feed = json_decode((string) $app->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true);
        self::assertSame('https://img.test/a.png', $feed['items'][0]['image']);
        self::assertDirectoryDoesNotExist($this->tmp . '/uploads/src');
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Media`
Expected: errors, classes not found.

- [ ] **Step 3: Implement the store and the localiser**

`modules/blog/src/Media/MediaStore.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use DateTimeImmutable;
use finfo;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Source\HttpFetcher;

final class MediaStore
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
        'audio/mpeg' => 'mp3',
    ];
    public const MAX_BYTES = 10_000_000;

    public function __construct(
        private readonly Connection $db,
        private readonly HttpFetcher $fetcher,
        private readonly LoggerInterface $logger,
        private readonly string $directory,
    ) {}

    /** @return array<string, mixed>|null */
    public function localize(Blog $blog, string $url, DateTimeImmutable $now): ?array
    {
        $existing = $this->db->select('media')
            ->where('blog_id', '=', $blog->id)
            ->where('source_url_hash', '=', sha1($url))
            ->first();
        if ($existing !== null) {
            return $existing;
        }
        try {
            $result = $this->fetcher->get($url);
        } catch (Throwable $e) {
            $this->logger->warning('Media {url} for {blog} could not be fetched: {reason}', ['url' => $url, 'blog' => $blog->handle, 'reason' => $e->getMessage()]);

            return null;
        }
        if ($result->status !== 200) {
            $this->logger->warning('Media {url} for {blog} returned HTTP {status}', ['url' => $url, 'blog' => $blog->handle, 'status' => $result->status]);

            return null;
        }

        return $this->store($blog, $result->body, $url, $now);
    }

    /** @return array<string, mixed>|null */
    public function store(Blog $blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): ?array
    {
        $size = strlen($bytes);
        if ($size === 0 || $size > self::MAX_BYTES) {
            $this->logger->warning('Media {url} for {blog} rejected: {bytes} bytes', ['url' => $sourceUrl ?? '(upload)', 'blog' => $blog->handle, 'bytes' => $size]);

            return null;
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = self::TYPES[$mime] ?? null;
        if ($ext === null) {
            $this->logger->warning('Media {url} for {blog} rejected: type {mime}', ['url' => $sourceUrl ?? '(upload)', 'blog' => $blog->handle, 'mime' => $mime]);

            return null;
        }
        $sha = hash('sha256', $bytes);
        $same = $this->db->select('media')->columns('path')->where('blog_id', '=', $blog->id)->where('sha256', '=', $sha)->first();
        $path = $same !== null ? (string) $same['path'] : $this->write($blog->handle, $bytes, $ext);
        [$width, $height] = str_starts_with($mime, 'image/') ? self::dimensions($bytes) : [null, null];
        $id = Ulid::generate();
        $this->db->insert('media', [
            'id' => $id,
            'blog_id' => $blog->id,
            'source_url' => $sourceUrl,
            'source_url_hash' => $sourceUrl === null ? null : sha1($sourceUrl),
            'path' => $path,
            'mime' => $mime,
            'bytes' => $size,
            'sha256' => $sha,
            'width' => $width,
            'height' => $height,
            'created_at' => $now,
        ]);

        return $this->find($id);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        return $this->db->select('media')->where('id', '=', $id)->first();
    }

    /**
     * @param list<string> $urls
     * @return array<string, array{id: string, ext: string}> source URL => media
     */
    public function lookup(int $blogId, array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $byHash = [];
        foreach ($urls as $url) {
            $byHash[sha1($url)] = $url;
        }
        $out = [];
        foreach (array_chunk(array_keys($byHash), 500) as $chunk) {
            $rows = $this->db->select('media')->columns('id', 'mime', 'source_url_hash')
                ->where('blog_id', '=', $blogId)->whereIn('source_url_hash', $chunk)->all();
            foreach ($rows as $row) {
                $url = $byHash[(string) $row['source_url_hash']] ?? null;
                $ext = self::TYPES[(string) $row['mime']] ?? null;
                if ($url !== null && $ext !== null) {
                    $out[$url] = ['id' => (string) $row['id'], 'ext' => $ext];
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public function absolutePath(array $row): string
    {
        return rtrim($this->directory, '/') . '/' . (string) $row['path'];
    }

    private function write(string $handle, string $bytes, string $ext): string
    {
        $dir = rtrim($this->directory, '/') . '/' . $handle;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create media directory {$dir}");
        }
        $name = Ulid::generate() . '.' . $ext;
        $tmp = $dir . '/.' . $name . '.tmp';
        if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $dir . '/' . $name)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write media file in {$dir}");
        }

        return $handle . '/' . $name;
    }

    /** @return array{0: ?int, 1: ?int} */
    private static function dimensions(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        return $info === false ? [null, null] : [(int) $info[0], (int) $info[1]];
    }
}
```

`modules/blog/src/Media/MediaLocalizer.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use DateTimeImmutable;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;

final class MediaLocalizer
{
    public function __construct(private readonly MediaStore $store) {}

    /** @return list<string> */
    public static function urls(PostRecord|Post $post): array
    {
        $doc = $post->doc;
        $urls = [$post->image];
        foreach (self::listAt($doc, '_athenana', 'images') as $image) {
            $urls[] = is_array($image) ? ($image['url'] ?? null) : null;
        }
        $urls[] = self::at($doc, '_video', 'thumbnail');
        $urls[] = self::at($doc, '_athenana', 'podcast', 'cover');
        foreach (self::listAt($doc, 'attachments') as $attachment) {
            $urls[] = is_array($attachment) ? ($attachment['url'] ?? null) : null;
        }
        $valid = array_filter($urls, static fn (mixed $url): bool => is_string($url) && preg_match('#^https?://#i', $url) === 1);

        return array_values(array_unique($valid));
    }

    public function localize(Blog $blog, PostRecord $record, DateTimeImmutable $now): int
    {
        $stored = 0;
        foreach (self::urls($record) as $url) {
            if ($this->store->localize($blog, $url, $now) !== null) {
                $stored++;
            }
        }

        return $stored;
    }

    /** @param array<mixed> $data */
    private static function at(array $data, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function listAt(array $data, string ...$keys): array
    {
        $value = self::at($data, ...$keys);

        return is_array($value) ? $value : [];
    }
}
```

- [ ] **Step 4: Wire it into the provider, sync and serving**

In `BlogServiceProvider::register()`, add this (with imports for `MediaStore`, `Connection`, `Psr\Log\LoggerInterface` and `HttpFetcher`):
```php
        $container->set(MediaStore::class, static fn (Container $c): MediaStore => new MediaStore(
            $c->get(Connection::class),
            $c->get(HttpFetcher::class),
            $c->get(LoggerInterface::class),
            (string) $c->get(Config::class)->get('blog.uploads', $c->get(App::class)->path('var/uploads')),
        ));
```

In `Syncer`:
1. Add `private readonly MediaLocalizer $media,` as the last constructor parameter.
2. In `syncPage()`, just before `return $changed;`, add:
```php
        if ($blog->media === 'local') {
            foreach ($results as $itemId => $result) {
                if ($result->status !== SaveResult::UNCHANGED) {
                    $this->media->localize($blog, $records[(string) $itemId], $now);
                }
            }
        }
```

`modules/blog/src/Http/MediaUrls.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Media\MediaLocalizer;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Post\Post;

final class MediaUrls
{
    public function __construct(private readonly MediaStore $store, private readonly UrlGenerator $urls) {}

    /**
     * @param list<Post> $posts
     * @return array<string, string> source media URL => absolute local URL
     */
    public function map(Blog $blog, array $posts): array
    {
        if ($blog->media !== 'local' || $posts === []) {
            return [];
        }
        $sources = [];
        foreach ($posts as $post) {
            array_push($sources, ...MediaLocalizer::urls($post));
        }
        $map = [];
        foreach ($this->store->lookup($blog->id, array_values(array_unique($sources))) as $url => $media) {
            $map[$url] = $this->urls->generate('blog.media', ['id' => $media['id'], 'ext' => $media['ext']], true);
        }

        return $map;
    }
}
```

`modules/blog/src/Http/MediaController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\Media\MediaStore;

final class MediaController extends Controller
{
    public function __construct(private readonly MediaStore $store) {}

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $row = $this->store->find($params['id']);
        if ($row === null || (MediaStore::TYPES[(string) $row['mime']] ?? null) !== $params['ext']) {
            throw new NotFound();
        }
        $path = $this->store->absolutePath($row);
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            throw new NotFound();
        }

        return new Response(200, [
            'Content-Type' => (string) $row['mime'],
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ], Stream::create($handle));
    }
}
```

In `Routes::routes()`, add:
```php
        $routes->get('/media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}', [MediaController::class, 'show'], 'blog.media');
```

In `FeedController`:
1. Add `private readonly MediaUrls $media,` to the constructor.
2. In `feedJson`, pass `$this->media->map($blog, $page)` as the last argument to `$this->feeds->feed(...)`.
3. In `feedXml`, pass it as the last argument to `$this->rss->render(...)`.

In `PostController`:
1. Add `private readonly MediaUrls $media,` to the constructor.
2. Pass `$this->media->map($blog, [$post])` as the 4th argument to `$this->items->item(...)`.

- [ ] **Step 5: Run the tests on SQLite and MySQL**

Run `vendor/bin/phpunit tests/Module/Blog`, then repeat with the MySQL env vars.
Expected: PASS on both. The earlier Task 6 and 7 tests must stay green.

- [ ] **Step 6: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): add local media mode with downloads, dedup and immutable media serving" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: `blog:mode` cut-over

**Files:**
- Create: `modules/blog/src/Cli/BlogModeCommand.php`
- Modify: `modules/blog/module.json` (add the command)
- Test: `tests/Module/Blog/Cli/BlogModeCommandTest.php`

**Interfaces:**
- **Consumes:** `BlogRepository::find/update`, `PostRepository::liveItemIds`, `Config` (`app.url`), `Blog::MODES`.
- **Produces the command** `blog:mode <handle> mirror|native [--source=<url> --format=<fmt>] [--force]`:
  - Switching to the current mode prints `Blog '<h>' is already <mode>.` and exits 0.
  - Switching to `mirror` refuses with exit 1 if live posts exist whose `item_id` starts with `{app.url}/s/` (posts written natively), unless `--force` is given.
  - `--source` and `--format` set `source_url` and `source_format` in the same update.
  - Validation errors (for example, a mirror with no source) surface as exit 1 through the kernel CLI.
  - Ids never change; only the `mode` column (plus any source fields) is updated.

- [ ] **Step 1: Write the failing test `tests/Module/Blog/Cli/BlogModeCommandTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use DateTimeImmutable;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class BlogModeCommandTest extends BlogTestCase
{
    public function testMirrorToNativeKeepsIds(): void
    {
        $app = $this->enableBlog();
        $this->xar(['blog:create', 'src', '--mode=mirror', '--format=athena', '--source=https://src.test/blog/src/feed.json'], $app);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->xar(['blog:sync', 'src', '--full'], $app);
        $before = array_column(json_decode((string) $this->app()->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true)['items'], 'id');

        [$code, $out] = $this->xar(['blog:mode', 'src', 'native']);
        self::assertSame(0, $code);
        self::assertStringContainsString("Blog 'src' is now native.", $out);
        $after = array_column(json_decode((string) $this->app()->handle(new ServerRequest('GET', '/blog/src/feed.json'))->getBody(), true)['items'], 'id');
        self::assertSame($before, $after);

        [$code, , $err] = $this->xar(['blog:sync', 'src']);
        self::assertSame(1, $code);
        self::assertStringContainsString('not a mirror blog', $err);

        [$code, $out] = $this->xar(['blog:mode', 'src', 'native']);
        self::assertSame(0, $code);
        self::assertStringContainsString("Blog 'src' is already native.", $out);
    }

    public function testNativeToMirrorNeedsSourceAndForceForNativePosts(): void
    {
        $app = $this->enableBlog();
        $this->xar(['blog:create', 'mine', '--mode=native'], $app);

        [$code, , $err] = $this->xar(['blog:mode', 'mine', 'mirror']);
        self::assertSame(1, $code);
        self::assertStringContainsString('source URL', $err);

        $blog = $this->blogs($this->app())->find('mine');
        self::assertNotNull($blog);
        $ulid = Ulid::generate();
        $this->app()->container()->get(PostRepository::class)->save($blog->id, new PostRecord(
            "http://xar.test/s/{$ulid}", $ulid, 'note', 'Mine', null, null, '<p>x</p>', null, new DateTimeImmutable('2026-10-01T00:00:00Z'), null, [], [], 'h',
        ), $this->now());

        $args = ['blog:mode', 'mine', 'mirror', '--source=https://src.test/blog/src/feed.json', '--format=athena'];
        [$code, , $err] = $this->xar($args);
        self::assertSame(1, $code);
        self::assertStringContainsString('1 native post(s)', $err);
        self::assertSame('native', $this->blogs($this->app())->find('mine')?->mode);

        [$code, $out] = $this->xar([...$args, '--force']);
        self::assertSame(0, $code);
        self::assertStringContainsString("Blog 'mine' is now mirror.", $out);
        $blog = $this->blogs($this->app())->find('mine');
        self::assertSame('https://src.test/blog/src/feed.json', $blog?->sourceUrl);
        self::assertSame('athena', $blog?->sourceFormat);
    }

    public function testUsageErrors(): void
    {
        $app = $this->enableBlog();
        foreach ([['blog:mode'], ['blog:mode', 'x'], ['blog:mode', 'x', 'sideways']] as $args) {
            [$code, , $err] = $this->xar($args, $app);
            self::assertSame(1, $code);
            self::assertStringContainsString('Usage: xar blog:mode', $err);
        }
        [$code, , $err] = $this->xar(['blog:mode', 'ghost', 'native'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'ghost'", $err);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Cli/BlogModeCommandTest.php`
Expected: FAIL (`Unknown command 'blog:mode'`).

- [ ] **Step 3: Implement `modules/blog/src/Cli/BlogModeCommand.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Config\Config;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;

final class BlogModeCommand extends Command
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'blog:mode';
    }

    public function description(): string
    {
        return 'Switch a blog between mirror and native mode (ids never change)';
    }

    public function usage(): string
    {
        return 'blog:mode <handle> mirror|native [--source=<url> --format=athena|jsonfeed] [--force]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        $mode = $input->argument(1);
        if ($handle === null || !in_array($mode, Blog::MODES, true)) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $blog = $this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'");
        if ($blog->mode === $mode) {
            $output->line("Blog '{$handle}' is already {$mode}.");

            return 0;
        }
        if ($mode === 'mirror') {
            $prefix = rtrim((string) $this->config->get('app.url', ''), '/') . '/s/';
            $native = count(array_filter($this->posts->liveItemIds($blog->id), static fn (string $id): bool => str_starts_with($id, $prefix)));
            if ($native > 0 && !$input->flag('force')) {
                $output->error("Blog '{$handle}' has {$native} native post(s); the next full sync would tombstone them. Re-run with --force to switch anyway.");

                return 1;
            }
        }
        $fields = array_filter([
            'mode' => $mode,
            'source_url' => $input->option('source'),
            'source_format' => $input->option('format'),
        ], static fn (?string $value): bool => $value !== null);
        $this->blogs->update($blog, $fields, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $output->line("Blog '{$handle}' is now {$mode}.");

        return 0;
    }
}
```

Add `"Xaraya\\Module\\Blog\\Cli\\BlogModeCommand"` to `commands` in `modules/blog/module.json`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit tests/Module/Blog`
Expected: PASS.

- [ ] **Step 5: Run the full checks and commit**

Run: `composer test && composer stan && composer cs`

```bash
git add -A
git commit -m "feat(blog): add blog:mode cut-over between mirror and native" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Athena parity fixture, acceptance test and docs

**Files:**
- Create: `tests/fixtures/athena/page-1.json`, `page-2.json`, `page-3.json`, `item.json`, `README.md`, and `tests/Module/Blog/AthenaParityTest.php`
- Modify: `README.md` (a Blog section)

**Interfaces:**
- **Consumes:** every earlier task.
- **Produces:** the acceptance gate from spec §7. Phoenix's served `feed.json` pages and `/s/{id}.json` must equal Athena's after normalising only `feed_url`/`next_url`. The cursors inside `next_url` must be identical.

- [ ] **Step 1: Record the fixture from the live feed**

This is a read-only public GET of the user's own blog.

```bash
mkdir -p tests/fixtures/athena
php -r '
$ctx = stream_context_create(["http" => ["header" => "User-Agent: XarayaPhoenix/0.1 (fixture recorder)\r\nAccept: application/feed+json"]]);
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
$url = "https://athenana.com/blog/wyome/feed.json";
for ($n = 1; $n <= 3 && $url !== null; $n++) {
    $page = json_decode((string) file_get_contents($url, false, $ctx), true, 512, JSON_THROW_ON_ERROR);
    $next = $page["next_url"] ?? null;
    if ($n === 3) { unset($page["next_url"]); }
    file_put_contents("tests/fixtures/athena/page-$n.json", json_encode($page, $flags) . "\n");
    $url = $next;
}
$first = json_decode((string) file_get_contents("tests/fixtures/athena/page-1.json"), true)["items"][0]["id"];
$item = json_decode((string) file_get_contents("https://athenana.com/s/" . substr($first, -26) . ".json", false, $ctx), true, 512, JSON_THROW_ON_ERROR);
file_put_contents("tests/fixtures/athena/item.json", json_encode($item, $flags) . "\n");
'
ls -la tests/fixtures/athena
```
Expected: three page files and `item.json`, each a few tens of KB.

Write `tests/fixtures/athena/README.md`:
```markdown
# Athena parity fixture

Recorded on <today's date, UTC> from `https://athenana.com/blog/wyome/feed.json` (3 pages) and
`https://athenana.com/s/<first item ulid>.json`, using Plan A Task 10 Step 1.

- `page-3.json` has its `next_url` removed, so the fixture is a closed set of items.
- To re-record, run the same script. The parity test must still pass afterwards; if it doesn't,
  Athena's format changed and Phoenix needs to follow.
```

- [ ] **Step 2: Write the acceptance test `tests/Module/Blog/AthenaParityTest.php`**

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class AthenaParityTest extends BlogTestCase
{
    private const SOURCE = 'https://athenana.com/blog/wyome/feed.json';
    private const DIR = __DIR__ . '/../../fixtures/athena';

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::DIR . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function cursor(mixed $url): ?string
    {
        if (!is_string($url)) {
            return null;
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return is_string($query['before'] ?? null) ? $query['before'] : null;
    }

    public function testServedFeedMatchesAthena(): void
    {
        $pages = [self::fixture('page-1'), self::fixture('page-2'), self::fixture('page-3')];
        $fetcher = (new FixtureFetcher())
            ->on(self::SOURCE, FixtureFetcher::json($pages[0]))
            ->on((string) $pages[0]['next_url'], FixtureFetcher::json($pages[1]))
            ->on((string) $pages[1]['next_url'], FixtureFetcher::json($pages[2]));

        $app = $this->enableBlog();
        [$code, $out, $err] = $this->xar([
            'blog:create', 'wyome', '--mode=mirror', '--format=athena', '--source=' . self::SOURCE,
            '--post-url=https://www.wyome.com/blog/post.html?id={id}',
        ], $app);
        self::assertSame(0, $code, $out . $err);

        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, $fetcher);
        $blog = $this->blogs($app)->find('wyome');
        self::assertNotNull($blog);
        $report = $app->container()->get(Syncer::class)->sync($blog, true, $this->now());
        $total = array_sum(array_map(static fn (array $page): int => count($page['items']), $pages));
        self::assertSame(3, $report->pages);
        self::assertSame($total, $report->created);
        self::assertSame(0, $report->skipped);

        $app = $this->app();
        $uri = '/blog/wyome/feed.json';
        foreach ($pages as $n => $expected) {
            $response = $app->handle(new ServerRequest('GET', $uri));
            self::assertSame(200, $response->getStatusCode());
            $served = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(self::cursor($expected['next_url'] ?? null), self::cursor($served['next_url'] ?? null), 'page ' . ($n + 1) . ' cursor');
            $next = $served['next_url'] ?? null;
            unset($expected['feed_url'], $expected['next_url'], $served['feed_url'], $served['next_url']);
            self::assertEquals($expected, $served, 'page ' . ($n + 1) . ' differs from Athena');
            if (!is_string($next)) {
                break;
            }
            $parts = parse_url($next);
            $uri = ($parts['path'] ?? '') . '?' . ($parts['query'] ?? '');
        }

        $item = self::fixture('item');
        $single = $app->handle(new ServerRequest('GET', '/s/' . substr((string) $item['id'], -26) . '.json'));
        self::assertSame(200, $single->getStatusCode());
        self::assertEquals($item, json_decode((string) $single->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
```

- [ ] **Step 3: Run the parity test**

Run: `vendor/bin/phpunit tests/Module/Blog/AthenaParityTest.php`
Expected: PASS.

If it fails, read the diff. Fix Phoenix (adapter, serialiser or envelope) to match Athena; never edit the fixture to match Phoenix. Some differences may come from Athena behaviour this plan doesn't model. Examples: pinned posts ordered first, an item shape that differs between `/s/{id}.json` and the feed, or fields that change with the request host. For those, STOP and report NEEDS_CONTEXT with the exact diff.

- [ ] **Step 4: Document the blog in `README.md`**

Append this section:
````markdown
## Blog (Athena-compatible)

Enable the module and mirror an existing JSON Feed blog (Athena or any JSON Feed 1.1 source):

```sh
bin/xar module:enable blog
bin/xar blog:create wyome --mode=mirror --format=athena \
  --source=https://athenana.com/blog/wyome/feed.json \
  --post-url='https://www.wyome.com/blog/post.html?id={id}'
bin/xar blog:sync wyome --full
```

Phoenix then serves the same feed shape as Athena, so a consumer only changes the host:

- `GET /blog/{handle}/feed.json`: JSON Feed 1.1, 50 items a page, with `?before=` cursors
- `GET /blog/{handle}/feed.xml`: RSS 2.0
- `GET /s/{id}.json`: a single item

Keep a mirror fresh with cron:

```
*/10 * * * * cd /path/to/phoenix && bin/xar blog:sync --all
15 3 * * *   cd /path/to/phoenix && bin/xar blog:sync --all --full
```

A quick sync stops at the first unchanged page. A full sync also catches edits to old posts and
deletions, and refuses to delete anything if the source suddenly shows under half of the posts.
Add `--media=local` at creation to download images and serve them from `/media/…`.
`bin/xar blog:mode <handle> native` stops mirroring; post ids never change.
````

- [ ] **Step 5: Run the final verification**

Run each of these:
- `composer test` on SQLite, then with the MySQL env vars: both PASS.
- `composer stan`: `[OK] No errors`.
- `composer cs`: exit 0.
- `git status --short`: empty after the commit.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "test(blog): add Athena parity fixture and acceptance test; document the blog module" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Do not push. The controller pushes and checks CI (Postgres runs only there).
