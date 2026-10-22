# Phoenix Blog Admin Screens Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give signed-in Authors, Editors and Administrators server-rendered admin screens under `/admin/blog` to list, write, preview, schedule, delete and undelete posts, upload images, sync mirrors and manage blogs, with post ownership enforced per item.

**Architecture:** A blog migration adds `xar_posts.owner_id` (FK users, `SET NULL`), which `PostWriter::create()` fills from the acting user; `Schema::table()` learns to add that FK in place (Task 1). One policy class, `AdminAccess`, turns a `Post` into the `{id, owner_id}` item `Auth::can()` reads (Task 2). The kernel gains session flash messages and manifest-declared admin links shown in the phoenix header (Task 3). Thin controllers under `modules/blog/src/Admin/Http/` render `blog::admin/*` templates through `AdminPage` and write only through `PostWriter`, `BlogRepository`, `MediaUploader` and `Syncer` (Tasks 4-11). Task 12 documents it and smoke-tests it in a `/tmp` clone.

**Tech Stack:** PHP ≥ 8.3, the Phoenix kernel (PSR-7/15 through nyholm/psr7, FastRoute, PDO), PHP and Twig templates, PHPUnit 11, PHPStan level 8, php-cs-fixer (PER-CS), SQLite, MySQL 8 and Postgres 16. No new Composer package.

**Spec:** `docs/superpowers/specs/2026-10-22-phoenix-blog-admin-design.md` (every section). The decisions there marked "controller's call" and "refined" are binding; this plan does not reopen them.

**Facts this plan relies on (checked in the code):**

- `Auth::can($level, $module, $component, $item)`: with `$item === null` every instance matches (an `own` grant matches any signed-in user). With an item, `own` matches when `owner_id` (array key or object property) equals the user id; a guest owns nothing. `Post` names its property `ownerId`, so callers pass `AdminAccess::item($post)`.
- Seeded grants: Administrators `* · * · *` admin; Editors `blog · * · *` delete (the blog is `"content": true`); Authors `blog · post · own` delete and `blog · post · *` read; Anonymous `* · * · *` read. No manifest grant is added.
- `RequireAuth` redirects a guest's HTML GET to `/login?next=…`, answers 401 otherwise, and 403 to a signed-in user who fails.
- `VerifyCsrf` checks every POST (`_csrf` field or `X-CSRF-Token`). `Helpers::csrf()` prints the hidden field. A failure is 403.
- `SessionMiddleware` sets `Cache-Control: private, no-cache` on any session response unless it already says `no-store`. A request that throws saves no session changes.
- `SecureHtml` rethrows an `HttpException` as a plain one, so `ValidationFailed::fields()` is lost on secureHtml routes: admin controllers catch `ValidationFailed` and render the 422 form themselves.
- `PostWriter::create/replace/patch/delete/undelete` call `assertNative()` (409, `PostWriter::MIRROR_MESSAGE`); `replace` and `patch` also refuse a deleted post (409 "The post is deleted; undelete it first"). `patch()` returns the post unchanged, with no write and no event, when the merge patch changes nothing. A missing `title` is derived from the content (`PostWriter::hasDerivedTitle()`).
- `ItemValidator` names errors by item key (`content_html`, `external_url`, `date_published`, `tags`, `_athenana.kind`, `_athenana.tags.0.name`, `_athenana.images`, …). `_athenana.quote` and `_source` are kept in `doc` by `JsonFeedAdapter::toRecord()`; `PostViewFactory` reads them for highlights.
- The pin is the blog's `pinned_item_id` (an item id), not a post column.
- `MediaUploader::upload()` reads the multipart field `file` (`MediaUploader::FIELD`), allows `MediaStore::TYPES` (five image types and `audio/mpeg`) up to `MediaStore::MAX_BYTES`, and throws `HttpException` 400/413/415/422. `MediaUploader::url($row)` is absolute. `MediaStore::find($id)` returns the row with `blog_id`.
- `Syncer::sync(Blog $blog, bool $full, DateTimeImmutable $now): SyncReport` throws `LogicException` for a non-mirror and `RuntimeException` for a failed fetch; `SyncReport::summary()` is one line.
- `BlogRepository::create()` and `update()` throw `InvalidArgumentException` with one message; they do not validate `author_url` or `language`.
- `UrlGenerator::generate()` appends parameters that are not in the path as a query string; `UrlGenerator::has($name)` exists. `RouteCollector::group('/admin/blog', …)` with a path of `''` gives `/admin/blog`.
- `Schema::table()` refuses foreign keys today, and `xar_posts` is referenced by `post_tags` and `post_search` (cascade): dropping it to rebuild would empty them on SQLite. `ALTER TABLE … ADD COLUMN owner_id … REFERENCES xar_users (id) ON DELETE SET NULL` (SQLite, Postgres) and `ALTER TABLE … ADD COLUMN …, ADD FOREIGN KEY …` (MySQL) were both run against SQLite and MySQL 8 while writing this plan: the FK is enforced, a deleted user leaves `NULL`, and referencing rows survive.
- MySQL tables use `utf8mb4_bin`, so `LIKE` is case-sensitive there; `LOWER(col) LIKE ?` is case-insensitive on all three databases.
- `config('app.timezone')` (default `UTC`) is the PHP default timezone.
- `Browser` (tests) keeps cookies, logs in through `/login`, and adds the last page's `_csrf` to `post()`; `Html::normalize()` collapses whitespace for engine parity. `MediaStoreTest::PNG` is a base64 1×1 PNG. `FeedFactory` and `FixtureFetcher` fake a mirror source.
- Public sidebar blocks are visible on routes matching `blog.*`; admin routes are named `admin.blog*` so they never match.

## Global Constraints

- **Never touch the dev database.** No task, test or manual step reads, replaces, migrates or deletes `var/database.sqlite` (it holds the synced wyome blog). Tests use `XAR_TEST_DSN` or SQLite in the test's temp dir. The Task 12 smoke run uses a fresh `/tmp` clone with `DB_DSN=sqlite:/tmp/phoenix-admin-smoke.sqlite` and `APP_DEBUG=true` exported, so no `var/cache/config.php` shadows `DB_DSN`.
- **PHP ≥ 8.3; no new Composer package**; `composer.json` `require` does not change.
- **JSON, RSS and public pages stay byte-identical.** `tests/Module/Blog/AthenaParityTest.php` passes unchanged after every task. `owner_id` never appears in any feed, item JSON, API or Micropub response.
- **Routes:** every admin route is under `/admin/blog`, named `admin.blog` or `admin.blog.*`, and carries `secureHtml`, `noStore` and an `auth:blog.<component>.<level>` check. Item checks use `AdminAccess` with the post.
- **Levels:** dashboard and list `blog.post.edit`; new post `blog.post.add`; edit, preview, save `blog.post.edit` + item `edit`; delete, undelete `blog.post.delete` + item `delete`; pin `edit` on `blog · blog`; sync, settings, new blog `blog.blog.admin`.
- **Responses:** every admin response sends `Cache-Control: private, no-store`. A successful POST answers 303 to a URL built from a route name, with a flash message. A failed form answers 422 with the form and its errors. No redirect target ever comes from the client.
- **Writes:** posts are written only through `PostWriter` (`create`, `patch`, `delete`, `undelete`); blogs only through `BlogRepository`; uploads only through `MediaUploader`; syncs only through `Syncer`.
- **Templates:** every admin template ships as `.php` and `.twig` in `modules/blog/templates/admin/`, and a test compares both engines' HTML after `Html::normalize` with the `_csrf` value masked. In PHP templates every value goes through `$x->e()`; Twig autoescapes; the only raw HTML is the preview's sanitised `PostView` through `blog::partials/post`. Every control has a `<label for>`; a field error is `<span class="field-error" id="<field-id>-error" role="alert">` and the control gets `aria-invalid="true" aria-describedby="<field-id>-error"`. No JavaScript, no inline styles.
- **Code conventions:** PHP files start with `<?php`, a blank line, then `declare(strict_types=1);`. Raw SQL uses `{table}` placeholders. Times are UTC `DateTimeImmutable`. Test properties are never `readonly`.
- **Commits:** one per task. Every commit message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, passed as a second `-m`. Do not push.
- **Checks before every commit:** `composer test && composer stan && composer cs`, then the task's tests on MySQL: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit <task test paths>`. Postgres runs in CI.

## Review Focus

1. **IDOR by id:** an Author who opens, previews or saves `/admin/blog/post/<someone else's id>`, or posts its delete or undelete form, must get 403 and leave the post untouched, even though the route-level `auth:blog.post.edit` passes. Tests: Task 7 `testAnAuthorCannotOpenPreviewOrSaveSomeoneElsesPost`, Task 8 `testAnAuthorCannotDeleteOrUndeleteSomeoneElsesPost`.
2. **Preview leaking unsanitised HTML:** content holding `<script>`, `onerror=` and `javascript:` links must render in the preview only as sanitised HTML, appear in the textarea only escaped, save nothing and write nothing to the sanitiser cache. Tests: Task 7 `testPreviewShowsSanitisedHtmlAndSavesNothing`, `PostViewFactoryTest::testAnUncachedViewWritesNothingToTheCache`.
3. **XSS through stored titles, tags, owners and flash messages:** a title like `"><script>alert(1)</script>` must come out escaped in the list, the form, the dashboard and the flash message after saving. Tests: Task 5 `testTitlesAreEscapedInTheList`, Task 6 `testTheFlashMessageEscapesTheTitle`.
4. **CSRF on every admin POST:** each of the seven admin POST routes, sent without a token or with another session's token, must be a 403 that changes nothing. Test: Task 11 `testEveryAdminPostRefusesAMissingOrForeignToken`.
5. **Mirror posts edited from the admin:** opening the new or edit form of a mirror blog or post, saving it, or deleting it must be a 409, and the mirror's list must offer no Edit, Delete or New post action. Test: Task 10 `testMirrorPostsCannotBeWrittenFromTheAdmin`.

---

## File Map

```
src/Kernel/Db/Schema/Schema.php, Db/Dialect/AbstractDialect.php, Db/Dialect/Mysql.php   FK on a new nullable column  [1]
modules/blog/migrations/2026_10_22_100004_add_owner_to_posts.php                                                  [1]
modules/blog/src/Post/Post.php (ownerId [1], fromRecord() [7]), Post/PostRepository.php (insert owner [1],
  adminCounts [4], adminPage [5])
modules/blog/src/Write/PostWriter.php (Access, owner [1]; previewCreate, previewPatch [7])
modules/blog/src/View/PostViewFactory.php (make(..., cached) [7])
modules/blog/src/Media/MediaUploader.php (IMAGE_TYPES, $types [9])
modules/blog/src/Admin/AdminAccess.php [2], Admin/AdminRoutes.php [4-11], Admin/PostForm.php [6, 7, 9]
modules/blog/src/Admin/Http/NoStore.php, AdminPage.php, DashboardController.php [4], PostListController.php [5],
  PostFormController.php [6, 7, 9, 10], PostActionsController.php [8], BlogAdminController.php [10, 11]
modules/blog/src/Routes.php (calls AdminRoutes::define [4]), modules/blog/module.json (middleware, admin [4])
modules/blog/templates/admin/{flash,dashboard,posts,form,settings,new-blog}.{php,twig}, modules/blog/assets/blog.css
src/Kernel/Auth/Session.php (flash [3]), Module/Manifest.php + ModuleRegistry.php (adminLinks [3]),
  View/Helpers.php (adminLinks [3]), themes/phoenix/templates/layout.{php,twig}, themes/phoenix/assets/phoenix.css [3]
tests/fixtures/auth/probe/module.json (admin key [3]), tests/Support/Browser.php (upload() [9])
tests/Kernel/Db/SchemaTest.php [1], tests/Kernel/Auth/SessionTest.php [3], tests/Kernel/Module/ManifestTest.php [3],
  tests/Kernel/Auth/BlocksAndHelpersTest.php [3]
tests/Module/Blog/Write/PostOwnerTest.php [1], tests/Module/Blog/Api/PostApiTest.php [1],
  tests/Module/Blog/View/PostViewFactoryTest.php [7], tests/Module/Blog/Admin/AdminTestCase.php [2],
  tests/Module/Blog/Admin/{AdminAccess [2], Dashboard [4], PostList [5], PostCreate [6], PostEdit [7],
  PostActions [8], ImageUpload [9], Mirror [10], BlogSettings [11]}Test.php
README.md, docs/superpowers/plans/2026-10-22-phoenix-blog-admin-followups.md, the Plan 3 follow-ups [12]
```

### Task 1: Post owners: an in-place foreign key, the migration and `PostWriter`

**Files:**
- Modify: `src/Kernel/Db/Schema/Schema.php`, `src/Kernel/Db/Dialect/AbstractDialect.php`, `src/Kernel/Db/Dialect/Mysql.php`
- Create: `modules/blog/migrations/2026_10_22_100004_add_owner_to_posts.php`
- Modify: `modules/blog/src/Post/Post.php`, `modules/blog/src/Post/PostRepository.php`, `modules/blog/src/Write/PostWriter.php`
- Test: `tests/Kernel/Db/SchemaTest.php`, `tests/Module/Blog/Write/PostOwnerTest.php` (new), `tests/Module/Blog/Api/PostApiTest.php`

