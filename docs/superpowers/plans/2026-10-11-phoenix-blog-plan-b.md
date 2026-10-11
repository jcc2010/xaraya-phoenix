# Phoenix Blog Plan B (Native Authoring) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Phoenix be the editor of a native blog: hashed bearer tokens (`tokens` module), one post-write service (`PostWriter`), a token-protected JSON API with media upload, `xar media:localize`, and a W3C Micropub endpoint (`micropub` module), while mirrored ids, URLs and the public feeds stay exactly as Plan A serves them.

**Architecture:**

- **Kernel (Task 1):** a module may declare route middleware aliases in `module.json` (`"middleware": {"token": "…"}`); `token:blog:write` reaches the class as `$args = ['blog:write']`. `ValidationFailed` is a 422 whose JSON body carries `error.fields`, and every `/api/…` error answers in JSON.
- **`tokens` module (Tasks 2-3):** `xar_tokens` stores the sha256 of each `xar_` + 40 base62 token, never the token. `TokenMiddleware` (`token:<scope>`) checks the bearer header, expiry, scopes and, on `{handle}` routes, the blog pin; it sets the `Token` request attribute and writes `last_used_at` at most once a minute.
- **`blog` additions (Tasks 4-8):** `PostWriter` maps a JSON Feed item through `AthenaAdapter` exactly as sync does, writes it in one transaction, bumps `updated_at`, and dispatches the item event after the commit. `PostApiController` and `MediaApiController` expose it under `/api` (registered only when `tokens` is enabled). `MediaUploader` feeds uploads into `MediaStore::ingest()`. `media:localize` reuses the sync's download rules.
- **`micropub` module (Tasks 9-11):** `MicropubInput` normalises form and JSON bodies, `Mf2Mapper` maps h-entry properties to item fields and back, `PostLocator` finds a post by URL, and `MicropubErrors` turns every 4xx into Micropub's `{"error", "error_description"}`.
- **Proof (Task 12):** the three Plan B definition-of-done items run end to end through the CLI and `App::handle`.

**Tech Stack:** PHP ≥ 8.3 (ext-fileinfo is already required), the Phoenix kernel (PSR-7/15 via nyholm/psr7, FastRoute, PDO), the Plan A blog module, PHPUnit 11, PHPStan level 8, php-cs-fixer (PER-CS). SQLite, MySQL 8 and Postgres 16. No new Composer package.

**Spec:** `docs/superpowers/specs/2026-10-08-phoenix-blog-design.md`: §1 (the `tokens` and `micropub` modules and the blog additions), §5 (5.1-5.5), the write-path parts of §6 and §7, and §8 "Plan B". Every task's code was built and run on SQLite and MySQL in a throwaway worktree while this plan was written.

**What Plan A already shipped (checked in the code; not re-planned):**

- `xar blog:mode <handle> mirror|native [--force]` (`BlogModeCommand`), including the refusal to switch a blog with live native posts back to mirror without `--force` (`PostRepository::countLiveNative()`: native means `source_hash IS NULL`).
- `MediaStore` (finfo type check against `TYPES`, `MAX_BYTES = 10_000_000`, `getimagesizefromstring` validity without GD, ULID file names under `{uploads}/{handle}/`, files shared by sha256), `MediaLocalizer::urls()`, the `blog.media` route (`GET /media/{id}.{ext}`, `immutable`, `nosniff`, sandbox CSP) and `MediaUrls`.
- `Syncer` dispatches `ItemCreated`/`ItemUpdated`/`ItemDeleted` (module `blog`, itemtype `post`, payload `{blog, item_id}`, or `{blog}` for deletes) after each page's transaction; a resurrected post is an `ItemUpdated`.
- `SearchIndexer` (subscribed in `modules/blog/module.json`) indexes on those events and swallows its own failures; `LikeSearchIndex::search()` hides deleted and scheduled posts at query time.
- `PostViewFactory::cacheKey()` keys sanitised HTML on `updated_at` (to the second) plus a hash of the input, so a write that bumps `updated_at` shows at once.
- **Not shipped:** `media:localize` (spec §5.4), any token, write path, API or Micropub code. Plan A's follow-ups list "Before Plan B" items; Task 13 records how this plan settles each.

**Facts this plan relies on (checked in the code):**

- `MiddlewareRegistry::resolve('token:blog:write')` splits on the first `:` (alias `token`, args `['blog:write']`) and builds a class through the container, passing the args to a constructor parameter named `$args`. `csrf` is only a marker alias today (no CSRF middleware exists), so `csrf:off` documents intent.
- Route middleware run in the order listed, the first outermost. `RouteHandler::attach()` sets route parameters (such as `handle`) as request attributes before route middleware run.
- `ErrorHandler` answers JSON when the path ends in `.json` or `Accept` contains `json` (without `text/html`); its JSON body is `{"error": {"status", "message"}}`. `HttpException::reason()` had no 409, 413 or 415.
- `App::routes()` builds each module's `Routes` with `Container::make()`, so a `Routes` constructor can take `ModuleRegistry`. `ModuleRegistry::enable()` refuses a module whose `requires.modules` are not enabled; `disable()` refuses while a dependant is enabled.
- Modules autoload as `Xaraya\Module\<Studly>\` from `modules/<name>/src/`; `composer.json` `autoload-dev` maps only the blog namespace, so Task 2 adds `Tokens` and `Micropub` for tests and PHPStan.
- `PostRepository::save()` silently swaps a colliding ULID, which a native create must never do (its `item_id` embeds the id). `ItemSerializer::url()` gives `post_url_pattern`, else the stored `url`, else `{app.url}/s/{id}`; `ItemSerializer::item()` always adds `_athenana.kind` and `_athenana.tags`.
- `AthenaAdapter::toRecord()` takes the kind from `_athenana.kind`, the tags from `_athenana.tags` (falling back to `tags`), derives a missing title (`Text::deriveTitle`), and keeps every other field in `doc`.
- Nyholm's `ServerRequest` does not fill `getQueryParams()` from the URI, so controllers read the query with `parse_str($request->getUri()->getQuery(), …)`, as `FeedController` does. PHPUnit refuses an abstract test case passed as a file argument, so run commands name directories or concrete test files.
- Postgres stores `json` columns as `JSONB`, which reorders keys: compare items as decoded JSON with sorted keys (`Text::canonicalJson`).

**Decisions and deviations from the spec:**

1. **Kernel additions** (Task 1): the `middleware` manifest key, `MiddlewareRegistry::has()`, `ValidationFailed` (422 with `error.fields`, which §5.2 asks for and the kernel could not express), JSON errors for every `/api` path, and the reason phrases for 409, 413 and 415. A module cannot replace a kernel alias or another module's alias: the second one is logged and skipped.
2. **`tokens` requires `blog`.** A token's `blog_id` pin and `--blog=<handle>` refer to blogs (with a foreign key). The blog module registers its `/api` routes only while `tokens` is enabled, because they need the `token` alias.
3. **Where the token is read.** `TokenMiddleware` reads only `Authorization: Bearer`. On Micropub routes the `micropubToken` middleware moves a form `access_token` field into that header first, so the field works there and nowhere else. A request that sends both gets a 400 (RFC 6750 §2). The query string is never read, and no message or log line contains a token.
4. **Blog pin.** The middleware checks the pin on routes with a `{handle}`. Routes that reach a blog through a post id or a Micropub destination check it in the controller (`ApiAccess::allow()`, `Destination::choose()`). Rejections are `TokenError`s: 401 `unauthorized` (with `WWW-Authenticate: Bearer` or `Bearer error="invalid_token"`), and 403 `insufficient_scope` or `forbidden`.
5. **`xar token:list`** is added, so `token:revoke <id>` has a way to find ids. `token:create` prints the id, the scopes and the token on its last line.
6. **content_html is stored as given** (§5.2). Pages already sanitise it at render time with the kernel `HtmlSanitizer` (blog pages plan), so the "sanitising follow-up" is covered for HTML pages. The JSON feed, RSS and the API serve it as stored; the README says so.
7. **Item rules** (`ItemValidator`): only the JSON Feed 1.1 item fields and `_`-prefixed objects are accepted (§5.2). Top-level `null` means absent. `_phoenix` is read-only and ignored on input. `id` and `url` are rejected on create; on replace they may come back unchanged (a GET body round-trips) but never changed. Titles are at most 1000 characters, dates must be RFC 3339 with seconds (years 1970-9999), URLs must be absolute http(s) and fit their columns, tags are 1-128 characters, attachments need an http(s) `url` and a `mime_type`, and an item needs at least one of `content_html`, `content_text`, `title`, `external_url` or `image`.
8. **Replace and patch:** a replace without `date_published` keeps the old date. A merge patch (RFC 7396) applies to the item as served. `{"date_published": null}` is a 422. Patching `tags` without `_athenana.tags` re-derives the slugs, and a title that was only derived from the content is derived again. `date_modified` is never invented by the API (the client owns it); a Micropub update sets it to now.
9. **API responses:** every item the API returns (not only GET) carries `_phoenix.status` (`published`, `scheduled` or `deleted`), is served as stored (no local-media rewriting, so a PUT of a GET body never stores `/media` URLs), and has `Cache-Control: no-store`. The post `Location` is the path `/api/posts/{id}` (§5.3); media `Location`s are absolute (§5.4). JSON bodies are capped at 1 MB (413) and must be `application/json` or `application/feed+json`; PATCH also takes `application/merge-patch+json` (other types are 415).
10. **Delete and undelete** are idempotent: repeating one changes nothing and fires no event. Undelete fires `ItemUpdated`, as a resurrecting sync does. Editing a deleted post is a 409 (`The post is deleted; undelete it first`).
11. **Uploads:** an upload to a mirror blog is a 409. The API answers `{url, type, bytes, width, height}`; the Micropub media endpoint answers 201 with only `Location`. PHP upload errors map to 413 (size), 400 (partial or missing) and 500 (server faults).
12. **Micropub:** only 400, 401 and 403 are used, so every other 4xx becomes 400 `invalid_request`. Plain-text `content` is escaped into one `<p>` per blank-line-separated paragraph with `<br>` for single newlines. Files are uploaded to the media endpoint, not to `/micropub`. Unknown properties are ignored. `q=config` also returns `"syndicate-to": []` and lists only the native blogs the token may write to. `q=syndicate-to` returns `{"syndicate-to": []}`. `q=source` leaves out `name` when it is only the derived title. Update, delete and undelete answer 204, and posts are found by `{app.url}/s/{id}[.json]`, a blog's `post_url_pattern`, or the item id. Scope mapping: create, update, delete, undelete and every `q=` query need `blog:write`; the media endpoint needs `media:write`.
13. **`media:localize`** walks published posts (scheduled included), skips URLs already under `{app.url}/media/`, and sets `media=local` even when some downloads fail (those stay remote and are logged), as sync does.

## Global Constraints

- **Test databases only.** Tests always use the test database (`XAR_TEST_DSN`, else SQLite in the test's temp dir). No task or manual step may delete, replace or migrate `var/database.sqlite`, which holds the user's synced live blog. A manual smoke step sets `DB_DSN=sqlite:/tmp/<name>.sqlite` and `APP_DEBUG=true`; debug mode stops the web server from writing `var/cache/config.php` with that DSN.
- **Modules:** `tokens` (`requires: blog`) and `micropub` (`requires: blog, tokens`) live in `modules/<name>/` with `module.json`, `src/` (namespace `Xaraya\Module\Tokens\` / `Xaraya\Module\Micropub\`) and `migrations/`. They are enabled with `xar module:enable`.
- **Token format:** `xar_` followed by 40 base62 characters (`[0-9A-Za-z]`) drawn from `random_bytes` without modulo bias. Store only `hash('sha256', $token)` in `token_hash` (unique), compare with `hash_equals`, and never log, echo (except once from `token:create`) or put a token in a URL.
- **Scopes:** `blog:write` and `media:write` (`Token::SCOPES`). `last_used_at` is written at most once in 60 seconds per token (`TokenRepository::TOUCH_SECONDS`), by a conditional `UPDATE`.
- **Patterns:** handle `[a-z0-9_-]{2,32}`; post id (ULID) `[0-9a-hjkmnp-tv-z]{26}`.
- **Routes:** `blog.api.posts` `POST /api/blogs/{handle}/posts`; `blog.api.post` `GET`, `blog.api.post.replace` `PUT`, `blog.api.post.patch` `PATCH`, `blog.api.post.delete` `DELETE /api/posts/{id}`; `blog.api.post.undelete` `POST /api/posts/{id}/undelete`. These use `csrf:off`, `token:blog:write`. `blog.api.media` `POST /api/blogs/{handle}/media` uses `csrf:off`, `token:media:write`. `micropub` `POST /micropub` uses `csrf:off`, `micropubErrors`, `micropubToken`, `token:blog:write`. `micropub.query` `GET /micropub` uses `micropubErrors`, `micropubToken`, `token:blog:write`. `micropub.media` `POST /micropub/media` uses `csrf:off`, `micropubErrors`, `micropubToken`, `token:media:write`.
- **Write-path invariants:** writes go through `PostWriter` only, are allowed on native blogs only (409 otherwise), set `updated_at`, write tags to `post_tags` as the Syncer does, leave `source_hash` null, and dispatch `ItemCreated`/`ItemUpdated`/`ItemDeleted` (module `blog`, itemtype `post`) only after the transaction commits. A new post gets a fresh ULID and `item_id = {app.url}/s/{id}`. A JSON Feed item round-trips: serialising the stored post gives back the item plus `id`, `url` and the derived fields (`title`, `date_published`, `_athenana.kind`, `_athenana.tags`), compared as decoded JSON.
- **Uploads (spec §5.4, verbatim limits):** 10 MB per file; types by `finfo` from `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif`, `audio/mpeg`; stored at `var/uploads/<handle>/<ulid>.<ext>` with the extension mapped from the detected type; the client's file name and type are never used.
- **Error bodies:** JSON API `{"error": {"status", "message", "fields"?}}`; Micropub `{"error": "invalid_request"|"unauthorized"|"forbidden"|"insufficient_scope", "error_description": "…"}` with 400, 401 or 403.
- **Code conventions:** PHP files start with `<?php`, a blank line, then `declare(strict_types=1);`. Raw SQL uses `{table}` placeholders. Tests extend `AppTestCase` (through `BlogTestCase`, `TokensTestCase`, `ApiTestCase` or `MicropubTestCase`); test properties are never `readonly`, because `tearDown()` unsets them. No templates are added, so the `.php`/`.twig` parity rule does not apply.
- **Commits:** one per task. Every commit message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, used exactly, passed as a second `-m`. Do not push.
- **Checks before every commit:** `composer test && composer stan && composer cs`. Then run the task's tests on MySQL: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit <task test paths>`. CI runs PHP 8.3/8.4 × sqlite/mysql/pgsql; Postgres runs only there.

## Review Focus

1. **A `blog:write` token pinned to one blog, aimed at another blog's post by id** (`/api/posts/{id}` and Micropub `url=`): 403 for every method, and the post is unchanged. Tests: Task 6 `testAPinnedTokenCannotReachAnotherBlogsPostsById`, Task 10 `testAPinnedTokenCannotTouchAnotherBlog`.
2. **A future `date_published` (scheduled post):** nothing public shows it (feed.json, feed.xml, `/s/{id}.json`, `/s/{id}`, the home page, search) until its time, while the API reports `_phoenix.status: scheduled`. Test: Task 6 `testAScheduledPostIsHiddenEverywhereButTheApi`.
3. **A polyglot or oversize upload, or a multipart body with no `file` field:** 422, 413 or 400, and no row or file is left behind. Tests: Task 7 `testAPolyglotImageIs422`, `testAnOversizeFileIs413`, `testMultipartWithoutAFileFieldIs400`.
4. **A PATCH that nulls a required field** (`date_published`, or every piece of content): 422 naming the field, and nothing is written. Tests: Task 5 `testPatchCannotRemoveTheDateOrEveryPieceOfContent`, Task 10 `testBadUpdatesAre400` (Micropub `delete: ["published"]`).
5. **A Micropub update on a deleted post:** 400 `invalid_request` "The post is deleted; undelete it first", and the post keeps its content. Test: Task 10 `testUpdatingADeletedPostIs400`.

---

## File Map

```
src/Kernel/Module/Manifest.php, ModuleRegistry.php       middleware(): module middleware aliases            [Task 1]
src/Kernel/Http/MiddlewareRegistry.php, App.php          has(); register module aliases                     [Task 1]
src/Kernel/Http/Exception/ValidationFailed.php, HttpException.php   422 with fields; 409/413/415 reasons    [Task 1]
src/Kernel/Http/Middleware/ErrorHandler.php              /api JSON errors, error.fields                     [Task 1]
composer.json                                            autoload-dev for Tokens and Micropub               [Task 2]
modules/tokens/module.json, migrations/2026_10_11_200001_create_tokens.php                                  [Tasks 2, 3]
modules/tokens/src/Token.php, TokenRepository.php, Cli/Token{Create,List,Revoke}Command.php                 [Task 2]
modules/tokens/src/TokenError.php, TokenMiddleware.php                                                      [Task 3]
modules/blog/src/Post/PostRepository.php                 insert, rewrite, setStatus [Task 4]; findByItemId [Task 10]
modules/blog/src/Write/ItemValidator.php, PostWriter.php [Task 4]; MergePatch.php, PostWriter changes [Task 5]
modules/blog/src/Http/Api/JsonBody.php, ApiAccess.php, PostApiController.php                                [Task 6]
modules/blog/src/Routes.php                              API routes [Task 6], media route [Task 7]
modules/blog/src/Media/MediaRejected.php, MediaUploader.php, MediaStore.php (ingest)                        [Task 7]
modules/blog/src/Http/Api/MediaApiController.php                                                            [Task 7]
modules/blog/src/Cli/MediaLocalizeCommand.php, modules/blog/module.json                                     [Task 8]
modules/micropub/module.json, src/{Routes,MicropubErrors,FormToken,MicropubInput,Mf2Mapper,Destination,MicropubController}.php  [Task 9]
modules/micropub/src/PostLocator.php, Mf2Mapper::patchFor, MicropubController update/delete/undelete       [Task 10]
modules/micropub/src/MicropubMediaController.php, MicropubController::query, Routes                         [Task 11]
README.md, docs/superpowers/plans/2026-10-08-phoenix-blog-plan-a-followups.md                               [Task 13]
tests/Kernel/Http/{ErrorHandlerTest,ModuleMiddlewareTest}.php, tests/Kernel/Module/ManifestTest.php         [Task 1]
tests/Module/Tokens/{TokensTestCase,TokenRepositoryTest,TokenCommandsTest,TokenMiddlewareTest}.php          [Tasks 2, 3]
tests/Module/Blog/Write/{PostWriterTestCase,PostWriterCreateTest,PostWriterChangeTest}.php                  [Tasks 4, 5]
tests/Module/Blog/Api/{ApiTestCase,PostApiTest,MediaApiTest}.php, tests/Module/Blog/Cli/MediaLocalizeCommandTest.php  [Tasks 6-8]
tests/Module/Micropub/{MicropubTestCase,MicropubCreateTest,MicropubUpdateTest,MicropubQueryTest,MicropubMediaTest,PlanBAcceptanceTest}.php  [Tasks 9-12]
```

### Task 1: Kernel: module middleware aliases, 422 field errors, JSON errors under `/api`

**Files:**
- Create: `src/Kernel/Http/Exception/ValidationFailed.php`
- Modify: `src/Kernel/Module/Manifest.php`, `src/Kernel/Module/ModuleRegistry.php`, `src/Kernel/Http/MiddlewareRegistry.php`, `src/Kernel/App.php`, `src/Kernel/Http/Exception/HttpException.php`, `src/Kernel/Http/Middleware/ErrorHandler.php`
- Test: `tests/Kernel/Http/ErrorHandlerTest.php`, `tests/Kernel/Module/ManifestTest.php`, `tests/Kernel/Http/ModuleMiddlewareTest.php` (new)

**Interfaces:**
- **Consumes:** `MiddlewareRegistry::register(string $alias, string|Closure $factory)` (a class gets the spec args through a constructor parameter named `$args`); `ModuleRegistry::enabled()`; `Fixtures::module()`.
- **Produces:**
  - `Manifest::middleware(): array<string, string>`: alias (`[a-z][A-Za-z0-9_]*`) => class. It throws `ModuleException("{name}: middleware must map aliases ([a-z][A-Za-z0-9_]*) to class names")`, and `ModuleRegistry::enable()` validates it before any state changes.
  - `MiddlewareRegistry::has(string $alias): bool` (an alias or a marker).
  - In `App`, the `MiddlewareRegistry` factory registers each enabled module's aliases after the kernel's. A taken alias is skipped and logged as `Module '{name}' cannot register middleware '{alias}': the alias is taken`.
  - `Xaraya\Kernel\Http\Exception\ValidationFailed extends HttpException`: `__construct(array<string, string> $fields, string $message = 'The request has invalid fields')`, status 422, `fields(): array<string, string>`.
  - `ErrorHandler`: the JSON body adds `error.fields` for a `ValidationFailed`, and a path equal to `/api` or starting with `/api/` (case-insensitive) always answers in JSON.
  - `HttpException::reason()`: 409 `Conflict`, 413 `Content Too Large`, 415 `Unsupported Media Type`.

- [ ] **Step 1: Write the failing tests**

In `tests/Kernel/Http/ErrorHandlerTest.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Pipeline;

```

with:

```php
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\MethodNotAllowed;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Pipeline;

```

In `tests/Kernel/Http/ErrorHandlerTest.php` (2 of 2), replace:

```php
        self::assertStringContainsString('application/json', $r2->getHeaderLine('Content-Type'));
    }

    public function testExceptionHeadersArePreserved(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger()), new ServerRequest('PUT', '/x'), new MethodNotAllowed(['GET', 'POST']));
```

with:

```php
        self::assertStringContainsString('application/json', $r2->getHeaderLine('Content-Type'));
    }

    public function testApiPathsAnswerInJsonWhateverTheAcceptHeader(): void
    {
        $eh = new ErrorHandler(new MemoryLogger());
        foreach (['/api/posts/x', '/API/blogs/a/posts', '/api'] as $path) {
            $response = $this->send($eh, (new ServerRequest('GET', $path))->withHeader('Accept', 'text/html'), new NotFound());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'), $path);
            self::assertSame(['error' => ['status' => 404, 'message' => 'Not Found']], json_decode((string) $response->getBody(), true));
        }
        $html = $this->send($eh, new ServerRequest('GET', '/apiary'), new NotFound());
        self::assertStringContainsString('text/html', $html->getHeaderLine('Content-Type'));
    }

    public function testValidationFailedCarriesItsFieldsInJsonOnly(): void
    {
        $eh = new ErrorHandler(new MemoryLogger());
        $e = new ValidationFailed(['title' => 'Must be a string', 'tags' => 'Must be a list of strings'], 'Invalid item');
        $json = $this->send($eh, new ServerRequest('POST', '/api/blogs/a/posts'), $e);
        self::assertSame(422, $json->getStatusCode());
        self::assertSame(
            ['error' => ['status' => 422, 'message' => 'Invalid item', 'fields' => ['title' => 'Must be a string', 'tags' => 'Must be a list of strings']]],
            json_decode((string) $json->getBody(), true),
        );
        $html = $this->send($eh, new ServerRequest('POST', '/form'), $e);
        self::assertSame(422, $html->getStatusCode());
        self::assertStringContainsString('Invalid item', (string) $html->getBody());
    }

    public function testNewReasonPhrases(): void
    {
        self::assertSame('Conflict', HttpException::reason(409));
        self::assertSame('Content Too Large', HttpException::reason(413));
        self::assertSame('Unsupported Media Type', HttpException::reason(415));
    }

    public function testExceptionHeadersArePreserved(): void
    {
        $response = $this->send(new ErrorHandler(new MemoryLogger()), new ServerRequest('PUT', '/x'), new MethodNotAllowed(['GET', 'POST']));
```

In `tests/Kernel/Module/ManifestTest.php`, replace:

```php
        self::assertSame('Xaraya\\Module\\HelloHooks\\', Manifest::fromDirectory($dir)->namespace());
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ModuleException::class);
```

with:

```php
        self::assertSame('Xaraya\\Module\\HelloHooks\\', Manifest::fromDirectory($dir)->namespace());
    }

    public function testReadsMiddlewareAliases(): void
    {
        $dir = $this->tempModule('{"name": "mw", "version": "1", "middleware": {"token": "Xaraya\\\\Module\\\\Mw\\\\Token"}}');
        self::assertSame(['token' => 'Xaraya\\Module\\Mw\\Token'], Manifest::fromDirectory($dir)->middleware());
        self::assertSame([], Manifest::fromDirectory(self::FIXTURES . '/alpha')->middleware());
    }

    public function testBadMiddlewareAliasesThrow(): void
    {
        foreach (['{"Token": "X"}', '{"to:ken": "X"}', '{"token": ""}', '{"token": 5}', '["X"]'] as $middleware) {
            try {
                Manifest::fromDirectory($this->tempModule('{"name": "mw", "version": "1", "middleware": ' . $middleware . '}'))->middleware();
                self::fail("{$middleware} must be rejected");
            } catch (ModuleException $e) {
                self::assertStringContainsString('middleware must map', $e->getMessage());
            }
        }
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(ModuleException::class);
```

`tests/Kernel/Http/ModuleMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Http;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AppTestCase;
use Xaraya\Tests\Support\Fixtures;

final class ModuleMiddlewareTest extends AppTestCase
{
    private const STAMP = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Xaraya\Module\Mw;

        use Psr\Http\Message\ResponseInterface;
        use Psr\Http\Message\ServerRequestInterface;
        use Psr\Http\Server\MiddlewareInterface;
        use Psr\Http\Server\RequestHandlerInterface;

        final class Stamp implements MiddlewareInterface
        {
            /** @param list<string> $args */
            public function __construct(private readonly array $args = []) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Stamp', implode('|', $this->args));
            }
        }
        PHP;

    private const ROUTES = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Xaraya\Module\Mw;

        use Nyholm\Psr7\Response;
        use Xaraya\Kernel\Routing\RouteCollector;
        use Xaraya\Kernel\Routing\RouteProvider;

        final class Routes implements RouteProvider
        {
            public function routes(RouteCollector $routes): void
            {
                $routes->get('/stamped', fn() => new Response(200, [], 'ok'), 'mw.stamped')->middleware('stamp:blog:write,media:write');
                $routes->get('/cors', fn() => new Response(200, [], 'ok'), 'mw.cors')->middleware('cors');
            }
        }
        PHP;

    private function app(string $middleware): App
    {
        Fixtures::module($this->tmp . '/modules', 'mw', [
            'routes' => 'Xaraya\\Module\\Mw\\Routes',
            'middleware' => json_decode($middleware, true),
        ], ['src/Stamp.php' => self::STAMP, 'src/Routes.php' => self::ROUTES]);
        $this->boot([$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('mw');

        return $this->boot([$this->tmp . '/modules']);
    }

    public function testAModuleAliasTakesColonArguments(): void
    {
        $response = $this->app('{"stamp": "Xaraya\\\\Module\\\\Mw\\\\Stamp"}')->handle(new ServerRequest('GET', '/stamped'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('blog:write|media:write', $response->getHeaderLine('X-Stamp'));
    }

    public function testAModuleCannotReplaceAKernelAlias(): void
    {
        $app = $this->app('{"stamp": "Xaraya\\\\Module\\\\Mw\\\\Stamp", "cors": "Xaraya\\\\Module\\\\Mw\\\\Stamp"}');
        $response = $app->handle(new ServerRequest('GET', '/cors'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('X-Stamp'));
        self::assertStringContainsString("Module 'mw' cannot register middleware 'cors': the alias is taken", (string) file_get_contents((string) glob($this->tmp . '/logs/*.log')[0]));
    }

    public function testEnableRejectsABadMiddlewareMap(): void
    {
        Fixtures::module($this->tmp . '/modules', 'bad', ['middleware' => ['Bad Alias' => 'X']]);
        $this->expectException(ModuleException::class);
        $this->boot([$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('bad');
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Http/ErrorHandlerTest.php tests/Kernel/Module/ManifestTest.php tests/Kernel/Http/ModuleMiddlewareTest.php`
Expected: FAIL. `Class "Xaraya\Kernel\Http\Exception\ValidationFailed" not found`, `Call to undefined method Xaraya\Kernel\Module\Manifest::middleware()`, `Path must not be empty` (nothing was logged), and failed assertions for the `/api` JSON and the reason phrases.