**Interfaces:**
- Consumes: `Access::user(): ?User`; `Auth::actAs(?User)`; `UserRepository::create(...)`; `PostWriterTestCase::{writer, served}`; `BlogTestCase::{addPost, postRecord, db}`; `TokensTestCase::{enableTokens, token}`; `ApiTestCase::{api, json}`.
- Produces:
  - `Schema::table()` accepts `Blueprint::foreign()` on a column the same blueprint adds when that column is nullable and has no default; any other FK throws `LogicException('Schema::table() can only add a foreign key on a new nullable column')`.
  - `AbstractDialect::addColumnSql(string $table, Column $column, ?array $foreignKey, string $prefix): string` (protected; `$table` is already prefixed and quoted by the caller). Default: `ALTER TABLE t ADD COLUMN <col> [REFERENCES <prefix+table> (<ref>) ON DELETE <ACTION>]`. `Mysql` overrides it: `ALTER TABLE t ADD COLUMN <col>[, ADD FOREIGN KEY (<col>) REFERENCES … ON DELETE <ACTION>]`.
  - Table `xar_posts.owner_id`: ulid, nullable, FK `users.id` `ON DELETE SET NULL`. No `down()`.
  - `Post::__construct(..., ?DateTimeImmutable $updatedAt = null, ?string $ownerId = null)`; `Post::fromRow()` reads `owner_id`.
  - `PostRepository::insert(int $blogId, string $id, PostRecord $record, DateTimeImmutable $now, ?string $ownerId = null): void`.
  - `PostWriter::__construct(BlogRepository, PostRepository, ItemSerializer, EventDispatcher, Config, Access $access)`; `create()` stores `$access->user()?->id` as the owner.

- [ ] **Step 1: Write the failing tests**

In `tests/Kernel/Db/SchemaTest.php` add:

```php
public function testTableAddsANullableForeignKeyColumnInPlace(): void
{
    $s = $this->db->schema();
    $s->create('people', function (Blueprint $t): void {
        $t->ulid('id')->primary();
    });
    $s->create('notes', function (Blueprint $t): void {
        $t->ulid('id')->primary();
    });
    $s->create('note_tags', function (Blueprint $t): void {
        $t->ulid('note_id');
        $t->foreign('note_id', 'notes');
    });
    $this->db->insert('people', ['id' => 'p1']);
    $this->db->insert('notes', ['id' => 'n1']);
    $this->db->insert('note_tags', ['note_id' => 'n1']);
    $s->table('notes', function (Blueprint $t): void {
        $t->ulid('owner_id')->nullable();
        $t->foreign('owner_id', 'people', 'id', 'set null');
    });
    $this->db->update('notes', ['owner_id' => 'p1'], ['id' => 'n1']);
    $this->db->delete('people', ['id' => 'p1']);
    self::assertNull($this->db->fetchValue('SELECT owner_id FROM {notes}'));
    self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM {note_tags}'), 'rows that reference the table survive');
    $this->expectException(PDOException::class);
    $this->db->update('notes', ['owner_id' => 'nobody'], ['id' => 'n1']);
}

public function testTableRefusesAForeignKeyOnANewNotNullColumn(): void
{
    $this->createAllTypes();
    $this->expectExceptionMessage('Schema::table() can only add a foreign key on a new nullable column');
    $this->db->schema()->table('things', function (Blueprint $t): void {
        $t->ulid('owner_id');
        $t->foreign('owner_id', 'things');
    });
}
```

(`testForeignKeysOnlyInCreate`, a FK on the existing `slug` column, must still throw.)

`tests/Module/Blog/Write/PostOwnerTest.php`:

```php
final class PostOwnerTest extends PostWriterTestCase
{
    private function actAs(?User $user): void
    {
        $this->app->container()->get(Auth::class)->actAs($user);
    }

    private function author(string $handle): User
    {
        return $this->app->container()->get(UserRepository::class)
            ->create($handle, $handle . '@example.com', 'correct horse battery', ucfirst($handle), 'active', ['Authors']);
    }

    private function find(string $id): ?Post
    {
        return $this->app->container()->get(PostRepository::class)->find($id);
    }

    public function testCreateRecordsTheActingUserAndLaterWritesKeepIt(): void
    {
        $ada = $this->author('ada');
        $this->actAs($ada);
        $post = $this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>']);
        self::assertSame($ada->id, $post->ownerId);
        $this->actAs(null);
        $post = $this->writer()->patch($post, ['title' => 'Edited']);
        $post = $this->writer()->delete($post);
        $post = $this->writer()->undelete($post);
        self::assertSame($ada->id, $post->ownerId, 'patch, delete and undelete keep the owner');
    }

    public function testAGuestWriteAndASyncedPostHaveNoOwner(): void
    {
        self::assertNull($this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>'])->ownerId);
        $id = $this->addPost($this->app, $this->blog, self::postRecord(1));
        self::assertNull($this->find($id)?->ownerId);
    }

    public function testDeletingTheUserKeepsThePostWithoutAnOwner(): void
    {
        $ada = $this->author('ada');
        $this->actAs($ada);
        $post = $this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>']);
        $this->db($this->app)->delete('users', ['id' => $ada->id]);
        $reloaded = $this->find($post->id);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->ownerId);
    }

    public function testTheOwnerNeverReachesTheServedItemFeedsOrRss(): void
    {
        $ada = $this->author('ada');
        $this->actAs($ada);
        $post = $this->writer()->create($this->blog, ['content_html' => '<p>Hi</p>', 'tags' => ['x']]);
        $this->actAs(null);
        self::assertArrayNotHasKey('owner_id', $this->served($post));
        foreach (['/blog/notes/feed.json', '/blog/notes/feed.xml', '/s/' . $post->id . '.json'] as $uri) {
            $response = $this->app->handle(new ServerRequest('GET', $uri));
            self::assertSame(200, $response->getStatusCode(), $uri);
            self::assertStringNotContainsString($ada->id, (string) $response->getBody(), $uri);
            self::assertStringNotContainsString('owner_id', (string) $response->getBody(), $uri);
        }
    }

    public function testTheAuthorsOwnGrantNowMatchesTheirPosts(): void
    {
        $ada = $this->author('ada');
        $bob = $this->author('bob');
        $this->actAs($bob);
        $bobs = $this->writer()->create($this->blog, ['content_html' => '<p>Bob</p>']);
        $this->actAs($ada);
        $adas = $this->writer()->create($this->blog, ['content_html' => '<p>Ada</p>']);
        $auth = $this->app->container()->get(Auth::class);
        self::assertTrue($auth->can('delete', 'blog', 'post', ['id' => $adas->id, 'owner_id' => $adas->ownerId]));
        self::assertFalse($auth->can('edit', 'blog', 'post', ['id' => $bobs->id, 'owner_id' => $bobs->ownerId]));
    }
}
```

In `tests/Module/Blog/Api/PostApiTest.php` add:

```php
public function testATokenThatActsAsAUserMakesThatUserTheOwner(): void
{
    $app = $this->enableTokens();
    $this->nativeBlog($app);
    $ada = $app->container()->get(UserRepository::class)
        ->create('ada', 'ada@example.com', 'correct horse battery', 'Ada', 'active', ['Authors']);
    $response = $this->api('POST', '/api/blogs/notes/posts', $this->token('blog:write', ['--user=ada']), ['content_html' => '<p>Mine</p>']);
    self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
    self::assertStringNotContainsString('owner', (string) $response->getBody());
    $id = basename((string) self::json($response)['id']);
    self::assertSame($ada->id, $this->app()->container()->get(PostRepository::class)->find($id)?->ownerId);
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Db/SchemaTest.php tests/Module/Blog/Write/PostOwnerTest.php --filter 'Foreign|Owner|Grant'`
Expected: FAIL: `LogicException: Foreign keys can only be declared in Schema::create()` and `Undefined property: Post::$ownerId`.

- [ ] **Step 3: Teach `Schema::table()` to add a foreign key on a new nullable column**

In `Schema::table()`, replace the blanket refusal: every FK's `column` must name a column in `$blueprint->columns` with `nullable === true` and `hasDefault === false`, else throw the message above. `AbstractDialect::alterTable()` passes each column's FK (or null) to `addColumnSql()`. Reuse the `FOREIGN KEY … ON DELETE` formatting `createTable()` already has (one private helper both call).

- [ ] **Step 4: Add the migration, `Post::$ownerId`, the repository column and the writer's owner**

- Migration: `$schema->table('posts', …)` with `$t->ulid('owner_id')->nullable(); $t->foreign('owner_id', 'users', 'id', 'set null');`. A docblock says why there is no `down()` (Task 12 records the follow-up).
- `Post::fromRow()`: `$s('owner_id')`.
- `PostRepository::insert()`: writes `owner_id`. `save()` and `columns()` never mention it, so sync neither sets nor clears an owner.
- `PostWriter`: the new constructor parameter; `create()` passes `$this->access->user()?->id` to `insert()`.

- [ ] **Step 5: Run the tests, the parity test and the full checks**

Run: `vendor/bin/phpunit tests/Kernel/Db/SchemaTest.php tests/Module/Blog/Write tests/Module/Blog/Api tests/Module/Blog/AthenaParityTest.php`
Expected: PASS. Then `composer test && composer stan && composer cs`, and the same four paths on MySQL (Global Constraints).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Db modules/blog/migrations/2026_10_22_100004_add_owner_to_posts.php modules/blog/src/Post modules/blog/src/Write/PostWriter.php tests/Kernel/Db/SchemaTest.php tests/Module/Blog/Write/PostOwnerTest.php tests/Module/Blog/Api/PostApiTest.php
git commit -m "feat(blog): posts have an owner, set from the acting user" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 2: The admin access policy and the admin test base

**Files:**
- Create: `modules/blog/src/Admin/AdminAccess.php`
- Test: `tests/Module/Blog/Admin/AdminTestCase.php` (new), `tests/Module/Blog/Admin/AdminAccessTest.php` (new)