- [ ] **Step 3: Implement**

`src/Kernel/Http/Exception/ValidationFailed.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Exception;

/** A 422 whose JSON error body carries `fields`: {name: message}. */
final class ValidationFailed extends HttpException
{
    /** @param array<string, string> $fields field name => message */
    public function __construct(private readonly array $fields, string $message = 'The request has invalid fields')
    {
        parent::__construct(422, $message);
    }

    /** @return array<string, string> */
    public function fields(): array
    {
        return $this->fields;
    }
}
```

In `src/Kernel/Http/Exception/HttpException.php`, replace:

```php
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
```

with:

```php
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            413 => 'Content Too Large',
            415 => 'Unsupported Media Type',
            419 => 'Page Expired',
            422 => 'Unprocessable Content',
            429 => 'Too Many Requests',
```

In `src/Kernel/Http/Middleware/ErrorHandler.php` (1 of 4), replace:

```php
use Throwable;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;

final class ErrorHandler implements MiddlewareInterface
{
```

with:

```php
use Throwable;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;

final class ErrorHandler implements MiddlewareInterface
{
```

In `src/Kernel/Http/Middleware/ErrorHandler.php` (2 of 4), replace:

```php
            }
            $message = $status >= 500 && !$this->debug ? HttpException::reason($status) : $cause->getMessage();

            return $this->respond($request, $status, $message, $e->headers(), $cause);
        } catch (Throwable $e) {
            $this->log($e);

```

with:

```php
            }
            $message = $status >= 500 && !$this->debug ? HttpException::reason($status) : $cause->getMessage();

            return $this->respond($request, $status, $message, $e->headers(), $cause, $e instanceof ValidationFailed ? $e->fields() : []);
        } catch (Throwable $e) {
            $this->log($e);

```

In `src/Kernel/Http/Middleware/ErrorHandler.php` (3 of 4), replace:

```php
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
```

with:

```php
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $fields
     */
    private function respond(ServerRequestInterface $request, int $status, string $message, array $headers, Throwable $e, array $fields = []): ResponseInterface
    {
        $trace = $this->debug && $status >= 500
            ? $e::class . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
            : null;
        if ($this->wantsJson($request)) {
            $error = ['status' => $status, 'message' => $message];
            if ($fields !== []) {
                $error['fields'] = $fields;
            }
            if ($trace !== null) {
                $error['trace'] = $trace;
            }
```

In `src/Kernel/Http/Middleware/ErrorHandler.php` (4 of 4), replace:

```php

    private function wantsJson(ServerRequestInterface $request): bool
    {
        if (str_ends_with(strtolower($request->getUri()->getPath()), '.json')) {
            return true;
        }
        $accept = $request->getHeaderLine('Accept');
```

with:

```php

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = strtolower($request->getUri()->getPath());
        // JSON paths and the /api/ prefix always answer in JSON, whatever the Accept header says.
        if (str_ends_with($path, '.json') || $path === '/api' || str_starts_with($path, '/api/')) {
            return true;
        }
        $accept = $request->getHeaderLine('Accept');
```

In `src/Kernel/Module/Manifest.php`, replace:

```php
        return $out;
    }

    /** @return list<array{subject: string, itemtype: string}> bindings seeded when this (observer) module is enabled */
    public function hookDefaults(): array
    {
```

with:

```php
        return $out;
    }

    /**
     * Route middleware aliases this module adds, e.g. {"token": "Xaraya\\Module\\Tokens\\TokenMiddleware"}.
     * A route then uses "token" or "token:<args>"; the class receives the args as a constructor parameter named $args.
     *
     * @return array<string, string> alias => class implementing MiddlewareInterface
     */
    public function middleware(): array
    {
        $out = [];
        foreach ((array) ($this->data['middleware'] ?? []) as $alias => $class) {
            if (!is_string($alias) || preg_match('/^[a-z][A-Za-z0-9_]*$/D', $alias) !== 1 || !is_string($class) || $class === '') {
                throw new ModuleException("{$this->name}: middleware must map aliases ([a-z][A-Za-z0-9_]*) to class names");
            }
            $out[$alias] = $class;
        }

        return $out;
    }

    /** @return list<array{subject: string, itemtype: string}> bindings seeded when this (observer) module is enabled */
    public function hookDefaults(): array
    {
```

In `src/Kernel/Module/ModuleRegistry.php`, replace:

```php
        $manifest->hookDefaults();
        $manifest->blocks();
        $manifest->blockDefaults();
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
        $this->migrator->migrate(['kernel' => $this->kernelMigrations]);
```

with:

```php
        $manifest->hookDefaults();
        $manifest->blocks();
        $manifest->blockDefaults();
        $manifest->middleware();
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
        $this->migrator->migrate(['kernel' => $this->kernelMigrations]);
```

In `src/Kernel/Http/MiddlewareRegistry.php`, replace:

```php
        $this->markers[$alias] = true;
    }

    public function isMarker(string $spec): bool
    {
        return isset($this->markers[explode(':', $spec, 2)[0]]);
```

with:

```php
        $this->markers[$alias] = true;
    }

    /** True when $alias is registered, as middleware or as a marker. */
    public function has(string $alias): bool
    {
        return isset($this->aliases[$alias]) || isset($this->markers[$alias]);
    }

    public function isMarker(string $spec): bool
    {
        return isset($this->markers[explode(':', $spec, 2)[0]]);
```

In `src/Kernel/App.php`, replace:

```php
            $registry->register('conditional', ConditionalGet::class);
            $registry->register('secureHtml', SecureHtml::class);
            $registry->registerMarker('csrf');

            return $registry;
        });
```

with:

```php
            $registry->register('conditional', ConditionalGet::class);
            $registry->register('secureHtml', SecureHtml::class);
            $registry->registerMarker('csrf');
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
                try {
                    $aliases = $manifest->middleware();
                } catch (\Throwable $e) {
                    $c->get(LoggerInterface::class)->error("Ignoring the middleware of module '{$manifest->name}': {$e->getMessage()}", ['exception' => $e]);
                    continue;
                }
                foreach ($aliases as $alias => $class) {
                    if ($registry->has($alias)) {
                        $c->get(LoggerInterface::class)->error("Module '{$manifest->name}' cannot register middleware '{$alias}': the alias is taken");
                        continue;
                    }
                    $registry->register($alias, $class);
                }
            }

            return $registry;
        });
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel`
Expected: PASS (`ErrorHandlerTest` 17 tests, `ManifestTest` 9, `ModuleMiddlewareTest` 3, and the rest of the kernel suite).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (570 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Kernel/Http tests/Kernel/Module`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Kernel tests/Kernel
git commit -m "feat(kernel): let modules add middleware aliases; 422 field errors and JSON errors under /api" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 2: `tokens` module: table, repository, `token:create|list|revoke`

**Files:**
- Create: `modules/tokens/module.json`, `modules/tokens/migrations/2026_10_11_200001_create_tokens.php`, `modules/tokens/src/Token.php`, `modules/tokens/src/TokenRepository.php`, `modules/tokens/src/Cli/TokenCreateCommand.php`, `modules/tokens/src/Cli/TokenListCommand.php`, `modules/tokens/src/Cli/TokenRevokeCommand.php`
- Modify: `composer.json` (`autoload-dev`)
- Test: `tests/Module/Tokens/TokensTestCase.php`, `tests/Module/Tokens/TokenRepositoryTest.php`, `tests/Module/Tokens/TokenCommandsTest.php`

**Interfaces:**
- **Consumes:** `BlogRepository::find(string $handle): ?Blog`, `findById(int): ?Blog`; `Connection::insert/select/delete/query` (`query()` expands `{tokens}` and normalises `DateTimeImmutable` params to UTC `Y-m-d H:i:s`); `Ulid::generate()`; `BlogTestCase::enableBlog()`, `xar()`, `nativeBlog()`, `db()`, `now()`.
- **Produces:**
  - Table `xar_tokens`: `id` ulid pk, `name` string(64), `token_hash` string(64) unique, `scopes` json, `blog_id` int nullable (FK blogs), `last_used_at`, `expires_at` (nullable), `created_at`.
  - `Xaraya\Module\Tokens\Token` (readonly): `id`, `name`, `scopes` (list<string>), `?int blogId`, `?DateTimeImmutable lastUsedAt`, `?DateTimeImmutable expiresAt`, `DateTimeImmutable createdAt`. It has `SCOPES = ['blog:write', 'media:write']`, `fromRow(array): self`, `hasScope(string): bool`, `allowsBlog(int $blogId): bool` (true when not pinned) and `isExpired(DateTimeImmutable $now): bool` (`expiresAt <= now`).
  - `Xaraya\Module\Tokens\TokenRepository`:
    - Constants `PREFIX = 'xar_'`, `LENGTH = 40`, `TOUCH_SECONDS = 60`.
    - `static generate(): string`, `static hash(string): string` (sha256 hex), `static looksLikeToken(string): bool`.
    - `create(string $name, list<string> $scopes, ?int $blogId, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): array{0: Token, 1: string}`. It throws `InvalidArgumentException` for a bad name, scopes or expiry.
    - `findByPlaintext(string): ?Token` (expired or not), `find(string $id): ?Token`, `all(): list<Token>`, `revoke(string $id): bool`, `touch(Token, DateTimeImmutable $now): bool`.
  - Commands `token:create --name= --scopes=a,b [--blog=<handle>] [--expires=<N>d]` (prints 3 lines, the token last), `token:list`, `token:revoke <id>`.
  - Test helpers (`TokensTestCase extends BlogTestCase`): `enableTokens(): App` and `token(string $scopes = 'blog:write', list<string> $extra = []): string` (the plaintext).

- [ ] **Step 1: Write the failing tests**

`tests/Module/Tokens/TokensTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Xaraya\Kernel\App;
use Xaraya\Tests\Module\Blog\BlogTestCase;

abstract class TokensTestCase extends BlogTestCase
{
    /** Enables blog, then tokens; returns a fresh app. */
    protected function enableTokens(): App
    {
        $this->enableBlog();
        [$code, $out, $err] = $this->xar(['module:enable', 'tokens']);
        self::assertSame(0, $code, $out . $err);

        return $this->app();
    }

    /**
     * Creates a token through `xar token:create` and returns its plaintext.
     *
     * @param list<string> $extra e.g. ['--blog=notes', '--expires=1d']
     */
    protected function token(string $scopes = 'blog:write', array $extra = []): string
    {
        [$code, $out, $err] = $this->xar(['token:create', '--name=test', '--scopes=' . $scopes, ...$extra]);
        self::assertSame(0, $code, $out . $err);
        $lines = explode("\n", trim($out));

        return (string) end($lines);
    }
}
```

`tests/Module/Tokens/TokenRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenRepositoryTest extends TokensTestCase
{
    private function repo(): TokenRepository
    {
        return $this->app()->container()->get(TokenRepository::class);
    }

    public function testGeneratesBase62TokensWithThePrefix(): void
    {
        $seen = [];
        for ($i = 0; $i < 50; $i++) {
            $token = TokenRepository::generate();
            self::assertMatchesRegularExpression('/^xar_[0-9A-Za-z]{40}$/D', $token);
            $seen[$token] = true;
        }
        self::assertCount(50, $seen);
    }

    public function testStoresOnlyTheHashAndFindsByPlaintext(): void
    {
        $app = $this->enableTokens();
        $now = $this->now();
        [$token, $plaintext] = $this->repo()->create('ci', ['blog:write', 'media:write', 'blog:write'], null, null, $now);
        self::assertSame(['blog:write', 'media:write'], $token->scopes);
        self::assertNull($token->blogId);

        $row = $this->db($app)->select('tokens')->where('id', '=', $token->id)->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $plaintext), $row['token_hash']);
        self::assertStringNotContainsString(substr($plaintext, 4), (string) json_encode($row));

        self::assertSame($token->id, $this->repo()->findByPlaintext($plaintext)?->id);
        self::assertNull($this->repo()->findByPlaintext('xar_' . str_repeat('A', 40)));
        self::assertNull($this->repo()->findByPlaintext($plaintext . 'x'));
        self::assertNull($this->repo()->findByPlaintext(''));
    }

    public function testValidatesNameScopesAndExpiry(): void
    {
        $this->enableTokens();
        $now = $this->now();
        foreach ([['', ['blog:write'], null], [str_repeat('n', 65), ['blog:write'], null], ['x', [], null], ['x', ['admin'], null], ['x', ['blog:write'], $now]] as [$name, $scopes, $expires]) {
            try {
                $this->repo()->create($name, $scopes, null, $expires, $now);
                self::fail('must be rejected: ' . json_encode([$name, $scopes]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTokenRules(): void
    {
        $now = new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('UTC'));
        $pinned = new Token('t', 'n', ['blog:write'], 7, null, $now, $now);
        self::assertTrue($pinned->hasScope('blog:write'));
        self::assertFalse($pinned->hasScope('media:write'));
        self::assertTrue($pinned->allowsBlog(7));
        self::assertFalse($pinned->allowsBlog(8));
        self::assertTrue($pinned->isExpired($now));
        self::assertFalse($pinned->isExpired($now->modify('-1 second')));
        $open = new Token('t', 'n', ['blog:write'], null, null, null, $now);
        self::assertTrue($open->allowsBlog(8));
        self::assertFalse($open->isExpired($now->modify('+100 years')));
    }

    public function testTouchWritesAtMostOnceAMinute(): void
    {
        $this->enableTokens();
        $now = new DateTimeImmutable('2026-10-11 12:00:00', new DateTimeZone('UTC'));
        [$token, $plaintext] = $this->repo()->create('ci', ['blog:write'], null, null, $now);

        // Two requests that loaded the token before either wrote: only one UPDATE matches.
        $first = $this->repo()->findByPlaintext($plaintext);
        $second = $this->repo()->findByPlaintext($plaintext);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertTrue($this->repo()->touch($first, $now));
        self::assertFalse($this->repo()->touch($second, $now->modify('+1 second')));

        $fresh = $this->repo()->findByPlaintext($plaintext);
        self::assertNotNull($fresh);
        self::assertEquals($now, $fresh->lastUsedAt);
        self::assertFalse($this->repo()->touch($fresh, $now->modify('+59 seconds')), 'no query inside the minute');
        self::assertTrue($this->repo()->touch($fresh, $now->modify('+60 seconds')));
    }

    public function testRevokeDeletes(): void
    {
        $this->enableTokens();
        [$token, $plaintext] = $this->repo()->create('ci', ['blog:write'], null, null, $this->now());
        self::assertTrue($this->repo()->revoke($token->id));
        self::assertFalse($this->repo()->revoke($token->id));
        self::assertNull($this->repo()->findByPlaintext($plaintext));
    }
}
```

`tests/Module/Tokens/TokenCommandsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Xaraya\Module\Tokens\TokenRepository;

final class TokenCommandsTest extends TokensTestCase
{
    public function testTokensNeedTheBlogModule(): void
    {
        [$code, , $err] = $this->xar(['module:enable', 'tokens']);
        self::assertSame(1, $code);
        self::assertStringContainsString("requires 'blog'", $err);
    }

    public function testCreatePrintsTheTokenOnceAndStoresItsHash(): void
    {
        $app = $this->enableTokens();
        $this->nativeBlog($app);
        [$code, $out, $err] = $this->xar(['token:create', '--name=phone', '--scopes=blog:write,media:write', '--blog=notes', '--expires=30d']);
        self::assertSame(0, $code, $err);
        $lines = explode("\n", trim($out));
        self::assertCount(3, $lines);
        self::assertMatchesRegularExpression("/^Created token [0-9a-z]{26} 'phone' \\(blog:write, media:write; blog notes; expires \\d{4}-\\d\\d-\\d\\d \\d\\d:\\d\\d UTC\\)\\.$/", $lines[0]);
        self::assertSame('Copy it now; it is not shown again:', $lines[1]);
        self::assertMatchesRegularExpression('/^xar_[0-9A-Za-z]{40}$/D', $lines[2]);

        $token = $this->app()->container()->get(TokenRepository::class)->findByPlaintext($lines[2]);
        self::assertNotNull($token);
        self::assertSame($this->blogs($this->app())->find('notes')?->id, $token->blogId);
        self::assertNotNull($token->expiresAt);
        self::assertEqualsWithDelta($this->now()->modify('+30 days')->getTimestamp(), $token->expiresAt->getTimestamp(), 5);

        [$code, $list] = $this->xar(['token:list']);
        self::assertSame(0, $code);
        self::assertStringContainsString($token->id, $list);
        self::assertStringContainsString('phone', $list);
        self::assertStringContainsString('notes', $list);
        self::assertStringNotContainsString($lines[2], $list);
    }

    public function testCreateRejectsBadInput(): void
    {
        $this->enableTokens();
        foreach ([
            [['--name=x'], 'Usage'],
            [['--name=x', '--scopes=admin'], 'Scopes must be'],
            [['--name=x', '--scopes=blog:write', '--blog=nope'], "Unknown blog 'nope'"],
            [['--name=x', '--scopes=blog:write', '--expires=tomorrow'], '--expires must be'],
            [['--name=x', '--scopes=blog:write', '--expires=0d'], '--expires must be'],
        ] as [$args, $message]) {
            [$code, , $err] = $this->xar(['token:create', ...$args]);
            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err);
        }
        self::assertSame([], $this->app()->container()->get(TokenRepository::class)->all());
    }

    public function testRevoke(): void
    {
        $this->enableTokens();
        $plaintext = $this->token();
        $repo = $this->app()->container()->get(TokenRepository::class);
        $id = $repo->findByPlaintext($plaintext)?->id;
        self::assertNotNull($id);

        [$code, $out] = $this->xar(['token:revoke', $id]);
        self::assertSame(0, $code);
        self::assertSame("Revoked token {$id}.\n", $out);
        self::assertNull($repo->findByPlaintext($plaintext));

        [$code, , $err] = $this->xar(['token:revoke', $id]);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown token '{$id}'", $err);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Tokens`
Expected: FAIL. `Class "Xaraya\Module\Tokens\TokenRepository" not found`, `Class "Xaraya\Module\Tokens\Token" not found`, and `Unknown module 'tokens'` from `module:enable`.

- [ ] **Step 3: Implement**

In `composer.json`, replace:

```json
    "autoload-dev": {
        "psr-4": {
            "Xaraya\\Tests\\": "tests/",
            "Xaraya\\Module\\Blog\\": "modules/blog/src/"
        }
    },
    "scripts": {
```

with:

```json
    "autoload-dev": {
        "psr-4": {
            "Xaraya\\Tests\\": "tests/",
            "Xaraya\\Module\\Blog\\": "modules/blog/src/",
            "Xaraya\\Module\\Micropub\\": "modules/micropub/src/",
            "Xaraya\\Module\\Tokens\\": "modules/tokens/src/"
        }
    },
    "scripts": {
```

Run: `composer dump-autoload`

`modules/tokens/module.json`:

```json
{
  "name": "tokens",
  "version": "0.1.0",
  "requires": { "kernel": "^0.1", "modules": { "blog": "^0.1" } },
  "migrations": "migrations",
  "commands": [
    "Xaraya\\Module\\Tokens\\Cli\\TokenCreateCommand",
    "Xaraya\\Module\\Tokens\\Cli\\TokenListCommand",
    "Xaraya\\Module\\Tokens\\Cli\\TokenRevokeCommand"
  ]
}
```

`modules/tokens/migrations/2026_10_11_200001_create_tokens.php`:

```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('tokens', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->string('token_hash', 64)->unique();
            $t->json('scopes');
            $t->int('blog_id')->nullable();
            $t->datetime('last_used_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at');
            $t->foreign('blog_id', 'blogs');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('tokens');
    }
};
```

`modules/tokens/src/Token.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use DateTimeImmutable;
use DateTimeZone;

/** A stored API token. The plaintext is never kept: only its sha256 is in xar_tokens. */
final class Token
{
    public const SCOPES = ['blog:write', 'media:write'];

    /** @param list<string> $scopes */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $scopes,
        public readonly ?int $blogId,
        public readonly ?DateTimeImmutable $lastUsedAt,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');
        $date = static fn(string $key): ?DateTimeImmutable => isset($row[$key]) && $row[$key] !== ''
            ? new DateTimeImmutable((string) $row[$key], $utc)
            : null;
        $scopes = is_string($row['scopes'] ?? null) ? json_decode($row['scopes'], true) : null;

        return new self(
            (string) $row['id'],
            (string) $row['name'],
            is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            isset($row['blog_id']) ? (int) $row['blog_id'] : null,
            $date('last_used_at'),
            $date('expires_at'),
            $date('created_at') ?? new DateTimeImmutable('@0'),
        );
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** A token with no blog may write to every blog; a pinned token only to its own. */
    public function allowsBlog(int $blogId): bool
    {
        return $this->blogId === null || $this->blogId === $blogId;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
```

`modules/tokens/src/TokenRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;

final class TokenRepository
{
    public const PREFIX = 'xar_';
    public const LENGTH = 40;
    /** last_used_at is written at most once in this many seconds per token. */
    public const TOUCH_SECONDS = 60;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly Connection $db) {}

    /** A new plaintext token: "xar_" and 40 base62 characters from random_bytes, without modulo bias. */
    public static function generate(): string
    {
        $out = '';
        while (strlen($out) < self::LENGTH) {
            foreach (str_split(random_bytes(64)) as $byte) {
                $n = ord($byte);
                // 248 = 4 × 62: bytes 248-255 are dropped so every character is equally likely.
                if ($n < 248 && strlen($out) < self::LENGTH) {
                    $out .= self::ALPHABET[$n % 62];
                }
            }
        }

        return self::PREFIX . $out;
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function looksLikeToken(string $value): bool
    {
        return preg_match('/^xar_[0-9A-Za-z]{40}$/D', $value) === 1;
    }

    /**
     * @param list<string> $scopes
     * @return array{0: Token, 1: string} the stored token and its plaintext, which is never stored
     */
    public function create(string $name, array $scopes, ?int $blogId, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new InvalidArgumentException('A token needs a name of 1 to 64 characters');
        }
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, Token::SCOPES) !== []) {
            throw new InvalidArgumentException('Scopes must be one or more of: ' . implode(', ', Token::SCOPES));
        }
        if ($expiresAt !== null && $expiresAt <= $now) {
            throw new InvalidArgumentException('A token must expire in the future');
        }
        $plaintext = self::generate();
        $id = Ulid::generate();
        $this->db->insert('tokens', [
            'id' => $id,
            'name' => $name,
            'token_hash' => self::hash($plaintext),
            'scopes' => $scopes,
            'blog_id' => $blogId,
            'last_used_at' => null,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return [$this->find($id) ?? throw new RuntimeException('Token insert failed'), $plaintext];
    }

    /** The token whose plaintext this is, expired or not; null for anything else. */
    public function findByPlaintext(string $plaintext): ?Token
    {
        if (!self::looksLikeToken($plaintext)) {
            return null;
        }
        $hash = self::hash($plaintext);
        $row = $this->db->select('tokens')->where('token_hash', '=', $hash)->first();

        return $row !== null && hash_equals((string) $row['token_hash'], $hash) ? Token::fromRow($row) : null;
    }

    public function find(string $id): ?Token
    {
        $row = $this->db->select('tokens')->where('id', '=', $id)->first();

        return $row === null ? null : Token::fromRow($row);
    }

    /** @return list<Token> oldest first */
    public function all(): array
    {
        return array_map(Token::fromRow(...), $this->db->select('tokens')->orderBy('created_at')->orderBy('id')->all());
    }

    public function revoke(string $id): bool
    {
        return $this->db->delete('tokens', ['id' => $id]) > 0;
    }

    /**
     * Records a use. Writes only when the stored last_used_at is a minute old or more, and the conditional
     * UPDATE lets only one of many concurrent requests do it. Returns whether this call wrote.
     */
    public function touch(Token $token, DateTimeImmutable $now): bool
    {
        $threshold = $now->modify('-' . self::TOUCH_SECONDS . ' seconds');
        if ($token->lastUsedAt !== null && $token->lastUsedAt > $threshold) {
            return false;
        }

        return $this->db->query(
            'UPDATE {tokens} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at <= ?)',
            [$now, $token->id, $threshold],
        )->rowCount() > 0;
    }
}
```

`modules/tokens/src/Cli/TokenCreateCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens\Cli;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenCreateCommand extends Command
{
    public function __construct(private readonly TokenRepository $tokens, private readonly BlogRepository $blogs) {}

    public function name(): string
    {
        return 'token:create';
    }

    public function description(): string
    {
        return 'Create an API token (shown once; only its hash is stored)';
    }

    public function usage(): string
    {
        return 'token:create --name=<name> --scopes=blog:write,media:write [--blog=<handle>] [--expires=<N>d]';
    }

    public function run(Input $input, Output $output): int
    {
        $name = $input->option('name');
        $scopes = $input->option('scopes');
        if ($name === null || $scopes === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $blogId = null;
        $handle = $input->option('blog');
        if ($handle !== null) {
            $blogId = ($this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'"))->id;
        }
        $expiresAt = null;
        $expires = $input->option('expires');
        if ($expires !== null) {
            if (preg_match('/^([1-9][0-9]{0,4})d$/D', $expires, $m) !== 1) {
                throw new InvalidArgumentException('--expires must be a number of days, like 90d');
            }
            $expiresAt = $now->modify("+{$m[1]} days");
        }
        [$token, $plaintext] = $this->tokens->create($name, array_map('trim', explode(',', $scopes)), $blogId, $expiresAt, $now);
        $output->line(sprintf(
            "Created token %s '%s' (%s%s%s).",
            $token->id,
            $token->name,
            implode(', ', $token->scopes),
            $handle === null ? '' : "; blog {$handle}",
            $expiresAt === null ? '' : '; expires ' . $expiresAt->format('Y-m-d H:i') . ' UTC',
        ));
        $output->line('Copy it now; it is not shown again:');
        $output->line($plaintext);

        return 0;
    }
}
```

`modules/tokens/src/Cli/TokenRevokeCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens\Cli;

use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenRevokeCommand extends Command
{
    public function __construct(private readonly TokenRepository $tokens) {}

    public function name(): string
    {
        return 'token:revoke';
    }

    public function description(): string
    {
        return 'Delete an API token';
    }

    public function usage(): string
    {
        return 'token:revoke <id>';
    }

    public function run(Input $input, Output $output): int
    {
        $id = $input->argument(0);
        if ($id === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        if (!$this->tokens->revoke($id)) {
            $output->error("Unknown token '{$id}'");

            return 1;
        }
        $output->line("Revoked token {$id}.");

        return 0;
    }
}
```

`modules/tokens/src/Cli/TokenListCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens\Cli;

use DateTimeImmutable;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenListCommand extends Command
{
    public function __construct(private readonly TokenRepository $tokens, private readonly BlogRepository $blogs) {}

    public function name(): string
    {
        return 'token:list';
    }

    public function description(): string
    {
        return 'List API tokens (never their values)';
    }

    public function run(Input $input, Output $output): int
    {
        $date = static fn(?DateTimeImmutable $d): string => $d === null ? '-' : $d->format('Y-m-d H:i');
        $rows = [];
        foreach ($this->tokens->all() as $token) {
            $rows[] = [
                $token->id,
                $token->name,
                implode(',', $token->scopes),
                $token->blogId === null ? '*' : ($this->blogs->findById($token->blogId)->handle ?? '?'),
                $date($token->lastUsedAt),
                $date($token->expiresAt),
            ];
        }
        $output->table(['id', 'name', 'scopes', 'blog', 'last used', 'expires'], $rows);

        return 0;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Tokens`
Expected: PASS (`TokenRepositoryTest` 6 tests, `TokenCommandsTest` 4).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (580 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Tokens`
Expected: PASS. `testTouchWritesAtMostOnceAMinute` pins the conditional `UPDATE` that keeps concurrent requests from both writing.

- [ ] **Step 6: Commit**

```bash
git add composer.json modules/tokens tests/Module/Tokens
git commit -m "feat(tokens): add the tokens module with hashed API tokens and token:create|list|revoke" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: The `token:<scope>` middleware

**Files:**
- Create: `modules/tokens/src/TokenError.php`, `modules/tokens/src/TokenMiddleware.php`
- Modify: `modules/tokens/module.json` (the `middleware` key from Task 1)
- Test: `tests/Module/Tokens/TokenMiddlewareTest.php`

**Interfaces:**
- **Consumes:** `TokenRepository::findByPlaintext()`, `touch()` (Task 2); `Token::isExpired()`, `hasScope()`, `allowsBlog()`; `BlogRepository::find()`; `MiddlewareRegistry::resolve()` with module aliases (Task 1); `HttpException(int $status, string $message, array $headers)`.
- **Produces:**
  - Alias `token` in `modules/tokens/module.json`. `token:blog:write` requires that scope, and `token:blog:write,media:write` requires both.
  - `TokenMiddleware::__construct(TokenRepository, BlogRepository, LoggerInterface, list<string> $args = [])` throws `LogicException("The token middleware needs a scope, as in 'token:blog:write'")` without args. `process()` sets the request attribute `Token::class`.
  - `Xaraya\Module\Tokens\TokenError extends HttpException`, with a public readonly `string $error` set to `unauthorized`, `insufficient_scope` or `forbidden`:
    - `missing()`: 401 `A bearer token is required`, `WWW-Authenticate: Bearer`.
    - `invalid()`: 401 `The token is invalid or expired`, `Bearer error="invalid_token"`.
    - `insufficientScope(string $scope)`: 403 `The token lacks the {scope} scope`, `Bearer error="insufficient_scope", scope="{scope}"`.
    - `wrongBlog()`: 403 `The token may not write to this blog`, `Bearer error="insufficient_scope"`.

- [ ] **Step 1: Write the failing test**

`tests/Module/Tokens/TokenMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;
use Xaraya\Module\Tokens\TokenRepository;

final class TokenMiddlewareTest extends TokensTestCase
{
    private App $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableTokens();
        $this->nativeBlog($this->app);
        $this->nativeBlog($this->app, ['handle' => 'other', 'title' => 'Other']);
    }

    private function send(ServerRequestInterface $request, string $spec = 'token:blog:write'): ResponseInterface
    {
        $middleware = $this->app()->container()->get(MiddlewareRegistry::class)->resolve($spec);
        $final = new CallableHandler(static function (ServerRequestInterface $r): ResponseInterface {
            $token = $r->getAttribute(Token::class);

            return new Response(200, ['X-Token' => $token instanceof Token ? $token->id : 'none']);
        });

        return (new Pipeline([$middleware], $final))->handle($request);
    }

    private static function bearer(string $token, string $uri = '/api/x'): ServerRequestInterface
    {
        return (new ServerRequest('POST', $uri))->withHeader('Authorization', 'Bearer ' . $token);
    }

    private function rejects(ServerRequestInterface $request, int $status, string $error, string $challenge, string $spec = 'token:blog:write'): void
    {
        try {
            $this->send($request, $spec);
            self::fail("expected {$status} {$error}");
        } catch (TokenError $e) {
            self::assertSame($status, $e->status());
            self::assertSame($error, $e->error);
            self::assertSame($challenge, $e->headers()['WWW-Authenticate']);
        }
    }

    public function testAcceptsAValidTokenAndSetsTheAttribute(): void
    {
        $plaintext = $this->token('blog:write,media:write');
        $id = $this->app->container()->get(TokenRepository::class)->findByPlaintext($plaintext)?->id;
        self::assertSame($id, $this->send(self::bearer($plaintext))->getHeaderLine('X-Token'));
        self::assertSame($id, $this->send(self::bearer($plaintext), 'token:blog:write,media:write')->getHeaderLine('X-Token'));
        self::assertSame($id, $this->send((new ServerRequest('GET', '/x'))->withHeader('Authorization', 'bearer  ' . $plaintext))->getHeaderLine('X-Token'));
    }

    public function testMissingMalformedUnknownAndExpiredTokensAre401(): void
    {
        $this->rejects(new ServerRequest('POST', '/api/x'), 401, 'unauthorized', 'Bearer');
        $this->rejects((new ServerRequest('POST', '/api/x'))->withHeader('Authorization', 'Basic dTpw'), 401, 'unauthorized', 'Bearer error="invalid_token"');
        $this->rejects(self::bearer('xar_' . str_repeat('a', 40)), 401, 'unauthorized', 'Bearer error="invalid_token"');

        $plaintext = $this->token('blog:write', ['--expires=1d']);
        $this->db($this->app)->update('tokens', ['expires_at' => $this->now()->modify('-1 second')], ['token_hash' => hash('sha256', $plaintext)]);
        $this->rejects(self::bearer($plaintext), 401, 'unauthorized', 'Bearer error="invalid_token"');
    }

    public function testATokenInTheQueryStringIsIgnored(): void
    {
        $plaintext = $this->token();
        $this->rejects(new ServerRequest('POST', '/api/x?access_token=' . $plaintext), 401, 'unauthorized', 'Bearer');
    }

    public function testAMissingScopeIs403(): void
    {
        $this->rejects(self::bearer($this->token('media:write')), 403, 'insufficient_scope', 'Bearer error="insufficient_scope", scope="blog:write"');
        $this->rejects(self::bearer($this->token('blog:write')), 403, 'insufficient_scope', 'Bearer error="insufficient_scope", scope="media:write"', 'token:blog:write,media:write');
    }

    public function testAPinnedTokenOnlyPassesForItsOwnHandle(): void
    {
        $plaintext = $this->token('blog:write', ['--blog=notes']);
        self::assertSame(200, $this->send(self::bearer($plaintext)->withAttribute('handle', 'notes'))->getStatusCode());
        $this->rejects(self::bearer($plaintext)->withAttribute('handle', 'other'), 403, 'forbidden', 'Bearer error="insufficient_scope"');
        self::assertSame(200, $this->send(self::bearer($this->token())->withAttribute('handle', 'other'))->getStatusCode(), 'an unpinned token writes anywhere');
    }

    public function testRecordsUseAtMostOnceAMinute(): void
    {
        $plaintext = $this->token();
        $repo = $this->app->container()->get(TokenRepository::class);
        self::assertNull($repo->findByPlaintext($plaintext)?->lastUsedAt);
        $this->send(self::bearer($plaintext));
        $used = $repo->findByPlaintext($plaintext)?->lastUsedAt;
        self::assertNotNull($used);

        $this->db($this->app)->update('tokens', ['last_used_at' => $used->modify('-30 seconds')], ['token_hash' => hash('sha256', $plaintext)]);
        $this->send(self::bearer($plaintext));
        self::assertEquals($used->modify('-30 seconds'), $repo->findByPlaintext($plaintext)?->lastUsedAt, 'a use inside the minute writes nothing');
    }

    public function testTheAliasNeedsAScope(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("needs a scope");
        $this->send(new ServerRequest('GET', '/'), 'token');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Tokens/TokenMiddlewareTest.php`
Expected: FAIL (6 errors, 1 failure): `Unknown middleware 'token'`.

- [ ] **Step 3: Implement**

In `modules/tokens/module.json`, replace:

```json
  "version": "0.1.0",
  "requires": { "kernel": "^0.1", "modules": { "blog": "^0.1" } },
  "migrations": "migrations",
  "commands": [
    "Xaraya\\Module\\Tokens\\Cli\\TokenCreateCommand",
    "Xaraya\\Module\\Tokens\\Cli\\TokenListCommand",
```

with:

```json
  "version": "0.1.0",
  "requires": { "kernel": "^0.1", "modules": { "blog": "^0.1" } },
  "migrations": "migrations",
  "middleware": { "token": "Xaraya\\Module\\Tokens\\TokenMiddleware" },
  "commands": [
    "Xaraya\\Module\\Tokens\\Cli\\TokenCreateCommand",
    "Xaraya\\Module\\Tokens\\Cli\\TokenListCommand",
```

`modules/tokens/src/TokenError.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use Xaraya\Kernel\Http\Exception\HttpException;

/**
 * A rejected bearer token. $error uses the Micropub/OAuth codes: unauthorized (401), insufficient_scope (403)
 * or forbidden (403). Messages never contain the token.
 */
final class TokenError extends HttpException
{
    private function __construct(int $status, public readonly string $error, string $message, string $challenge)
    {
        parent::__construct($status, $message, ['WWW-Authenticate' => $challenge]);
    }

    public static function missing(): self
    {
        return new self(401, 'unauthorized', 'A bearer token is required', 'Bearer');
    }

    public static function invalid(): self
    {
        return new self(401, 'unauthorized', 'The token is invalid or expired', 'Bearer error="invalid_token"');
    }

    public static function insufficientScope(string $scope): self
    {
        return new self(403, 'insufficient_scope', "The token lacks the {$scope} scope", 'Bearer error="insufficient_scope", scope="' . $scope . '"');
    }

    public static function wrongBlog(): self
    {
        return new self(403, 'forbidden', 'The token may not write to this blog', 'Bearer error="insufficient_scope"');
    }
}
```

`modules/tokens/src/TokenMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Tokens;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Module\Blog\BlogRepository;

/**
 * Route middleware "token:<scope>[,<scope>…]": requires `Authorization: Bearer <token>` with every listed scope.
 * The token is never read from the query string. On success the request carries the Token as the
 * Token::class attribute. A route with a {handle} parameter also checks the token's blog pin; routes that reach
 * a blog another way (a post id, a Micropub destination) check Token::allowsBlog() themselves.
 */
final class TokenMiddleware implements MiddlewareInterface
{
    /** @param list<string> $args the required scopes */
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly BlogRepository $blogs,
        private readonly LoggerInterface $logger,
        private readonly array $args = [],
    ) {
        if ($args === []) {
            throw new LogicException("The token middleware needs a scope, as in 'token:blog:write'");
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            throw TokenError::missing();
        }
        if (preg_match('/^Bearer +(\S+) *$/iD', $header, $m) !== 1) {
            throw TokenError::invalid();
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $token = $this->tokens->findByPlaintext($m[1]);
        if ($token === null || $token->isExpired($now)) {
            throw TokenError::invalid();
        }
        foreach ($this->args as $scope) {
            if (!$token->hasScope($scope)) {
                throw TokenError::insufficientScope($scope);
            }
        }
        $handle = $request->getAttribute('handle');
        if (is_string($handle) && $token->blogId !== null) {
            $blog = $this->blogs->find($handle);
            if ($blog !== null && !$token->allowsBlog($blog->id)) {
                throw TokenError::wrongBlog();
            }
        }
        try {
            $this->tokens->touch($token, $now);
        } catch (Throwable $e) {
            // Recording the use must never fail the request. Log the token's id, never its value.
            $this->logger->warning('Could not record the use of token {id}: {reason}', ['id' => $token->id, 'reason' => $e->getMessage()]);
        }

        return $handler->handle($request->withAttribute(Token::class, $token));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Tokens`
Expected: PASS (`TokenMiddlewareTest` 7 tests). The setUp enables the module afresh, so the new manifest key is read.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (587 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Tokens`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/tokens tests/Module/Tokens
git commit -m "feat(tokens): add the token:<scope> bearer middleware" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 4: `PostWriter::create`, the native write path

**Files:**
- Create: `modules/blog/src/Write/ItemValidator.php`, `modules/blog/src/Write/PostWriter.php`
- Modify: `modules/blog/src/Post/PostRepository.php` (`insert`, `rewrite`, `setStatus`, a shared `columns()`)
- Test: `tests/Module/Blog/Write/PostWriterTestCase.php`, `tests/Module/Blog/Write/PostWriterCreateTest.php`

**Interfaces:**
- **Consumes:** `ValidationFailed` (Task 1); `AthenaAdapter::toRecord(array $item, DateTimeImmutable $now): PostRecord` and `JsonFeedAdapter::KINDS`; `PostRecord`; `Ulid::generate()`; `EventDispatcher::dispatch()`; `ItemCreated('blog', 'post', $id, ['blog' => $handle, 'item_id' => $itemId])`; `Config::get('app.url')`; `ItemSerializer::item()`; `SearchIndex::search()`; `Text::canonicalJson()`.
- **Produces:**
  - `PostRepository::insert(int $blogId, string $id, PostRecord $record, DateTimeImmutable $now): void` (one transaction, status `published`, tags written; a colliding id is an error, never swapped).
  - `PostRepository::rewrite(Post $post, PostRecord $record, DateTimeImmutable $now): void` (every content column, doc and tags; keeps id, item_id, status and created_at).
  - `PostRepository::setStatus(string $id, string $status, DateTimeImmutable $now): bool` (false when the post already had that status).
  - `ItemValidator`: `FIELDS` (the 16 JSON Feed item fields), `MAX_TITLE = 1000`, `MAX_TAG = 128`, `errors(array $item): array<string, string>`, `static date(string): ?DateTimeImmutable` (RFC 3339 → UTC) and `static isHttpUrl(string): bool`.
  - `PostWriter::create(Blog $blog, array $item, ?DateTimeImmutable $now = null): Post`. It throws `HttpException(409, "Blog '{handle}' is a mirror: its posts come from its source")` for a mirror blog and `ValidationFailed($fields, 'Invalid item')` with sorted fields for a bad item.
  - Test helpers (`PostWriterTestCase extends BlogTestCase`): `$app`, `$blog` (native `notes`), `writer()`, `served(Post): array`, `assertSameItem(array, array)` and `fieldErrors(callable): array<string, string>`.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Write/PostWriterTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Write;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Support\Text;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Tests\Module\Blog\BlogTestCase;

abstract class PostWriterTestCase extends BlogTestCase
{
    protected App $app;
    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->blog = $this->nativeBlog($this->app);
    }

    protected function writer(): PostWriter
    {
        return $this->app->container()->get(PostWriter::class);
    }

    /** @return array<string, mixed> the post as the feed serves it */
    protected function served(Post $post): array
    {
        return $this->app->container()->get(ItemSerializer::class)->item($this->blog, $post, 'http://xar.test');
    }

    /**
     * Decoded JSON compared without regard to key order (spec §2.2).
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    protected static function assertSameItem(array $expected, array $actual): void
    {
        self::assertSame(Text::canonicalJson($expected), Text::canonicalJson($actual));
    }

    /** @return array<string, string> the fields of the ValidationFailed that $write throws */
    protected static function fieldErrors(callable $write): array
    {
        try {
            $write();
        } catch (ValidationFailed $e) {
            self::assertSame(422, $e->status());

            return $e->fields();
        }
        self::fail('expected a 422');
    }
}
```

`tests/Module/Blog/Write/PostWriterCreateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Write;

use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Write\ItemValidator;

final class PostWriterCreateTest extends PostWriterTestCase
{
    public function testCreatesANativePostWithAFreshIdAndItemId(): void
    {
        $post = $this->writer()->create($this->blog, ['title' => 'Hello', 'content_html' => '<p>Hi <script>x()</script></p>']);
        self::assertTrue(Ulid::isValid($post->id));
        self::assertSame("http://xar.test/s/{$post->id}", $post->itemId);
        self::assertSame('published', $post->status);
        self::assertNull($post->url);
        self::assertSame('<p>Hi <script>x()</script></p>', $post->contentHtml, 'stored as given; pages sanitise at render');
        self::assertNotNull($post->updatedAt);
        self::assertSame(1, $this->app->container()->get(PostRepository::class)->countLiveNative($this->blog->id));
        self::assertSame("http://xar.test/s/{$post->id}", $this->served($post)['url']);
    }

    public function testAnItemRoundTripsThroughTheSerializer(): void
    {
        $item = [
            'title' => 'A photo walk',
            'content_html' => '<p>Look.</p>',
            'summary' => 'Walk',
            'image' => 'https://img.test/a.jpg',
            'date_published' => '2026-10-01T08:00:00+00:00',
            'date_modified' => '2026-10-02T09:30:00+00:00',
            'tags' => ['Walks', 'Autumn light'],
            'attachments' => [['url' => 'https://cdn.test/a.mp3', 'mime_type' => 'audio/mpeg']],
            '_athenana' => [
                'kind' => 'photo',
                'images' => [['url' => 'https://img.test/a.jpg', 'width' => 800, 'height' => 600]],
                'tags' => [['name' => 'Walks', 'slug' => 'walks'], ['name' => 'Autumn light', 'slug' => 'autumn-light']],
            ],
            '_video' => ['provider' => 'youtube', 'id' => 'abc'],
        ];
        $post = $this->writer()->create($this->blog, $item);
        self::assertSame([['name' => 'Walks', 'slug' => 'walks'], ['name' => 'Autumn light', 'slug' => 'autumn-light']], $post->tags);
        self::assertSameItem(['id' => $post->itemId, 'url' => "http://xar.test/s/{$post->id}", ...$item], $this->served($post));
    }

    public function testDerivesTitleAndKindAndDefaultsTheDate(): void
    {
        $before = $this->now()->modify('-1 second');
        $note = $this->writer()->create($this->blog, ['content_html' => '<p>Hello <b>world</b></p>', 'tags' => ['Café Life']]);
        self::assertSame('Hello world', $note->title);
        self::assertSame('note', $note->kind);
        self::assertGreaterThanOrEqual($before, $note->datePublished);
        self::assertSame([['name' => 'Café Life', 'slug' => 'caf-life']], $note->tags);

        $link = $this->writer()->create($this->blog, ['external_url' => 'https://www.example.com/a/b/']);
        self::assertSame(['link', 'example.com/a/b'], [$link->kind, $link->title]);

        $photo = $this->writer()->create($this->blog, ['image' => 'https://img.test/x.png']);
        self::assertSame(['photo', 'Photo'], [$photo->kind, $photo->title]);
    }

    public function testAFutureDateIsStoredAsScheduled(): void
    {
        $post = $this->writer()->create($this->blog, ['title' => 'Later', 'date_published' => '2099-01-01T00:00:00Z']);
        self::assertSame('2099-01-01 00:00:00', $post->datePublished->format('Y-m-d H:i:s'));
        self::assertSame([], $this->app->container()->get(PostRepository::class)->page($this->blog->id, $this->now(), null, 50));
    }

    public function testRejectsInvalidItemsWithFieldMessages(): void
    {
        $cases = [
            [['title' => 'x', 'foo' => 1], 'foo', 'Unknown field'],
            [['title' => 'x', '_ext' => 'str'], '_ext', 'Extension fields must be JSON objects'],
            [['title' => 5], 'title', 'Must be a string'],
            [['title' => str_repeat('t', ItemValidator::MAX_TITLE + 1)], 'title', 'Must be at most 1000 characters'],
            [['title' => 'x', 'date_published' => 'tomorrow'], 'date_published', 'Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00'],
            [['title' => 'x', 'date_published' => '2026-02-30T10:00:00Z'], 'date_published', 'Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00'],
            [['title' => 'x', 'external_url' => 'javascript:alert(1)'], 'external_url', 'Must be an absolute http(s) URL of at most 2048 bytes'],
            [['title' => 'x', 'image' => '/relative.png'], 'image', 'Must be an absolute http(s) URL of at most 2048 bytes'],
            [['title' => 'x', 'tags' => 'a,b'], 'tags', 'Must be a list of tag names of 1 to 128 characters'],
            [['title' => 'x', 'tags' => ['ok', '']], 'tags', 'Must be a list of tag names of 1 to 128 characters'],
            [['title' => 'x', 'attachments' => [['url' => 'ftp://x']]], 'attachments', 'Each attachment needs an http(s) url and a mime_type'],
            [['title' => 'x', '_athenana' => ['kind' => 'essay']], '_athenana.kind', 'Must be one of: note, photo, link, highlight'],
            [['title' => 'x', 'id' => 'mine'], 'id', 'Is assigned by the server; leave it out'],
            [['title' => 'x', 'url' => 'https://elsewhere.test/'], 'url', 'Is assigned by the server; leave it out'],
            [[], 'content_html', 'An item needs content_html, content_text, a title, an external_url or an image'],
            [['content_html' => ''], 'content_html', 'An item needs content_html, content_text, a title, an external_url or an image'],
        ];
        foreach ($cases as [$item, $field, $message]) {
            $fields = self::fieldErrors(fn() => $this->writer()->create($this->blog, $item));
            self::assertSame($message, $fields[$field] ?? null, json_encode($item) . ' => ' . json_encode($fields));
        }
        self::assertSame(0, $this->app->container()->get(PostRepository::class)->countLive($this->blog->id));
    }

    public function testReservedAndNullFieldsAreIgnored(): void
    {
        $post = $this->writer()->create($this->blog, ['title' => 'x', 'summary' => null, '_phoenix' => ['status' => 'deleted']]);
        self::assertSame('published', $post->status);
        self::assertArrayNotHasKey('_phoenix', $post->doc);
        self::assertArrayNotHasKey('summary', $post->doc);
    }

    public function testMirrorBlogsRefuseWritesWith409(): void
    {
        $mirror = $this->blogs($this->app)->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/feed.json', 'source_format' => 'athena'], $this->now());
        try {
            $this->writer()->create($mirror, ['title' => 'x']);
            self::fail('expected 409');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status());
            self::assertSame("Blog 'src' is a mirror: its posts come from its source", $e->getMessage());
        }
    }

    public function testEventsFireAfterTheCommitAndTheSearchIndexFollows(): void
    {
        $seen = [];
        $db = $this->app->container()->get(Connection::class);
        $this->app->container()->get(EventDispatcher::class)->listen(ItemCreated::class, static function (ItemCreated $e) use (&$seen, $db): void {
            $seen[] = [$e->module, $e->itemtype, $e->item['blog'] ?? null, $db->pdo()->inTransaction()];
        });
        $post = $this->writer()->create($this->blog, ['title' => 'Searchable walrus']);
        self::assertSame([['blog', 'post', 'notes', false]], $seen);
        self::assertSame([$post->id], $this->app->container()->get(SearchIndex::class)->search($this->blog->id, 'walrus', 10, 0)['ids']);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Write`
Expected: FAIL. `Cannot resolve 'Xaraya\Module\Blog\Write\PostWriter': no binding and no such class` and `Class "Xaraya\Module\Blog\Write\ItemValidator" not found`.

- [ ] **Step 3: Implement**

In `modules/blog/src/Post/PostRepository.php` (1 of 3), replace:

```php
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
                if ($record->sourceHash !== null && $existing['source_hash'] === $record->sourceHash && $existing['status'] === 'published') {
```

with:

```php
                ->where('blog_id', '=', $blogId)
                ->where('item_id', '=', $record->itemId)
                ->first();
            $columns = [...$this->columns($record, $now), 'status' => 'published'];
            if ($existing !== null) {
                $id = (string) $existing['id'];
                if ($record->sourceHash !== null && $existing['source_hash'] === $record->sourceHash && $existing['status'] === 'published') {
```

In `modules/blog/src/Post/PostRepository.php` (2 of 3), replace:

```php
        });
    }

    public function find(string $id): ?Post
    {
        $row = $this->db->select('posts')->where('id', '=', $id)->first();
```

with:

```php
        });
    }

    /**
     * Inserts a post written natively, under the id its item_id already embeds. Unlike save(), it never swaps a
     * colliding id: a collision is an error.
     */
    public function insert(int $blogId, string $id, PostRecord $record, DateTimeImmutable $now): void
    {
        $this->db->transaction(function () use ($blogId, $id, $record, $now): void {
            $this->db->insert('posts', [
                'id' => $id,
                'blog_id' => $blogId,
                'item_id' => $record->itemId,
                ...$this->columns($record, $now),
                'status' => 'published',
                'created_at' => $now,
            ]);
            $this->writeTags($id, $blogId, $record->tags);
        });
    }

    /** Replaces a post's content columns, doc and tags. Its id, item_id, status and created_at stay. */
    public function rewrite(Post $post, PostRecord $record, DateTimeImmutable $now): void
    {
        $this->db->transaction(function () use ($post, $record, $now): void {
            $this->db->update('posts', $this->columns($record, $now), ['id' => $post->id]);
            $this->writeTags($post->id, $post->blogId, $record->tags);
        });
    }

    /** Sets a post's status (published or deleted) and bumps updated_at; false when it already had that status. */
    public function setStatus(string $id, string $status, DateTimeImmutable $now): bool
    {
        return $this->db->query(
            'UPDATE {posts} SET status = ?, updated_at = ? WHERE id = ? AND status <> ?',
            [$status, $now, $id, $status],
        )->rowCount() > 0;
    }

    public function find(string $id): ?Post
    {
        $row = $this->db->select('posts')->where('id', '=', $id)->first();
```

In `modules/blog/src/Post/PostRepository.php` (3 of 3), replace:

```php
        return $deleted;
    }

    /** @param list<array{name: string, slug: string}> $tags */
    private function writeTags(string $postId, int $blogId, array $tags): void
    {
```

with:

```php
        return $deleted;
    }

    /** @return array<string, mixed> every content column, plus updated_at */
    private function columns(PostRecord $record, DateTimeImmutable $now): array
    {
        return [
            'kind' => $record->kind,
            'title' => $record->title,
            'url' => $record->url,
            'external_url' => $record->externalUrl,
            'content_html' => $record->contentHtml,
            'image' => $record->image,
            'date_published' => $record->datePublished,
            'date_modified' => $record->dateModified,
            'source_hash' => $record->sourceHash,
            'doc' => $record->doc === [] ? null : $record->doc,
            'updated_at' => $now,
        ];
    }

    /** @param list<array{name: string, slug: string}> $tags */
    private function writeTags(string $postId, int $blogId, array $tags): void
    {
```

`modules/blog/src/Write/ItemValidator.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Write;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;

/**
 * Checks a JSON Feed 1.1 item sent to the write path. Only the JSON Feed item fields and `_`-prefixed extension
 * objects are allowed. Returns field => message; an empty array means valid. Null values count as absent.
 */
final class ItemValidator
{
    public const FIELDS = [
        'id', 'url', 'external_url', 'title', 'content_html', 'content_text', 'summary', 'image', 'banner_image',
        'date_published', 'date_modified', 'authors', 'author', 'tags', 'language', 'attachments',
    ];
    public const MAX_TITLE = 1000;
    public const MAX_TAG = 128;

    private const STRINGS = ['id', 'url', 'external_url', 'title', 'content_html', 'content_text', 'summary', 'image', 'banner_image', 'date_published', 'date_modified', 'language'];
    /** field => longest value in bytes (the posts column sizes) */
    private const URLS = ['url' => 1024, 'external_url' => 2048, 'image' => 2048, 'banner_image' => 2048];

    /**
     * @param array<array-key, mixed> $item
     * @return array<string, string>
     */
    public function errors(array $item): array
    {
        $errors = [];
        foreach ($item as $key => $value) {
            $key = (string) $key;
            if ($value === null) {
                continue;
            }
            if (str_starts_with($key, '_')) {
                if (!self::isObject($value)) {
                    $errors[$key] = 'Extension fields must be JSON objects';
                }
            } elseif (!in_array($key, self::FIELDS, true)) {
                $errors[$key] = 'Unknown field';
            } elseif (in_array($key, self::STRINGS, true) && !is_string($value)) {
                $errors[$key] = 'Must be a string';
            }
        }
        $str = static fn(string $key): ?string => is_string($item[$key] ?? null) && $item[$key] !== '' ? $item[$key] : null;

        if (($title = $str('title')) !== null && mb_strlen($title) > self::MAX_TITLE) {
            $errors['title'] = 'Must be at most ' . self::MAX_TITLE . ' characters';
        }
        foreach (self::URLS as $key => $max) {
            $url = $str($key);
            if ($url !== null && (!self::isHttpUrl($url) || strlen($url) > $max)) {
                $errors[$key] = "Must be an absolute http(s) URL of at most {$max} bytes";
            }
        }
        foreach (['date_published', 'date_modified'] as $key) {
            $date = $str($key);
            if ($date !== null && self::date($date) === null) {
                $errors[$key] = 'Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00';
            }
        }
        $tags = $item['tags'] ?? null;
        if ($tags !== null && (!is_array($tags) || !array_is_list($tags) || !self::allTags($tags))) {
            $errors['tags'] = 'Must be a list of tag names of 1 to ' . self::MAX_TAG . ' characters';
        }
        foreach (['attachments', 'authors'] as $key) {
            $list = $item[$key] ?? null;
            if ($list !== null && (!is_array($list) || !array_is_list($list) || array_filter($list, static fn(mixed $v): bool => !self::isObject($v)) !== [])) {
                $errors[$key] = 'Must be a list of objects';
            }
        }
        if (is_array($item['attachments'] ?? null) && !isset($errors['attachments'])) {
            foreach ($item['attachments'] as $attachment) {
                if (!is_array($attachment) || !is_string($attachment['url'] ?? null) || !self::isHttpUrl($attachment['url']) || !is_string($attachment['mime_type'] ?? null)) {
                    $errors['attachments'] = 'Each attachment needs an http(s) url and a mime_type';
                }
            }
        }
        if (($item['author'] ?? null) !== null && !self::isObject($item['author'])) {
            $errors['author'] = 'Must be an object';
        }
        $athenana = $item['_athenana'] ?? null;
        if (is_array($athenana)) {
            $kind = $athenana['kind'] ?? null;
            if ($kind !== null && !in_array($kind, JsonFeedAdapter::KINDS, true)) {
                $errors['_athenana.kind'] = 'Must be one of: ' . implode(', ', JsonFeedAdapter::KINDS);
            }
            $athenaTags = $athenana['tags'] ?? null;
            if ($athenaTags !== null && (!is_array($athenaTags) || !array_is_list($athenaTags)
                || array_filter($athenaTags, static fn(mixed $t): bool => !is_array($t) || !is_string($t['name'] ?? null) || $t['name'] === '') !== [])) {
                $errors['_athenana.tags'] = 'Must be a list of {name, slug} objects';
            }
            $images = $athenana['images'] ?? null;
            if ($images !== null && (!is_array($images) || !array_is_list($images)
                || array_filter($images, static fn(mixed $i): bool => !is_array($i) || !is_string($i['url'] ?? null) || !self::isHttpUrl($i['url'])) !== [])) {
                $errors['_athenana.images'] = 'Must be a list of objects with an http(s) url';
            }
        }
        $hasContent = false;
        foreach (['content_html', 'content_text', 'title', 'external_url', 'image'] as $key) {
            $hasContent = $hasContent || $str($key) !== null;
        }
        if (!$hasContent) {
            $errors['content_html'] ??= 'An item needs content_html, content_text, a title, an external_url or an image';
        }

        return $errors;
    }

    /** Parses an RFC 3339 date-time (seconds required, years 1970-9999) into UTC; null when it is not one. */
    public static function date(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:[Zz]|[+-](\d{2}):(\d{2}))$/D', $value, $m) !== 1) {
            return null;
        }
        $offsetOk = !isset($m[7]) || ((int) $m[7] <= 23 && (int) ($m[8] ?? '0') <= 59);
        if ((int) $m[1] < 1970 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 || !$offsetOk) {
            return null;
        }

        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s/?\#\\\\]+[^\s\\\\\x00-\x1f\x7f]*$#iD', $url) === 1;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** @param list<mixed> $tags */
    private static function allTags(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!is_string($tag) || trim($tag) === '' || mb_strlen($tag) > self::MAX_TAG) {
                return false;
            }
        }

        return true;
    }
}
```

`modules/blog/src/Write/PostWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Write;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\AthenaAdapter;

/**
 * The one write path for native posts (JSON API and Micropub). Input is a JSON Feed item; it is mapped to columns,
 * doc and tags exactly as sync maps an Athena item, so ItemSerializer gives the item back. content_html is stored
 * as given (pages sanitise it when they render). Every write bumps updated_at, and item events are dispatched only
 * after the database transaction has committed.
 */
final class PostWriter
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly EventDispatcher $events,
        private readonly Config $config,
    ) {}

    /** @param array<array-key, mixed> $item */
    public function create(Blog $blog, array $item, ?DateTimeImmutable $now = null): Post
    {
        $now ??= self::now();
        self::assertNative($blog);
        $item = self::withoutReserved($item);
        $errors = (new ItemValidator())->errors($item);
        foreach (['id', 'url'] as $field) {
            if (array_key_exists($field, $item)) {
                $errors[$field] = 'Is assigned by the server; leave it out';
            }
        }
        self::check($errors);
        $item['date_published'] ??= $now->format(DATE_ATOM);
        $id = Ulid::generate();
        $itemId = $this->appUrl() . '/s/' . $id;
        $this->posts->insert($blog->id, $id, $this->record($item, $itemId, $id, null, $now), $now);
        $this->events->dispatch(new ItemCreated('blog', 'post', $id, ['blog' => $blog->handle, 'item_id' => $itemId]));

        return $this->posts->find($id) ?? throw new RuntimeException("Post {$id} vanished after insert");
    }

    private static function assertNative(Blog $blog): void
    {
        if ($blog->isMirror()) {
            throw new HttpException(409, "Blog '{$blog->handle}' is a mirror: its posts come from its source");
        }
    }

    /**
     * Drops `_phoenix` (the API's read-only status flag) and top-level nulls, which mean "absent".
     *
     * @param array<array-key, mixed> $item
     * @return array<string, mixed>
     */
    private static function withoutReserved(array $item): array
    {
        unset($item['_phoenix']);
        $out = [];
        foreach ($item as $key => $value) {
            if ($value !== null) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, string> $errors */
    private static function check(array $errors): void
    {
        if ($errors !== []) {
            ksort($errors);

            throw new ValidationFailed($errors, 'Invalid item');
        }
    }

    /** @param array<string, mixed> $item a validated item with date_published set */
    private function record(array $item, string $itemId, string $id, ?string $url, DateTimeImmutable $now): PostRecord
    {
        unset($item['url']);
        $item['id'] = $itemId;
        try {
            $r = (new AthenaAdapter())->toRecord($item, $now);
        } catch (InvalidArgumentException $e) {
            throw new ValidationFailed(['item' => $e->getMessage()], 'Invalid item');
        }

        // source_hash stays null: that is what marks a post as native (PostRepository::countLiveNative()).
        return new PostRecord($itemId, $id, $r->kind, $r->title, $url, $r->externalUrl, $r->contentHtml, $r->image, $r->datePublished, $r->dateModified, $r->doc, $r->tags, null);
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

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Write tests/Module/Blog/Post tests/Module/Blog/Sync`
Expected: PASS (`PostWriterCreateTest` 8 tests). `save()` behaves as before for sync.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (595 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Blog/Write tests/Module/Blog/Post`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Post/PostRepository.php modules/blog/src/Write tests/Module/Blog/Write
git commit -m "feat(blog): add PostWriter::create, the native write path, with JSON Feed item validation" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 5: `PostWriter` replace, merge-patch, delete and undelete

**Files:**
- Create: `modules/blog/src/Write/MergePatch.php`
- Modify: `modules/blog/src/Write/PostWriter.php`
- Test: `tests/Module/Blog/Write/PostWriterChangeTest.php`

**Interfaces:**
- **Consumes:** Task 4 (`PostRepository::rewrite()`, `setStatus()`, `ItemValidator`, `PostWriterTestCase`); `BlogRepository::findById()`; `ItemSerializer::item()` and `url()`; `ItemUpdated` and `ItemDeleted`; `Text::deriveTitle(string $kind, ?string $contentHtml, ?string $externalUrl): string`.
- **Produces:**
  - `MergePatch::apply(mixed $target, mixed $patch): mixed` (RFC 7396; `[]` counts as an empty object).
  - The `PostWriter` constructor becomes `(BlogRepository, PostRepository, ItemSerializer, EventDispatcher, Config)`.
  - `PostWriter::replace(Post, array $item, ?DateTimeImmutable $now = null): Post`. `id` and `url` may be sent back unchanged; changed, they are 422 `Cannot be changed` and `Is assigned by the server; leave it out or send it unchanged`. A missing `date_published` keeps the old one, and `ItemUpdated` follows.
  - `PostWriter::patch(Post, array $partial, ?DateTimeImmutable $now = null): Post`. `date_published: null` is 422 `Cannot be removed`. `tags` without `_athenana.tags` re-derives the slugs, and a derived title follows the content.
  - `PostWriter::delete(Post, ?DateTimeImmutable $now = null): Post` (`ItemDeleted` with `{blog}`, only on a change) and `undelete(Post, ?DateTimeImmutable $now = null): Post` (`ItemUpdated`, only on a change).
  - Replace, patch, delete and undelete throw `HttpException(409)` on a mirror blog. Replace and patch throw `HttpException(409, 'The post is deleted; undelete it first')` on a deleted post.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Write/PostWriterChangeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Write;

use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Write\MergePatch;

final class PostWriterChangeTest extends PostWriterTestCase
{
    /** @var list<string> */
    private array $events = [];

    private function post(): Post
    {
        $post = $this->writer()->create($this->blog, [
            'title' => 'First',
            'content_html' => '<p>One</p>',
            'tags' => ['Old tag'],
            'date_published' => '2026-10-01T08:00:00Z',
        ]);
        $this->app->container()->get(EventDispatcher::class)->listen(ItemEvent::class, function (ItemEvent $e): void {
            $this->events[] = (new \ReflectionClass($e))->getShortName() . ':' . $e->id;
        });

        return $post;
    }

    private function backdate(Post $post): void
    {
        $this->db($this->app)->update('posts', ['updated_at' => '2000-01-01 00:00:00'], ['id' => $post->id]);
    }

    private static function statusOf(callable $write): int
    {
        try {
            $write();
        } catch (HttpException $e) {
            return $e->status();
        }
        self::fail('expected an HttpException');
    }

    public function testMergePatchFollowsRfc7396(): void
    {
        $target = ['a' => 'b', 'c' => ['d' => 'e', 'f' => 'g'], 'list' => [1, 2]];
        self::assertSame(['a' => 'z', 'c' => ['d' => 'e'], 'list' => [3]], MergePatch::apply($target, ['a' => 'z', 'c' => ['f' => null], 'list' => [3]]));
        self::assertSame(['a' => 'b', 'c' => ['d' => 'e', 'f' => 'g'], 'list' => [1, 2]], MergePatch::apply($target, []));
        self::assertSame(['x' => ['y' => 1]], MergePatch::apply(['x' => 'scalar'], ['x' => ['y' => 1]]));
        self::assertSame('replaced', MergePatch::apply($target, 'replaced'));
    }

    public function testReplaceRewritesEverythingButIdentityAndBumpsUpdatedAt(): void
    {
        $post = $this->post();
        $this->backdate($post);
        $item = ['title' => 'Second', 'content_text' => 'Two', 'tags' => ['New']];
        $replaced = $this->writer()->replace($post, $item);
        self::assertSame([$post->id, $post->itemId], [$replaced->id, $replaced->itemId]);
        self::assertSame('2026-10-01 08:00:00', $replaced->datePublished->format('Y-m-d H:i:s'), 'a missing date_published keeps the old one');
        self::assertNull($replaced->contentHtml);
        self::assertSame([['name' => 'New', 'slug' => 'new']], $replaced->tags);
        self::assertGreaterThan(new \DateTimeImmutable('2001-01-01'), $replaced->updatedAt);
        self::assertSame(["ItemUpdated:{$post->id}"], $this->events);
    }

    public function testAServedItemCanBePutBackUnchanged(): void
    {
        $post = $this->post();
        $served = $this->served($post);
        $again = $this->writer()->replace($post, [...$served, '_phoenix' => ['status' => 'published']]);
        self::assertSameItem($served, $this->served($again));
    }

    public function testReplaceRefusesToChangeIdOrUrl(): void
    {
        $post = $this->post();
        $fields = self::fieldErrors(fn() => $this->writer()->replace($post, ['title' => 'x', 'id' => 'other', 'url' => 'https://evil.test/']));
        self::assertSame(['id' => 'Cannot be changed', 'url' => 'Is assigned by the server; leave it out or send it unchanged'], $fields);
    }

    public function testPatchMergesIntoTheServedItem(): void
    {
        $post = $this->post();
        $this->backdate($post);
        $patched = $this->writer()->patch($post, ['title' => 'Patched', 'tags' => ['Fresh', 'Two words'], 'summary' => 'S']);
        self::assertSame('Patched', $patched->title);
        self::assertSame('<p>One</p>', $patched->contentHtml);
        self::assertSame([['name' => 'Fresh', 'slug' => 'fresh'], ['name' => 'Two words', 'slug' => 'two-words']], $patched->tags);
        self::assertSame('S', $patched->doc['summary'] ?? null);
        self::assertGreaterThan(new \DateTimeImmutable('2001-01-01'), $patched->updatedAt);

        $cleared = $this->writer()->patch($patched, ['summary' => null, 'tags' => null]);
        self::assertArrayNotHasKey('summary', $cleared->doc);
        self::assertSame([], $cleared->tags);
    }

    public function testADerivedTitleFollowsTheContentButAnExplicitOneStays(): void
    {
        $note = $this->writer()->create($this->blog, ['content_html' => '<p>Old words</p>']);
        self::assertSame('Old words', $note->title);
        self::assertSame('New words', $this->writer()->patch($note, ['content_html' => '<p>New words</p>'])->title);

        $titled = $this->post();
        self::assertSame('First', $this->writer()->patch($titled, ['content_html' => '<p>Changed</p>'])->title);
    }

    public function testPatchCannotRemoveTheDateOrEveryPieceOfContent(): void
    {
        $post = $this->post();
        self::assertSame(['date_published' => 'Cannot be removed'], self::fieldErrors(fn() => $this->writer()->patch($post, ['date_published' => null])));
        $fields = self::fieldErrors(fn() => $this->writer()->patch($post, ['title' => null, 'content_html' => null]));
        self::assertSame('An item needs content_html, content_text, a title, an external_url or an image', $fields['content_html'] ?? null);
        self::assertSame('First', $this->writer()->patch($post, [])->title, 'nothing was written');
    }

    public function testDeleteAndUndeleteAreIdempotentAndFireOnce(): void
    {
        $post = $this->post();
        $index = $this->app->container()->get(SearchIndex::class);
        self::assertSame(1, $index->search($this->blog->id, 'first', 10, 0)['total']);

        $deleted = $this->writer()->delete($post);
        self::assertSame('deleted', $deleted->status);
        self::assertSame('deleted', $this->writer()->delete($deleted)->status);
        self::assertSame(0, $index->search($this->blog->id, 'first', 10, 0)['total']);

        $back = $this->writer()->undelete($deleted);
        self::assertSame('published', $back->status);
        $this->writer()->undelete($back);
        self::assertSame(1, $index->search($this->blog->id, 'first', 10, 0)['total']);
        self::assertSame(["ItemDeleted:{$post->id}", "ItemUpdated:{$post->id}"], $this->events);
    }

    public function testADeletedPostMustBeUndeletedBeforeItIsEdited(): void
    {
        $post = $this->writer()->delete($this->post());
        self::assertSame(409, self::statusOf(fn() => $this->writer()->replace($post, ['title' => 'x'])));
        self::assertSame(409, self::statusOf(fn() => $this->writer()->patch($post, ['title' => 'x'])));
    }

    public function testMirrorPostsCannotBeChanged(): void
    {
        $post = $this->post();
        $this->db($this->app)->update('blogs', ['mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], ['id' => $this->blog->id]);
        foreach ([
            fn() => $this->writer()->replace($post, ['title' => 'x']),
            fn() => $this->writer()->patch($post, ['title' => 'x']),
            fn() => $this->writer()->delete($post),
            fn() => $this->writer()->undelete($post),
        ] as $write) {
            self::assertSame(409, self::statusOf($write));
        }
        self::assertSame([], $this->events);
    }

    public function testEventsSeeTheCommittedRow(): void
    {
        $post = $this->post();
        $titles = [];
        $this->app->container()->get(EventDispatcher::class)->listen(ItemUpdated::class, function (ItemUpdated $e) use (&$titles): void {
            $titles[] = $this->app->container()->get(PostRepository::class)->find($e->id)?->title;
        });
        $this->writer()->patch($post, ['title' => 'Seen']);
        self::assertSame(['Seen'], $titles);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Write/PostWriterChangeTest.php`
Expected: FAIL. `Class "Xaraya\Module\Blog\Write\MergePatch" not found` and `Call to undefined method Xaraya\Module\Blog\Write\PostWriter::replace()` (and `patch()`, `delete()`).

- [ ] **Step 3: Implement**

`modules/blog/src/Write/MergePatch.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Write;

/**
 * JSON Merge Patch (RFC 7396) over decoded JSON arrays. A patch member set to null removes the member; an object
 * merges recursively; anything else (a scalar or a list) replaces. An empty array counts as an empty object.
 */
final class MergePatch
{
    public static function apply(mixed $target, mixed $patch): mixed
    {
        if (!self::isObject($patch)) {
            return $patch;
        }
        /** @var array<array-key, mixed> $patch */
        $result = self::isObject($target) && is_array($target) ? $target : [];
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($result[$key]);
            } else {
                $result[$key] = self::apply($result[$key] ?? null, $value);
            }
        }

        return $result;
    }

    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}
```

In `modules/blog/src/Write/PostWriter.php` (1 of 3), replace:

```php
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\AthenaAdapter;

/**
 * The one write path for native posts (JSON API and Micropub). Input is a JSON Feed item; it is mapped to columns,
```

with:

```php
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\AthenaAdapter;
use Xaraya\Module\Blog\Support\Text;

/**
 * The one write path for native posts (JSON API and Micropub). Input is a JSON Feed item; it is mapped to columns,
```

In `modules/blog/src/Write/PostWriter.php` (2 of 3), replace:

```php
final class PostWriter
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly EventDispatcher $events,
        private readonly Config $config,
    ) {}
```

with:

```php
final class PostWriter
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly ItemSerializer $serializer,
        private readonly EventDispatcher $events,
        private readonly Config $config,
    ) {}
```

In `modules/blog/src/Write/PostWriter.php` (3 of 3), replace:

```php
        return $this->posts->find($id) ?? throw new RuntimeException("Post {$id} vanished after insert");
    }

    private static function assertNative(Blog $blog): void
    {
        if ($blog->isMirror()) {
```

with:

```php
        return $this->posts->find($id) ?? throw new RuntimeException("Post {$id} vanished after insert");
    }

    /**
     * Replaces the post with $item. `id` and `url` may be sent back unchanged (a GET body round-trips) but never
     * changed. A missing date_published keeps the current one.
     *
     * @param array<array-key, mixed> $item
     */
    public function replace(Post $post, array $item, ?DateTimeImmutable $now = null): Post
    {
        $now ??= self::now();
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        self::assertLive($post);
        $item = self::withoutReserved($item);
        $errors = (new ItemValidator())->errors($item);
        if (array_key_exists('id', $item) && $item['id'] !== $post->itemId) {
            $errors['id'] = 'Cannot be changed';
        }
        if (array_key_exists('url', $item) && $item['url'] !== $this->serializer->url($blog, $post, $this->appUrl())) {
            $errors['url'] = 'Is assigned by the server; leave it out or send it unchanged';
        }
        self::check($errors);
        $item['date_published'] ??= $post->datePublished->format(DATE_ATOM);
        $this->posts->rewrite($post, $this->record($item, $post->itemId, $post->id, $post->url, $now), $now);
        $this->events->dispatch(new ItemUpdated('blog', 'post', $post->id, ['blog' => $blog->handle, 'item_id' => $post->itemId]));

        return $this->reload($post);
    }

    /**
     * Applies a JSON Merge Patch (RFC 7396) to the item as the feed serves it, then replaces. Patching `tags`
     * without `_athenana.tags` re-derives the tag slugs from the new names, and a derived title follows the content.
     *
     * @param array<array-key, mixed> $partial
     */
    public function patch(Post $post, array $partial, ?DateTimeImmutable $now = null): Post
    {
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        self::assertLive($post);
        if (array_key_exists('date_published', $partial) && $partial['date_published'] === null) {
            self::check(['date_published' => 'Cannot be removed']);
        }
        $current = $this->serializer->item($blog, $post, $this->appUrl());
        // A title that was only derived from the content (§3.1) is derived again from the patched content.
        $text = is_string($post->doc['content_text'] ?? null) ? htmlspecialchars($post->doc['content_text']) : null;
        if (!array_key_exists('title', $partial) && $post->title === Text::deriveTitle($post->kind, $post->contentHtml ?? $text, $post->externalUrl)) {
            unset($current['title']);
        }
        $athenaTags = is_array($partial['_athenana'] ?? null) && array_key_exists('tags', $partial['_athenana']);
        if (array_key_exists('tags', $partial) && !$athenaTags && is_array($current['_athenana'] ?? null)) {
            unset($current['_athenana']['tags']);
        }
        $merged = MergePatch::apply($current, $partial);

        return $this->replace($post, is_array($merged) ? $merged : [], $now);
    }

    /** Tombstones the post (status deleted). Deleting a deleted post changes nothing and fires no event. */
    public function delete(Post $post, ?DateTimeImmutable $now = null): Post
    {
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        if ($this->posts->setStatus($post->id, 'deleted', $now ?? self::now())) {
            $this->events->dispatch(new ItemDeleted('blog', 'post', $post->id, ['blog' => $blog->handle]));
        }

        return $this->reload($post);
    }

    /** Brings a tombstoned post back, as a resurrecting sync does (ItemUpdated). */
    public function undelete(Post $post, ?DateTimeImmutable $now = null): Post
    {
        $blog = $this->blogOf($post);
        self::assertNative($blog);
        if ($this->posts->setStatus($post->id, 'published', $now ?? self::now())) {
            $this->events->dispatch(new ItemUpdated('blog', 'post', $post->id, ['blog' => $blog->handle, 'item_id' => $post->itemId]));
        }

        return $this->reload($post);
    }

    private function blogOf(Post $post): Blog
    {
        return $this->blogs->findById($post->blogId) ?? throw new RuntimeException("Blog {$post->blogId} no longer exists");
    }

    private function reload(Post $post): Post
    {
        return $this->posts->find($post->id) ?? throw new RuntimeException("Post {$post->id} vanished");
    }

    private static function assertLive(Post $post): void
    {
        if ($post->status === 'deleted') {
            throw new HttpException(409, 'The post is deleted; undelete it first');
        }
    }

    private static function assertNative(Blog $blog): void
    {
        if ($blog->isMirror()) {
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Write`
Expected: PASS (`PostWriterChangeTest` 11 tests, `PostWriterCreateTest` 8).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (606 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Blog/Write`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Write tests/Module/Blog/Write
git commit -m "feat(blog): replace, merge-patch, delete and undelete native posts" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 6: The JSON API

**Files:**
- Create: `modules/blog/src/Http/Api/JsonBody.php`, `modules/blog/src/Http/Api/ApiAccess.php`, `modules/blog/src/Http/Api/PostApiController.php`
- Modify: `modules/blog/src/Routes.php`
- Test: `tests/Module/Blog/Api/ApiTestCase.php`, `tests/Module/Blog/Api/PostApiTest.php`

**Interfaces:**
- **Consumes:** `PostWriter` (Tasks 4-5); `TokenMiddleware` and the `Token` attribute, `TokenError::missing()` and `wrongBlog()` (Task 3); `ModuleRegistry::isEnabled(string): bool`; `RouteCollector::group()`, `post/get/put/patch/delete()`; `Controller::json()`; `ItemSerializer::item()`; the JSON-under-`/api` rule (Task 1); `TokensTestCase::enableTokens()`, `token()`.
- **Produces:**
  - `JsonBody::decode(ServerRequestInterface, list<string> $types = ['application/json', 'application/feed+json']): array<string, mixed>` with `MAX_BYTES = 1_000_000`: 415 for another type, 413 over the cap, 400 for invalid JSON or a non-object.
  - `ApiAccess::allow(ServerRequestInterface, Blog): Token`: 401 with no token, 403 `The token may not write to this blog` for a pin elsewhere.
  - `PostApiController::create/show/replace/patch/delete/undelete(ServerRequestInterface, array $params)` on the six routes named in Global Constraints, registered only when the `tokens` module is enabled. `Routes` gains `__construct(ModuleRegistry $modules)`.
  - Responses: the item as stored plus `_phoenix: {status: published|scheduled|deleted}`, `Cache-Control: no-store`; create is 201 with `Location: /api/posts/{id}`; delete is 204 with an empty body.
  - Test helpers (`ApiTestCase extends TokensTestCase`): `api(string $method, string $uri, ?string $token, array|string|null $body = null, string $type = 'application/json'): ResponseInterface`, `page(string $uri)` (phoenix theme) and `json(ResponseInterface): array`.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Api/ApiTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Api;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Tests\Module\Tokens\TokensTestCase;

/** HTTP tests of the token-protected write routes, through App::handle. */
abstract class ApiTestCase extends TokensTestCase
{
    /** @param array<string, mixed>|string|null $body an array is sent as JSON */
    protected function api(string $method, string $uri, ?string $token, array|string|null $body = null, string $type = 'application/json'): ResponseInterface
    {
        $request = new ServerRequest($method, $uri);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', $type)
                ->withBody(Stream::create(is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : $body));
        }

        return $this->app()->handle($request);
    }

    /** A public page or feed, rendered in the phoenix theme. */
    protected function page(string $uri): ResponseInterface
    {
        return $this->app(['themes.paths' => ['themes'], 'app.theme' => 'phoenix', 'view.engine' => 'php'])->handle(new ServerRequest('GET', $uri));
    }

    /** @return array<string, mixed> */
    protected static function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
```

`tests/Module/Blog/Api/PostApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Api;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Http\Api\JsonBody;

final class PostApiTest extends ApiTestCase
{
    private App $site;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = $this->enableTokens();
        $this->nativeBlog($this->site);
        $this->nativeBlog($this->site, ['handle' => 'other', 'title' => 'Other']);
        $this->token = $this->token();
    }

    /** @return array<string, mixed> the created item */
    private function create(array $item, string $handle = 'notes'): array
    {
        $response = $this->api('POST', "/api/blogs/{$handle}/posts", $this->token, $item);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        return self::json($response);
    }

    public function testAPostedItemAppearsInTheFeed(): void
    {
        $response = $this->api('POST', '/api/blogs/notes/posts', $this->token, ['title' => 'Hello API', 'content_html' => '<p>From the API</p>', 'tags' => ['api']]);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $item = self::json($response);
        $id = substr((string) $item['id'], strlen('http://xar.test/s/'));
        self::assertSame("/api/posts/{$id}", $response->getHeaderLine('Location'));
        self::assertSame("http://xar.test/s/{$id}", $item['url']);
        self::assertSame(['status' => 'published'], $item['_phoenix']);
        self::assertSame(['kind' => 'note', 'tags' => [['name' => 'api', 'slug' => 'api']]], $item['_athenana']);

        $feed = self::json($this->page('/blog/notes/feed.json'));
        self::assertSame([$item['id']], array_column($feed['items'], 'id'));
        unset($item['_phoenix']);
        self::assertEquals($item, $feed['items'][0]);
    }

    public function testEveryStatusOfTheApi(): void
    {
        $item = $this->create(['title' => 'One']);
        $id = substr((string) $item['id'], -26);

        self::assertSame(401, $this->api('POST', '/api/blogs/notes/posts', null, ['title' => 'x'])->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/blogs/notes/posts', $this->token('media:write'), ['title' => 'x'])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/blogs/nope/posts', $this->token, ['title' => 'x'])->getStatusCode());
        self::assertSame(415, $this->api('POST', '/api/blogs/notes/posts', $this->token, 'title=x', 'application/x-www-form-urlencoded')->getStatusCode());
        self::assertSame(400, $this->api('POST', '/api/blogs/notes/posts', $this->token, '{"title":')->getStatusCode());
        self::assertSame(400, $this->api('POST', '/api/blogs/notes/posts', $this->token, '["title"]')->getStatusCode());
        self::assertSame(413, $this->api('POST', '/api/blogs/notes/posts', $this->token, '{"title":"' . str_repeat('x', JsonBody::MAX_BYTES) . '"}')->getStatusCode());

        $this->blogs($this->app())->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $mirror = $this->api('POST', '/api/blogs/src/posts', $this->token, ['title' => 'x']);
        self::assertSame(409, $mirror->getStatusCode());
        self::assertSame("Blog 'src' is a mirror: its posts come from its source", self::json($mirror)['error']['message']);

        self::assertSame(404, $this->api('GET', '/api/posts/' . str_repeat('0', 26), $this->token)->getStatusCode());
        self::assertSame(200, $this->api('GET', "/api/posts/{$id}", $this->token)->getStatusCode());
        self::assertSame('Two', self::json($this->api('PUT', "/api/posts/{$id}", $this->token, ['title' => 'Two']))['title']);
        self::assertSame('Three', self::json($this->api('PATCH', "/api/posts/{$id}", $this->token, ['title' => 'Three'], 'application/merge-patch+json'))['title']);

        $deleted = $this->api('DELETE', "/api/posts/{$id}", $this->token);
        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame('', (string) $deleted->getBody());
        self::assertSame(204, $this->api('DELETE', "/api/posts/{$id}", $this->token)->getStatusCode());
        self::assertSame(['status' => 'deleted'], self::json($this->api('GET', "/api/posts/{$id}", $this->token))['_phoenix']);
        self::assertSame(409, $this->api('PUT', "/api/posts/{$id}", $this->token, ['title' => 'x'])->getStatusCode());
        self::assertSame(404, $this->page("/s/{$id}.json")->getStatusCode());

        $back = $this->api('POST', "/api/posts/{$id}/undelete", $this->token);
        self::assertSame(200, $back->getStatusCode());
        self::assertSame(['status' => 'published'], self::json($back)['_phoenix']);
        self::assertSame(200, $this->page("/s/{$id}.json")->getStatusCode());
    }

    public function testUnknownTopLevelFieldsAre422WithFields(): void
    {
        $response = $this->api('POST', '/api/blogs/notes/posts', $this->token, ['title' => 'x', 'titel' => 'typo', '_ext' => 1]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ['status' => 422, 'message' => 'Invalid item', 'fields' => ['_ext' => 'Extension fields must be JSON objects', 'titel' => 'Unknown field']],
            self::json($response)['error'],
        );
        self::assertSame(0, (int) $this->db($this->app())->fetchValue('SELECT COUNT(*) FROM {posts}'));
    }

    public function testAPinnedTokenCannotReachAnotherBlogsPostsById(): void
    {
        $other = $this->create(['title' => 'Not yours'], 'other');
        $id = substr((string) $other['id'], -26);
        $pinned = $this->token('blog:write', ['--blog=notes']);

        self::assertSame(201, $this->api('POST', '/api/blogs/notes/posts', $pinned, ['title' => 'Mine'])->getStatusCode());
        self::assertSame(403, $this->api('POST', '/api/blogs/other/posts', $pinned, ['title' => 'x'])->getStatusCode());
        foreach ([['GET', "/api/posts/{$id}", null], ['PUT', "/api/posts/{$id}", ['title' => 'Hijacked']], ['PATCH', "/api/posts/{$id}", ['title' => 'Hijacked']], ['DELETE', "/api/posts/{$id}", null], ['POST', "/api/posts/{$id}/undelete", null]] as [$method, $uri, $body]) {
            $response = $this->api($method, $uri, $pinned, $body);
            self::assertSame(403, $response->getStatusCode(), "{$method} {$uri}");
            self::assertSame('The token may not write to this blog', self::json($response)['error']['message']);
        }
        $after = self::json($this->api('GET', "/api/posts/{$id}", $this->token));
        self::assertSame(['Not yours', ['status' => 'published']], [$after['title'], $after['_phoenix']]);
    }

    public function testAScheduledPostIsHiddenEverywhereButTheApi(): void
    {
        $item = $this->create(['title' => 'Future walrus', 'content_html' => '<p>Soon</p>', 'date_published' => '2099-01-01T09:00:00Z']);
        $id = substr((string) $item['id'], -26);
        self::assertSame(['status' => 'scheduled'], $item['_phoenix']);
        self::assertSame(['status' => 'scheduled'], self::json($this->api('GET', "/api/posts/{$id}", $this->token))['_phoenix']);

        self::assertSame([], self::json($this->page('/blog/notes/feed.json'))['items'] ?? []);
        self::assertStringNotContainsString('walrus', (string) $this->page('/blog/notes/feed.xml')->getBody());
        self::assertSame(404, $this->page("/s/{$id}.json")->getStatusCode());
        self::assertSame(404, $this->page("/s/{$id}")->getStatusCode());
        self::assertStringNotContainsString('walrus', (string) $this->page('/blog/notes')->getBody());
        $search = $this->page('/blog/notes/search?q=walrus');
        self::assertSame(200, $search->getStatusCode());
        self::assertStringNotContainsString('Future walrus', (string) $search->getBody());
    }

    public function testAnEditShowsOnThePostPageAtOnce(): void
    {
        $item = $this->create(['title' => 'Cached', 'content_html' => '<p>Before edit</p>']);
        $id = substr((string) $item['id'], -26);
        self::assertStringContainsString('Before edit', (string) $this->page("/s/{$id}")->getBody());
        $this->api('PATCH', "/api/posts/{$id}", $this->token, ['content_html' => '<p>After edit</p>']);
        $html = (string) $this->page("/s/{$id}")->getBody();
        self::assertStringContainsString('After edit', $html);
        self::assertStringNotContainsString('Before edit', $html);
    }

    public function testTokensNeverReachTheLogs(): void
    {
        $this->create(['title' => 'Logged?']);
        $this->api('POST', '/api/blogs/notes/posts', $this->token . 'x', ['title' => 'x']);
        $this->api('POST', '/api/blogs/notes/posts?access_token=' . $this->token, null, ['title' => 'x']);
        $this->api('GET', '/api/posts/' . str_repeat('z', 26), $this->token);
        foreach (glob($this->tmp . '/logs/*') ?: [] as $file) {
            self::assertStringNotContainsString(substr($this->token, 4), (string) file_get_contents($file), $file);
        }
    }

    public function testErrorsAreJsonEvenForHtmlClients(): void
    {
        $response = $this->app()->handle((new ServerRequest('GET', '/api/posts/' . str_repeat('0', 26)))->withHeader('Accept', 'text/html'));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testTheApiNeedsTheTokensModule(): void
    {
        $this->xar(['module:disable', 'tokens']);
        $response = $this->api('POST', '/api/blogs/notes/posts', $this->token, ['title' => 'x']);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Api`
Expected: FAIL: `Failed asserting that 404 is identical to 201` (and 422): no `/api` route exists yet.

- [ ] **Step 3: Implement**

`modules/blog/src/Http/Api/JsonBody.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;

/** Reads a JSON object request body: 415 for another content type, 413 over MAX_BYTES, 400 when it is not an object. */
final class JsonBody
{
    public const MAX_BYTES = 1_000_000;

    /**
     * @param list<string> $types accepted media types
     * @return array<string, mixed>
     */
    public static function decode(ServerRequestInterface $request, array $types = ['application/json', 'application/feed+json']): array
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        if (!in_array($type, $types, true)) {
            throw new HttpException(415, 'Send the body as ' . implode(' or ', $types));
        }
        $stream = $request->getBody();
        $body = '';
        while (!$stream->eof() && strlen($body) <= self::MAX_BYTES) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }
        if (strlen($body) > self::MAX_BYTES) {
            throw new HttpException(413, 'The body is larger than ' . self::MAX_BYTES . ' bytes');
        }
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'The body is not valid JSON');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(400, 'The body must be a JSON object');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
```

`modules/blog/src/Http/Api/ApiAccess.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/** The blog-pin check for routes that reach a blog without a {handle} parameter (a post id, a Micropub destination). */
final class ApiAccess
{
    /** Returns the request's token when it may write to $blog; throws 401 without a token, 403 for another blog's pin. */
    public static function allow(ServerRequestInterface $request, Blog $blog): Token
    {
        $token = $request->getAttribute(Token::class);
        if (!$token instanceof Token) {
            throw TokenError::missing();
        }
        if (!$token->allowsBlog($blog->id)) {
            throw TokenError::wrongBlog();
        }

        return $token;
    }
}
```

`modules/blog/src/Http/Api/PostApiController.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Write\PostWriter;

/**
 * The native JSON API (spec §5.3). Every route runs behind `token:blog:write`. Items are served as stored (no
 * local-media rewriting) with `_phoenix.status` = published, scheduled or deleted, which the writer ignores on input.
 */
final class PostApiController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly PostWriter $writer,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}

    /** @param array<string, string> $params */
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blogs->find($params['handle']) ?? throw new NotFound("Unknown blog '{$params['handle']}'");
        ApiAccess::allow($request, $blog);
        $post = $this->writer->create($blog, JsonBody::decode($request));

        return $this->item($blog, $post, 201)->withHeader('Location', '/api/posts/' . $post->id);
    }

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);

        return $this->item($blog, $post);
    }

    /** @param array<string, string> $params */
    public function replace(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);

        return $this->item($blog, $this->writer->replace($post, JsonBody::decode($request)));
    }

    /** @param array<string, string> $params */
    public function patch(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);
        $partial = JsonBody::decode($request, ['application/merge-patch+json', 'application/json']);

        return $this->item($blog, $this->writer->patch($post, $partial));
    }

    /** @param array<string, string> $params */
    public function delete(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [, $post] = $this->load($request, $params['id']);
        $this->writer->delete($post);

        return new Response(204, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $params */
    public function undelete(ServerRequestInterface $request, array $params): ResponseInterface
    {
        [$blog, $post] = $this->load($request, $params['id']);

        return $this->item($blog, $this->writer->undelete($post));
    }

    /** @return array{0: Blog, 1: Post} */
    private function load(ServerRequestInterface $request, string $id): array
    {
        $post = $this->posts->find($id) ?? throw new NotFound('Unknown post');
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound('Unknown post');
        // The {id} route has no {handle}, so the token middleware cannot check a blog pin: check it here.
        ApiAccess::allow($request, $blog);

        return [$blog, $post];
    }

    private function item(Blog $blog, Post $post, int $status = 200): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $state = $post->status === 'deleted' ? 'deleted' : ($post->datePublished > $now ? 'scheduled' : 'published');
        $item = $this->items->item($blog, $post, rtrim((string) $this->config->get('app.url', ''), '/')) + ['_phoenix' => ['status' => $state]];

        return $this->json($item, $status)->withHeader('Cache-Control', 'no-store');
    }
}
```

In `modules/blog/src/Routes.php` (1 of 3), replace:

```php

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\AboutController;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
```

with:

```php

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\AboutController;
use Xaraya\Module\Blog\Http\Api\PostApiController;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
```

In `modules/blog/src/Routes.php` (2 of 3), replace:

```php

final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/feed.json', [FeedController::class, 'feedJson'], 'blog.feed.json')
```

with:

```php

final class Routes implements RouteProvider
{
    private const ID = '{id:[0-9a-hjkmnp-tv-z]{26}}';

    public function __construct(private readonly ModuleRegistry $modules) {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/feed.json', [FeedController::class, 'feedJson'], 'blog.feed.json')
```

In `modules/blog/src/Routes.php` (3 of 3), replace:

```php
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');
    }
}
```

with:

```php
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');

        // The native JSON API needs the tokens module, which provides the `token` middleware.
        if ($this->modules->isEnabled('tokens')) {
            $routes->group('/api', ['csrf:off', 'token:blog:write'], static function (RouteCollector $api): void {
                $api->post('/blogs/{handle:[a-z0-9_-]{2,32}}/posts', [PostApiController::class, 'create'], 'blog.api.posts');
                $api->get('/posts/' . self::ID, [PostApiController::class, 'show'], 'blog.api.post');
                $api->put('/posts/' . self::ID, [PostApiController::class, 'replace'], 'blog.api.post.replace');
                $api->patch('/posts/' . self::ID, [PostApiController::class, 'patch'], 'blog.api.post.patch');
                $api->delete('/posts/' . self::ID, [PostApiController::class, 'delete'], 'blog.api.post.delete');
                $api->post('/posts/' . self::ID . '/undelete', [PostApiController::class, 'undelete'], 'blog.api.post.undelete');
            });
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog`
Expected: PASS (`PostApiTest` 9 tests; the Plan A blog tests are unchanged).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (615 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Blog/Api`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Http/Api modules/blog/src/Routes.php tests/Module/Blog/Api
git commit -m "feat(blog): add the token-protected JSON API for native posts" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: Media upload: `MediaStore::ingest`, `MediaUploader` and the API endpoint

**Files:**
- Create: `modules/blog/src/Media/MediaRejected.php`, `modules/blog/src/Media/MediaUploader.php`, `modules/blog/src/Http/Api/MediaApiController.php`
- Modify: `modules/blog/src/Media/MediaStore.php` (the private `doStore()` becomes the public, throwing `ingest()`; `store()` keeps logging and returning null), `modules/blog/src/Routes.php`
- Test: `tests/Module/Blog/Api/MediaApiTest.php`

**Interfaces:**
- **Consumes:** `MediaStore::TYPES`, `MAX_BYTES`, `isRealImage()`, `write()`; `UrlGenerator::generate('blog.media', ['id', 'ext'], true)`; `ApiAccess::allow()`, `ApiTestCase` (Task 6); PSR-7 `UploadedFileInterface` (`getError()`, `getStream()`); `MediaStoreTest::PNG` (a 1×1 PNG, base64).
- **Produces:**
  - `MediaRejected extends RuntimeException` with a public readonly `int $status` (413, 415 or 422).
  - `MediaStore::ingest(Blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): array<string, mixed>` (the media row). It throws `MediaRejected` with `The file is empty` (422), `The file is larger than 10000000 bytes` (413), `Files of type {mime} are not allowed; send …` (415) or `The file is not a valid {mime} image` (422).
  - `MediaUploader::FIELD = 'file'`, `upload(Blog, ServerRequestInterface, DateTimeImmutable): array` (the row) and `url(array $row): string` (the absolute `blog.media` URL). It reads at most `MAX_BYTES + 1` bytes and maps PHP upload errors to 413, 400 or a `RuntimeException` (500).
  - Route `blog.api.media` `POST /api/blogs/{handle}/media` (`csrf:off`, `token:media:write`) → `MediaApiController::upload()`: 201, `Location` (absolute), body `{url, type, bytes, width, height}`; 409 for a mirror blog.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Api/MediaApiTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Api;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;

final class MediaApiTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $app = $this->enableTokens();
        $this->nativeBlog($app);
        $this->nativeBlog($app, ['handle' => 'other', 'title' => 'Other']);
        $this->token = $this->token('media:write');
    }

    /** Writes a fixture file into the test's temp dir and returns its path. */
    private function fixture(string $name, string $bytes): string
    {
        $path = $this->tmp . '/fixtures/' . $name;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $bytes);

        return $path;
    }

    /** @param array<string, UploadedFile> $files */
    private function upload(array $files, ?string $token = null, string $handle = 'notes'): ResponseInterface
    {
        $request = (new ServerRequest('POST', "/api/blogs/{$handle}/media"))
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withUploadedFiles($files);

        return $this->app()->handle($request);
    }

    private static function file(string $path, string $clientName = 'photo.png', string $clientType = 'image/png', int $error = UPLOAD_ERR_OK): UploadedFile
    {
        return new UploadedFile($path, is_file($path) ? (int) filesize($path) : 0, $error, $clientName, $clientType);
    }

    private function png(): string
    {
        return $this->fixture('tiny.png', (string) base64_decode(MediaStoreTest::PNG, true));
    }

    private function mediaRows(): int
    {
        return (int) $this->db($this->app())->fetchValue('SELECT COUNT(*) FROM {media}');
    }

    public function testStoresAnUploadUnderAUlidNameWhateverTheClientSays(): void
    {
        $response = $this->upload(['file' => self::file($this->png(), '../../evil.php', 'text/html')]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-hjkmnp-tv-z]{26}\.png$#', $location);
        self::assertSame(['url' => $location, 'type' => 'image/png', 'bytes' => 70, 'width' => 1, 'height' => 1], self::json($response));

        $files = glob($this->tmp . '/uploads/notes/*') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('#/uploads/notes/[0-9a-hjkmnp-tv-z]{26}\.png$#', $files[0]);
        self::assertSame([], glob($this->tmp . '/*.php') ?: [], 'nothing escapes the uploads directory');
        self::assertSame(['notes'], array_map('basename', glob($this->tmp . '/uploads/*') ?: []));

        $served = $this->app()->handle(new ServerRequest('GET', (string) parse_url($location, PHP_URL_PATH)));
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        self::assertSame((string) file_get_contents($this->png()), (string) $served->getBody());
    }

    public function testATextFileNamedJpgIs415(): void
    {
        $response = $this->upload(['file' => self::file($this->fixture('notes.jpg', "just some text\n"), 'notes.jpg', 'image/jpeg')]);
        self::assertSame(415, $response->getStatusCode());
        self::assertStringStartsWith('Files of type text/plain are not allowed', self::json($response)['error']['message']);
        self::assertSame(0, $this->mediaRows());
    }

    public function testAPolyglotImageIs422(): void
    {
        $response = $this->upload(['file' => self::file($this->fixture('poly.gif', 'GIF89a<html><script>alert(1)</script>'), 'poly.gif', 'image/gif')]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('The file is not a valid image/gif image', self::json($response)['error']['message']);
        self::assertSame(0, $this->mediaRows());
    }

    public function testAnOversizeFileIs413(): void
    {
        $path = $this->fixture('big.png', (string) base64_decode(MediaStoreTest::PNG, true) . str_repeat("\0", MediaStore::MAX_BYTES));
        $response = $this->upload(['file' => self::file($path)]);
        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $this->mediaRows());
        self::assertSame([], glob($this->tmp . '/uploads/notes/*') ?: []);
    }

    public function testPhpUploadErrorsAreMapped(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE => 413, UPLOAD_ERR_FORM_SIZE => 413, UPLOAD_ERR_PARTIAL => 400, UPLOAD_ERR_NO_FILE => 400, UPLOAD_ERR_CANT_WRITE => 500] as $error => $status) {
            self::assertSame($status, $this->upload(['file' => self::file('/nonexistent', 'a.png', 'image/png', $error)])->getStatusCode(), "error {$error}");
        }
    }

    public function testMultipartWithoutAFileFieldIs400(): void
    {
        foreach ([[], ['photo' => self::file($this->png())]] as $files) {
            $response = $this->upload($files);
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('Upload one file in the multipart field "file"', self::json($response)['error']['message']);
        }
    }

    public function testScopeBlogPinModeAndHandleAreChecked(): void
    {
        self::assertSame(403, $this->upload(['file' => self::file($this->png())], $this->token('blog:write'))->getStatusCode());
        self::assertSame(403, $this->upload(['file' => self::file($this->png())], $this->token('media:write', ['--blog=other']))->getStatusCode());
        self::assertSame(404, $this->upload(['file' => self::file($this->png())], null, 'nope')->getStatusCode());
        $this->blogs($this->app())->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        self::assertSame(409, $this->upload(['file' => self::file($this->png())], null, 'src')->getStatusCode());
        self::assertSame(0, $this->mediaRows());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Api/MediaApiTest.php`
Expected: FAIL: `Failed asserting that 404 is identical to 201` (and 415, 422, 413): the route does not exist.

- [ ] **Step 3: Implement**

`modules/blog/src/Media/MediaRejected.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use RuntimeException;

/** Bytes MediaStore refuses to keep. $status is the HTTP answer for an upload: 413 too large, 415 type, 422 invalid. */
final class MediaRejected extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
```

In `modules/blog/src/Media/MediaStore.php` (1 of 3), replace:

```php
        return $this->store($blog, $result->body, $url, $now);
    }

    /** @return array<string, mixed>|null */
    public function store(Blog $blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): ?array
    {
        try {
            return $this->doStore($blog, $bytes, $sourceUrl, $now);
        } catch (Throwable $e) {
            $this->logger->warning('Media {url} for {blog} could not be stored: {reason}', ['url' => $sourceUrl ?? '(upload)', 'blog' => $blog->handle, 'reason' => $e->getMessage()]);

```

with:

```php
        return $this->store($blog, $result->body, $url, $now);
    }

    /**
     * Like ingest(), but logs any failure and returns null: sync and media:localize carry on without the file.
     *
     * @return array<string, mixed>|null
     */
    public function store(Blog $blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): ?array
    {
        try {
            return $this->ingest($blog, $bytes, $sourceUrl, $now);
        } catch (Throwable $e) {
            $this->logger->warning('Media {url} for {blog} could not be stored: {reason}', ['url' => $sourceUrl ?? '(upload)', 'blog' => $blog->handle, 'reason' => $e->getMessage()]);

```

In `modules/blog/src/Media/MediaStore.php` (2 of 3), replace:

```php
        }
    }

    /** @return array<string, mixed>|null */
    private function doStore(Blog $blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): ?array
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
        if (str_starts_with($mime, 'image/') && $mime !== 'image/avif' && !self::isRealImage($bytes)) {
            $this->logger->warning('Media {url} for {blog} rejected: not a valid image', ['url' => $sourceUrl ?? '(upload)', 'blog' => $blog->handle]);

            return null;
        }
        $sha = hash('sha256', $bytes);
        $same = $this->db->select('media')->columns('path')->where('blog_id', '=', $blog->id)->where('sha256', '=', $sha)->first();
```

with:

```php
        }
    }

    /**
     * Stores $bytes as a new media row of $blog. The type comes from finfo (never from a client), the file name is a
     * fresh ULID with the extension of that type, under {uploads}/{handle}/. Identical bytes share one file.
     *
     * @return array<string, mixed> the media row
     * @throws MediaRejected when the bytes are empty, too large, of a type not allowed or not a valid image
     */
    public function ingest(Blog $blog, string $bytes, ?string $sourceUrl, DateTimeImmutable $now): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            throw new MediaRejected(422, 'The file is empty');
        }
        if ($size > self::MAX_BYTES) {
            throw new MediaRejected(413, 'The file is larger than ' . self::MAX_BYTES . ' bytes');
        }
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext = self::TYPES[$mime] ?? null;
        if ($ext === null) {
            throw new MediaRejected(415, "Files of type {$mime} are not allowed; send " . implode(', ', array_keys(self::TYPES)));
        }
        if (str_starts_with($mime, 'image/') && $mime !== 'image/avif' && !self::isRealImage($bytes)) {
            throw new MediaRejected(422, "The file is not a valid {$mime} image");
        }
        $sha = hash('sha256', $bytes);
        $same = $this->db->select('media')->columns('path')->where('blog_id', '=', $blog->id)->where('sha256', '=', $sha)->first();
```

In `modules/blog/src/Media/MediaStore.php` (3 of 3), replace:

```php
            'created_at' => $now,
        ]);

        return $this->find($id);
    }

    /** @return array<string, mixed>|null */
```

with:

```php
            'created_at' => $now,
        ]);

        return $this->find($id) ?? throw new RuntimeException("Media {$id} vanished after insert");
    }

    /** @return array<string, mixed>|null */
```

`modules/blog/src/Media/MediaUploader.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Media;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;

/**
 * Takes the multipart field `file` of an upload request (JSON API and Micropub media endpoints) into MediaStore.
 * The client's file name and type are never used.
 */
final class MediaUploader
{
    public const FIELD = 'file';

    public function __construct(private readonly MediaStore $store, private readonly UrlGenerator $urls) {}

    /** @return array<string, mixed> the media row */
    public function upload(Blog $blog, ServerRequestInterface $request, DateTimeImmutable $now): array
    {
        $file = $request->getUploadedFiles()[self::FIELD] ?? null;
        if (!$file instanceof UploadedFileInterface) {
            throw new HttpException(400, 'Upload one file in the multipart field "' . self::FIELD . '"');
        }
        match ($file->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new HttpException(413, 'The file is larger than this server accepts'),
            UPLOAD_ERR_PARTIAL => throw new HttpException(400, 'The upload was interrupted'),
            UPLOAD_ERR_NO_FILE => throw new HttpException(400, 'Upload one file in the multipart field "' . self::FIELD . '"'),
            default => throw new RuntimeException('The upload failed with PHP upload error ' . $file->getError()),
        };
        try {
            return $this->store->ingest($blog, self::read($file), null, $now);
        } catch (MediaRejected $e) {
            throw new HttpException($e->status, $e->getMessage());
        }
    }

    /**
     * The absolute public URL of a media row (the blog.media route).
     *
     * @param array<string, mixed> $row
     */
    public function url(array $row): string
    {
        return $this->urls->generate('blog.media', ['id' => (string) $row['id'], 'ext' => MediaStore::TYPES[(string) $row['mime']] ?? 'bin'], true);
    }

    /** Reads at most MAX_BYTES + 1 bytes, so an oversize upload is refused without loading all of it. */
    private static function read(UploadedFileInterface $file): string
    {
        $stream = $file->getStream();
        $bytes = '';
        while (!$stream->eof() && strlen($bytes) <= MediaStore::MAX_BYTES) {
            $chunk = $stream->read(1 << 20);
            if ($chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        if (strlen($bytes) > MediaStore::MAX_BYTES) {
            throw new HttpException(413, 'The file is larger than ' . MediaStore::MAX_BYTES . ' bytes');
        }

        return $bytes;
    }
}
```

`modules/blog/src/Http/Api/MediaApiController.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http\Api;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Media\MediaUploader;

/** POST /api/blogs/{handle}/media (scope media:write): stores one upload, 201 with the absolute media URL. */
final class MediaApiController extends Controller
{
    public function __construct(private readonly BlogRepository $blogs, private readonly MediaUploader $uploader) {}

    /** @param array<string, string> $params */
    public function upload(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $blog = $this->blogs->find($params['handle']) ?? throw new NotFound("Unknown blog '{$params['handle']}'");
        ApiAccess::allow($request, $blog);
        if ($blog->isMirror()) {
            throw new HttpException(409, "Blog '{$blog->handle}' is a mirror: its posts come from its source");
        }
        $row = $this->uploader->upload($blog, $request, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $url = $this->uploader->url($row);

        return $this->json([
            'url' => $url,
            'type' => (string) $row['mime'],
            'bytes' => (int) $row['bytes'],
            'width' => $row['width'] === null ? null : (int) $row['width'],
            'height' => $row['height'] === null ? null : (int) $row['height'],
        ], 201)->withHeader('Location', $url)->withHeader('Cache-Control', 'no-store');
    }
}
```

In `modules/blog/src/Routes.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\AboutController;
use Xaraya\Module\Blog\Http\Api\PostApiController;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
```

with:

```php
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\AboutController;
use Xaraya\Module\Blog\Http\Api\MediaApiController;
use Xaraya\Module\Blog\Http\Api\PostApiController;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
```

In `modules/blog/src/Routes.php` (2 of 2), replace:

```php
                $api->delete('/posts/' . self::ID, [PostApiController::class, 'delete'], 'blog.api.post.delete');
                $api->post('/posts/' . self::ID . '/undelete', [PostApiController::class, 'undelete'], 'blog.api.post.undelete');
            });
        }
    }
}
```

with:

```php
                $api->delete('/posts/' . self::ID, [PostApiController::class, 'delete'], 'blog.api.post.delete');
                $api->post('/posts/' . self::ID . '/undelete', [PostApiController::class, 'undelete'], 'blog.api.post.undelete');
            });
            $routes->post('/api/blogs/{handle:[a-z0-9_-]{2,32}}/media', [MediaApiController::class, 'upload'], 'blog.api.media')
                ->middleware('csrf:off', 'token:media:write');
        }
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Api tests/Module/Blog/Media`
Expected: PASS (`MediaApiTest` 7 tests; `MediaStoreTest` and `LocalMediaTest` unchanged).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (622 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Blog/Api tests/Module/Blog/Media`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Media modules/blog/src/Http/Api/MediaApiController.php modules/blog/src/Routes.php tests/Module/Blog/Api/MediaApiTest.php
git commit -m "feat(blog): accept media uploads through the JSON API" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 8: `xar media:localize <handle>`

**Files:**
- Create: `modules/blog/src/Cli/MediaLocalizeCommand.php`
- Modify: `modules/blog/module.json` (`commands`)
- Test: `tests/Module/Blog/Cli/MediaLocalizeCommandTest.php`

**Interfaces:**
- **Consumes:** `PostRepository::publishedAfter(int $blogId, ?string $afterId, int $limit): list<Post>`; `MediaLocalizer::urls(PostRecord|Post): list<string>`; `MediaStore::localize(Blog, string $url, DateTimeImmutable): ?array` (deduplicates by URL, then by sha256; logs and returns null on failure); `BlogRepository::update(Blog, ['media' => 'local'], $now)`; `FixtureFetcher`; `BlogTestCase::addPost()`, `postRecord()`.
- **Produces:** command `media:localize <handle>`. It prints `{handle}: {n} media URL(s) stored locally, {m} left remote (see the log). Media is now local.` and exits 0, or exits 1 with `Usage: xar media:localize <handle>` or `Unknown blog '{handle}'`.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Cli/MediaLocalizeCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Module\Blog\Source\FetchResult;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;

final class MediaLocalizeCommandTest extends BlogTestCase
{
    public function testDownloadsRemoteMediaThenSwitchesTheBlogToLocal(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['image' => 'https://img.test/a.png', 'kind' => 'photo']));
        $this->addPost($app, $blog, self::postRecord(2, ['image' => 'https://img.test/missing.png', 'doc' => ['_athenana' => ['images' => [['url' => 'https://img.test/a.png']]]]]));
        $this->addPost($app, $blog, self::postRecord(3, ['image' => 'http://xar.test/media/01jabcdefghjkmnpqrstvwxyz0.png']));

        $fetcher = (new FixtureFetcher())->on('https://img.test/a.png', new FetchResult(200, (string) base64_decode(MediaStoreTest::PNG, true)));
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, $fetcher);
        [$code, $out, $err] = $this->xar(['media:localize', 'notes'], $app);
        self::assertSame(0, $code, $err);
        self::assertSame("notes: 2 media URL(s) stored locally, 1 left remote (see the log). Media is now local.\n", $out);
        $fetched = array_column($fetcher->requests, 'url');
        sort($fetched);
        self::assertSame(['https://img.test/a.png', 'https://img.test/missing.png'], $fetched, 'fetched once each; our own /media URL never');
        self::assertSame('local', $this->blogs($this->app())->find('notes')?->media);

        $feed = json_decode((string) $this->app()->handle(new ServerRequest('GET', '/blog/notes/feed.json'))->getBody(), true);
        $images = array_column($feed['items'], 'image');
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-z]{26}\.png$#', $images[0]);
        self::assertSame('https://img.test/missing.png', $images[1], 'a failed download stays remote');
    }

    public function testNeedsAKnownHandle(): void
    {
        $this->enableBlog();
        [$code, , $err] = $this->xar(['media:localize']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar media:localize <handle>', $err);
        [$code, , $err] = $this->xar(['media:localize', 'nope']);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'nope'", $err);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Cli/MediaLocalizeCommandTest.php`
Expected: FAIL: `Unknown command 'media:localize'. Run 'xar help'.`

- [ ] **Step 3: Implement**

`modules/blog/src/Cli/MediaLocalizeCommand.php`:

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
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Media\MediaLocalizer;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Post\PostRepository;

/**
 * Downloads every remote media URL of a blog's published posts (scheduled ones included) with the sync rules
 * (§3.4: allowed types only, deduplicated by URL then sha256, failures stay remote), then sets media=local.
 */
final class MediaLocalizeCommand extends Command
{
    private const CHUNK = 200;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly MediaStore $store,
        private readonly Config $config,
    ) {}

    public function name(): string
    {
        return 'media:localize';
    }

    public function description(): string
    {
        return "Download a blog's remote media and serve it from /media (sets media=local)";
    }

    public function usage(): string
    {
        return 'media:localize <handle>';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $blog = $this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'");
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Uploads already live at {app.url}/media/…: never download our own files.
        $own = rtrim((string) $this->config->get('app.url', ''), '/') . '/media/';
        $local = $failed = 0;
        $after = null;
        while (($chunk = $this->posts->publishedAfter($blog->id, $after, self::CHUNK)) !== []) {
            foreach ($chunk as $post) {
                foreach (MediaLocalizer::urls($post) as $url) {
                    if (str_starts_with($url, $own)) {
                        continue;
                    }
                    $this->store->localize($blog, $url, $now) !== null ? $local++ : $failed++;
                }
            }
            $after = $chunk[count($chunk) - 1]->id;
        }
        $this->blogs->update($blog, ['media' => 'local'], $now);
        $output->line("{$handle}: {$local} media URL(s) stored locally, {$failed} left remote (see the log). Media is now local.");

        return 0;
    }
}
```

In `modules/blog/module.json`, replace:

```json
    "Xaraya\\Module\\Blog\\Cli\\BlogListCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogModeCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogReindexCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogSyncCommand"
  ],
  "blocks": {
    "blog.info": "Xaraya\\Module\\Blog\\Blocks\\InfoBlock",
```

with:

```json
    "Xaraya\\Module\\Blog\\Cli\\BlogListCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogModeCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogReindexCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogSyncCommand",
    "Xaraya\\Module\\Blog\\Cli\\MediaLocalizeCommand"
  ],
  "blocks": {
    "blog.info": "Xaraya\\Module\\Blog\\Blocks\\InfoBlock",
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Cli`
Expected: PASS (`MediaLocalizeCommandTest` 2 tests). Two posts made in the same millisecond may come back from `publishedAfter()` in either order, so the test sorts the fetched URLs.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (624 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Blog/Cli`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/module.json modules/blog/src/Cli/MediaLocalizeCommand.php tests/Module/Blog/Cli/MediaLocalizeCommandTest.php
git commit -m "feat(blog): add xar media:localize" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 9: `micropub` module: form and JSON create

**Files:**
- Create: `modules/micropub/module.json`, `modules/micropub/src/Routes.php`, `modules/micropub/src/MicropubErrors.php`, `modules/micropub/src/FormToken.php`, `modules/micropub/src/MicropubInput.php`, `modules/micropub/src/Mf2Mapper.php`, `modules/micropub/src/Destination.php`, `modules/micropub/src/MicropubController.php`
- Test: `tests/Module/Micropub/MicropubTestCase.php`, `tests/Module/Micropub/MicropubCreateTest.php`

**Interfaces:**
- **Consumes:** `PostWriter::create()` (Task 4); `TokenError` with `->error`, `Token::allowsBlog()` (Task 3); `ValidationFailed::fields()` (Task 1); `JsonBody::decode()` (Task 6); `ItemSerializer::url(Blog, Post, string $appUrl)`; `BlogRepository::all()`, `find()`, `findById()`; `Text::plain()`, `deriveTitle()`; `TokensTestCase`.
- **Produces:**
  - Middleware aliases `micropubErrors` (`MicropubErrors`) and `micropubToken` (`FormToken`). Route `micropub` `POST /micropub` (`csrf:off`, `micropubErrors`, `micropubToken`, `token:blog:write`) → `MicropubController::post()`.
  - `MicropubInput` (readonly `string $type` (`entry`), `?string $action`, `?string $url`, `array<string, list<mixed>> $properties`, `array<string, list<mixed>> $commands`, `array<string, mixed> $json`). It has `static fromRequest(ServerRequestInterface): self` (JSON, form or multipart; else 415 → 400), `static mediaType(ServerRequestInterface): string` and `command(string): ?string`.
  - `Mf2Mapper`: `PROPERTIES`, `toItem(array $properties): array` (always sets `_athenana.kind`), `fromItem(array $item): array<string, list<mixed>>` and `static textToHtml(string): string`.
  - `Destination`: `choices(Token): list<Blog>` (native blogs the token may use, by handle) and `choose(?string $uid, Token): Blog` (400 `Unknown destination '{uid}'`, 403 for a pin elsewhere, 400 `Choose a blog with mp-destination (q=config lists them)`).
  - `MicropubController::__construct(PostWriter, Mf2Mapper, Destination, ItemSerializer, Config)` and `post(ServerRequestInterface): ResponseInterface`: create answers 201 with `Location` = the item url; another action is 400 `Unknown action '{action}'`.
  - Test helpers (`MicropubTestCase extends TokensTestCase`):
    - `$token` and `enableMicropub(): App` (the blog `notes` and a `blog:write,media:write` token).
    - Requests: `form(array $fields, ?string $token = null, string $uri = '/micropub')` (`''` sends no header), `postJson(array $body, ?string $token = null)` and `query(array $query, ?string $token = null)`.
    - Assertions: `created(ResponseInterface): Post`, `body(ResponseInterface): array` and `assertMicropubError(ResponseInterface, int $status, string $error, ?string $description = null)`.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Micropub/MicropubTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Tests\Module\Tokens\TokensTestCase;

/** HTTP tests of the micropub module, through App::handle. */
abstract class MicropubTestCase extends TokensTestCase
{
    protected string $token = '';

    /** Enables blog, tokens and micropub, creates the native blog "notes" and an unpinned blog:write,media:write token. */
    protected function enableMicropub(): App
    {
        $app = $this->enableTokens();
        [$code, $out, $err] = $this->xar(['module:enable', 'micropub']);
        self::assertSame(0, $code, $out . $err);
        $this->nativeBlog($app);
        $this->token = $this->token('blog:write,media:write');

        return $this->app();
    }

    /**
     * A form-encoded POST /micropub. $fields is sent both as the raw body and as the parsed body, as PHP does.
     *
     * @param array<string, mixed> $fields
     */
    protected function form(array $fields, ?string $token = null, string $uri = '/micropub'): ResponseInterface
    {
        $request = (new ServerRequest('POST', $uri))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody(Stream::create(http_build_query($fields)))
            ->withParsedBody($fields);
        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token));
        }

        return $this->app()->handle($request);
    }

    /** @param array<string, mixed> $body */
    protected function postJson(array $body, ?string $token = null): ResponseInterface
    {
        return $this->app()->handle((new ServerRequest('POST', '/micropub'))
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token))
            ->withHeader('Content-Type', 'application/json')
            ->withBody(Stream::create(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))));
    }

    /** @param array<string, string> $query */
    protected function query(array $query, ?string $token = null): ResponseInterface
    {
        return $this->app()->handle((new ServerRequest('GET', '/micropub?' . http_build_query($query)))
            ->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token)));
    }

    /** The post a 201's Location points at (http://xar.test/s/{id}). */
    protected function created(ResponseInterface $response): Post
    {
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^http://xar\.test/s/[0-9a-hjkmnp-tv-z]{26}$#', $location);
        $post = $this->app()->container()->get(PostRepository::class)->find(substr($location, -26));
        self::assertNotNull($post);

        return $post;
    }

    /** @return array<string, mixed> */
    protected static function body(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    protected static function assertMicropubError(ResponseInterface $response, int $status, string $error, ?string $description = null): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $body = self::body($response);
        self::assertSame($error, $body['error'] ?? null);
        self::assertIsString($body['error_description'] ?? null);
        if ($description !== null) {
            self::assertSame($description, $body['error_description']);
        }
    }
}
```

`tests/Module/Micropub/MicropubCreateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Xaraya\Module\Micropub\Mf2Mapper;

final class MicropubCreateTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    public function testAFormNoteBecomesAPost(): void
    {
        $post = $this->created($this->form(['h' => 'entry', 'content' => "Hello <world>\nline two\n\nSecond", 'category' => ['indieweb', 'Micro Pub']]));
        self::assertSame('note', $post->kind);
        self::assertSame('<p>Hello &lt;world&gt;<br>' . "\n" . 'line two</p><p>Second</p>', $post->contentHtml);
        self::assertSame([['name' => 'indieweb', 'slug' => 'indieweb'], ['name' => 'Micro Pub', 'slug' => 'micro-pub']], $post->tags);
        self::assertSame('Hello <world> line two Second', $post->title);
    }

    public function testAJsonLinkBecomesALinkPost(): void
    {
        $post = $this->created($this->postJson(['type' => ['h-entry'], 'properties' => [
            'bookmark-of' => ['https://example.com/article'],
            'name' => ['A good read'],
            'content' => [['html' => '<p>Worth it.</p>']],
            'published' => ['2026-10-10T09:00:00+02:00'],
        ]]));
        self::assertSame(['link', 'https://example.com/article', 'A good read', '<p>Worth it.</p>'], [$post->kind, $post->externalUrl, $post->title, $post->contentHtml]);
        self::assertSame('2026-10-10 07:00:00', $post->datePublished->format('Y-m-d H:i:s'));
    }

    public function testJsonPhotosFillImageAndImages(): void
    {
        $post = $this->created($this->postJson(['type' => ['h-entry'], 'properties' => [
            'photo' => ['https://img.test/1.jpg', ['value' => 'https://img.test/2.jpg', 'alt' => 'Two']],
        ]]));
        self::assertSame(['photo', 'https://img.test/1.jpg', 'Photo'], [$post->kind, $post->image, $post->title]);
        self::assertSame([['url' => 'https://img.test/1.jpg'], ['url' => 'https://img.test/2.jpg']], $post->doc['_athenana']['images'] ?? null);
    }

    public function testFormPhotoListAndHtmlContent(): void
    {
        $post = $this->created($this->form(['photo' => ['https://img.test/a.jpg'], 'content' => ['html' => '<p><b>Hi</b></p>']]));
        self::assertSame(['photo', '<p><b>Hi</b></p>'], [$post->kind, $post->contentHtml]);
    }

    public function testTheTokenMayComeAsAFormField(): void
    {
        $post = $this->created($this->form(['content' => 'Via field', 'access_token' => $this->token], ''));
        self::assertSame('<p>Via field</p>', $post->contentHtml);
        self::assertMicropubError($this->form(['content' => 'Both', 'access_token' => $this->token]), 400, 'invalid_request', 'Send the token in the Authorization header or in access_token, not both');
        self::assertMicropubError($this->form(['content' => 'Query'], '', '/micropub?access_token=' . $this->token), 401, 'unauthorized');
        self::assertMicropubError($this->form(['content' => 'Bad', 'access_token' => 'nope'], ''), 401, 'unauthorized', 'The token is invalid or expired');
    }

    public function testScopeErrorsUseMicropubCodes(): void
    {
        $response = $this->form(['content' => 'x'], $this->token('media:write'));
        self::assertMicropubError($response, 403, 'insufficient_scope', 'The token lacks the blog:write scope');
        self::assertSame('Bearer error="insufficient_scope", scope="blog:write"', $response->getHeaderLine('WWW-Authenticate'));
    }

    public function testTheDestinationIsChosenAndPinsAreKept(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        self::assertMicropubError($this->form(['content' => 'Where?']), 400, 'invalid_request', 'Choose a blog with mp-destination (q=config lists them)');
        self::assertSame($this->blogs($this->app())->find('other')?->id, $this->created($this->form(['content' => 'x', 'mp-destination' => 'other']))->blogId);
        self::assertSame($this->blogs($this->app())->find('other')?->id, $this->created($this->postJson(['type' => ['h-entry'], 'properties' => ['content' => ['x'], 'mp-destination' => ['other']]]))->blogId);
        self::assertMicropubError($this->form(['content' => 'x', 'mp-destination' => 'nope']), 400, 'invalid_request', "Unknown destination 'nope'");

        $pinned = $this->token('blog:write', ['--blog=notes']);
        self::assertSame($this->blogs($this->app())->find('notes')?->id, $this->created($this->form(['content' => 'pinned'], $pinned))->blogId);
        self::assertMicropubError($this->form(['content' => 'x', 'mp-destination' => 'other'], $pinned), 403, 'forbidden');
    }

    public function testInvalidRequestsAre400(): void
    {
        self::assertMicropubError($this->form(['h' => 'card', 'name' => 'Me']), 400, 'invalid_request', 'Only h-entry posts are supported');
        self::assertMicropubError($this->form(['content' => 'x', 'published' => 'yesterday']), 400, 'invalid_request', 'date_published: Must be an RFC 3339 date, like 2026-10-11T09:30:00+00:00');
        self::assertMicropubError($this->form(['bookmark-of' => 'javascript:alert(1)']), 400, 'invalid_request', 'external_url: Must be an absolute http(s) URL of at most 2048 bytes');
        self::assertMicropubError($this->postJson(['type' => ['h-entry'], 'properties' => ['content' => [5]]]), 400, 'invalid_request', 'content must be text or {"html": "…"}');
        self::assertMicropubError($this->postJson(['type' => ['h-entry'], 'properties' => ['content' => 'not a list']]), 400, 'invalid_request', 'Property content must be a list of values');
        self::assertMicropubError($this->form([]), 400, 'invalid_request', 'content_html: An item needs content_html, content_text, a title, an external_url or an image');
        self::assertMicropubError($this->form(['action' => 'explode', 'url' => 'http://xar.test/s/x']), 400, 'invalid_request', "Unknown action 'explode'");
        $xml = $this->app()->handle((new ServerRequest('POST', '/micropub'))->withHeader('Authorization', 'Bearer ' . $this->token)->withHeader('Content-Type', 'text/xml')->withBody(Stream::create('<x/>')));
        self::assertMicropubError($xml, 400, 'invalid_request');
    }

    public function testTextToHtml(): void
    {
        self::assertSame('<p>a &amp; b</p>', Mf2Mapper::textToHtml(" a & b \n"));
        self::assertSame('', Mf2Mapper::textToHtml("\n\n"));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Micropub`
Expected: FAIL: `Failed asserting that 1 is identical to 0` (`module:enable micropub` reports `Unknown module 'micropub'`).

- [ ] **Step 3: Implement**

`modules/micropub/module.json`:

```json
{
  "name": "micropub",
  "version": "0.1.0",
  "requires": { "kernel": "^0.1", "modules": { "blog": "^0.1", "tokens": "^0.1" } },
  "routes": "Xaraya\\Module\\Micropub\\Routes",
  "middleware": {
    "micropubErrors": "Xaraya\\Module\\Micropub\\MicropubErrors",
    "micropubToken": "Xaraya\\Module\\Micropub\\FormToken"
  }
}
```

`modules/micropub/src/Routes.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/**
 * Micropub (W3C) on top of the blog's PostWriter. micropubErrors turns every 4xx into Micropub's error JSON, and
 * micropubToken moves a form `access_token` into the Authorization header before `token:` checks it.
 */
final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->post('/micropub', [MicropubController::class, 'post'], 'micropub')
            ->middleware('csrf:off', 'micropubErrors', 'micropubToken', 'token:blog:write');
    }
}
```

`modules/micropub/src/MicropubErrors.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Module\Tokens\TokenError;