**Interfaces:**
- Consumes: `Access`; `Post::$ownerId` (Task 1); `Forbidden`; `Browser`; `Html::normalize`.
- Produces:
  - `Xaraya\Module\Blog\Admin\AdminAccess(Access $access)`:
    - `static item(Post $post): array{id: string, owner_id: ?string}`
    - `canManagePosts(): bool` (`edit`, blog, post, no item), `canCreate(): bool` (`add`, no item)
    - `canEdit(Post $post): bool` (`edit` with the item), `canDelete(Post $post): bool` (`delete` with the item)
    - `canPin(): bool` (`edit`, blog, blog), `canAdminBlogs(): bool` (`admin`, blog, blog)
    - `assertEdit(Post $post): void` and `assertDelete(Post $post): void` throw `Forbidden`.
  - `Xaraya\Tests\Module\Blog\Admin\AdminTestCase extends BlogTestCase`:
    - `PASSWORD = 'correct horse battery'`; `setUp()` enables blog.
    - `app(array $overrides = []): App` adds `themes.paths ['themes']`, `app.theme phoenix`, `app.name Phoenix`, `app.locale en`, `auth.password_options ['cost' => 4]`, `session.gc_probability 0`, `view.engine php`.
    - `site(string $engine = 'php'): App`; `user(App $app, string $handle, list<string> $roles = []): User` (email `<handle>@example.com`, name `ucfirst($handle)`, password `PASSWORD`); `signIn(App $app, string $handle): Browser` (asserts the login's 303).
    - `postAs(App $app, ?User $owner, Blog $blog, array $item): Post` (acts as `$owner`, calls `PostWriter::create`, acts as a guest again).
    - `posts(App $app): PostRepository`.
    - `both(string $path, string $handle): string`: signs `$handle` in on a php and a twig app, GETs `$path` on each, asserts 200 and equal HTML after `Html::normalize` and masking `name="_csrf" value="…"`; returns the PHP HTML.
    - `static main(string $html): string` (the `<main>` contents); `static assertPrivateNoStore(ResponseInterface $r): void`.

- [ ] **Step 1: Write the failing test**

```php
final class AdminAccessTest extends AdminTestCase
{
    public function testWhoMayDoWhat(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $users = [
            'root' => $this->user($app, 'root', ['Administrators']),
            'ed' => $this->user($app, 'ed', ['Editors']),
            'ada' => $this->user($app, 'ada', ['Authors']),
            'bob' => $this->user($app, 'bob', ['Authors']),
            'mia' => $this->user($app, 'mia'),
        ];
        $posts = [
            'ada' => $this->postAs($app, $users['ada'], $blog, ['content_html' => '<p>a</p>']),
            'bob' => $this->postAs($app, $users['bob'], $blog, ['content_html' => '<p>b</p>']),
            'synced' => $this->posts($app)->find($this->addPost($app, $blog, self::postRecord(1))),
        ];
        $caps = function (?User $user) use ($app, $posts): string {
            $app->container()->get(Auth::class)->actAs($user);
            $access = $app->container()->get(AdminAccess::class);
            $out = array_filter(['manage' => $access->canManagePosts(), 'create' => $access->canCreate()]);
            foreach ($posts as $name => $post) {
                self::assertNotNull($post);
                $out += array_filter(["edit:{$name}" => $access->canEdit($post), "delete:{$name}" => $access->canDelete($post)]);
            }
            $out += array_filter(['pin' => $access->canPin(), 'admin' => $access->canAdminBlogs()]);

            return implode(' ', array_keys($out));
        };
        $all = 'manage create edit:ada delete:ada edit:bob delete:bob edit:synced delete:synced pin';
        self::assertSame($all . ' admin', $caps($users['root']));
        self::assertSame($all, $caps($users['ed']));
        self::assertSame('manage create edit:ada delete:ada', $caps($users['ada']));
        self::assertSame('', $caps($users['mia']));
        self::assertSame('', $caps(null));
    }

    public function testAssertEditAndAssertDeleteThrowForbidden(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $bobs = $this->postAs($app, $this->user($app, 'bob', ['Authors']), $blog, ['content_html' => '<p>b</p>']);
        $app->container()->get(Auth::class)->actAs($this->user($app, 'ada', ['Authors']));
        $access = $app->container()->get(AdminAccess::class);
        foreach (['assertEdit', 'assertDelete'] as $method) {
            try {
                $access->{$method}($bobs);
                self::fail("{$method} must refuse");
            } catch (Forbidden) {
                self::assertSame(['id' => $bobs->id, 'owner_id' => $bobs->ownerId], AdminAccess::item($bobs));
            }
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/AdminAccessTest.php`
Expected: FAIL: `Class "Xaraya\Module\Blog\Admin\AdminAccess" not found`.

- [ ] **Step 3: Implement `AdminAccess` and the `AdminTestCase` helpers as listed in Interfaces**

- [ ] **Step 4: Run it to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/AdminAccessTest.php`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add modules/blog/src/Admin/AdminAccess.php tests/Module/Blog/Admin
git commit -m "feat(blog): AdminAccess policy for per-post admin checks" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: Kernel flash messages and manifest admin links in the phoenix header

**Files:**
- Modify: `src/Kernel/Auth/Session.php`, `src/Kernel/Module/Manifest.php`, `src/Kernel/Module/ModuleRegistry.php`, `src/Kernel/View/Helpers.php`, `themes/phoenix/templates/layout.php`, `themes/phoenix/templates/layout.twig`, `themes/phoenix/assets/phoenix.css`, `tests/fixtures/auth/probe/module.json`
- Test: `tests/Kernel/Auth/SessionTest.php`, `tests/Kernel/Module/ManifestTest.php`, `tests/Kernel/Auth/BlocksAndHelpersTest.php`

**Interfaces:**
- Consumes: `Access::{user, can}`, `ModuleRegistry::enabled()`, `UrlGenerator::{has, generate}`, `Translator::t`.
- Produces:
  - `Session::FLASH = '_flash'`; `Session::flash(string $message, string $kind = 'status'): void` (`$kind` is `status` or `error`, else `InvalidArgumentException`); `Session::takeFlash(): list<array{kind: string, message: string}>` returns and removes them.
  - `Manifest::adminLinks(): list<array{route: string, label: string, component: string, level: string}>` from the `"admin"` key; a bad entry throws `ModuleException("{name}: admin[{i}] needs a \"route\", a \"label\", a \"component\" and a \"level\" (…levels…)")`. `ModuleRegistry::enable()` validates it with the other keys.
  - `Helpers::adminLinks(): list<array{url: string, label: string}>`: `[]` for a guest without reading the session or the database; otherwise each enabled module's links whose route exists and for which `can(level, <module name>, component)` passes; a manifest whose `admin` key is invalid is skipped. `Helpers::TWIG['adminLinks'] = false`.
  - Phoenix layout: after the tagline, when `adminLinks()` is not empty, `<nav class="admin-links" aria-label="Admin"><ul><li><a href="…">label</a></li>…</ul></nav>` (labels through `t()`).
  - Probe fixture: `"admin": [{"route": "probe.admin", "label": "Probe admin", "component": "secret", "level": "admin"}, {"route": "probe.missing", "label": "Gone", "component": "secret", "level": "read"}]`.

- [ ] **Step 1: Write the failing tests**

In `SessionTest`:

```php
public function testFlashMessagesAreKeptUntilTakenOnce(): void
{
    $session = Session::fresh();
    self::assertSame([], $session->takeFlash());
    $session->flash('Created “<b>x</b>”.');
    $session->flash('Sync failed', 'error');
    self::assertTrue($session->isDirty());
    self::assertSame([
        ['kind' => 'status', 'message' => 'Created “<b>x</b>”.'],
        ['kind' => 'error', 'message' => 'Sync failed'],
    ], $session->takeFlash());
    self::assertSame([], $session->takeFlash());
    $this->expectException(\InvalidArgumentException::class);
    $session->flash('x', 'warning');
}
```

In `ManifestTest`:

```php
public function testReadsAndValidatesAdminLinks(): void
{
    $dir = $this->tempModule('{"name": "mw", "version": "1", "admin": [{"route": "mw.admin", "label": "Mw", "component": "thing", "level": "edit"}]}');
    self::assertSame([['route' => 'mw.admin', 'label' => 'Mw', 'component' => 'thing', 'level' => 'edit']], Manifest::fromDirectory($dir)->adminLinks());
    self::assertSame([], Manifest::fromDirectory(self::FIXTURES . '/alpha')->adminLinks());
    foreach (['{"route": "x"}', '{"route": "x", "label": "X", "component": "c", "level": "god"}', '"x"'] as $link) {
        try {
            Manifest::fromDirectory($this->tempModule('{"name": "mw", "version": "1", "admin": [' . $link . ']}'))->adminLinks();
            self::fail("{$link} must be rejected");
        } catch (ModuleException $e) {
            self::assertStringContainsString('mw: admin[0] needs', $e->getMessage());
        }
    }
}
```

In `BlocksAndHelpersTest`:

```php
public function testAdminLinksShowOnlyToUsersWhoMayUseThem(): void
{
    foreach (['php', 'twig'] as $engine) {
        $app = $this->probeApp(['view.engine' => $engine]);
        $helpers = new Helpers($app->container()->get(View::class), $app->container());
        self::assertSame([], $helpers->adminLinks(), "{$engine}: guest");
        $guestHome = (string) $app->handle(new ServerRequest('GET', '/'))->getBody();
        self::assertStringNotContainsString('admin-links', $guestHome);

        $app->container()->get(Auth::class)->actAs($this->users($app)->findByHandle('mia') ?? $this->user($app, 'mia'));
        self::assertSame([], $helpers->adminLinks(), "{$engine}: member");

        $root = $this->users($app)->findByHandle('root') ?? $this->user($app, 'root', ['Administrators']);
        $app->container()->get(Auth::class)->actAs($root);
        self::assertSame([['url' => '/probe/admin', 'label' => 'Probe admin']], $helpers->adminLinks(), "{$engine}: the missing route is skipped");

        $browser = new Browser($app);
        $browser->login('root', self::PASSWORD);
        self::assertStringContainsString('<nav class="admin-links" aria-label="Admin"><ul><li><a href="/probe/admin">Probe admin</a></li></ul></nav>', Html::normalize((string) $browser->get('/')->getBody()));
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/SessionTest.php tests/Kernel/Module/ManifestTest.php tests/Kernel/Auth/BlocksAndHelpersTest.php --filter 'Flash|AdminLinks'`
Expected: FAIL: `Call to undefined method …Session::takeFlash()`.

- [ ] **Step 3: Implement flash, `Manifest::adminLinks()`, its validation in `enable()`, `Helpers::adminLinks()`, the header markup in both layouts, and a `.admin-links` rule in `phoenix.css`**

The level check uses `Access::LEVELS`; the route check uses `UrlGenerator::has()`.

- [ ] **Step 4: Run them to verify they pass, then the theme parity tests**

Run: `vendor/bin/phpunit tests/Kernel/Auth tests/Kernel/Module tests/Kernel/View/PhoenixThemeTest.php`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add src/Kernel/Auth/Session.php src/Kernel/Module src/Kernel/View/Helpers.php themes/phoenix tests/fixtures/auth/probe/module.json tests/Kernel
git commit -m "feat(kernel): session flash messages and manifest admin links in the header" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 4: Admin routes, `noStore`, `AdminPage` and the dashboard

**Files:**
- Create: `modules/blog/src/Admin/AdminRoutes.php`, `modules/blog/src/Admin/Http/NoStore.php`, `modules/blog/src/Admin/Http/AdminPage.php`, `modules/blog/src/Admin/Http/DashboardController.php`, `modules/blog/templates/admin/flash.{php,twig}`, `modules/blog/templates/admin/dashboard.{php,twig}`
- Modify: `modules/blog/src/Routes.php`, `modules/blog/module.json`, `modules/blog/src/Post/PostRepository.php`, `modules/blog/assets/blog.css`
- Test: `tests/Module/Blog/Admin/DashboardTest.php`

**Interfaces:**
- Consumes: `AdminAccess` (Task 2); `Session::{flash, takeFlash}`, `Helpers::adminLinks` (Task 3); `LoginController::session()`; `AssetUrls::url('module/blog/blog.css')`; `BlogRepository::all()`.
- Produces:
  - `AdminRoutes::define(RouteCollector $routes): void`: `$routes->group('/admin/blog', ['secureHtml', 'noStore'], …)`; this task adds `GET ''` → `[DashboardController::class, 'index']`, `admin.blog`, `auth:blog.post.edit`. `Routes::routes()` calls it. Later tasks add their routes here.
  - `module.json`: `"middleware": {"noStore": "Xaraya\\Module\\Blog\\Admin\\Http\\NoStore"}` and `"admin": [{"route": "admin.blog", "label": "Blog admin", "component": "post", "level": "edit"}]`.
  - `NoStore` (route middleware): sets `Cache-Control: private, no-store` on every response it returns.
  - `AdminPage(AssetUrls $assets)`:
    - `page(ServerRequestInterface $request, string $template, array $data, string $title, int $status = 200): Page`: data gets `flash` (`Session::takeFlash()`), title is `"{$title} · Admin"`, `styles` is `[blog.css]`.
    - `done(ServerRequestInterface $request, string $location, string $message, string $kind = 'status'): ResponseInterface`: flashes, answers 303 to `$location`.
    - `static now(): DateTimeImmutable` (UTC).
  - `PostRepository::adminCounts(int $blogId, DateTimeImmutable $now): array{published: int, scheduled: int, deleted: int}` (tab rules from spec §3.2).
  - `DashboardController(BlogRepository, PostRepository, AdminAccess, AdminPage, UrlGenerator)::index(ServerRequestInterface $request): Page`, template `blog::admin/dashboard` with `blogs: list<array{handle: string, title: string, mode: string, counts: array{published: int, scheduled: int, deleted: int}, publicUrl: string, settingsUrl: ?string}>` and `newBlogUrl: ?string` (null until Task 11 adds the routes; built only when `UrlGenerator::has()`).
  - Template `blog::admin/flash` with `flash`: each message `<p class="admin-flash admin-flash-<kind>" role="status|alert">` (`alert` for `error`), escaped, not translated. Every admin template renders it first.
  - Dashboard markup: `<h1>Blog admin</h1>`, `<table class="admin-blogs">`, one `<tr id="blog-<handle>">` per blog with title, handle, mode, the counts as `<n> published`, `<n> scheduled` and `<n> deleted`, and a "View blog" link; `<p class="admin-empty">No blogs yet.</p>` when there are none.

- [ ] **Step 1: Write the failing tests**

```php
final class DashboardTest extends AdminTestCase
{
    public function testAGuestIsSentToLoginAndAMemberIsRefused(): void
    {
        $app = $this->site();
        $response = (new Browser($app))->get('/admin/blog');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?next=%2Fadmin%2Fblog', $response->getHeaderLine('Location'));
        $this->user($app, 'mia');
        self::assertSame(403, $this->signIn($app, 'mia')->get('/admin/blog')->getStatusCode());
    }

    public function testTheDashboardListsEveryBlogWithItsCounts(): void
    {
        $app = $this->site();
        $notes = $this->nativeBlog($app, ['title' => 'Notes <&>']);
        $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena'], $this->now());
        $ada = $this->user($app, 'ada', ['Authors']);
        $this->postAs($app, $ada, $notes, ['content_html' => '<p>now</p>']);
        $this->postAs($app, $ada, $notes, ['content_html' => '<p>later</p>', 'date_published' => '2999-01-01T00:00:00Z']);
        $gone = $this->postAs($app, $ada, $notes, ['content_html' => '<p>gone</p>']);
        $app->container()->get(PostWriter::class)->delete($gone);

        $main = self::main($this->both('/admin/blog', 'ada'));
        self::assertStringContainsString('<tr id="blog-notes">', $main);
        self::assertStringContainsString('Notes &lt;&amp;&gt;', $main);
        self::assertMatchesRegularExpression('#<tr id="blog-notes">.*1 published.*1 scheduled.*1 deleted.*</tr>#sU', $main);
        self::assertStringContainsString('<tr id="blog-mirror">', $main);
        self::assertSame(['published' => 1, 'scheduled' => 1, 'deleted' => 1], $this->posts($app)->adminCounts($notes->id, $this->now()));
    }

    public function testAdminResponsesAreNeverStoredAndTheHeaderLinksHere(): void
    {
        $app = $this->site();
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $response = $browser->get('/admin/blog');
        self::assertSame(200, $response->getStatusCode());
        self::assertPrivateNoStore($response);
        self::assertStringContainsString('<a href="/admin/blog">Blog admin</a>', (string) $response->getBody());
    }

    public function testEveryAdminRouteIsSecureHtmlNoStoreAndAuthChecked(): void
    {
        $routes = array_filter(
            $this->site()->container()->get(Router::class)->routes(),
            static fn(Route $r): bool => str_starts_with((string) $r->name, 'admin.blog'),
        );
        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            self::assertStringStartsWith('/admin/blog', $route->path, (string) $route->name);
            self::assertTrue($route->hasMiddleware('secureHtml') && $route->hasMiddleware('noStore'), (string) $route->name);
            self::assertCount(1, preg_grep('/^auth:blog\.(post|blog)\.[a-z]+$/', $route->getMiddleware()) ?: [], (string) $route->name);
        }
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/DashboardTest.php`
Expected: FAIL: 404 for `/admin/blog`, and `adminCounts()` undefined.

- [ ] **Step 3: Implement the routes, `NoStore`, `AdminPage`, `adminCounts()`, the controller, both templates in both engines, and the `.admin-*` rules in `blog.css`**

The dashboard shows every blog: the route already requires `blog.post.edit`, and grants are not per blog.

- [ ] **Step 4: Run them to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin tests/Module/Blog/AthenaParityTest.php`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add modules/blog tests/Module/Blog/Admin/DashboardTest.php
git commit -m "feat(blog): admin dashboard behind auth, secureHtml and no-store" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 5: The posts list: tabs, title search and paging

**Files:**
- Create: `modules/blog/src/Admin/Http/PostListController.php`, `modules/blog/templates/admin/posts.{php,twig}`
- Modify: `modules/blog/src/Admin/AdminRoutes.php`, `modules/blog/src/Post/PostRepository.php`, `modules/blog/templates/admin/dashboard.{php,twig}` (a "Posts" link per blog)
- Test: `tests/Module/Blog/Admin/PostListTest.php`

**Interfaces:**
- Consumes: `PostRepository::adminCounts` and `AdminPage` (Task 4); `LikeSearchIndex::escapeLike()`; `Paging::{page, query}`; `UserRepository::find()`; `blog::partials/pager`.
- Produces:
  - Route `GET /{handle:[a-z0-9_-]{2,32}}/posts` → `[PostListController::class, 'index']`, `admin.blog.posts`, `auth:blog.post.edit`.
  - `PostRepository::ADMIN_TABS = ['published', 'scheduled', 'deleted']`.
  - `PostRepository::adminPage(int $blogId, string $tab, string $title, DateTimeImmutable $now, int $limit, int $offset): array{total: int, posts: list<Post>}`: raw SQL with the tab condition, `LOWER(title) LIKE ? ESCAPE '!'` with `'%' . LikeSearchIndex::escapeLike(mb_strtolower($title)) . '%'` when `$title !== ''`, `ORDER BY date_published DESC, id DESC`.
  - `PostListController(BlogRepository, PostRepository, UserRepository, AdminAccess, AdminPage, UrlGenerator, Config)::index(ServerRequestInterface $request, array $params): Page`; `PAGE_SIZE = 20`. `?tab=` outside `ADMIN_TABS` or `?q=` that is not a string of at most 200 characters is a 400 (`HttpException`). Unknown blog: 404.
  - Template `blog::admin/posts` with `blog: array{handle, title, mode, sourceUrl: ?string}`, `tabs: list<array{name, label, count, url, current: bool}>`, `q`, `rows`, `pager` (the `blog::partials/pager` shape), `actions: list<array{label, url}>` (header actions; empty in this task):
    - `rows: list<array{id, title, kind, dateIso, dateDisplay, status, owner, actions: list<array{label: string, url: string, method: 'get'|'post', fields: array<string, string>}>}>`; `dateDisplay` is `Y-m-d H:i` in `app.timezone`; `owner` is the handle or `—`. `actions` is empty in this task; Tasks 7 and 8 fill it.
  - Markup: tabs `<nav class="admin-tabs" aria-label="Post status">`, links "Published (n)", "Scheduled (n)", "Deleted (n)", `aria-current="page"` on the current one; `<form class="admin-search" method="get" role="search">` with `<label for="admin-q">Search titles</label>` and `name="q"`, plus a hidden `tab` when it is not `published`; `<table class="admin-posts">` with one `<tr id="post-<id>">` per post whose first cell is `<td class="admin-title">` (the escaped title) and with an owner cell `<td class="admin-owner">`; a `get` action renders `<a>`, a `post` action renders `<form class="admin-action" method="post">` with `csrf()`, its hidden `fields` and a `<button>`; `<p class="admin-empty">No posts.</p>` when empty. Pager URLs keep `tab` (unless `published`) and `q` (unless empty) and add `page` (unless 1).

- [ ] **Step 1: Write the failing tests**

```php
final class PostListTest extends AdminTestCase
{
    private Blog $blog;
    private User $ada;

    private function setUpPosts(App $app): void
    {
        $this->blog = $this->nativeBlog($app);
        $this->ada = $this->user($app, 'ada', ['Authors']);
    }

    public function testTabsSplitPublishedScheduledAndDeleted(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        $this->postAs($app, $this->ada, $this->blog, ['title' => 'Live one', 'content_html' => '<p>x</p>']);
        $this->postAs($app, $this->ada, $this->blog, ['title' => 'Future one', 'content_html' => '<p>x</p>', 'date_published' => '2999-01-01T00:00:00Z']);
        $gone = $this->postAs($app, null, $this->blog, ['title' => 'Gone one', 'content_html' => '<p>x</p>']);
        $app->container()->get(PostWriter::class)->delete($gone);

        $published = self::main($this->both('/admin/blog/notes/posts', 'ada'));
        self::assertStringContainsString('Live one', $published);
        self::assertStringNotContainsString('Future one', $published);
        self::assertStringNotContainsString('Gone one', $published);
        self::assertStringContainsString('<a href="/admin/blog/notes/posts" aria-current="page">Published (1)</a>', $published);
        self::assertStringContainsString('<td class="admin-owner">ada</td>', $published);

        $scheduled = self::main($this->both('/admin/blog/notes/posts?tab=scheduled', 'ada'));
        self::assertStringContainsString('Future one', $scheduled);
        self::assertStringNotContainsString('Live one', $scheduled);
        $deleted = self::main($this->both('/admin/blog/notes/posts?tab=deleted', 'ada'));
        self::assertStringContainsString('Gone one', $deleted);
        self::assertStringContainsString('<td class="admin-owner">—</td>', $deleted);
    }

    public function testTitleSearchIgnoresCaseAndTreatsWildcardsLiterally(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        foreach (['Coffee notes', 'COFFEE at noon', '100% tea', 'tea_time', 'Water'] as $title) {
            $this->postAs($app, $this->ada, $this->blog, ['title' => $title, 'content_html' => '<p>x</p>']);
        }
        $browser = $this->signIn($app, 'ada');
        $titles = function (string $q) use ($browser): array {
            preg_match_all('#<tr id="post-[^"]+"><td[^>]*>(?:<a[^>]*>)?([^<]+)#', self::main(Html::normalize((string) $browser->get('/admin/blog/notes/posts?q=' . rawurlencode($q))->getBody())), $m);
            sort($m[1]);

            return $m[1];
        };
        self::assertSame(['COFFEE at noon', 'Coffee notes'], $titles('coffee'));
        self::assertSame(['100% tea'], $titles('%'));
        self::assertSame(['tea_time'], $titles('_'));
        self::assertSame(400, $browser->get('/admin/blog/notes/posts?tab=drafts')->getStatusCode());
        self::assertSame(404, $browser->get('/admin/blog/nope/posts')->getStatusCode());
    }

    public function testTwentyPerPageWithAPagerThatKeepsTheTabAndQuery(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        for ($i = 1; $i <= 21; $i++) {
            $this->postAs($app, $this->ada, $this->blog, ['title' => "Tea {$i}", 'content_html' => '<p>x</p>', 'date_published' => sprintf('2999-01-%02dT00:00:00Z', $i)]);
        }
        $browser = $this->signIn($app, 'ada');
        $first = (string) $browser->get('/admin/blog/notes/posts?tab=scheduled&q=tea')->getBody();
        self::assertSame(20, substr_count($first, '<tr id="post-'));
        self::assertStringContainsString('href="/admin/blog/notes/posts?tab=scheduled&amp;q=tea&amp;page=2"', $first);
        $second = (string) $browser->get('/admin/blog/notes/posts?tab=scheduled&q=tea&page=2')->getBody();
        self::assertSame(1, substr_count($second, '<tr id="post-'));
        self::assertStringContainsString('Tea 1<', $second, 'oldest last');
    }

    public function testTitlesAreEscapedInTheList(): void
    {
        $app = $this->site();
        $this->setUpPosts($app);
        $this->postAs($app, $this->ada, $this->blog, ['title' => '"><script>alert(1)</script>', 'content_html' => '<p>x</p>', 'tags' => ['"><img src=x onerror=alert(1)>']]);
        $html = $this->both('/admin/blog/notes/posts', 'ada');
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/PostListTest.php`
Expected: FAIL: 404 for `/admin/blog/notes/posts`.

- [ ] **Step 3: Implement the route, `adminPage()`, the controller and both templates; add a "Posts" link per blog on the dashboard**

- [ ] **Step 4: Run them to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin`
Expected: PASS. Then the full checks and MySQL (the search test is the one that proves `LOWER()` on `utf8mb4_bin`).

- [ ] **Step 5: Commit**

```bash
git add modules/blog tests/Module/Blog/Admin/PostListTest.php
git commit -m "feat(blog): admin posts list with status tabs, title search and paging" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 6: The new post form

**Files:**
- Create: `modules/blog/src/Admin/PostForm.php`, `modules/blog/src/Admin/Http/PostFormController.php`, `modules/blog/templates/admin/form.{php,twig}`
- Modify: `modules/blog/src/Admin/AdminRoutes.php`, `modules/blog/templates/admin/{dashboard,posts}.{php,twig}` (a "New post" action)
- Test: `tests/Module/Blog/Admin/PostCreateTest.php`

**Interfaces:**
- Consumes: `PostWriter::create`; `ValidationFailed::fields()`; `AdminAccess::{canCreate, canPin}`; `AdminPage::{page, done}`; `BlogRepository::update` (the pin); `JsonFeedAdapter::KINDS`; `config('app.timezone')`.
- Produces:
  - Routes: `GET /{handle}/posts/new` → `newForm`, `admin.blog.post.new`; `POST /{handle}/posts/new` → `create`, `admin.blog.post.create`; both `auth:blog.post.add`.
  - `final class PostForm` (readonly public properties `kind, title, content, externalUrl, quote, sourceName, sourceUrl, tags, published: string` and `pinned: bool`):
    - `static blank(): self` (kind `note`, the rest empty);
    - `static fromInput(array $body): self`: non-string values become `''`; every value is trimmed except `content` and `quote`, whose CRLF and CR become LF;
    - `check(string $timezone): array<string, string>` (form field => message): `kind` not in `JsonFeedAdapter::KINDS` → `Choose note, link, photo or highlight`; `published` not empty and not `Y-m-d\TH:i` or `Y-m-d\TH:i:s` (checked by round-trip formatting) → `Use a date and time like 2026-10-22T09:30`; `source_url` not empty and not `ItemValidator::isHttpUrl()` → `Must be an http(s) URL`; `source_url` without `source_name` → `Give the source a name`;
    - `toItem(string $timezone): array<string, mixed>`: `_athenana.kind`, plus only the non-empty `title`, `content_html`, `external_url`, `_athenana.quote`, `_source {name, url?}`, `tags` (split on `,`, trimmed, empties dropped, duplicates removed in order) and `date_published` (the local time in `$timezone`, as `DATE_ATOM` in UTC);
    - `values(): array<string, string|bool>` keyed by the HTML field names `kind, title, content, external_url, quote, source_name, source_url, tags, date_published, pinned`;
    - `const FIELDS = ['_athenana.kind' => 'kind', 'title' => 'title', 'content_html' => 'content', 'external_url' => 'external_url', '_athenana.quote' => 'quote', '_source' => 'source_name', 'tags' => 'tags', 'date_published' => 'date_published', 'image' => 'file', '_athenana.images' => 'file']`; `static field(string $itemKey): ?string` maps a key or any `_athenana.tags…` key (→ `tags`); `null` means "show above the form".
  - `PostFormController(BlogRepository, PostRepository, PostWriter, AdminAccess, AdminPage, UrlGenerator, Config)`:
    - `newForm(ServerRequestInterface $request, array $params): Page`; `create(ServerRequestInterface $request, array $params): ResponseInterface|Page`.
    - `create`: `check()` errors, then `PostWriter::create()` errors mapped through `PostForm::field()`, give a 422 form. Success: when `canPin()` and `pinned`, set the blog's `pinned_item_id` to the new post's `itemId`; then `AdminPage::done()` to `admin.blog.posts` (with `tab=scheduled` when the post is in the future) and the flash `Created “<title>”.` (title cut to 80 characters with `mb_strimwidth(…, '…')`).
  - Template `blog::admin/form` with `blog`, `heading`, `action`, `values`, `errors: array<string, string>`, `formErrors: list<string>`, `kinds: list<array{value, label}>`, `timezone`, `canPin: bool`; later tasks add `preview`, `image`, `uploadId`. Markup: `<form class="admin-form" method="post" action="…">` with `csrf()`; each field in `<p class="admin-field">` with `<label for="post-<name with - for _>">`; control attributes in the order `id`, `name`, `type` (omitted for text), `value`, then `aria-invalid` and `aria-describedby`; field errors and `aria-*` per Global Constraints; form errors in `<div class="form-error" role="alert"><ul><li>…`; `content` is a `<textarea id="post-content" name="content" rows="12">`; `date_published` is `type="datetime-local"` with a hint `<span class="form-hint" id="post-date-published-hint">Time zone: <tz>. A future time schedules the post.</span>` and always `aria-describedby="post-date-published-hint"` (plus ` post-date-published-error` on an error); the pinned checkbox only when `canPin`; `<button type="submit" name="save" value="1">Save</button>`.

- [ ] **Step 1: Write the failing tests**

```php
final class PostCreateTest extends AdminTestCase
{
    public function testAnAuthorCreatesAHighlightThatTheyOwn(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $html = $this->both('/admin/blog/notes/posts/new', 'ada');
        self::assertStringContainsString('<label for="post-title">', $html);
        self::assertStringNotContainsString('name="pinned"', $html, 'Authors cannot pin');

        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', [
            'kind' => 'highlight', 'title' => '', 'content' => "<p>Worth\r\nreading</p>", 'quote' => 'To be or not',
            'source_name' => 'Hamlet', 'source_url' => 'https://example.com/hamlet', 'tags' => ' plays, , Plays ,drama',
            'date_published' => '', 'save' => '1',
        ]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/notes/posts', $response->getHeaderLine('Location'));
        self::assertPrivateNoStore($response);
        $post = $this->posts($app)->page($blog->id, $this->now(), null, 1)[0];
        self::assertSame($ada->id, $post->ownerId);
        self::assertSame('highlight', $post->kind);
        self::assertSame("<p>Worth\nreading</p>", $post->contentHtml);
        self::assertSame(['plays', 'drama'], array_column($post->tags, 'name'), 'empties dropped; one tag per slug, the first wins');
        self::assertSame('To be or not', $post->doc['_athenana']['quote'] ?? null);
        self::assertSame(['name' => 'Hamlet', 'url' => 'https://example.com/hamlet'], $post->doc['_source'] ?? null);
        self::assertStringContainsString('<p class="admin-flash admin-flash-status" role="status">Created “', (string) $browser->get('/admin/blog/notes/posts')->getBody());
    }

    public function testAFutureLocalTimeSchedulesThePostInTheSiteTimezone(): void
    {
        $app = $this->app(['app.timezone' => 'Europe/Paris']);
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>Later</p>', 'date_published' => '2999-07-01T09:30', 'save' => '1']);
        self::assertSame('/admin/blog/notes/posts?tab=scheduled', $response->getHeaderLine('Location'));
        $post = $this->posts($app)->adminPage($blog->id, 'scheduled', '', $this->now(), 20, 0)['posts'][0];
        self::assertSame('2999-07-01T07:30:00+00:00', $post->datePublished->format(DATE_ATOM));
    }

    public function testErrorsAreShownInlineAndNothingIsSaved(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $response = $browser->post('/admin/blog/notes/posts/new', [
            'kind' => 'link', 'content' => '', 'external_url' => 'ftp://nope', 'date_published' => 'tomorrow', 'save' => '1',
        ]);
        self::assertSame(422, $response->getStatusCode());
        $html = Html::normalize((string) $response->getBody());
        self::assertStringContainsString('<span class="field-error" id="post-date-published-error" role="alert">Use a date and time like 2026-10-22T09:30</span>', $html);
        self::assertStringContainsString('id="post-date-published" name="date_published" type="datetime-local" value="tomorrow" aria-invalid="true" aria-describedby="post-date-published-hint post-date-published-error"', $html);
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);

        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'link', 'content' => '', 'external_url' => 'ftp://nope', 'save' => '1']);
        self::assertStringContainsString('id="post-external-url-error" role="alert">Must be an absolute http(s) URL', Html::normalize($browser->body()));
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testTheFlashMessageEscapesTheTitle(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'title' => '<img src=x onerror=alert(1)>', 'content' => '<p>x</p>', 'save' => '1']);
        $html = (string) $browser->get('/admin/blog/notes/posts')->getBody();
        self::assertStringContainsString('Created “&lt;img src=x onerror=alert(1)&gt;”.', $html);
        self::assertStringNotContainsString('<img src=x', $html);
    }

    public function testAnEditorMayPinTheNewPost(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'ed', ['Editors']);
        self::assertStringContainsString('name="pinned"', $this->both('/admin/blog/notes/posts/new', 'ed'));
        $browser = $this->signIn($app, 'ed');
        $browser->get('/admin/blog/notes/posts/new');
        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>Top</p>', 'pinned' => '1', 'save' => '1']);
        $blog = $this->blogs($app)->find('notes');
        $post = $this->posts($app)->page((int) $blog?->id, $this->now(), null, 1)[0];
        self::assertSame($post->itemId, $blog?->pinnedItemId);
    }

    public function testAMemberCannotOpenTheForm(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'mia');
        self::assertSame(403, $this->signIn($app, 'mia')->get('/admin/blog/notes/posts/new')->getStatusCode());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/PostCreateTest.php`
Expected: FAIL: 404 for `/admin/blog/notes/posts/new`.

- [ ] **Step 3: Implement `PostForm`, the controller, the routes and both form templates; add "New post" (when `canCreate()`) to the dashboard rows and the list's header `actions`**

`app.timezone` comes from `Config`; the controller never trusts a timezone from the request.

- [ ] **Step 4: Run them to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add modules/blog tests/Module/Blog/Admin/PostCreateTest.php
git commit -m "feat(blog): admin new post form through PostWriter, with inline errors" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: Editing through `patch`, and preview

**Files:**
- Modify: `modules/blog/src/Write/PostWriter.php`, `modules/blog/src/Post/Post.php`, `modules/blog/src/View/PostViewFactory.php`, `modules/blog/src/Admin/PostForm.php`, `modules/blog/src/Admin/Http/PostFormController.php`, `modules/blog/src/Admin/Http/PostListController.php`, `modules/blog/src/Admin/AdminRoutes.php`, `modules/blog/templates/admin/form.{php,twig}`
- Test: `tests/Module/Blog/Admin/PostEditTest.php`, `tests/Module/Blog/View/PostViewFactoryTest.php`

**Interfaces:**
- Consumes: `PostForm`, `PostFormController` (Task 6); `AdminAccess::{assertEdit, canEdit}`; `PostWriter::{patch, hasDerivedTitle}`; `PostViewFactory::make`.
- Produces:
  - Routes: `GET /post/{id:[0-9a-hjkmnp-tv-z]{26}}` → `edit`, `admin.blog.post.edit`; `POST` same path → `update`, `admin.blog.post.update`; both `auth:blog.post.edit`.
  - `Post::fromRecord(PostRecord $record, string $id, int $blogId, ?string $ownerId, DateTimeImmutable $now): self` (status `published`, `updatedAt` = `$now`).
  - `PostWriter::previewCreate(Blog $blog, array $item, ?DateTimeImmutable $now = null): Post` and `PostWriter::previewPatch(Post $post, array $partial, ?DateTimeImmutable $now = null): Post`: exactly the checks and mapping of `create()` and `patch()` (they share private `prepareCreate`/`prepareReplace` steps with them), but nothing is written and no event fires. They throw `ValidationFailed` and the 409s the same way.
  - `PostViewFactory::make(Blog $blog, Post $post, array $media = [], bool $pinned = false, bool $cached = true): PostView`: `$cached = false` sanitises without reading or writing the cache.
  - `PostForm::fromPost(Post $post, Blog $blog, string $timezone): self` (title `''` when `PostWriter::hasDerivedTitle()`; `content` is the stored `contentHtml`; `quote` and source from `doc`; tags joined with `, `; `published` as `Y-m-d\TH:i` in `$timezone`; `pinned` when the blog's `pinnedItemId` equals the post's `itemId`).
  - `PostForm::toPatch(Post $post, string $timezone): array<string, mixed>`: a merge patch with `title` (omitted when empty and the title is derived, else value or null), `content_html`, `external_url`, `tags` (value or null), `_athenana: {kind, quote: value|null}`, `_source: {name, url|null}|null`, and `date_published` only when `published` differs from `fromPost()`'s value.
  - `PostFormController::edit(ServerRequestInterface $request, array $params): Page` and `update(ServerRequestInterface $request, array $params): ResponseInterface|Page`: unknown id 404; `AdminAccess::assertEdit()` before anything else; a deleted post is a 409 `The post is deleted; undelete it first`. A body with `preview` runs `previewPatch()` (or `previewCreate()` in `create()`) and renders the form with `preview` = the uncached `PostView`, status 200, saving nothing. Saving runs `patch()`; when it returns the same `updatedAt`, the flash is `No changes.`, otherwise `Saved “<title>”.`; then 303 to the post's edit page. The pin is set or cleared after a save only when `canPin()`.
  - Template additions: `preview: ?PostView` renders after the form as `<section class="admin-preview" aria-labelledby="preview-heading"><h2 id="preview-heading">Preview</h2>` + `blog::partials/post` with `full: true`; a `<button type="submit" name="preview" value="1">Preview</button>` next to Save.
  - List rows: an `Edit` `get` action (`admin.blog.post.edit`) when `canEdit()` and the row is not deleted.

- [ ] **Step 1: Write the failing tests**

In `PostViewFactoryTest`:

```php
public function testAnUncachedViewWritesNothingToTheCache(): void
{
    $view = $this->factory()->make(self::blog(), self::post(), cached: false);
    self::assertSame('<p>Hello <b>world</b></p>', $view->contentHtml);
    self::assertSame([], glob($this->tmp . '/cache/*') ?: []);
}
```

`tests/Module/Blog/Admin/PostEditTest.php`:

```php
final class PostEditTest extends AdminTestCase
{
    public function testTheFormRoundTripsAndAnUnchangedSaveWritesNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $post = $this->postAs($app, $ada, $blog, [
            'content_html' => "<p>Derived\nfrom here</p>", 'tags' => ['a', 'b'], 'date_published' => '2026-10-01T09:30:15Z',
            '_athenana' => ['kind' => 'note', 'images' => [['url' => 'https://cdn.test/1.png', 'width' => 10, 'height' => 10]]],
        ]);
        $html = $this->both('/admin/blog/post/' . $post->id, 'ada');
        self::assertStringContainsString('id="post-title" name="title" value=""', $html, 'a derived title stays derived');
        self::assertStringContainsString('value="a, b"', $html);
        self::assertStringContainsString('value="2026-10-01T09:30"', $html);

        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/post/' . $post->id);
        $fields = ['kind' => 'note', 'title' => '', 'content' => "<p>Derived\r\nfrom here</p>", 'tags' => 'a, b', 'date_published' => '2026-10-01T09:30', 'save' => '1'];
        $response = $browser->post('/admin/blog/post/' . $post->id, $fields);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/post/' . $post->id, $response->getHeaderLine('Location'));
        self::assertStringContainsString('No changes.', (string) $browser->get('/admin/blog/post/' . $post->id)->getBody());
        self::assertEquals($post->updatedAt, $this->posts($app)->find($post->id)?->updatedAt);

        $browser->post('/admin/blog/post/' . $post->id, ['title' => 'Now titled', 'tags' => 'b'] + $fields);
        $saved = $this->posts($app)->find($post->id);
        self::assertSame('Now titled', $saved?->title);
        self::assertSame(['b'], array_column($saved->tags, 'name'));
        self::assertSame('2026-10-01T09:30:15+00:00', $saved->datePublished->format(DATE_ATOM), 'seconds survive an unchanged date');
        self::assertSame('https://cdn.test/1.png', $saved->doc['_athenana']['images'][0]['url'] ?? null, 'fields the form does not show survive');
        self::assertSame($ada->id, $saved->ownerId);
    }

    public function testPreviewShowsSanitisedHtmlAndSavesNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $post = $this->postAs($app, $ada, $blog, ['title' => 'Before', 'content_html' => '<p>Before</p>']);
        $evil = '<p>Shown</p><script>alert(1)</script><img src="x" onerror="alert(2)"><a href="javascript:alert(3)">j</a>';
        $browser = $this->signIn($app, 'ada');
        foreach (['/admin/blog/post/' . $post->id, '/admin/blog/notes/posts/new'] as $path) {
            $browser->get($path);
            $response = $browser->post($path, ['kind' => 'note', 'title' => 'After', 'content' => $evil, 'preview' => '1']);
            self::assertSame(200, $response->getStatusCode(), $path);
            $html = (string) $response->getBody();
            $preview = (string) strstr($html, '<section class="admin-preview"');
            self::assertStringContainsString('<p>Shown</p>', $preview, $path);
            foreach (['<script', 'onerror', 'javascript:'] as $needle) {
                self::assertStringNotContainsString($needle, $preview, "{$path}: {$needle}");
            }
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, 'the textarea keeps the raw HTML, escaped');
        }
        self::assertSame('Before', $this->posts($app)->find($post->id)?->title);
        self::assertSame(1, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testAnAuthorCannotOpenPreviewOrSaveSomeoneElsesPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $bobs = $this->postAs($app, $this->user($app, 'bob', ['Authors']), $blog, ['title' => 'Bob’s', 'content_html' => '<p>b</p>']);
        $browser = $this->signIn($app, 'ada');
        self::assertSame(403, $browser->get('/admin/blog/post/' . $bobs->id)->getStatusCode());
        foreach (['save', 'preview'] as $button) {
            $browser->get('/admin/blog/notes/posts');
            $response = $browser->post('/admin/blog/post/' . $bobs->id, ['kind' => 'note', 'title' => 'Hijacked', 'content' => '<p>x</p>', $button => '1']);
            self::assertSame(403, $response->getStatusCode(), $button);
            self::assertStringNotContainsString('Hijacked', (string) $response->getBody());
        }
        self::assertSame('Bob’s', $this->posts($app)->find($bobs->id)?->title);
        $list = (string) $browser->get('/admin/blog/notes/posts')->getBody();
        self::assertStringNotContainsString('/admin/blog/post/' . $bobs->id, $list, 'no Edit action on someone else’s post');
    }

    public function testADeletedPostCannotBeEditedAndAnUnknownIdIs404(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $ed = $this->user($app, 'ed', ['Editors']);
        $post = $app->container()->get(PostWriter::class)->delete($this->postAs($app, $ed, $blog, ['content_html' => '<p>x</p>']));
        $browser = $this->signIn($app, 'ed');
        self::assertSame(409, $browser->get('/admin/blog/post/' . $post->id)->getStatusCode());
        self::assertSame(404, $browser->get('/admin/blog/post/01m3t19wn8reaww81zg1m47tjq')->getStatusCode());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/PostEditTest.php tests/Module/Blog/View/PostViewFactoryTest.php`
Expected: FAIL: 404 for `/admin/blog/post/<id>`, and `Unknown named parameter $cached`.

- [ ] **Step 3: Split `PostWriter::create/replace` into prepare and write steps, add the two preview methods and `Post::fromRecord()`, and add `$cached` to `PostViewFactory::make()`**

`patch()` keeps its no-op rule; `previewPatch()` previews the served post itself when the patch is a no-op.

- [ ] **Step 4: Add `fromPost()`, `toPatch()`, the edit and update actions, the preview branch in `create()`, the routes, the template's preview section and button, and the list's Edit action**

- [ ] **Step 5: Run them to verify they pass, then every write test**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin tests/Module/Blog/Write tests/Module/Blog/View tests/Module/Blog/Api tests/Module/Micropub tests/Module/Blog/AthenaParityTest.php`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 6: Commit**

```bash
git add modules/blog tests/Module/Blog/Admin/PostEditTest.php tests/Module/Blog/View/PostViewFactoryTest.php
git commit -m "feat(blog): admin edit through merge patch, with an uncached sanitised preview" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 8: Delete and undelete

**Files:**
- Create: `modules/blog/src/Admin/Http/PostActionsController.php`
- Modify: `modules/blog/src/Admin/AdminRoutes.php`, `modules/blog/src/Admin/Http/PostListController.php`
- Test: `tests/Module/Blog/Admin/PostActionsTest.php`

**Interfaces:**
- Consumes: `PostWriter::{delete, undelete}`; `AdminAccess::{assertDelete, canDelete}`; `AdminPage::done`; `PostRepository::ADMIN_TABS`.
- Produces:
  - Routes: `POST /post/{id}/delete` → `delete`, `admin.blog.post.delete`; `POST /post/{id}/undelete` → `undelete`, `admin.blog.post.undelete`; both `auth:blog.post.delete`.
  - `PostActionsController(BlogRepository, PostRepository, PostWriter, AdminAccess, AdminPage, UrlGenerator)::delete(ServerRequestInterface $request, array $params): ResponseInterface` and `undelete(...)`: 404 for an unknown id; `assertDelete()`; the writer call; 303 to `admin.blog.posts` of the post's blog with the posted `tab` (kept only when it is in `ADMIN_TABS` and not `published`) and `page` (kept only when it matches `[1-9][0-9]{0,5}` and is not `1`); flash `Deleted “<title>”.` or `Restored “<title>”.`.
  - List rows: a `Delete` `post` action (live rows) or an `Undelete` `post` action (deleted rows) when `canDelete()`, with hidden `tab` and `page` of the current list.

- [ ] **Step 1: Write the failing tests**

```php
final class PostActionsTest extends AdminTestCase
{
    public function testAnAuthorDeletesAndUndeletesTheirOwnPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->postAs($app, $this->user($app, 'ada', ['Authors']), $blog, ['title' => 'Mine', 'content_html' => '<p>x</p>']);
        $browser = $this->signIn($app, 'ada');
        self::assertStringContainsString('action="/admin/blog/post/' . $post->id . '/delete"', $this->both('/admin/blog/notes/posts', 'ada'));
        $browser->get('/admin/blog/notes/posts');
        $response = $browser->post('/admin/blog/post/' . $post->id . '/delete', ['tab' => 'published', 'page' => '1']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/notes/posts', $response->getHeaderLine('Location'));
        self::assertSame('deleted', $this->posts($app)->find($post->id)?->status);
        self::assertStringContainsString('Deleted “Mine”.', (string) $browser->get('/admin/blog/notes/posts?tab=deleted')->getBody());

        $response = $browser->post('/admin/blog/post/' . $post->id . '/undelete', ['tab' => 'deleted', 'page' => '2']);
        self::assertSame('/admin/blog/notes/posts?tab=deleted&page=2', $response->getHeaderLine('Location'));
        self::assertSame('published', $this->posts($app)->find($post->id)?->status);
    }

    public function testTheRedirectIgnoresAnythingButAKnownTabAndAPageNumber(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $post = $this->postAs($app, $this->user($app, 'ed', ['Editors']), $blog, ['content_html' => '<p>x</p>']);
        $browser = $this->signIn($app, 'ed');
        $browser->get('/admin/blog/notes/posts');
        $response = $browser->post('/admin/blog/post/' . $post->id . '/delete', ['tab' => 'https://evil.test/', 'page' => '//evil.test', 'next' => 'https://evil.test/']);
        self::assertSame('/admin/blog/notes/posts', $response->getHeaderLine('Location'));
    }

    public function testAnAuthorCannotDeleteOrUndeleteSomeoneElsesPost(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $bob = $this->user($app, 'bob', ['Authors']);
        $live = $this->postAs($app, $bob, $blog, ['content_html' => '<p>live</p>']);
        $dead = $app->container()->get(PostWriter::class)->delete($this->postAs($app, $bob, $blog, ['content_html' => '<p>dead</p>']));
        $browser = $this->signIn($app, 'ada');
        foreach ([[$live, 'delete', 'published'], [$dead, 'undelete', 'deleted']] as [$post, $action, $status]) {
            $browser->get('/admin/blog/notes/posts');
            self::assertSame(403, $browser->post("/admin/blog/post/{$post->id}/{$action}")->getStatusCode(), $action);
            self::assertSame($status, $this->posts($app)->find($post->id)?->status, $action);
        }
        self::assertStringNotContainsString('/delete"', (string) $browser->get('/admin/blog/notes/posts')->getBody());
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/PostActionsTest.php`
Expected: FAIL: 404 (no delete route) and no `action="…/delete"` in the list.

- [ ] **Step 3: Implement the controller, the routes and the list actions**

- [ ] **Step 4: Run them to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add modules/blog tests/Module/Blog/Admin/PostActionsTest.php
git commit -m "feat(blog): admin delete and undelete as CSRF-checked POST forms" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 9: Image upload in the post form

**Files:**
- Modify: `modules/blog/src/Media/MediaUploader.php`, `modules/blog/src/Admin/PostForm.php`, `modules/blog/src/Admin/Http/PostFormController.php`, `modules/blog/templates/admin/form.{php,twig}`, `tests/Support/Browser.php`
- Test: `tests/Module/Blog/Admin/ImageUploadTest.php`

**Interfaces:**
- Consumes: `MediaUploader::{upload, url, FIELD}`; `MediaStore::{find, TYPES}`; `PostForm::{toItem, toPatch}`; `PostWriter::{create, patch, previewCreate, previewPatch}`.
- Produces:
  - `MediaUploader::IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif']`; `upload(Blog $blog, ServerRequestInterface $request, DateTimeImmutable $now, list<string> $types = []): array`: after reading the bytes and before `MediaStore::ingest()`, a sniffed type (`finfo`) outside a non-empty `$types` is a 415 `Choose a file of type: <types>`. The API keeps `[]`.
  - `PostForm`: `removeImage: bool` and `uploadId: string` (a ULID or `''`), read from `remove_image` and `upload_id`.
  - `PostFormController` image rule, for create, update and preview, applied **before** `PostForm::check()` and `PostWriter` validation so that a re-rendered form can carry the upload:
    - no `file` entry, or one with `UPLOAD_ERR_NO_FILE`, is no upload;
    - otherwise `MediaUploader::upload(…, MediaUploader::IMAGE_TYPES)`; an `HttpException` with a 4xx status becomes the field error `file` (its message) and a 422 form;
    - with no new upload, an `upload_id` whose `MediaStore::find()` row has this blog's id is reused; any other value is ignored;
    - the image goes in as `image` = `MediaUploader::url($row)` and `_athenana.images` = `[['url' => …, 'width' => (int) …, 'height' => (int) …]]` (null when the row has none) (added to the item, or to the patch's `_athenana`);
    - `remove_image` on edit (with no upload) patches `image: null` and `_athenana.images: null`;
    - a re-rendered form (422 or preview) carries `uploadId` and shows the stored image.
  - Form markup: `enctype="multipart/form-data"`; `<label for="post-file">Image</label><input id="post-file" name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif">`; the current or uploaded image as `<img class="admin-image" src="…" alt="">` with `<input type="hidden" name="upload_id">` when there is an upload; a `remove_image` checkbox on edit when the post has an image.
  - `Browser::upload(string $path, array $fields, array $files): ResponseInterface` (multipart: `withParsedBody($fields + _csrf)` and `withUploadedFiles($files)`).

- [ ] **Step 1: Write the failing tests**

```php
final class ImageUploadTest extends AdminTestCase
{
    private function file(string $name, string $bytes, string $type): UploadedFile
    {
        $path = $this->tmp . '/' . $name;
        file_put_contents($path, $bytes);

        return new UploadedFile($path, strlen($bytes), UPLOAD_ERR_OK, $name, $type);
    }

    private function mediaRows(App $app): int
    {
        return (int) $this->db($app)->fetchValue('SELECT COUNT(*) FROM {media}');
    }

    public function testAnUploadedImageBecomesThePostsImage(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $png = (string) base64_decode(MediaStoreTest::PNG, true);
        $response = $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'save' => '1'], ['file' => $this->file('p.png', $png, 'image/png')]);
        self::assertSame(303, $response->getStatusCode(), $browser->body());
        $post = $this->posts($app)->page($blog->id, $this->now(), null, 1)[0];
        self::assertMatchesRegularExpression('#^http://xar\.test/media/[0-9a-hjkmnp-tv-z]{26}\.png$#', (string) $post->image);
        self::assertSame([['url' => $post->image, 'width' => 1, 'height' => 1]], $post->doc['_athenana']['images'] ?? null);

        $browser->get('/admin/blog/post/' . $post->id);
        $browser->post('/admin/blog/post/' . $post->id, ['kind' => 'photo', 'content' => '<p>caption</p>', 'remove_image' => '1', 'save' => '1']);
        $saved = $this->posts($app)->find($post->id);
        self::assertNull($saved?->image);
        self::assertArrayNotHasKey('images', $saved->doc['_athenana'] ?? []);
    }

    public function testOnlyImagesAreAcceptedAndARefusalStoresNothing(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00" . str_repeat("\xff\xfb\x90\x64" . str_repeat("\x00", 413), 4);
        foreach ([['a.mp3', $mp3, 'audio/mpeg'], ['shell.png', "<?php echo 'x';\n", 'image/png']] as [$name, $bytes, $type]) {
            $browser->get('/admin/blog/notes/posts/new');
            $response = $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'], ['file' => $this->file($name, $bytes, $type)]);
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringContainsString('id="post-file-error" role="alert">Choose a file of type: image/jpeg', Html::normalize($browser->body()), $name);
        }
        self::assertSame(0, $this->mediaRows($app));
        self::assertSame(0, $this->posts($app)->adminCounts($blog->id, $this->now())['published']);
    }

    public function testAnUploadSurvivesAValidationErrorButNotAForeignUploadId(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $other = $this->nativeBlog($app, ['handle' => 'other', 'title' => 'Other']);
        $this->user($app, 'ada', ['Authors']);
        $browser = $this->signIn($app, 'ada');
        $browser->get('/admin/blog/notes/posts/new');
        $png = (string) base64_decode(MediaStoreTest::PNG, true);
        $browser->upload('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'date_published' => 'nope', 'save' => '1'], ['file' => $this->file('p.png', $png, 'image/png')]);
        self::assertSame(422, $browser->last?->getStatusCode());
        self::assertSame(1, preg_match('/name="upload_id" value="([0-9a-hjkmnp-tv-z]{26})"/', $browser->body(), $m));
        $browser->post('/admin/blog/notes/posts/new', ['kind' => 'photo', 'content' => '', 'upload_id' => $m[1], 'save' => '1']);
        self::assertNotNull($this->posts($app)->page($blog->id, $this->now(), null, 1)[0]->image);

        $browser->get('/admin/blog/other/posts/new');
        $response = $browser->post('/admin/blog/other/posts/new', ['kind' => 'photo', 'content' => '', 'upload_id' => $m[1], 'save' => '1']);
        self::assertSame(422, $response->getStatusCode(), 'another blog’s upload is ignored, so the photo has no content');
        self::assertSame(0, $this->posts($app)->adminCounts($other->id, $this->now())['published']);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/ImageUploadTest.php`
Expected: FAIL: `Call to undefined method …Browser::upload()`.

- [ ] **Step 3: Add `Browser::upload()`, `IMAGE_TYPES` and the `$types` check, and the controller's image rule and markup**

- [ ] **Step 4: Run them to verify they pass, then the media API tests**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin tests/Module/Blog/Api/MediaApiTest.php tests/Module/Blog/Media`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add modules/blog tests/Support/Browser.php tests/Module/Blog/Admin/ImageUploadTest.php
git commit -m "feat(blog): image upload in the admin post form, images only" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 10: Mirror blogs are read-only; "Sync now"

**Files:**
- Create: `modules/blog/src/Admin/Http/BlogAdminController.php`
- Modify: `modules/blog/src/Admin/AdminRoutes.php`, `modules/blog/src/Admin/Http/PostFormController.php`, `modules/blog/src/Admin/Http/PostActionsController.php`, `modules/blog/src/Admin/Http/PostListController.php`, `modules/blog/templates/admin/posts.{php,twig}`
- Test: `tests/Module/Blog/Admin/MirrorTest.php`

**Interfaces:**
- Consumes: `PostWriter::assertNative()`; `Syncer::sync`; `SyncReport::summary()`; `AdminAccess::canAdminBlogs`; `FeedFactory`, `FixtureFetcher` (tests).
- Produces:
  - Every `PostFormController` action and both `PostActionsController` actions call `PostWriter::assertNative()` on the blog right after the access check (so a mirror answers 409 before any form is shown).
  - The list, for a mirror: `<p class="admin-mirror-note">Mirror of <source URL>; edits come from the source.</p>`, no row actions and no "New post"; for `canAdminBlogs()`, a header action `Sync now` (`post` to `admin.blog.sync`).
  - Route `POST /{handle}/sync` → `[BlogAdminController::class, 'sync']`, `admin.blog.sync`, `auth:blog.blog.admin`.
  - `BlogAdminController(BlogRepository, Syncer, AdminAccess, AdminPage, UrlGenerator, UserRepository)::sync(ServerRequestInterface $request, array $params): ResponseInterface`: 404 unknown blog; a native blog is a 409 `Blog '<handle>' is native; there is nothing to sync`; `Syncer::sync($blog, false, AdminPage::now())`; flash `SyncReport::summary()` (kind `error` when `!ok()`), or `Sync failed: <message>` (kind `error`) on a `RuntimeException`; 303 to the blog's posts list.

- [ ] **Step 1: Write the failing tests**

```php
final class MirrorTest extends AdminTestCase
{
    private const SOURCE = 'https://src.test/blog/src/feed.json';

    private function mirror(App $app): Blog
    {
        return $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => self::SOURCE, 'source_format' => 'athena'], $this->now());
    }

    public function testMirrorPostsCannotBeWrittenFromTheAdmin(): void
    {
        $app = $this->site();
        $blog = $this->mirror($app);
        $id = $this->addPost($app, $blog, self::postRecord(1, ['title' => 'Synced']));
        $this->user($app, 'root', ['Administrators']);
        $list = self::main($this->both('/admin/blog/mirror/posts', 'root'));
        self::assertStringContainsString('<p class="admin-mirror-note">Mirror of https://src.test/blog/src/feed.json; edits come from the source.</p>', $list);
        foreach (['/admin/blog/post/', '/posts/new', '/delete"'] as $needle) {
            self::assertStringNotContainsString($needle, $list, $needle);
        }
        $browser = $this->signIn($app, 'root');
        self::assertSame(409, $browser->get('/admin/blog/mirror/posts/new')->getStatusCode());
        self::assertSame(409, $browser->get('/admin/blog/post/' . $id)->getStatusCode());
        foreach (['/admin/blog/post/' . $id, '/admin/blog/post/' . $id . '/delete', '/admin/blog/mirror/posts/new'] as $path) {
            $browser->get('/admin/blog/mirror/posts');
            self::assertSame(409, $browser->post($path, ['kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'])->getStatusCode(), $path);
        }
        self::assertSame('Synced', $this->posts($app)->find($id)?->title);
        self::assertSame('published', $this->posts($app)->find($id)?->status);
    }

    public function testSyncNowReportsItsCountsAndOnlyAdministratorsMayRunIt(): void
    {
        $app = $this->site();
        $blog = $this->mirror($app);
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->user($app, 'ed', ['Editors']);
        $editor = $this->signIn($app, 'ed');
        self::assertStringNotContainsString('Sync now', (string) $editor->get('/admin/blog/mirror/posts')->getBody());
        self::assertSame(403, $editor->post('/admin/blog/mirror/sync')->getStatusCode());

        $this->user($app, 'root', ['Administrators']);
        $root = $this->signIn($app, 'root');
        $root->get('/admin/blog/mirror/posts');
        $response = $root->post('/admin/blog/mirror/sync');
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin/blog/mirror/posts', $response->getHeaderLine('Location'));
        self::assertStringContainsString('mirror: 1 page(s), 3 created, 0 updated', (string) $root->get('/admin/blog/mirror/posts')->getBody());
        self::assertSame(3, $this->posts($app)->countLive($blog->id));
    }

    public function testAFailedFetchIsAnErrorFlash(): void
    {
        $app = $this->site();
        $this->mirror($app);
        $app->container()->instance(HttpFetcher::class, new FixtureFetcher());
        $this->user($app, 'root', ['Administrators']);
        $root = $this->signIn($app, 'root');
        $root->get('/admin/blog/mirror/posts');
        $root->post('/admin/blog/mirror/sync');
        self::assertStringContainsString('<p class="admin-flash admin-flash-error" role="alert">Sync failed:', Html::normalize((string) $root->get('/admin/blog/mirror/posts')->getBody()));
    }
}
```

(`FixtureFetcher::get()` answers 404 for a URL it does not know, so `Syncer` throws `RuntimeException("Fetching … returned HTTP 404")`.)

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/MirrorTest.php`
Expected: FAIL: the new form of a mirror answers 200, and `/admin/blog/mirror/sync` is 404.

- [ ] **Step 3: Implement the guards, the list's mirror view and the sync action**

- [ ] **Step 4: Run them to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin tests/Module/Blog/Sync`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add modules/blog tests/Module/Blog/Admin/MirrorTest.php
git commit -m "feat(blog): read-only mirror blogs in the admin, with Sync now" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 11: Blog settings and new blogs

**Files:**
- Modify: `modules/blog/src/Admin/Http/BlogAdminController.php`, `modules/blog/src/Admin/AdminRoutes.php`, `modules/blog/templates/admin/dashboard.{php,twig}`
- Create: `modules/blog/templates/admin/settings.{php,twig}`, `modules/blog/templates/admin/new-blog.{php,twig}`
- Test: `tests/Module/Blog/Admin/BlogSettingsTest.php`

**Interfaces:**
- Consumes: `BlogRepository::{find, create, update}`; `ItemValidator::isHttpUrl`; `Access::user()`; `AdminPage`.
- Produces:
  - Routes, all `auth:blog.blog.admin`: `GET /new` → `newBlogForm`, `admin.blog.new`; `POST /new` → `createBlog`, `admin.blog.create`; `GET /{handle}/settings` → `settings`, `admin.blog.settings`; `POST /{handle}/settings` → `saveSettings`, `admin.blog.settings.save`.
  - `BlogAdminController::SETTINGS = ['title', 'description_html', 'author_name', 'author_url', 'language', 'post_url_pattern', 'media']`.
  - `saveSettings`: trims each posted setting; `''` becomes `null` for `description_html`, `author_url` and `post_url_pattern`; checks `author_url` with `ItemValidator::isHttpUrl()` (`Author URL must be an http(s) URL`) and `language` against `/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/D` (`Language must be a tag like en or en-GB`); then `BlogRepository::update()`; an `InvalidArgumentException` message is a form error; errors give a 422 page with the posted values; success flashes `Settings saved.` and 303s to the settings page.
  - `createBlog`: `BlogRepository::create(['handle' => …, 'title' => …, 'mode' => 'native', 'author_name' => <user's displayName()>])`; an `InvalidArgumentException` is a 422 form error; success flashes `Created blog “<title>”.` and 303s to its posts list.
  - Settings markup: field ids `blog-title`, `blog-description-html` (textarea), `blog-author-name`, `blog-author-url`, `blog-language`, `blog-post-url-pattern`, `blog-media` (`<select>` of `Blog::MEDIA`); `<p class="admin-mode">Mode: <mode>. Change it with <code>bin/xar blog:mode <handle> mirror|native</code>.</p>`; for a mirror, `<p class="form-notice">A sync may overwrite the title, description and author from the source.</p>`. New blog markup: `blog-handle` (with `pattern="[a-z0-9_-]{2,32}"`), `blog-title`, and `<p>Mode: native. Mirror blogs are created with <code>bin/xar blog:create</code>.</p>`.
  - Dashboard: "Settings" per blog and the "New blog" link, both when `canAdminBlogs()`.

- [ ] **Step 1: Write the failing tests**

```php
final class BlogSettingsTest extends AdminTestCase
{
    public function testAnAdministratorEditsSettingsWithValidation(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        $this->user($app, 'root', ['Administrators']);
        $html = $this->both('/admin/blog/notes/settings', 'root');
        self::assertStringContainsString('<code>bin/xar blog:mode notes mirror|native</code>', $html);
        $browser = $this->signIn($app, 'root');
        $fields = ['title' => 'Field notes', 'description_html' => '', 'author_name' => 'Ada', 'author_url' => 'javascript:alert(1)',
            'language' => 'en-GB', 'post_url_pattern' => '', 'media' => 'local'];
        $browser->get('/admin/blog/notes/settings');
        self::assertSame(422, $browser->post('/admin/blog/notes/settings', $fields)->getStatusCode());
        self::assertStringContainsString('Author URL must be an http(s) URL', $browser->body());
        $browser->post('/admin/blog/notes/settings', ['post_url_pattern' => 'https://x.test/p'] + ['author_url' => ''] + $fields);
        self::assertSame(422, $browser->last?->getStatusCode());
        self::assertStringContainsString('Post URL pattern must be an http(s) URL containing {id} exactly once', $browser->body());
        $response = $browser->post('/admin/blog/notes/settings', ['author_url' => 'https://ada.test'] + $fields);
        self::assertSame(303, $response->getStatusCode());
        $blog = $this->blogs($app)->find('notes');
        self::assertSame(['Field notes', null, 'https://ada.test', 'en-GB', 'local', 'native'], [$blog?->title, $blog?->descriptionHtml, $blog?->authorUrl, $blog?->language, $blog?->media, $blog?->mode]);
    }

    public function testAnAdministratorCreatesANativeBlog(): void
    {
        $app = $this->site();
        $this->user($app, 'root', ['Administrators']);
        self::assertStringContainsString('<label for="blog-handle">', $this->both('/admin/blog/new', 'root'));
        $browser = $this->signIn($app, 'root');
        $browser->get('/admin/blog/new');
        self::assertSame(422, $browser->post('/admin/blog/new', ['handle' => 'No Caps', 'title' => 'x'])->getStatusCode());
        $response = $browser->post('/admin/blog/new', ['handle' => 'diary', 'title' => 'Diary', 'mode' => 'mirror']);
        self::assertSame('/admin/blog/diary/posts', $response->getHeaderLine('Location'));
        $blog = $this->blogs($app)->find('diary');
        self::assertSame(['native', 'Root'], [$blog?->mode, $blog?->authorName], 'the mode is always native; the author is the user');
    }

    public function testEditorsAndAuthorsCannotManageBlogs(): void
    {
        $app = $this->site();
        $this->nativeBlog($app);
        foreach (['ed' => 'Editors', 'ada' => 'Authors'] as $handle => $role) {
            $this->user($app, $handle, [$role]);
            $browser = $this->signIn($app, $handle);
            self::assertSame(403, $browser->get('/admin/blog/notes/settings')->getStatusCode(), $handle);
            self::assertSame(403, $browser->get('/admin/blog/new')->getStatusCode(), $handle);
            self::assertStringNotContainsString('/settings"', (string) $browser->get('/admin/blog')->getBody(), $handle);
        }
    }

    public function testEveryAdminPostRefusesAMissingOrForeignToken(): void
    {
        $app = $this->site();
        $blog = $this->nativeBlog($app);
        $this->blogs($app)->create(['handle' => 'mirror', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena'], $this->now());
        $root = $this->user($app, 'root', ['Administrators']);
        $post = $this->postAs($app, $root, $blog, ['title' => 'Kept', 'content_html' => '<p>x</p>']);
        $paths = [
            '/admin/blog/new', '/admin/blog/notes/posts/new', '/admin/blog/post/' . $post->id,
            '/admin/blog/post/' . $post->id . '/delete', '/admin/blog/post/' . $post->id . '/undelete',
            '/admin/blog/mirror/sync', '/admin/blog/notes/settings',
        ];
        $posts = array_filter(
            $app->container()->get(Router::class)->routes(),
            static fn(Route $r): bool => str_starts_with((string) $r->name, 'admin.blog') && in_array('POST', $r->methods, true),
        );
        self::assertCount(count($paths), $posts, 'every admin POST route is listed here');

        $other = $this->signIn($app, 'root');
        $other->get('/admin/blog/new');
        $foreign = (string) $other->csrfField();
        $browser = $this->signIn($app, 'root');
        $fields = ['handle' => 'evil', 'title' => 'Hijacked', 'kind' => 'note', 'content' => '<p>x</p>', 'save' => '1'];
        foreach ($paths as $path) {
            foreach (['' => 'missing', $foreign => 'foreign'] as $token => $label) {
                self::assertSame(403, $browser->post($path, ['_csrf' => (string) $token] + $fields)->getStatusCode(), "{$path}: {$label}");
            }
        }
        self::assertNull($this->blogs($app)->find('evil'));
        self::assertSame('Kept', $this->posts($app)->find($post->id)?->title);
        self::assertSame('published', $this->posts($app)->find($post->id)?->status);
        self::assertSame('Notes', $this->blogs($app)->find('notes')?->title);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin/BlogSettingsTest.php`
Expected: FAIL: 404 for `/admin/blog/notes/settings` and `/admin/blog/new`.

- [ ] **Step 3: Implement the four actions, the routes, both templates in both engines, and the dashboard links**

- [ ] **Step 4: Run them to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Admin`
Expected: PASS. Then the full checks and MySQL.

- [ ] **Step 5: Commit**

```bash
git add modules/blog tests/Module/Blog/Admin/BlogSettingsTest.php
git commit -m "feat(blog): admin blog settings and native blog creation" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 12: README, follow-ups and a smoke run in `/tmp`

**Files:**
- Modify: `README.md`, `docs/superpowers/plans/2026-10-21-phoenix-kernel-auth-followups.md`
- Create: `docs/superpowers/plans/2026-10-22-phoenix-blog-admin-followups.md`

**Interfaces:**
- Consumes: every route, rule and message of Tasks 1-11.
- Produces: documentation only.

- [ ] **Step 1: Document**

- `README.md`, in "Blog (Athena-compatible)", a new `### Admin screens` section: the URL `/admin/blog`; the "Blog admin" header link; the role table from spec §2 (Administrators, Editors, Authors, Members); post owners (`owner_id`, set from the signed-in user or the token's user, never in feeds); what the form does (kinds, HTML stored as given and sanitised on render, `datetime-local` in `APP_TIMEZONE`, a future date schedules, Preview, images only, pin for Editors and Administrators); mirror blogs are read-only with "Sync now" for Administrators; settings and new blogs for Administrators; mode switching and mirror creation stay `bin/xar blog:mode` and `bin/xar blog:create`.
- `README.md`, in "Themes, templates and blocks": the manifest `"admin"` key and the `adminLinks()` helper; in "Users, roles and sign-in": flash messages (`Session::flash()`, `takeFlash()`), and that `Schema::table()` can add a foreign key on a new nullable column.
- Plan 3 follow-ups: strike "No admin UI" for the blog (keep it for users, roles and grants) and note that the Authors `own` grant now matches.
- New follow-ups file, at least: the owner migration has no `down()` and re-running it after a manual rollback fails; tag names cannot contain commas in the form; an upload whose post then fails validation leaves a media row (reused by `upload_id` or a re-upload, never collected); Authors see every post in the list; the pin is per blog and needs `edit` on `blog · blog`; mirror settings can be overwritten by a sync; no user, role, grant or token UI; no bulk actions or revision history; a quick sync in a web request is bounded only by `Syncer::MAX_PAGES`; the spec's open questions.

- [ ] **Step 2: Smoke-test in a `/tmp` clone with a throwaway database**

Commit Tasks 1-11 first (the clone sees only commits). Record `ls -l var/database.sqlite` before and after.

```bash
ls -l var/database.sqlite
export DB_DSN=sqlite:/tmp/phoenix-admin-smoke.sqlite APP_DEBUG=true APP_URL=http://127.0.0.1:8099
rm -rf /tmp/phoenix-admin-smoke /tmp/phoenix-admin-smoke.sqlite /tmp/phoenix-admin-smoke.jar
git clone -q . /tmp/phoenix-admin-smoke && cd /tmp/phoenix-admin-smoke && composer install -q
bin/xar install --admin=admin --email=admin@example.com --password='smoke test password'
bin/xar module:enable blog
bin/xar asset:publish
bin/xar user:create ada --email=ada@example.com --role=Authors --password='author password'
php -S 127.0.0.1:8099 -t public public/index.php >/tmp/phoenix-admin-smoke.log 2>&1 & SERVER=$!; sleep 1
U=http://127.0.0.1:8099; J=/tmp/phoenix-admin-smoke.jar
csrf() { curl -s -c $J -b $J "$U$1" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -1; }
login() { T=$(csrf /login); curl -s -c $J -b $J -o /dev/null -w "login-$1 %{http_code}\n" -d "_csrf=$T" -d login=$1 --data-urlencode "password=$2" $U/login; }
curl -s -o /dev/null -w 'guest %{http_code} %{redirect_url}\n' $U/admin/blog
login admin 'smoke test password'
T=$(csrf /admin/blog/new); curl -s -c $J -b $J -o /dev/null -w 'new-blog %{http_code} %{redirect_url}\n' -d "_csrf=$T" -d handle=notes -d title=Notes $U/admin/blog/new
curl -s -c $J -b $J -D - -o /dev/null $U/admin/blog | grep -i '^cache-control'
rm -f $J; login ada 'author password'
curl -s -b $J $U/ | grep -o '<nav class="admin-links".*</nav>'
T=$(csrf /admin/blog/notes/posts/new); curl -s -c $J -b $J -o /dev/null -w 'create %{http_code} %{redirect_url}\n' -F "_csrf=$T" -F kind=note -F title='Hello admin' -F 'content=<p>Hi <script>x</script></p>' -F tags='a, b' -F save=1 $U/admin/blog/notes/posts/new
curl -s -b $J $U/admin/blog/notes/posts | grep -o 'Created “Hello admin”.'
curl -s $U/blog/notes/feed.json | grep -c owner_id
curl -s -o /dev/null -w 'public-post %{http_code}\n' $U/blog/notes
curl -s -b $J -o /dev/null -w 'settings-as-author %{http_code}\n' $U/admin/blog/notes/settings
kill $SERVER
cd - >/dev/null; rm -rf /tmp/phoenix-admin-smoke /tmp/phoenix-admin-smoke.sqlite /tmp/phoenix-admin-smoke.jar /tmp/phoenix-admin-smoke.log
unset DB_DSN APP_DEBUG APP_URL
ls -l var/database.sqlite
```

Expected (after the install and enable output): `guest 302 http://127.0.0.1:8099/login?next=%2Fadmin%2Fblog`, `login-admin 303`, `new-blog 303 …/admin/blog/notes/posts`, `Cache-Control: private, no-store`, `login-ada 303`, the nav with `<a href="/admin/blog">Blog admin</a>`, `create 303 …/admin/blog/notes/posts`, `Created “Hello admin”.`, `0`, `public-post 200`, `settings-as-author 403`, and both `ls` lines identical.

- [ ] **Step 3: Run the full checks**

Run: `composer test && composer stan && composer cs`, then `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Module/Blog tests/Kernel/Db tests/Kernel/Auth tests/Kernel/Module`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add README.md docs/superpowers/plans/2026-10-21-phoenix-kernel-auth-followups.md docs/superpowers/plans/2026-10-22-phoenix-blog-admin-followups.md
git commit -m "docs: blog admin screens, follow-ups and smoke run" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

## Self-review (done while writing this plan)

- **Spec coverage:**
  - §1 ownership: Task 1 (column, FK, writer, token owner, no leak). §2 access: Task 2 (policy), Task 4 (route rules, `noStore`, `admin.blog` names), item checks in Tasks 7 and 8.
  - §3.1 dashboard: Tasks 4, 5, 6, 11. §3.2 list: Task 5 (+ actions in 6, 7, 8, 10). §3.3 form: Tasks 6 (create, fields, errors, pin) and 7 (edit, patch, preview). §3.4 upload: Task 9. §3.5 mirrors: Task 10. §3.6 settings and §3.7 new blog: Task 11.
  - §4 navigation: Tasks 3 and 4. §5 safety: CSRF Task 11, no-store Task 4, escaping Tasks 5 and 6, 303 + flash Tasks 3 and 6-11, no open redirect Task 8, upload types Task 9, dev DB Global Constraints and Task 12. §6 flash: Task 3. §7 definition of done: Tasks 6-11 tests and the Task 12 smoke run.
- **Step scan:** every code step names the file, the signature and the values the spec pins; bodies are left to the implementer except where the spec fixes copy (messages, markup the tests match). Test steps carry their assertions.
- **Type consistency:** used identically everywhere: `AdminAccess::{item, canManagePosts, canCreate, canEdit, canDelete, canPin, canAdminBlogs, assertEdit, assertDelete}`; `AdminPage::{page, done, now}`; `PostForm::{blank, fromInput, fromPost, check, toItem, toPatch, values, field, FIELDS}`; `PostRepository::{insert(…, ?string $ownerId), adminCounts, adminPage, ADMIN_TABS}`; `PostWriter::{previewCreate, previewPatch}`; `Post::{$ownerId, fromRecord}`; `PostViewFactory::make(…, bool $cached)`; `MediaUploader::{IMAGE_TYPES, upload(…, list<string> $types)}`; `Session::{flash, takeFlash, FLASH}`; `Manifest::adminLinks`; `Helpers::adminLinks`; route names `admin.blog`, `admin.blog.posts`, `admin.blog.post.{new, create, edit, update, delete, undelete}`, `admin.blog.{sync, settings, settings.save, new, create}`.
- **Review Focus:** each of the five lines names its test in its owning task. Also covered: scheduled and deleted visibility (Task 5 `testTabsSplitPublishedScheduledAndDeleted`), upload type checks (Task 9 `testOnlyImagesAreAcceptedAndARefusalStoresNothing`), owner not in JSON or RSS (Task 1 `testTheOwnerNeverReachesTheServedItemFeedsOrRss` and `testATokenThatActsAsAUserMakesThatUserTheOwner`), open redirects (Task 8), no-store on every route (Task 4).
- **Proportion:** the spec is about 200 lines; this plan is longer because its tests carry the exact markup, messages and edge cases the spec implies. No implementation body is written out.