/**
 * Answers every 4xx in Micropub's shape, {"error": …, "error_description": …}: 401 unauthorized, 403 forbidden or
 * insufficient_scope, and 400 invalid_request for everything else (validation, unknown posts, conflicts, sizes).
 * 5xx errors go on to the kernel ErrorHandler, which logs them.
 */
final class MicropubErrors implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            $status = $e->status();
            if ($status >= 500) {
                throw $e;
            }
            [$status, $error] = match (true) {
                $e instanceof TokenError => [$status, $e->error],
                $status === 401 => [401, 'unauthorized'],
                $status === 403 => [403, 'forbidden'],
                default => [400, 'invalid_request'],
            };
            $response = Controller::jsonResponse(['error' => $error, 'error_description' => self::describe($e)], $status);
            foreach ($e->headers() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }

            return $response;
        }
    }

    private static function describe(HttpException $e): string
    {
        if (!$e instanceof ValidationFailed) {
            return $e->getMessage();
        }
        $parts = [];
        foreach ($e->fields() as $field => $message) {
            $parts[] = "{$field}: {$message}";
        }

        return implode('; ', $parts);
    }
}
```

`modules/micropub/src/FormToken.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Http\Exception\HttpException;

/**
 * Micropub lets a form body carry the token as `access_token` (spec §5.1: Micropub routes only). This parses a
 * form-encoded body when the server has not, and moves that field into `Authorization: Bearer`, so `token:` reads
 * a header as everywhere else. Sending the token both ways is a 400 (RFC 6750 §2). The query string is never read.
 */
final class FormToken implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $form = $request->getParsedBody();
        if (!is_array($form) && MicropubInput::mediaType($request) === 'application/x-www-form-urlencoded') {
            parse_str((string) $request->getBody(), $form);
            $request = $request->withParsedBody($form);
        }
        if (is_array($form) && array_key_exists('access_token', $form)) {
            if ($request->hasHeader('Authorization')) {
                throw new HttpException(400, 'Send the token in the Authorization header or in access_token, not both');
            }
            $token = $form['access_token'];
            unset($form['access_token']);
            $request = $request->withParsedBody($form)->withHeader('Authorization', 'Bearer ' . (is_string($token) ? $token : ''));
        }

        return $handler->handle($request);
    }
}
```

`modules/micropub/src/MicropubInput.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Http\Api\JsonBody;

/**
 * A Micropub POST, form-encoded or JSON, normalised: the h type ("entry"), the action and its url, mf2 properties
 * (every value a list) and the mp-* commands. JSON update bodies keep their replace/add/delete objects in $json.
 */
final class MicropubInput
{
    /**
     * @param array<string, list<mixed>> $properties
     * @param array<string, list<mixed>> $commands
     * @param array<string, mixed> $json the decoded JSON body ([] for a form)
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $action,
        public readonly ?string $url,
        public readonly array $properties,
        public readonly array $commands,
        public readonly array $json,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $mediaType = self::mediaType($request);
        if ($mediaType === 'application/json') {
            return self::fromJson(JsonBody::decode($request, ['application/json']));
        }
        if ($mediaType === 'application/x-www-form-urlencoded' || $mediaType === 'multipart/form-data') {
            $form = $request->getParsedBody();

            return self::fromForm(is_array($form) ? $form : []);
        }
        throw new HttpException(415, 'Send application/x-www-form-urlencoded, multipart/form-data or application/json');
    }

    public static function mediaType(ServerRequestInterface $request): string
    {
        return strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
    }

    /** The first value of an mp-* command, when it is a string. */
    public function command(string $name): ?string
    {
        $value = $this->commands[$name][0] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<array-key, mixed> $form */
    private static function fromForm(array $form): self
    {
        $type = 'entry';
        $action = $url = null;
        $properties = $commands = [];
        foreach ($form as $key => $value) {
            $key = (string) $key;
            match (true) {
                $key === 'h' => $type = self::string($value, 'h'),
                $key === 'action' => $action = self::string($value, 'action'),
                $key === 'url' => $url = self::string($value, 'url'),
                str_starts_with($key, 'mp-') => $commands[$key] = self::values($value),
                default => $properties[$key] = self::values($value),
            };
        }

        return new self($type, $action, $url, $properties, $commands, []);
    }

    /** @param array<string, mixed> $json */
    private static function fromJson(array $json): self
    {
        $type = 'entry';
        if (array_key_exists('type', $json)) {
            $types = $json['type'];
            $first = is_array($types) && array_is_list($types) ? ($types[0] ?? null) : null;
            if (!is_string($first) || !str_starts_with($first, 'h-')) {
                throw new HttpException(400, 'type must be a list like ["h-entry"]');
            }
            $type = substr($first, 2);
        }
        $properties = $commands = [];
        $raw = $json['properties'] ?? [];
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new HttpException(400, 'properties must be an object');
        }
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (!is_array($value) || !array_is_list($value)) {
                throw new HttpException(400, "Property {$key} must be a list of values");
            }
            str_starts_with($key, 'mp-') ? $commands[$key] = $value : $properties[$key] = $value;
        }
        $action = isset($json['action']) ? self::string($json['action'], 'action') : null;
        $url = isset($json['url']) ? self::string($json['url'], 'url') : null;

        return new self($type, $action, $url, $properties, $commands, $json);
    }

    private static function string(mixed $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new HttpException(400, "{$name} must be a string");
        }

        return $value;
    }

    /** @return list<mixed> a form value as an mf2 value list: "x" => ["x"], x[]=a&x[]=b => ["a","b"], x[html]=… => [{html: …}] */
    private static function values(mixed $value): array
    {
        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [$value];
    }
}
```

`modules/micropub/src/Mf2Mapper.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Support\Text;

/**
 * Maps h-entry properties to JSON Feed item fields and back (spec §5.5):
 * content → content_html (text is escaped into <p>…</p>, one per blank-line-separated paragraph); name → title;
 * bookmark-of → external_url (kind link); photo → image (the first) and _athenana.images (kind photo);
 * category → tags; published → date_published. Other properties are ignored.
 */
final class Mf2Mapper
{
    public const PROPERTIES = ['content', 'name', 'bookmark-of', 'photo', 'category', 'published'];

    /**
     * @param array<string, list<mixed>> $properties
     * @return array<string, mixed> item fields; `_athenana` holds kind (always) and images (with photos)
     */
    public function toItem(array $properties): array
    {
        $item = [];
        if (($content = $properties['content'][0] ?? null) !== null) {
            $item['content_html'] = self::contentHtml($content);
        }
        if (($name = $properties['name'][0] ?? null) !== null) {
            $item['title'] = self::text($name, 'name');
        }
        if (($bookmark = $properties['bookmark-of'][0] ?? null) !== null) {
            $item['external_url'] = self::url($bookmark, 'bookmark-of');
        }
        $photos = array_map(static fn(mixed $p): string => self::url($p, 'photo'), $properties['photo'] ?? []);
        if ($photos !== []) {
            $item['image'] = $photos[0];
            $item['_athenana']['images'] = array_map(static fn(string $url): array => ['url' => $url], $photos);
        }
        if (isset($properties['category'])) {
            $item['tags'] = array_map(static fn(mixed $c): string => self::text($c, 'category'), $properties['category']);
        }
        if (($published = $properties['published'][0] ?? null) !== null) {
            $item['date_published'] = self::text($published, 'published');
        }
        $item['_athenana']['kind'] = isset($item['external_url']) ? 'link' : ($photos !== [] ? 'photo' : 'note');

        return $item;
    }

    /**
     * The h-entry properties of a served item, for q=source. `name` is left out when it is only the title derived
     * from the content (a note has no name of its own).
     *
     * @param array<string, mixed> $item
     * @return array<string, list<mixed>>
     */
    public function fromItem(array $item): array
    {
        $str = static fn(string $key): ?string => is_string($item[$key] ?? null) && $item[$key] !== '' ? $item[$key] : null;
        $athenana = is_array($item['_athenana'] ?? null) ? $item['_athenana'] : [];
        $kind = is_string($athenana['kind'] ?? null) ? $athenana['kind'] : 'note';
        $properties = [];
        if (($html = $str('content_html')) !== null) {
            $properties['content'] = [['html' => $html, 'value' => Text::plain($html)]];
        } elseif (($text = $str('content_text')) !== null) {
            $properties['content'] = [$text];
        }
        $title = $str('title');
        if ($title !== null && $title !== Text::deriveTitle($kind, $str('content_html') ?? (($t = $str('content_text')) === null ? null : htmlspecialchars($t)), $str('external_url'))) {
            $properties['name'] = [$title];
        }
        if (($external = $str('external_url')) !== null) {
            $properties['bookmark-of'] = [$external];
        }
        $photos = [];
        foreach (is_array($athenana['images'] ?? null) ? $athenana['images'] : [] as $image) {
            if (is_array($image) && is_string($image['url'] ?? null)) {
                $photos[] = $image['url'];
            }
        }
        if ($photos === [] && ($image = $str('image')) !== null) {
            $photos[] = $image;
        }
        if ($photos !== []) {
            $properties['photo'] = $photos;
        }
        $tags = array_values(array_filter(is_array($item['tags'] ?? null) ? $item['tags'] : [], 'is_string'));
        if ($tags !== []) {
            $properties['category'] = $tags;
        }
        if (($published = $str('date_published')) !== null) {
            $properties['published'] = [$published];
        }

        return $properties;
    }

    /** Escapes plain text into paragraphs: blank lines split paragraphs, single newlines become <br>. */
    public static function textToHtml(string $text): string
    {
        $html = '';
        foreach (preg_split('/\R\s*\R/u', trim($text)) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                $html .= '<p>' . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
            }
        }

        return $html;
    }

    private static function contentHtml(mixed $content): string
    {
        if (is_string($content)) {
            return self::textToHtml($content);
        }
        if (is_array($content) && is_string($content['html'] ?? null)) {
            return $content['html'];
        }
        if (is_array($content) && is_string($content['value'] ?? null)) {
            return self::textToHtml($content['value']);
        }
        throw new HttpException(400, 'content must be text or {"html": "…"}');
    }

    private static function text(mixed $value, string $property): string
    {
        if (!is_string($value)) {
            throw new HttpException(400, "{$property} must be text");
        }

        return $value;
    }

    /** A URL property: a string, or an object with a "value" (photo with alt) or a "url" (an h-cite). */
    private static function url(mixed $value, string $property): string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? ($value['properties']['url'][0] ?? null);
        }
        if (!is_string($value) || $value === '') {
            throw new HttpException(400, "{$property} must be a URL");
        }

        return $value;
    }
}
```

`modules/micropub/src/Destination.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/** Picks the blog a Micropub request writes to: mp-destination, else the token's blog, else the only native blog. */
final class Destination
{
    public function __construct(private readonly BlogRepository $blogs) {}

    /** @return list<Blog> the native blogs this token may write to (q=config's destinations) */
    public function choices(Token $token): array
    {
        return array_values(array_filter(
            $this->blogs->all(),
            static fn(Blog $blog): bool => !$blog->isMirror() && $token->allowsBlog($blog->id),
        ));
    }

    public function choose(?string $uid, Token $token): Blog
    {
        if ($uid !== null) {
            $blog = $this->blogs->find($uid);
            if ($blog === null || $blog->isMirror()) {
                throw new HttpException(400, "Unknown destination '{$uid}'");
            }
            if (!$token->allowsBlog($blog->id)) {
                throw TokenError::wrongBlog();
            }

            return $blog;
        }
        if ($token->blogId !== null) {
            $blog = $this->blogs->findById($token->blogId);
            if ($blog !== null && !$blog->isMirror()) {
                return $blog;
            }
        }
        $choices = $this->choices($token);
        if (count($choices) === 1) {
            return $choices[0];
        }
        throw new HttpException(400, 'Choose a blog with mp-destination (q=config lists them)');
    }
}
```

`modules/micropub/src/MicropubController.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/** POST /micropub. Every Micropub action maps onto PostWriter and needs the blog:write scope. */
final class MicropubController extends Controller
{
    public function __construct(
        private readonly PostWriter $writer,
        private readonly Mf2Mapper $mapper,
        private readonly Destination $destination,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}

    public function post(ServerRequestInterface $request): ResponseInterface
    {
        $input = MicropubInput::fromRequest($request);

        return match ($input->action) {
            null, 'create' => $this->create($input, self::token($request)),
            default => throw new HttpException(400, "Unknown action '{$input->action}'"),
        };
    }

    private function create(MicropubInput $input, Token $token): ResponseInterface
    {
        if ($input->type !== 'entry') {
            throw new HttpException(400, 'Only h-entry posts are supported');
        }
        $blog = $this->destination->choose($input->command('mp-destination'), $token);
        $post = $this->writer->create($blog, $this->mapper->toItem($input->properties));

        return new Response(201, ['Location' => $this->items->url($blog, $post, $this->appUrl())]);
    }

    private static function token(ServerRequestInterface $request): Token
    {
        $token = $request->getAttribute(Token::class);

        return $token instanceof Token ? $token : throw TokenError::missing();
    }

    private function appUrl(): string
    {
        return rtrim((string) $this->config->get('app.url', ''), '/');
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Micropub`
Expected: PASS (`MicropubCreateTest` 9 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (633 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Micropub`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/micropub tests/Module/Micropub
git commit -m "feat(micropub): add the micropub module with form and JSON create" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 10: Micropub update, delete and undelete

**Files:**
- Create: `modules/micropub/src/PostLocator.php`
- Modify: `modules/micropub/src/Mf2Mapper.php` (`FIELDS`, `patchFor()`), `modules/micropub/src/MicropubController.php`, `modules/blog/src/Post/PostRepository.php` (`findByItemId()`)
- Test: `tests/Module/Micropub/MicropubUpdateTest.php`

**Interfaces:**
- **Consumes:** `PostWriter::patch()`, `delete()`, `undelete()` (Task 5: the 409s for a deleted post or a mirror become Micropub 400s); `ApiAccess::allow()` (Task 6); `Mf2Mapper::fromItem()`, `toItem()`, `MicropubInput` (Task 9); `ItemSerializer::item()`; `BlogRepository::all()`.
- **Produces:**
  - `PostRepository::findByItemId(int $blogId, string $itemId): ?Post` (any status).
  - `PostLocator::find(string $url): ?Post`: `{app.url}/s/{id}` (or `.json`), then each blog's `post_url_pattern`, then the item id.
  - `Mf2Mapper::FIELDS` (property => item fields) and `patchFor(array $properties, list<string> $touched): array` (a merge patch that sets or clears exactly the touched properties' fields; touching `bookmark-of` or `photo` re-derives `_athenana.kind`).
  - `MicropubController`: its constructor becomes `(PostWriter, Mf2Mapper, Destination, PostLocator, BlogRepository, ItemSerializer, Config)`.
  - Actions: `action=update` (JSON only: `replace`, `add`, and `delete` as a list of properties or an object of values) sets `date_modified` to now and answers 204. `action=delete` and `action=undelete` (form or JSON) answer 204.
  - Errors (400): `No post at {url}`, `action={action} needs a url`, `Send updates as JSON`, `{op}.{property} must be a list of values`, `{op} must be an object of properties`.

- [ ] **Step 1: Write the failing test**

`tests/Module/Micropub/MicropubUpdateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;

final class MicropubUpdateTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    /** @param array<string, mixed> $extra */
    private function note(string $content = 'First draft', array $extra = []): string
    {
        return $this->form(['content' => $content, 'category' => ['a'], ...$extra])->getHeaderLine('Location');
    }

    private function post(string $url): Post
    {
        $post = $this->app()->container()->get(PostRepository::class)->find(substr($url, -26));
        self::assertNotNull($post);

        return $post;
    }

    /** @param array<string, mixed> $operations */
    private function update(string $url, array $operations, ?string $token = null): ResponseInterface
    {
        return $this->postJson(['action' => 'update', 'url' => $url, ...$operations], $token);
    }

    public function testReplaceRewritesOnlyThatProperty(): void
    {
        $url = $this->note();
        $response = $this->update($url, ['replace' => ['content' => ['Second draft']]]);
        self::assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $post = $this->post($url);
        self::assertSame(['<p>Second draft</p>', 'Second draft'], [$post->contentHtml, $post->title]);
        self::assertSame([['name' => 'a', 'slug' => 'a']], $post->tags);
        self::assertNotNull($post->dateModified);
    }

    public function testAddAndDeleteValuesAndProperties(): void
    {
        $url = $this->note();
        $this->update($url, ['add' => ['category' => ['b', 'c']]]);
        self::assertSame(['a', 'b', 'c'], array_column($this->post($url)->tags, 'name'));
        $this->update($url, ['delete' => ['category' => ['a', 'c']]]);
        self::assertSame(['b'], array_column($this->post($url)->tags, 'name'));
        $this->update($url, ['delete' => ['category']]);
        self::assertSame([], $this->post($url)->tags);
    }

    public function testPhotosAndBookmarksChangeTheKind(): void
    {
        $url = $this->note();
        $this->update($url, ['add' => ['photo' => ['https://img.test/p.jpg']]]);
        $post = $this->post($url);
        self::assertSame(['photo', 'https://img.test/p.jpg'], [$post->kind, $post->image]);
        $this->update($url, ['replace' => ['bookmark-of' => ['https://example.com/']], 'delete' => ['photo']]);
        $post = $this->post($url);
        self::assertSame(['link', 'https://example.com/', null], [$post->kind, $post->externalUrl, $post->image]);
        self::assertArrayNotHasKey('images', $post->doc['_athenana'] ?? []);
        $this->update($url, ['delete' => ['bookmark-of']]);
        self::assertSame('note', $this->post($url)->kind);
    }

    public function testANameCanBeSetAndRemoved(): void
    {
        $url = $this->note('Body text');
        $this->update($url, ['replace' => ['name' => ['A title']]]);
        self::assertSame('A title', $this->post($url)->title);
        $this->update($url, ['delete' => ['name']]);
        self::assertSame('Body text', $this->post($url)->title);
    }

    public function testDeleteAndUndelete(): void
    {
        $url = $this->note('Gone soon');
        self::assertSame(204, $this->form(['action' => 'delete', 'url' => $url])->getStatusCode());
        self::assertSame('deleted', $this->post($url)->status);
        self::assertSame(404, $this->app()->handle(new ServerRequest('GET', '/s/' . substr($url, -26) . '.json'))->getStatusCode());
        self::assertSame(204, $this->postJson(['action' => 'undelete', 'url' => $url])->getStatusCode());
        self::assertSame('published', $this->post($url)->status);
    }

    public function testUpdatingADeletedPostIs400(): void
    {
        $url = $this->note('Deleted');
        $this->form(['action' => 'delete', 'url' => $url]);
        self::assertMicropubError($this->update($url, ['replace' => ['content' => ['Revived?']]]), 400, 'invalid_request', 'The post is deleted; undelete it first');
        self::assertSame('<p>Deleted</p>', $this->post($url)->contentHtml);
    }

    public function testBadUpdatesAre400(): void
    {
        $url = $this->note();
        self::assertMicropubError($this->update('http://xar.test/s/' . str_repeat('0', 26), ['replace' => ['content' => ['x']]]), 400, 'invalid_request', 'No post at http://xar.test/s/' . str_repeat('0', 26));
        self::assertMicropubError($this->postJson(['action' => 'update', 'replace' => ['content' => ['x']]]), 400, 'invalid_request', 'action=update needs a url');
        self::assertMicropubError($this->form(['action' => 'update', 'url' => $url, 'content' => 'x']), 400, 'invalid_request', 'Send updates as JSON');
        self::assertMicropubError($this->update($url, ['replace' => ['content' => 'x']]), 400, 'invalid_request', 'replace.content must be a list of values');
        self::assertMicropubError($this->update($url, ['replace' => [['content']]]), 400, 'invalid_request', 'replace must be an object of properties');
        self::assertMicropubError($this->update($url, ['delete' => ['published']]), 400, 'invalid_request', 'date_published: Cannot be removed');
    }

    public function testPostUrlPatternsAndItemIdsLocatePosts(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'site', 'post_url_pattern' => 'https://www.example.org/post.html?id={id}']);
        $url = $this->form(['content' => 'Patterned', 'mp-destination' => 'site'])->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^https://www\.example\.org/post\.html\?id=[0-9a-z]{26}$#', $url);
        self::assertSame(204, $this->update($url, ['replace' => ['content' => ['Found by pattern']]])->getStatusCode());
        $post = $this->post($url);
        self::assertSame('<p>Found by pattern</p>', $post->contentHtml);
        self::assertSame(204, $this->update($post->itemId, ['replace' => ['content' => ['Found by item id']]])->getStatusCode());
        self::assertSame('<p>Found by item id</p>', $this->post($url)->contentHtml);
    }

    public function testAPinnedTokenCannotTouchAnotherBlog(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        $url = $this->form(['content' => 'Theirs', 'mp-destination' => 'other'])->getHeaderLine('Location');
        $pinned = $this->token('blog:write', ['--blog=notes']);
        self::assertMicropubError($this->update($url, ['replace' => ['content' => ['Mine now']]], $pinned), 403, 'forbidden');
        self::assertMicropubError($this->form(['action' => 'delete', 'url' => $url], $pinned), 403, 'forbidden');
        self::assertSame(['<p>Theirs</p>', 'published'], [$this->post($url)->contentHtml, $this->post($url)->status]);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Micropub/MicropubUpdateTest.php`
Expected: FAIL: `Failed asserting that 400 is identical to 204` (`Unknown action 'update'`), and unchanged posts.

- [ ] **Step 3: Implement**

In `modules/blog/src/Post/PostRepository.php`, replace:

```php
        return $out;
    }

    /** The blog's post with this source item id, if it is live: published and not scheduled. */
    public function findLiveByItemId(int $blogId, string $itemId, DateTimeImmutable $now): ?Post
    {
```

with:

```php
        return $out;
    }

    /** The blog's post with this item id, whatever its status. */
    public function findByItemId(int $blogId, string $itemId): ?Post
    {
        $row = $this->db->select('posts')->where('blog_id', '=', $blogId)->where('item_id', '=', $itemId)->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }

    /** The blog's post with this source item id, if it is live: published and not scheduled. */
    public function findLiveByItemId(int $blogId, string $itemId, DateTimeImmutable $now): ?Post
    {
```

`modules/micropub/src/PostLocator.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use Xaraya\Kernel\Config\Config;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;

/**
 * Finds the post a Micropub `url` names: Phoenix's own {app.url}/s/{id}[.json], a blog's post_url_pattern, or the
 * item id itself (a post mirrored before a cut-over keeps its source id).
 */
final class PostLocator
{
    private const ULID = '([0-9a-hjkmnp-tv-z]{26})';

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly Config $config,
    ) {}

    public function find(string $url): ?Post
    {
        $app = preg_quote(rtrim((string) $this->config->get('app.url', ''), '/'), '#');
        if (preg_match('#^' . $app . '/s/' . self::ULID . '(?:\.json)?$#D', $url, $m) === 1) {
            return $this->posts->find($m[1]);
        }
        foreach ($this->blogs->all() as $blog) {
            if ($blog->postUrlPattern !== null) {
                $pattern = str_replace(preg_quote('{id}', '#'), self::ULID, preg_quote($blog->postUrlPattern, '#'));
                if (preg_match('#^' . $pattern . '$#D', $url, $m) === 1) {
                    $post = $this->posts->find($m[1]);
                    if ($post !== null && $post->blogId === $blog->id) {
                        return $post;
                    }
                }
            }
            $post = $this->posts->findByItemId($blog->id, $url);
            if ($post !== null) {
                return $post;
            }
        }

        return null;
    }
}
```

In `modules/micropub/src/Mf2Mapper.php` (1 of 2), replace:

```php
{
    public const PROPERTIES = ['content', 'name', 'bookmark-of', 'photo', 'category', 'published'];

    /**
     * @param array<string, list<mixed>> $properties
     * @return array<string, mixed> item fields; `_athenana` holds kind (always) and images (with photos)
```

with:

```php
{
    public const PROPERTIES = ['content', 'name', 'bookmark-of', 'photo', 'category', 'published'];

    /** The item fields each property writes ("_athenana.images" is nested), so an update clears exactly those. */
    public const FIELDS = [
        'content' => ['content_html'],
        'name' => ['title'],
        'bookmark-of' => ['external_url'],
        'photo' => ['image', '_athenana.images'],
        'category' => ['tags'],
        'published' => ['date_published'],
    ];

    /**
     * @param array<string, list<mixed>> $properties
     * @return array<string, mixed> item fields; `_athenana` holds kind (always) and images (with photos)
```

In `modules/micropub/src/Mf2Mapper.php` (2 of 2), replace:

```php
        return $properties;
    }

    /** Escapes plain text into paragraphs: blank lines split paragraphs, single newlines become <br>. */
    public static function textToHtml(string $text): string
    {
```

with:

```php
        return $properties;
    }

    /**
     * A JSON Merge Patch for PostWriter::patch() that sets every field of the $touched properties from $properties
     * (the whole post after the update), or removes it. Touching bookmark-of or photo re-derives the kind.
     *
     * @param array<string, list<mixed>> $properties
     * @param list<string> $touched
     * @return array<string, mixed>
     */
    public function patchFor(array $properties, array $touched): array
    {
        $touched = array_values(array_intersect(self::PROPERTIES, $touched));
        $item = $this->toItem(array_intersect_key($properties, array_flip($touched)));
        $patch = [];
        foreach ($touched as $property) {
            foreach (self::FIELDS[$property] as $field) {
                if ($field === '_athenana.images') {
                    $patch['_athenana']['images'] = $item['_athenana']['images'] ?? null;
                } else {
                    $patch[$field] = $item[$field] ?? null;
                }
            }
        }
        if (in_array('bookmark-of', $touched, true) || in_array('photo', $touched, true)) {
            $patch['_athenana']['kind'] = isset($properties['bookmark-of'][0]) ? 'link' : (isset($properties['photo'][0]) ? 'photo' : 'note');
        }

        return $patch;
    }

    /** Escapes plain text into paragraphs: blank lines split paragraphs, single newlines become <br>. */
    public static function textToHtml(string $text): string
    {
```

In `modules/micropub/src/MicropubController.php` (1 of 4), replace:

```php
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;
```

with:

```php
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Http\Api\ApiAccess;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Write\PostWriter;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;
```

In `modules/micropub/src/MicropubController.php` (2 of 4), replace:

```php
        private readonly PostWriter $writer,
        private readonly Mf2Mapper $mapper,
        private readonly Destination $destination,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}
```

with:

```php
        private readonly PostWriter $writer,
        private readonly Mf2Mapper $mapper,
        private readonly Destination $destination,
        private readonly PostLocator $locator,
        private readonly BlogRepository $blogs,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}
```

In `modules/micropub/src/MicropubController.php` (3 of 4), replace:

```php

        return match ($input->action) {
            null, 'create' => $this->create($input, self::token($request)),
            default => throw new HttpException(400, "Unknown action '{$input->action}'"),
        };
    }
```

with:

```php

        return match ($input->action) {
            null, 'create' => $this->create($input, self::token($request)),
            'update' => $this->update($request, $input),
            'delete' => $this->delete($request, $input),
            'undelete' => $this->undelete($request, $input),
            default => throw new HttpException(400, "Unknown action '{$input->action}'"),
        };
    }
```

In `modules/micropub/src/MicropubController.php` (4 of 4), replace:

```php
        return new Response(201, ['Location' => $this->items->url($blog, $post, $this->appUrl())]);
    }

    private static function token(ServerRequestInterface $request): Token
    {
        $token = $request->getAttribute(Token::class);
```

with:

```php
        return new Response(201, ['Location' => $this->items->url($blog, $post, $this->appUrl())]);
    }

    /**
     * action=update (JSON only): replace, add and delete operate on the post's h-entry properties (as q=source
     * shows them); only the touched properties are written back, and date_modified is set to now.
     */
    private function update(ServerRequestInterface $request, MicropubInput $input): ResponseInterface
    {
        if ($input->json === []) {
            throw new HttpException(400, 'Send updates as JSON');
        }
        [$blog, $post] = $this->locate($request, $input);
        $properties = $this->mapper->fromItem($this->items->item($blog, $post, $this->appUrl()));
        $touched = [];
        foreach (['replace', 'add'] as $operation) {
            foreach (self::operation($input->json, $operation) as $property => $values) {
                if (!is_array($values) || !array_is_list($values)) {
                    throw new HttpException(400, "{$operation}.{$property} must be a list of values");
                }
                $properties[$property] = $operation === 'replace' ? $values : [...($properties[$property] ?? []), ...$values];
                $touched[] = $property;
            }
        }
        $delete = $input->json['delete'] ?? [];
        if (!is_array($delete)) {
            throw new HttpException(400, 'delete must be a list of properties or an object of values');
        }
        foreach ($delete as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value)) {
                    throw new HttpException(400, 'delete must list property names');
                }
                unset($properties[$value]);
                $touched[] = $value;
            } elseif (is_array($value)) {
                $properties[$key] = array_values(array_filter($properties[$key] ?? [], static fn(mixed $v): bool => !in_array($v, $value, true)));
                $touched[] = $key;
            } else {
                throw new HttpException(400, "delete.{$key} must be a list of values");
            }
        }
        $patch = $this->mapper->patchFor($properties, $touched);
        $patch['date_modified'] = gmdate(DATE_ATOM);
        $this->writer->patch($post, $patch);

        return new Response(204);
    }

    private function delete(ServerRequestInterface $request, MicropubInput $input): ResponseInterface
    {
        [, $post] = $this->locate($request, $input);
        $this->writer->delete($post);

        return new Response(204);
    }

    private function undelete(ServerRequestInterface $request, MicropubInput $input): ResponseInterface
    {
        [, $post] = $this->locate($request, $input);
        $this->writer->undelete($post);

        return new Response(204);
    }

    /** @return array{0: Blog, 1: Post} */
    private function locate(ServerRequestInterface $request, MicropubInput $input): array
    {
        if ($input->url === null) {
            throw new HttpException(400, "action={$input->action} needs a url");
        }
        $post = $this->locator->find($input->url) ?? throw new HttpException(400, "No post at {$input->url}");
        $blog = $this->blogs->findById($post->blogId) ?? throw new HttpException(400, "No post at {$input->url}");
        ApiAccess::allow($request, $blog);

        return [$blog, $post];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<array-key, mixed>
     */
    private static function operation(array $json, string $name): array
    {
        $value = $json[$name] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new HttpException(400, "{$name} must be an object of properties");
        }

        return $value;
    }

    private static function token(ServerRequestInterface $request): Token
    {
        $token = $request->getAttribute(Token::class);
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Micropub`
Expected: PASS (`MicropubUpdateTest` 9 tests, `MicropubCreateTest` 9).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (642 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Micropub`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/micropub modules/blog/src/Post/PostRepository.php tests/Module/Micropub/MicropubUpdateTest.php
git commit -m "feat(micropub): support update, delete and undelete" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 11: Micropub queries and the media endpoint

**Files:**
- Create: `modules/micropub/src/MicropubMediaController.php`
- Modify: `modules/micropub/src/MicropubController.php` (`query()`, `source()`, a shared `find()`), `modules/micropub/src/Routes.php`
- Test: `tests/Module/Micropub/MicropubQueryTest.php`, `tests/Module/Micropub/MicropubMediaTest.php`

**Interfaces:**
- **Consumes:** `Destination::choices()`, `choose()`, `Mf2Mapper::fromItem()` (Task 9); `PostLocator` (Task 10); `MediaUploader::upload()`, `url()` (Task 7); `UrlGenerator::generate('micropub.media', [], true)`; `MicropubTestCase::query()`.
- **Produces:**
  - Route `micropub.query` `GET /micropub` (`micropubErrors`, `micropubToken`, `token:blog:write`) → `MicropubController::query()`. All answers have `Cache-Control: no-store`:
    - `q=config` → `{media-endpoint, destination: [{uid, name}], syndicate-to: []}`.
    - `q=source&url=…[&properties[]=…]` → `{type: ["h-entry"], properties}`, or `{properties}` when properties are asked.
    - `q=syndicate-to` → `{syndicate-to: []}`.
    - Errors (400): `q=source needs a url`, `No post at {url}`, `Unknown query '{q}'`, `Send q=config, q=source or q=syndicate-to`.
  - Route `micropub.media` `POST /micropub/media` (`csrf:off`, `micropubErrors`, `micropubToken`, `token:media:write`) → `MicropubMediaController::upload()`: 201 with an absolute `Location`; the blog comes from form `mp-destination`, else the token's blog, else the only native blog.
  - The `MicropubController` constructor gains `UrlGenerator $urls` (before `Config`).

- [ ] **Step 1: Write the failing tests**

`tests/Module/Micropub/MicropubQueryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;

final class MicropubQueryTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    public function testConfigListsTheNativeBlogsTheTokenMayUse(): void
    {
        $this->nativeBlog($this->app(), ['handle' => 'other', 'title' => 'Other']);
        $this->blogs($this->app())->create(['handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/f.json', 'source_format' => 'athena'], $this->now());
        $response = $this->query(['q' => 'config']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([
            'media-endpoint' => 'http://xar.test/micropub/media',
            'destination' => [['uid' => 'notes', 'name' => 'Notes'], ['uid' => 'other', 'name' => 'Other']],
            'syndicate-to' => [],
        ], self::body($response));
        self::assertSame([['uid' => 'other', 'name' => 'Other']], self::body($this->query(['q' => 'config'], $this->token('blog:write', ['--blog=other'])))['destination']);
    }

    public function testSyndicateToIsEmpty(): void
    {
        self::assertSame(['syndicate-to' => []], self::body($this->query(['q' => 'syndicate-to'])));
    }

    public function testSourceReturnsTheEntryOrTheAskedProperties(): void
    {
        $note = $this->form(['content' => 'A note', 'category' => ['x'], 'published' => '2026-10-01T10:00:00Z'])->getHeaderLine('Location');
        self::assertSame(['type' => ['h-entry'], 'properties' => [
            'content' => [['html' => '<p>A note</p>', 'value' => 'A note']],
            'category' => ['x'],
            'published' => ['2026-10-01T10:00:00+00:00'],
        ]], self::body($this->query(['q' => 'source', 'url' => $note])));

        $link = $this->form(['name' => 'Named', 'bookmark-of' => 'https://example.com/', 'photo' => ['https://img.test/a.jpg', 'https://img.test/b.jpg']])->getHeaderLine('Location');
        $properties = self::body($this->query(['q' => 'source', 'url' => $link]))['properties'];
        self::assertSame(['Named'], $properties['name']);
        self::assertSame(['https://example.com/'], $properties['bookmark-of']);
        self::assertSame(['https://img.test/a.jpg', 'https://img.test/b.jpg'], $properties['photo']);

        $response = $this->app()->handle((new ServerRequest('GET', '/micropub?q=source&url=' . rawurlencode($link) . '&properties[]=name&properties[]=photo'))->withHeader('Authorization', 'Bearer ' . $this->token));
        self::assertSame(['properties' => ['name' => ['Named'], 'photo' => ['https://img.test/a.jpg', 'https://img.test/b.jpg']]], self::body($response));
    }

    public function testBadQueriesAre400AndATokenIsRequired(): void
    {
        self::assertMicropubError($this->query(['q' => 'source']), 400, 'invalid_request', 'q=source needs a url');
        self::assertMicropubError($this->query(['q' => 'source', 'url' => 'https://nowhere.test/']), 400, 'invalid_request', 'No post at https://nowhere.test/');
        self::assertMicropubError($this->query(['q' => 'channels']), 400, 'invalid_request', "Unknown query 'channels'");
        self::assertMicropubError($this->query([]), 400, 'invalid_request', 'Send q=config, q=source or q=syndicate-to');
        self::assertMicropubError($this->app()->handle(new ServerRequest('GET', '/micropub?q=config')), 401, 'unauthorized', 'A bearer token is required');
        self::assertMicropubError($this->app()->handle(new ServerRequest('GET', '/micropub?q=config&access_token=' . $this->token)), 401, 'unauthorized');
    }
}
```

`tests/Module/Micropub/MicropubMediaTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;

final class MicropubMediaTest extends MicropubTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMicropub();
    }

    /**
     * @param array<string, UploadedFile> $files
     * @param array<string, string> $form
     */
    private function upload(array $files, array $form = [], ?string $token = null): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/micropub/media'))
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withUploadedFiles($files)
            ->withParsedBody($form);
        if ($token !== '') {
            $request = $request->withHeader('Authorization', 'Bearer ' . ($token ?? $this->token));
        }

        return $this->app()->handle($request);
    }

    private function file(string $bytes, string $name = 'photo.png'): UploadedFile
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, strlen($bytes), UPLOAD_ERR_OK, $name, 'image/png');
    }

    private function png(): UploadedFile
    {
        return $this->file((string) base64_decode(MediaStoreTest::PNG, true));
    }

    public function testStoresTheFileAndPointsAtIt(): void
    {
        $response = $this->upload(['file' => $this->png()]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $location = $response->getHeaderLine('Location');
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-hjkmnp-tv-z]{26}\.png$#', $location);
        self::assertCount(1, glob($this->tmp . '/uploads/notes/*.png') ?: []);
        self::assertSame(200, $this->app()->handle(new ServerRequest('GET', (string) parse_url($location, PHP_URL_PATH)))->getStatusCode());
    }

    public function testTheTokenMayBeAMultipartField(): void
    {
        self::assertSame(201, $this->upload(['file' => $this->png()], ['access_token' => $this->token], '')->getStatusCode());
    }

    public function testErrorsUseMicropubCodes(): void
    {
        self::assertMicropubError($this->upload(['file' => $this->file("plain text\n", 'a.jpg')]), 400, 'invalid_request');
        self::assertMicropubError($this->upload([]), 400, 'invalid_request', 'Upload one file in the multipart field "file"');
        self::assertMicropubError($this->upload(['file' => $this->png()], [], $this->token('blog:write')), 403, 'insufficient_scope', 'The token lacks the media:write scope');
        self::assertMicropubError($this->upload(['file' => $this->png()], ['mp-destination' => 'nope']), 400, 'invalid_request', "Unknown destination 'nope'");
        self::assertSame([], glob($this->tmp . '/uploads/notes/*') ?: []);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Micropub/MicropubQueryTest.php tests/Module/Micropub/MicropubMediaTest.php`
Expected: FAIL: `Failed asserting that 405 is identical to 200` (GET /micropub has no route), `JsonException: Syntax error` (an HTML 405 page), `Failed asserting that 404 is identical to 201` (no media route).

- [ ] **Step 3: Implement**

In `modules/micropub/src/MicropubController.php` (1 of 4), replace:

```php
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
```

with:

```php
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Feed\ItemSerializer;
```

In `modules/micropub/src/MicropubController.php` (2 of 4), replace:

```php
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/** POST /micropub. Every Micropub action maps onto PostWriter and needs the blog:write scope. */
final class MicropubController extends Controller
{
    public function __construct(
```

with:

```php
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/** POST /micropub and its GET queries. Every action maps onto PostWriter; all of them need the blog:write scope. */
final class MicropubController extends Controller
{
    public function __construct(
```

In `modules/micropub/src/MicropubController.php` (3 of 4), replace:

```php
        private readonly PostLocator $locator,
        private readonly BlogRepository $blogs,
        private readonly ItemSerializer $items,
        private readonly Config $config,
    ) {}

    public function post(ServerRequestInterface $request): ResponseInterface
    {
        $input = MicropubInput::fromRequest($request);
```

with:

```php
        private readonly PostLocator $locator,
        private readonly BlogRepository $blogs,
        private readonly ItemSerializer $items,
        private readonly UrlGenerator $urls,
        private readonly Config $config,
    ) {}

    /** GET /micropub?q=config|source|syndicate-to */
    public function query(ServerRequestInterface $request): ResponseInterface
    {
        $token = self::token($request);
        parse_str($request->getUri()->getQuery(), $query);
        $q = $query['q'] ?? null;
        $body = match ($q) {
            'config' => [
                'media-endpoint' => $this->urls->generate('micropub.media', [], true),
                'destination' => array_map(static fn(Blog $blog): array => ['uid' => $blog->handle, 'name' => $blog->title], $this->destination->choices($token)),
                'syndicate-to' => [],
            ],
            'source' => $this->source($request, $query),
            'syndicate-to' => ['syndicate-to' => []],
            default => throw new HttpException(400, is_string($q) ? "Unknown query '{$q}'" : 'Send q=config, q=source or q=syndicate-to'),
        };

        return $this->json($body)->withHeader('Cache-Control', 'no-store');
    }

    public function post(ServerRequestInterface $request): ResponseInterface
    {
        $input = MicropubInput::fromRequest($request);
```

In `modules/micropub/src/MicropubController.php` (4 of 4), replace:

```php
        return new Response(204);
    }

    /** @return array{0: Blog, 1: Post} */
    private function locate(ServerRequestInterface $request, MicropubInput $input): array
    {
        if ($input->url === null) {
            throw new HttpException(400, "action={$input->action} needs a url");
        }
        $post = $this->locator->find($input->url) ?? throw new HttpException(400, "No post at {$input->url}");
        $blog = $this->blogs->findById($post->blogId) ?? throw new HttpException(400, "No post at {$input->url}");
        ApiAccess::allow($request, $blog);

        return [$blog, $post];
```

with:

```php
        return new Response(204);
    }

    /**
     * q=source: the post's h-entry, or only the requested properties[] (then without "type").
     *
     * @param array<array-key, mixed> $query
     * @return array<string, mixed>
     */
    private function source(ServerRequestInterface $request, array $query): array
    {
        $url = $query['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new HttpException(400, 'q=source needs a url');
        }
        [$blog, $post] = $this->find($request, $url);
        $properties = $this->mapper->fromItem($this->items->item($blog, $post, $this->appUrl()));
        $wanted = $query['properties'] ?? null;
        if ($wanted === null) {
            return ['type' => ['h-entry'], 'properties' => $properties];
        }
        $wanted = array_filter(is_array($wanted) ? $wanted : [$wanted], 'is_string');

        return ['properties' => array_intersect_key($properties, array_flip($wanted))];
    }

    /** @return array{0: Blog, 1: Post} */
    private function locate(ServerRequestInterface $request, MicropubInput $input): array
    {
        if ($input->url === null) {
            throw new HttpException(400, "action={$input->action} needs a url");
        }

        return $this->find($request, $input->url);
    }

    /** @return array{0: Blog, 1: Post} */
    private function find(ServerRequestInterface $request, string $url): array
    {
        $post = $this->locator->find($url) ?? throw new HttpException(400, "No post at {$url}");
        $blog = $this->blogs->findById($post->blogId) ?? throw new HttpException(400, "No post at {$url}");
        ApiAccess::allow($request, $blog);

        return [$blog, $post];
```

`modules/micropub/src/MicropubMediaController.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Micropub;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Module\Blog\Media\MediaUploader;
use Xaraya\Module\Tokens\Token;
use Xaraya\Module\Tokens\TokenError;

/**
 * POST /micropub/media (scope media:write): the multipart field `file` is stored for the blog chosen as for a post
 * (mp-destination, the token's blog, or the only native blog); 201 with Location = the absolute media URL.
 */
final class MicropubMediaController extends Controller
{
    public function __construct(private readonly MediaUploader $uploader, private readonly Destination $destination) {}

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getAttribute(Token::class);
        if (!$token instanceof Token) {
            throw TokenError::missing();
        }
        $form = $request->getParsedBody();
        $uid = is_array($form) && is_string($form['mp-destination'] ?? null) && $form['mp-destination'] !== '' ? $form['mp-destination'] : null;
        $blog = $this->destination->choose($uid, $token);
        $row = $this->uploader->upload($blog, $request, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return new Response(201, ['Location' => $this->uploader->url($row), 'Cache-Control' => 'no-store']);
    }
}
```

In `modules/micropub/src/Routes.php`, replace:

```php
    {
        $routes->post('/micropub', [MicropubController::class, 'post'], 'micropub')
            ->middleware('csrf:off', 'micropubErrors', 'micropubToken', 'token:blog:write');
    }
}
```

with:

```php
    {
        $routes->post('/micropub', [MicropubController::class, 'post'], 'micropub')
            ->middleware('csrf:off', 'micropubErrors', 'micropubToken', 'token:blog:write');
        $routes->get('/micropub', [MicropubController::class, 'query'], 'micropub.query')
            ->middleware('micropubErrors', 'micropubToken', 'token:blog:write');
        $routes->post('/micropub/media', [MicropubMediaController::class, 'upload'], 'micropub.media')
            ->middleware('csrf:off', 'micropubErrors', 'micropubToken', 'token:media:write');
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Micropub`
Expected: PASS (`MicropubQueryTest` 4 tests, `MicropubMediaTest` 3, and Tasks 9-10's tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (649 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Micropub`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add modules/micropub tests/Module/Micropub
git commit -m "feat(micropub): answer q=config, q=source and q=syndicate-to; add the media endpoint" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 12: The Plan B definition of done, end to end (including the `blog:mode` cut-over)

**Files:**
- Test: `tests/Module/Micropub/PlanBAcceptanceTest.php`

**Interfaces:**
- **Consumes:** everything above, through `xar` and `App::handle` only; `FeedFactory::items/pages/fetcher()` and `HttpFetcher` (Plan A) to sync a mirror; `MicropubTestCase`.
- **Produces:** one test per §8 Plan B item:
  - `testDone1APostedItemAppearsInTheFeed`: native blog plus `token:create`, then POST, then `feed.json`.
  - `testDone2AMicropubClientWritesNotesLinksAndPhotos`: a note, a link and a photo via the media endpoint, each updated and then deleted.
  - `testDone3TheBlogModeCutOverKeepsEveryIdAndUrl`: sync a mirror with `--post-url`, switch it to native, write through the API and Micropub; every mirrored `id` and `url` is unchanged, and an untouched `/s/{id}.json` serves the same bytes.

This task adds no production code: the test pins behaviour that Tasks 1-11 built. If it fails, fix the task that owns the behaviour.

- [ ] **Step 1: Write the test**

`tests/Module/Micropub/PlanBAcceptanceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Micropub;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Tests\Module\Blog\Media\MediaStoreTest;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

/** Spec §8, Plan B definition of done, end to end through the CLI and App::handle. */
final class PlanBAcceptanceTest extends MicropubTestCase
{
    /** @param array<string, mixed>|null $body */
    private function api(string $method, string $uri, ?array $body = null): ResponseInterface
    {
        $request = (new ServerRequest($method, $uri))->withHeader('Authorization', 'Bearer ' . $this->token);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody(Stream::create(json_encode($body, JSON_THROW_ON_ERROR)));
        }

        return $this->app()->handle($request);
    }

    /** @return list<array<string, mixed>> */
    private function feedItems(string $handle): array
    {
        $feed = self::body($this->app()->handle(new ServerRequest('GET', "/blog/{$handle}/feed.json")));

        return $feed['items'] ?? [];
    }

    public function testDone1APostedItemAppearsInTheFeed(): void
    {
        $this->enableTokens();
        $this->xar(['module:enable', 'micropub']);
        [$code, , $err] = $this->xar(['blog:create', 'notes', '--mode=native', '--title=Notes']);
        self::assertSame(0, $code, $err);
        $this->token = $this->token('blog:write', ['--blog=notes']);

        $response = $this->api('POST', '/api/blogs/notes/posts', ['title' => 'Done one', 'content_html' => '<p>It works.</p>']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame([['Done one', '<p>It works.</p>']], array_map(static fn(array $i): array => [$i['title'], $i['content_html']], $this->feedItems('notes')));
    }

    public function testDone2AMicropubClientWritesNotesLinksAndPhotos(): void
    {
        $this->enableMicropub();
        $note = $this->form(['h' => 'entry', 'content' => 'A note'])->getHeaderLine('Location');
        $link = $this->postJson(['type' => ['h-entry'], 'properties' => ['bookmark-of' => ['https://example.com/'], 'name' => ['Example']]])->getHeaderLine('Location');

        $path = $this->tmp . '/p.png';
        file_put_contents($path, (string) base64_decode(MediaStoreTest::PNG, true));
        $media = $this->app()->handle((new ServerRequest('POST', '/micropub/media'))
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withHeader('Content-Type', 'multipart/form-data; boundary=x')
            ->withUploadedFiles(['file' => new UploadedFile($path, (int) filesize($path), UPLOAD_ERR_OK, 'p.png', 'image/png')]));
        self::assertSame(201, $media->getStatusCode());
        $photo = $this->form(['photo' => $media->getHeaderLine('Location'), 'content' => 'Sunset'])->getHeaderLine('Location');

        $kinds = array_column($this->feedItems('notes'), '_athenana', 'url');
        self::assertSame(['note', 'link', 'photo'], [$kinds[$note]['kind'], $kinds[$link]['kind'], $kinds[$photo]['kind']]);
        self::assertSame($media->getHeaderLine('Location'), array_column($this->feedItems('notes'), 'image', 'url')[$photo]);

        foreach ([$note, $link, $photo] as $url) {
            self::assertSame(204, $this->postJson(['action' => 'update', 'url' => $url, 'replace' => ['content' => ['Edited']]])->getStatusCode());
        }
        self::assertSame(['<p>Edited</p>'], array_values(array_unique(array_column($this->feedItems('notes'), 'content_html'))));

        foreach ([$note, $link, $photo] as $url) {
            self::assertSame(204, $this->form(['action' => 'delete', 'url' => $url])->getStatusCode());
        }
        self::assertSame([], $this->feedItems('notes'));
    }

    public function testDone3TheBlogModeCutOverKeepsEveryIdAndUrl(): void
    {
        $this->enableMicropub();
        [$code, , $err] = $this->xar(['blog:create', 'src', '--mode=mirror', '--format=athena', '--source=https://src.test/blog/src/feed.json', '--post-url=https://www.example.org/post.html?id={id}']);
        self::assertSame(0, $code, $err);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->xar(['blog:sync', 'src', '--full'], $app);
        $before = array_map(static fn(array $i): array => [$i['id'], $i['url']], $this->feedItems('src'));
        self::assertCount(3, $before);
        $single = (string) $this->app()->handle(new ServerRequest('GET', '/s/' . substr((string) $before[1][1], -26) . '.json'))->getBody();

        self::assertSame(409, $this->api('POST', '/api/blogs/src/posts', ['title' => 'Too early'])->getStatusCode(), 'a mirror refuses writes');
        [$code] = $this->xar(['blog:mode', 'src', 'native']);
        self::assertSame(0, $code);

        $created = $this->api('POST', '/api/blogs/src/posts', ['title' => 'First native post', 'date_published' => '2026-10-02T12:00:00Z']);
        self::assertSame(201, $created->getStatusCode());
        $newId = substr((string) self::body($created)['id'], -26);
        $oldId = substr((string) $before[0][1], -26);
        self::assertSame(200, $this->api('PATCH', "/api/posts/{$oldId}", ['title' => 'Edited after cut-over'])->getStatusCode());
        self::assertSame(204, $this->postJson(['action' => 'update', 'url' => $before[2][1], 'replace' => ['name' => ['Edited by Micropub']]])->getStatusCode());

        $after = array_map(static fn(array $i): array => [$i['id'], $i['url']], $this->feedItems('src'));
        self::assertSame(["http://xar.test/s/{$newId}", "https://www.example.org/post.html?id={$newId}"], $after[0]);
        self::assertSame($before, array_slice($after, 1), 'mirrored ids and urls never change');
        self::assertSame(['Edited after cut-over', 'Post 1', 'Edited by Micropub'], array_column(array_slice($this->feedItems('src'), 1), 'title'));
        self::assertSame($single, (string) $this->app()->handle(new ServerRequest('GET', '/s/' . substr((string) $before[1][1], -26) . '.json'))->getBody(), 'an untouched post serves the same bytes');
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Module/Micropub/PlanBAcceptanceTest.php`
Expected: PASS (3 tests, 45 assertions).

- [ ] **Step 3: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (652 tests).
Run: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit`
Expected: PASS (652 tests): the whole suite on MySQL once, before the docs.

- [ ] **Step 4: Commit**

```bash
git add tests/Module/Micropub/PlanBAcceptanceTest.php
git commit -m "test: pin the Plan B definition of done end to end" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 13: README, follow-ups and a manual smoke test

**Files:**
- Modify: `README.md`, `docs/superpowers/plans/2026-10-08-phoenix-blog-plan-a-followups.md`

**Interfaces:**
- **Consumes:** the commands, routes and rules of Tasks 1-12.
- **Produces:** a README section on native writing, and the Plan A "Before Plan B" follow-ups answered.

- [ ] **Step 1: Document**

In `README.md` (1 of 2), replace:

```markdown
private, link-local and other internal addresses are refused unless `blog.fetch_allow_private` is
set to true in the config.
`bin/xar blog:mode <handle> native` stops mirroring; post ids never change.

### Blog pages

```

with:

```markdown
private, link-local and other internal addresses are refused unless `blog.fetch_allow_private` is
set to true in the config.
`bin/xar blog:mode <handle> native` stops mirroring; post ids never change.
`bin/xar media:localize <handle>` downloads a blog's remote media now and switches it to `media=local`.

### Blog pages

```

In `README.md` (2 of 2), replace:

```markdown
rebuilds it. **Upgrading an install that already has blogs:** run `bin/xar migrate`, then
`bin/xar blog:reindex --all` once, or older posts won't be found.

License: GPL-2.0-or-later.
```

with:

````markdown
rebuilds it. **Upgrading an install that already has blogs:** run `bin/xar migrate`, then
`bin/xar blog:reindex --all` once, or older posts won't be found.

### Writing natively: tokens, the JSON API and Micropub

A native blog is written in Phoenix itself. Enable the two modules, create the blog and a token:

```sh
bin/xar module:enable tokens
bin/xar module:enable micropub
bin/xar blog:create notes --mode=native --title=Notes
bin/xar token:create --name=phone --scopes=blog:write,media:write --blog=notes --expires=365d
```

`token:create` prints the token (`xar_` and 40 letters and digits) once; only its sha256 is stored.
`bin/xar token:list` shows ids, scopes, blog and last use (never a token), and `bin/xar token:revoke <id>`
deletes one. A token made with `--blog` can only write to that blog. Send it as `Authorization: Bearer xar_…`;
a token in a query string is ignored.

The JSON API takes and returns JSON Feed 1.1 items (scope `blog:write`):

- `POST /api/blogs/{handle}/posts`: 201 with `Location: /api/posts/{id}`
- `GET /api/posts/{id}`: the item, with `_phoenix.status` set to `published`, `scheduled` or `deleted`
- `PUT /api/posts/{id}` replaces; `PATCH /api/posts/{id}` merges (RFC 7396, `application/merge-patch+json`)
- `DELETE /api/posts/{id}`: 204; `POST /api/posts/{id}/undelete`: 200
- `POST /api/blogs/{handle}/media` (scope `media:write`): the multipart field `file`; 201 with `Location` set to its `/media/…` URL

Errors are JSON. An unknown or invalid field is a 422 whose `error.fields` names it, and a write to a mirror
blog is a 409. A `date_published` in the future schedules the post: feeds, pages and search show it from then on.
`content_html` is stored as sent. Pages sanitise it when they render, but the JSON and RSS feeds serve it
unchanged, so give tokens only to people you trust with the blog's HTML.

Uploads may be JPEG, PNG, GIF, WebP, AVIF or MP3, at most 10 MB. The type is read from the bytes, never the file
name, and the file is stored as `var/uploads/<handle>/<ulid>.<ext>`. PHP's `upload_max_filesize` and
`post_max_size` must allow 10 MB.

Micropub clients use `{app.url}/micropub` and the media endpoint `{app.url}/micropub/media`. Phoenix has no
IndieAuth yet, so give the client a token from `token:create`. Creating, updating, deleting, undeleting and the
`q=config`, `q=source` and `q=syndicate-to` queries need `blog:write`; the media endpoint needs `media:write`.
`mp-destination` is a blog handle (`q=config` lists them); without it the token's blog, or the only native blog,
is used. `content` becomes `content_html` (plain text is escaped into paragraphs), `name` the title,
`bookmark-of` a link post, `photo` a photo post (upload files to the media endpoint first and send their URLs),
`category` the tags and `published` the date. Other properties are ignored.

Switching a mirror with `bin/xar blog:mode <handle> native` keeps every post's id and URL; the old posts can then
be edited through the API or Micropub by their URL.

License: GPL-2.0-or-later.
````

In `docs/superpowers/plans/2026-10-08-phoenix-blog-plan-a-followups.md`, replace:

```markdown
- Decide listener-exception policy in `Syncer` before adding subscribers.
- Inline `<img src>` in `content_html` is not rewritten by local media (matters after cut-over).

## Parked

- StreamFetcher deadline is not total during the header phase (`fopen` blocks while a server drips headers). Mitigated by `timeout` in the documented cron lines. Fix: `stream_socket_client` + own HTTP/1.0 parsing inside the deadline loop, or ext-curl.
```

with:

```markdown
- Decide listener-exception policy in `Syncer` before adding subscribers.
- Inline `<img src>` in `content_html` is not rewritten by local media (matters after cut-over).

How Plan B (`2026-10-11-phoenix-blog-plan-b.md`) settled these:

- Native creates use `PostRepository::insert()`, which never swaps ids; `item_id` embeds the id.
- `ItemValidator` rejects relative or impossible dates, titles over 1000 characters, non-http(s) URLs and unknown top-level fields (422 with `fields`).
- Column lengths are checked before writing (422); unique violations stay 500s (a ULID collision is the only way to reach one).
- `tombstone()` is unchanged (sync only); native deletes are one conditional `UPDATE` (`setStatus()`). Cross-blog isolation is tested for tokens, the API and Micropub.
- Uploads read at most 10 MB + 1 byte from the upload stream. Nothing deletes media yet, so no refcount is needed.
- Listener policy: writes dispatch item events only after the transaction commits, as sync does; `SearchIndexer` swallows and logs its own failures.
- Inline `<img src>` rewriting is still open.

## Parked

- StreamFetcher deadline is not total during the header phase (`fopen` blocks while a server drips headers). Mitigated by `timeout` in the documented cron lines. Fix: `stream_socket_client` + own HTTP/1.0 parsing inside the deadline loop, or ext-curl.
```

- [ ] **Step 2: Smoke-test against a throwaway database**

This step never touches `var/database.sqlite`. `DB_DSN` points every command at a file in `/tmp`, and `APP_DEBUG=true` keeps the web server from caching that DSN in `var/cache/config.php`. `module:enable` clears `var/cache`, which only rebuilds caches.

```bash
export DB_DSN=sqlite:/tmp/phoenix-planb-smoke.sqlite APP_DEBUG=true APP_URL=http://127.0.0.1:8099
rm -f /tmp/phoenix-planb-smoke.sqlite
bin/xar module:enable blog && bin/xar module:enable tokens && bin/xar module:enable micropub
bin/xar blog:create smoke --mode=native --title=Smoke
TOKEN=$(bin/xar token:create --name=smoke --scopes=blog:write,media:write --blog=smoke | tail -1)
php -S 127.0.0.1:8099 -t public public/index.php >/tmp/phoenix-planb-smoke.log 2>&1 & SERVER=$!; sleep 1
curl -s -o /dev/null -w '%{http_code} %header{location}\n' -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"title":"Smoke test","content_html":"<p>Hi</p>"}' http://127.0.0.1:8099/api/blogs/smoke/posts
curl -s http://127.0.0.1:8099/blog/smoke/feed.json | php -r '$f=json_decode(stream_get_contents(STDIN),true); echo implode(",", array_column($f["items"],"title")), "\n";'
curl -s -o /dev/null -w '%{http_code} %header{location}\n' -X POST -H "Authorization: Bearer $TOKEN" -d h=entry -d 'content=Hello from Micropub' http://127.0.0.1:8099/micropub
php -r 'file_put_contents("/tmp/phoenix-planb-smoke.png", base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=="));'
curl -s -o /dev/null -w '%{http_code} %header{location}\n' -X POST -H "Authorization: Bearer $TOKEN" -F file=@/tmp/phoenix-planb-smoke.png http://127.0.0.1:8099/micropub/media
curl -s -X POST -F "access_token=$TOKEN" -F file=@/tmp/phoenix-planb-smoke.png -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8099/micropub/media
curl -s -H "Authorization: Bearer $TOKEN" 'http://127.0.0.1:8099/micropub?q=config'; echo
curl -s 'http://127.0.0.1:8099/micropub?q=config'; echo
kill $SERVER
rm -rf var/uploads/smoke /tmp/phoenix-planb-smoke.sqlite /tmp/phoenix-planb-smoke.png /tmp/phoenix-planb-smoke.log
unset DB_DSN APP_DEBUG APP_URL
```

Expected, line by line after the four "Enabled/Created" lines:

```
201 /api/posts/<ulid>
Smoke test
201 http://127.0.0.1:8099/s/<ulid>
201 http://127.0.0.1:8099/media/<ulid>.png
201
{"media-endpoint":"http://127.0.0.1:8099/micropub/media","destination":[{"uid":"smoke","name":"Smoke"}],"syndicate-to":[]}
{"error":"unauthorized","error_description":"A bearer token is required"}
```

Then `git status --short` shows only the two documentation files, and `var/database.sqlite` has the same modification time as before the step.

- [ ] **Step 3: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (652 tests).

- [ ] **Step 4: Commit**

```bash
git add README.md docs/superpowers/plans/2026-10-08-phoenix-blog-plan-a-followups.md
git commit -m "docs: document tokens, the JSON API, uploads and Micropub" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Self-review (done while writing this plan)

- **Spec coverage:**
  - §1 modules: Tasks 2, 3 and 9.
  - §5.1 tokens: Tasks 2 and 3 (`access_token` on Micropub routes: Task 9).
  - §5.2 `PostWriter`: Tasks 4 and 5.
  - §5.3 JSON API: Task 6.
  - §5.4 media: Tasks 7, 8 and 11.
  - §5.5 Micropub: Tasks 9-11.
  - §6 mirror writes are 409: Tasks 4-7 and 10.
  - §7 HTTP tests: every JSON API status (Task 6), token scope, expiry and pinning (Tasks 3 and 6), media limits (Task 7), Micropub create, update, delete and queries (Tasks 9-11), and the mapping unit tests (Task 9).
  - §8 Plan B: items 1-3 in Task 12; CI is the checks of every task.
- **Placeholders:** none. Every code step embeds the file or the exact replace/with text from the proof build.
- **Type consistency:** these names are used identically everywhere:
  - `PostWriter` (`create/replace/patch/delete/undelete`), `ApiAccess::allow`, `TokenError::{missing, invalid, insufficientScope, wrongBlog}` and `->error`.
  - `MediaUploader::{upload, url}`, `MediaStore::ingest`, `Destination::{choices, choose}`.
  - `Mf2Mapper::{toItem, fromItem, patchFor, textToHtml, PROPERTIES, FIELDS}`, `PostLocator::find` and `PostRepository::{insert, rewrite, setStatus, findByItemId}`.
- **Review Focus:** each of the five lines has a named test in its owning task. Three other cases are covered too:
  - tokens in query strings and logs: Task 3 `testATokenInTheQueryStringIsIgnored`, Task 6 `testTokensNeverReachTheLogs`, Task 9 `testTheTokenMayComeAsAFormField`;
  - concurrent `last_used_at`: Task 2 `testTouchWritesAtMostOnceAMinute`;
  - unknown top-level fields: Task 4 `testRejectsInvalidItemsWithFieldMessages`, Task 6 `testUnknownTopLevelFieldsAre422WithFields`.
