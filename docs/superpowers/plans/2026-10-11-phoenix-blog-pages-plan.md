# Phoenix Blog HTML Pages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Render every stored blog as themable, server-side HTML (home, post, tag, tag cloud, about and search pages, plus four sidebar blocks) in the neutral `phoenix` theme, with identical output from the PHP and Twig engines, render-time sanitising, a strict CSP and a portable search index, while the JSON and RSS output stay byte-for-byte unchanged.

**Architecture:**

- **Pages:** five small controllers (`PostPageController`, `BlogPagesController`, `TagsController`, `AboutController`, `SearchController`) return kernel `Page` objects. `BlogPage` builds every one of them: blog lookup, `<title>`, meta and Open Graph tags, feed links, canonical URL, `lang`, the `blog.css` stylesheet and `Cache-Control`.
- **Posts:** `PostViewFactory` turns a stored `Post` into an engine-neutral `PostView` (scalars, arrays and nulls). Its HTML fields pass the new kernel `HtmlSanitizer`, cached in the kernel file cache. Templates live in `modules/blog/templates/` as `.php` and `.twig`, and pull every overridable partial with `render()` so a theme override in either engine is found.
- **Search:** `xar_post_search` holds each post's normalised text (`SearchText`). `LikeSearchIndex` ANDs one escaped `LIKE` per term, blog subscribers keep it current on `ItemCreated`/`ItemUpdated`/`ItemDeleted`, and `xar blog:reindex` rebuilds it.
- **Blocks:** four block types (`blog.info`, `blog.tagcloud`, `blog.searchbox`, `blog.recent`) are seeded into the `sidebar` region by `blockDefaults`, visible on `blog.*` routes.

**Tech Stack:** PHP ≥ 8.3 with ext-dom (newly required), the Phoenix kernel from Plans 1 and 2 (View, Page, Helpers, blocks, Cache, SecureHtml, ErrorPages, UrlGenerator), PDO on SQLite, MySQL 8 and Postgres 16, PHPUnit 11, PHPStan level 8. Twig 3 stays optional (`require-dev`). No new Composer package and no JavaScript.

**Spec:** `docs/superpowers/specs/2026-10-09-phoenix-blog-pages-design.md` (all of it). Read with it: `docs/superpowers/plans/2026-10-09-phoenix-kernel-views-plan.md` (the conventions this plan follows) and the "Blog pages plan" section of `docs/superpowers/plans/2026-10-09-phoenix-kernel-views-followups.md` (Task 15 records how each item was settled).

**Deviations from the spec, decided while planning:**

1. **Template names** follow the kernel convention: `blog::home`, `blog::partials/kinds/link` (spec §2.2 writes `blog/home`). A theme still overrides them at `themes/<theme>/templates/modules/blog/<path>.php|.twig`.
2. **The blog stylesheet ships in the module**, as `modules/blog/assets/blog.css`. `xar asset:publish` publishes it to `/assets/module/blog/blog.css` and every blog page adds it through `Page::$styles`. Spec §2.2 puts it in `themes/phoenix/assets/blog.css`, but module styling has to work under any theme.
3. **`AssetUrls` kernel service** (Task 1) holds the asset-URL logic that only `Helpers::asset()` had, so controllers can fill `Page::$styles`.
4. **`HtmlSanitizer` lives in the kernel** (`Xaraya\Kernel\Support\HtmlSanitizer`), because it is generic. Its constructor takes the site host (from `app.url`) to tell external links apart, and it exposes `isSafeUrl()`. Elements nested deeper than 64 levels are dropped with their content, which bounds the recursion on hostile input.
5. **Who calls the sanitiser.** Spec §3.2 names `PostViewFactory` and the about page. In this plan the about page's call lives in `BlogPage::descriptionHtml()`, and every page's plain-text blog description (meta description, home header, `blog.info` block) is taken from that sanitised HTML, so the text of a `<script>` in `description_html` never reaches a page.
6. **Cache key.** Sanitised HTML is cached under `sanitized/{post_id}-{updated_at}-{field}-{first 12 hex of sha1(input)}`. `updated_at` has one-second resolution, and the hash keeps two writes in the same second apart. `Post` gains a trailing `?DateTimeImmutable $updatedAt = null` so the key can use it.
7. **`PostView` additions:** `externalUrl` (the link partial needs it). A mention's `date` is already formatted with `j M Y`. `PostViewFactory` also offers `link()`, `plainText()`, `display()` and `duration()` for the pages and blocks.
8. **Comment de-duplication** applies to every kind whose `_athenana.comment` is a byte-for-byte prefix of `content_html`, not only to links. The test is strict, so it cannot remove anything else.
9. **Normalisation order.** `SearchText` strips accents with `Normalizer` NFD first (when ext-intl is present), then transliterates what is left with `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE')`. Spec §4 orders them the other way round, but macOS iconv turns "é" into "'e", which would split words. Without ext-intl the iconv path alone runs, as the spec says.
10. **`SearchIndex::clear(int $blogId)`** is added to the interface for `blog:reindex`.
11. **Search snippets** are cut from the post's sanitised visible text (`PostViewFactory::plainText()`), so a match never shows script source.
12. **Smoke-test parsing** ignores libxml error 801 ("Tag main invalid"): libxml2's HTML 4 parser reports every HTML5 element (`main`, `article`, `time`, `mark`, `audio`, `figure`) that way, and the kernel layout already uses them. Every other libxml error must be absent.
13. **`xar blog:blocks`** is new. `blockDefaults` only seed on a module's first install, and every existing install enabled the blog module in Plan A. The command creates the four blocks when no `blog.*` block exists.
14. **Small choices the spec leaves open:**
    - Tags whose slug contains `/` are not linked, since no route can match them.
    - The pinned post sits above page 1 and also keeps its place in date order.
    - The home page `<title>` is the blog title alone.
    - A cursor page past the last post is an empty 200 page; only a first page with no posts makes a tag 404.
    - A `q` that is not a string, and a `page` that is not `[1-9][0-9]{0,5}`, are 400s too.
    - Videos from providers other than YouTube are not shown, because the spec defines `watchUrl` for YouTube only.

**Facts this plan relies on (checked in the code):**

- Plan A routes only `GET /s/{id}.json` (`blog.post.json` → `PostController::show`). `GET /s/{id}` had no route and returned 404. Task 8 adds `blog.post` for `/s/{id}` and does not touch `blog.post.json` or `PostController`; a test pins its bytes.
- `RouteHandler::match()` matches the `rawurldecode`d path, so route parameters arrive decoded exactly once. FastRoute regexes have no `u` flag, so `[^/]{1,128}` counts bytes. Stored slugs are at most 128 bytes (`AthenaAdapter`, `Text::slug`).
- `ModuleRegistry::enable()` seeds `blockDefaults` only when `xar_modules` had no row for the module.
- `ErrorHandler` renders HTML errors through `ErrorPages` with `SecureHtml::HEADERS`. Error pages are rendered from the request before route matching, so blocks limited to routes are hidden on them.
- MySQL tables use `utf8mb4_bin`, so `=` and `LIKE` compare bytes on all three databases. Postgres stores `json` columns as `JSONB`, which has no `LIKE`.
- `Connection::sql()` expands `{posts}` to the quoted, prefixed table name. `Connection::normalize()` writes dates as UTC `Y-m-d H:i:s`.
- `_athenana` item keys (from Athena's `BlogFeedItem`): `kind`, `pinned`, `images[{url,width,height}]`, `song{channel,duration_seconds}`, `podcast{show,episode,duration_seconds,published_at,audio_url,cover}`, `quote`, `comment` (HTML), `tags`, `mentions[{source,title,date}]`. Beside them, `_video{provider,id,thumbnail,source}` and `_source{name,url}`.
- `BlogTestCase` already has `app()`, `enableBlog()`, `xar()`, `db()`, `blogs()` and `now()`. `PostRepositoryTest` declares a private `record()`, so the new shared helper is named `postRecord()`.

## Global Constraints

- **Content-Security-Policy**, sent on every page by the kernel `secureHtml` alias (`SecureHtml::HEADERS`), verbatim: `default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self'; script-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'`, together with `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`.
- **Patterns:** handle `[a-z0-9_-]{2,32}`; post id (ULID) `[0-9a-hjkmnp-tv-z]{26}`; tag slug `[^/]{1,128}`, URL-decoded and compared exactly.
- **Routes:** `blog.home` `GET /blog/{handle}`, `blog.tag` `GET /blog/{handle}/tag/{slug}`, `blog.tags` `GET /blog/{handle}/tags`, `blog.about` `GET /blog/{handle}/about`, `blog.search` `GET /blog/{handle}/search?q=`, `blog.post` `GET /s/{id}`. Each uses `->middleware('secureHtml', 'conditional')`.
- **Paging:** 20 posts a page (`BlogPage::PAGE_SIZE`). Home and tag pages use the feed's `?before=` cursor codec (`Feed\Cursor`); search uses `?page=N`, 1-based.
- **Excerpt:** the first 160 characters of the plain text (`PostViewFactory::EXCERPT_LENGTH`).
- **Dates:** displayed with the format `j M Y` (`PostViewFactory::DATE_FORMAT`).
- **Search limits:** `q` at most 200 characters (`SearchController::MAX_QUERY_LENGTH`; longer is a 400). Terms come from `SearchText::normalize`; terms shorter than 2 characters are dropped, and at most 8 are kept. Each term adds `body LIKE ? ESCAPE '!'`, with `!`, `%` and `_` escaped using `!`. With no usable terms the result is `{total: 0, ids: []}`.
- **Head:** `<html lang>` from `blog.language`; `<title>` is "page name · blog title"; `<meta name="description">` is the first 160 plain-text characters on post pages and the blog description as plain text elsewhere; `<link rel="alternate">` to both feeds; `og:title`, `og:type` (`article` for posts, `website` otherwise), `og:url` (the canonical) and `og:image` (the post's image, when set).
- **Link rule:** a mirror blog with `post_url_pattern` links posts to the pattern URL; every other blog links to `/s/{id}`. The canonical of `/s/{id}` is `ItemSerializer::url()`.
- **Errors:** 404 for an unknown blog, a post that is unknown, deleted or scheduled, or a tag with no published posts. 400 for a malformed cursor, a `page` below 1, or a `q` longer than 200 characters. Controllers throw only `NotFound` or `HttpException(400, …)`; the kernel renders them through the theme's `error/{status}` (or `error/default`) with the secureHtml headers.
- **Caching:** `Cache-Control: public, max-age=60` plus the body ETag from `conditional`. No `Last-Modified`.
- **HtmlSanitizer allowlists (spec §3.1, verbatim):**
  - Allowed elements: `p br strong em b i u s blockquote q cite code pre ul ol li a img figure figcaption h2 h3 h4 hr sup sub span`.
  - Allowed attributes: `a[href title]`, `img[src alt width height]`, `q[cite]`, `blockquote[cite]`; no `class`, `id` or `style` anywhere.
  - URLs (`href`, `src`, `cite`): entity-decode and strip whitespace and control characters before checking; allow `http:`, `https:`, `mailto:` (href only) and scheme-less relative URLs; anything else removes the attribute; an `img` without a valid `src` is removed.
  - Removed with their content: `script style iframe frame object embed form input button textarea select svg math template noscript`.
  - Also removed: comments, processing instructions and every other attribute (including `on*`). `h1` is demoted to `h2`. Unknown elements are unwrapped.
  - Every `<a>` gets `rel="nofollow noopener ugc"`; links to external hosts also get `target="_blank"`.
  - Output is serialised HTML and idempotent: `clean(clean(x)) === clean(x)`.
- **Where sanitising happens:** at render time only (`PostViewFactory`, `BlogPage::descriptionHtml()`). Stored data and the JSON and RSS output are never touched; `AthenaParityTest` must keep passing unchanged.
- **Templates:**
  - Names are `blog::<path>`; a theme overrides one at `themes/<theme>/templates/modules/blog/<path>`.
  - Every page and partial ships as both `.php` and `.twig`.
  - Every overridable partial (`partials/post`, `partials/kinds/*`, `partials/extras/*`, `partials/pager`, `partials/tagcloud`, `partials/searchform`, `blocks/*`) is pulled with `$x->render()` / Twig `render()`, never `$x->include()` or `{% include %}`, because only `render()` resolves across engines.
- **Escaping:**
  - Every value in a PHP template goes through `$x->e()`.
  - Raw output is allowed only for HTML that is already sanitised or rendered: `contentHtml`, `commentHtml`, `descriptionHtml`, a search `snippet` (built escaped by `SearchText::snippet`), and `render()` results. In Twig, `|raw` covers the same variables.
- **Engine parity:** tests compare `Xaraya\Tests\Support\Html::normalize()` output from both engines. It strips whitespace on both sides of every tag, so expected-HTML needles have no spaces next to tags (`via<a href=…`). Two adjacent outputs separated only by text must sit on one line in both engines.
- **No JavaScript, no iframes, no inline `style=` and no third-party URLs** in templates or CSS. A video is a thumbnail link to `https://www.youtube.com/watch?v={id}`.
- **Code conventions:**
  - PHP class files start with `<?php`, a blank line, then `declare(strict_types=1);`. Templates are exempt: PHPStan and php-cs-fixer skip `templates/`.
  - Blog classes are `Xaraya\Module\Blog\…` under `modules/blog/src/`; tests are `Xaraya\Tests\Module\Blog\…` under `tests/Module/Blog/`.
  - Raw SQL uses `{table}` placeholders, never a hand-written prefix, and never `LIKE` on a JSON column (Postgres `JSONB`).
- **Test classes:** extend `AppTestCase` (here through `BlogTestCase` or `BlogPageTestCase`). `tearDown()` unsets every subclass property, so test properties are never `readonly`.
- **Commits:** one per task. Every commit message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, used exactly, passed as a second `-m`. Do not push.
- **Checks before every commit:** `composer test && composer stan && composer cs`. Then run the task's tests on MySQL: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit <task test files>`. CI runs the full matrix, PHP 8.3/8.4 × sqlite/mysql/pgsql; Postgres runs only there.

## Review Focus

1. **Hostile or garbled `content_html`** (broken UTF-8, NUL bytes, thousands of nested elements, CDATA, entity-encoded or whitespace-split `javascript:`): the page still renders with valid UTF-8 and nothing executable, and sanitising finishes and stays idempotent. Tests: Task 2 (`testBrokenUtf8AndNulBytesComeBackAsValidUtf8`, `testHugeNestingIsCutAndStaysSafe`, the vectors) and Task 6 (`testGarbledContentStillGivesValidUtf8`).
2. **Tag slugs containing `%`, `+`, unicode, an encoded `/`, or bytes that are not UTF-8:** each reaches exactly its own tag after one decode; `%2F`, `%2525` and `%FF` are clean themed 404s, never a 500 from the database. Test: Task 8 `testSlugsAreDecodedOnceAndComparedExactly`.
3. **Search queries made only of punctuation or of `%`, `_`, `!`:** zero results, never "match everything", on a 200 page that says "0 results". Tests: Task 4 `testWildcardsMatchLiterallyOrNotAtAll`, Task 12 `testMissesEmptyQueriesAndPunctuation`.
4. **A pinned post that is scheduled, deleted or unknown:** the home page shows no pinned section and stays 200. Test: Task 9 `testAPinnedPostThatIsHiddenIsNotShown`.
5. **Cursor tampering** (garbage, empty, array-valued, over-long, an impossible date, a well-formed cursor dated far in the future or past): malformed cursors are 400s through the theme; a well-formed one never reveals a scheduled post, and one past the end is an empty page. Tests: Task 8 `testMalformedCursorsAre400ThroughTheTheme`, Task 9 `testCursorTampering`.

---

## File Map

```
composer.json, composer.lock                         + ext-dom
src/Kernel/Assets/AssetUrls.php                      asset URL service (from Helpers::asset)          [Task 1]
src/Kernel/Support/HtmlSanitizer.php                 DOM allowlist sanitiser                           [Task 2]
src/Kernel/App.php                                   AssetUrls and HtmlSanitizer bindings              [Tasks 1, 2]
src/Kernel/View/Helpers.php                          asset() delegates to AssetUrls                    [Task 1]
modules/blog/module.json                             subscribers, commands, blocks, blockDefaults      [Tasks 4, 5, 13]
modules/blog/migrations/2026_10_11_100003_create_post_search.php                                       [Task 4]
modules/blog/src/BlogServiceProvider.php             SearchIndex and PostViewFactory bindings          [Tasks 4, 6]
modules/blog/src/Routes.php                          the six page routes                               [Tasks 8-12]
modules/blog/src/Post/Post.php                       + updatedAt                                       [Task 6]
modules/blog/src/Post/PostRepository.php             publishedAfter, neighbours, pageByTag, findLiveByItemId, tagCounts, findMany
modules/blog/src/Search/SearchText.php               normalize, terms, body, snippet                   [Task 3]
modules/blog/src/Search/SearchIndex.php, LikeSearchIndex.php, SearchIndexer.php                        [Task 4]
modules/blog/src/Cli/BlogReindexCommand.php          xar blog:reindex                                  [Task 5]
modules/blog/src/View/PostView.php, PostViewFactory.php                                                [Task 6]
modules/blog/src/View/TagCloud.php                   five quantile steps, sorted by name               [Task 10]
modules/blog/src/Http/BlogPage.php, Paging.php       page head, blog lookup, query parsing             [Task 8]
modules/blog/src/Http/PostPageController.php         /s/{id}                                           [Task 8]
modules/blog/src/Http/BlogPagesController.php        tag listing [Task 8], home [Task 9]
modules/blog/src/Http/TagsController.php, AboutController.php, SearchController.php                    [Tasks 10-12]
modules/blog/src/Blocks/CurrentBlog.php, InfoBlock.php, TagCloudBlock.php, SearchBoxBlock.php, RecentBlock.php  [Task 13]
modules/blog/src/Cli/BlogBlocksCommand.php           xar blog:blocks                                   [Task 13]
modules/blog/templates/partials/post, kinds/{note,link,highlight,photo}, extras/{video,podcast,song,gallery,mentions,tags}  [Task 7]
modules/blog/templates/partials/pager, post, tag     [Task 8]; home [Task 9]; partials/tagcloud, tags [Task 10]
modules/blog/templates/about [Task 11]; partials/searchform, search [Task 12]; blocks/{info,tagcloud,searchbox,recent} [Task 13]
modules/blog/assets/blog.css                                                                           [Task 14]
README.md, docs/superpowers/plans/2026-10-09-phoenix-kernel-views-followups.md                         [Tasks 5, 15]
tests/Kernel/Assets/AssetUrlsTest.php, tests/Kernel/Support/HtmlSanitizerTest.php
tests/Module/Blog/BlogTestCase.php (helpers), Http/BlogPageTestCase.php, Support/PostViews.php
tests/Module/Blog/{Search,Cli,View,Http,Blocks}/…Test.php, BlogAssetsTest.php, BlogPagesSmokeTest.php
```

### Task 1: `AssetUrls` kernel service

**Files:**
- Create: `src/Kernel/Assets/AssetUrls.php`
- Modify: `src/Kernel/App.php` (one `use`, one binding), `src/Kernel/View/Helpers.php` (`asset()`)
- Test: `tests/Kernel/Assets/AssetUrlsTest.php`

**Interfaces:**
- **Consumes:** `Config::get('assets.url', '/assets')`; `Helpers::asset()` and its existing tests (`ViewTest`, `AssetPublisherTest::testAssetHelperUsesTheConfiguredUrl`).
- **Produces `Xaraya\Kernel\Assets\AssetUrls`:**
  - `__construct(string $baseUrl = '/assets')`
  - `url(string $path): string` returns `rtrim($baseUrl, '/') . '/' . ltrim($path, '/')`. It throws `InvalidArgumentException("Invalid asset path '…'")` for an empty path or any `..` segment.
- **Produces the binding:** `AssetUrls::class` → `new AssetUrls((string) $config->get('assets.url', '/assets'))`. `Helpers::asset($path)` returns `$container->get(AssetUrls::class)->url($path)`.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/Assets/AssetUrlsTest.php`:
```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Assets/AssetUrlsTest.php`
Expected: 3 errors, `Class "Xaraya\Kernel\Assets\AssetUrls" not found`.

- [ ] **Step 3: Implement**

`src/Kernel/Assets/AssetUrls.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Assets;

use InvalidArgumentException;

/** URLs of published assets: {assets.url}/{path}, e.g. /assets/module/blog/blog.css. Templates reach it as asset(). */
final class AssetUrls
{
    public function __construct(private readonly string $baseUrl = '/assets') {}

    public function url(string $path): string
    {
        $path = ltrim($path, '/');
        if ($path === '' || in_array('..', explode('/', $path), true)) {
            throw new InvalidArgumentException("Invalid asset path '{$path}'");
        }

        return rtrim($this->baseUrl, '/') . '/' . $path;
    }
}
```

In `src/Kernel/App.php`, add `use Xaraya\Kernel\Assets\AssetUrls;` after `use Xaraya\Kernel\Assets\AssetPublisher;`. Then add the binding on the line before `$c->set(AssetPublisher::class, …)`:
```php
        $c->set(AssetUrls::class, fn(): AssetUrls => new AssetUrls((string) $config->get('assets.url', '/assets')));
```

In `src/Kernel/View/Helpers.php`, add `use Xaraya\Kernel\Assets\AssetUrls;` after `use Stringable;`, and replace `asset()` with:
```php
    /** The URL of a published asset, e.g. asset('theme/phoenix/phoenix.css'). */
    public function asset(string $path): string
    {
        return $this->container->get(AssetUrls::class)->url($path);
    }
```
Keep `use InvalidArgumentException;`: `e()` still throws it.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Assets tests/Kernel/View`
Expected: PASS, including the existing `asset('../config/app.php')` rejection in `ViewTest` and `testAssetHelperUsesTheConfiguredUrl`.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (458 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Assets/AssetUrls.php src/Kernel/App.php src/Kernel/View/Helpers.php tests/Kernel/Assets/AssetUrlsTest.php
git commit -m "feat(kernel): extract AssetUrls so controllers can build asset URLs" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `HtmlSanitizer` and ext-dom

**Files:**
- Create: `src/Kernel/Support/HtmlSanitizer.php`
- Modify: `composer.json` (`"ext-dom": "*"`), `composer.lock` (content hash), `src/Kernel/App.php` (binding)
- Test: `tests/Kernel/Support/HtmlSanitizerTest.php`

**Interfaces:**
- **Consumes:** ext-dom (`DOMDocument`), `mb_scrub`.
- **Produces `Xaraya\Kernel\Support\HtmlSanitizer`:**
  - `public const MAX_DEPTH = 64`, plus `ALLOWED` (element => allowed attributes), `DROPPED` and `LINK_REL = 'nofollow noopener ugc'`.
  - `__construct(?string $siteHost = null)`: an `<a>` whose absolute href has another host (case-insensitive) gets `target="_blank"`. With no site host, every absolute link counts as external.
  - `clean(string $html): string`: the §3.1 rules. The input is scrubbed to valid UTF-8, NUL bytes are removed, and elements deeper than `MAX_DEPTH` are dropped with their content. Idempotent.
  - `static isSafeUrl(string $value, bool $allowMailto = false): bool`
- **Produces the binding:** `HtmlSanitizer::class` → `new HtmlSanitizer(<host of app.url, or null>)`.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/Support/HtmlSanitizerTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Tests\Support\AppTestCase;

final class HtmlSanitizerTest extends AppTestCase
{
    private function clean(string $html): string
    {
        return (new HtmlSanitizer('xar.test'))->clean($html);
    }

    /** @return array<string, array{string, string}> */
    public static function vectors(): array
    {
        return [
            'script' => ['<script>alert(1)</script><p>ok</p>', '<p>ok</p>'],
            'img onerror' => ['<img src="/x.png" onerror="alert(1)">', '<img src="/x.png">'],
            'javascript href' => ['<a href="javascript:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'entity-encoded javascript' => ['<a href="&#106;avascript:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'tab-split javascript' => ['<a href="java&#x09;script:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'space-led javascript' => ['<a href=" javascript:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'upper-case javascript' => ['<a href="JAVASCRIPT:alert(1)">x</a>', '<a rel="nofollow noopener ugc">x</a>'],
            'svg onload' => ['<svg onload="alert(1)"><circle/></svg>after', 'after'],
            'data href' => ['<a href="data:text/html,xx">d</a>', '<a rel="nofollow noopener ugc">d</a>'],
            'data img' => ['<img src="data:image/png;base64,AAAA"><p>z</p>', '<p>z</p>'],
            'vbscript cite' => ['<blockquote cite="vbscript:x"><p>q</p></blockquote>', '<blockquote><p>q</p></blockquote>'],
            'style, class and id' => ['<p style="color:red" class="c" id="i">s</p>', '<p>s</p>'],
            'iframe and form' => ['<iframe src="https://x.test"></iframe><form><input name="a"><button>b</button></form>', ''],
            'dropped with content' => ['<noscript><p>n</p></noscript><template><p>t</p></template><math><mi>x</mi></math><object>o</object>', ''],
            'nested and broken' => ['<p>unclosed <b>bold <i>it</p> tail', '<p>unclosed <b>bold <i>it</i></b></p> tail'],
            'stray end tags' => ['</p></div><p>stray</p>', '<p>stray</p>'],
            'comment' => ['<!-- secret --><p>c</p>', '<p>c</p>'],
            'h1 demoted' => ['<h1>T</h1>', '<h2>T</h2>'],
            'unknown unwrapped' => ['<custom-el><b>kept</b></custom-el><table><tr><td>cell</td></tr></table>', '<b>kept</b>cell'],
            'allowed attributes' => [
                '<img src="/x.png" width="10" height="20px" alt="a" title="t"><q cite="https://c.test">q</q>',
                '<img src="/x.png" width="10" alt="a"><q cite="https://c.test">q</q>',
            ],
            'escaped text stays escaped' => ['<p>a &lt;b&gt; &amp; c</p>', '<p>a &lt;b&gt; &amp; c</p>'],
            'query string ampersand' => ['<a href="/s?a=1&amp;b=2">q</a>', '<a href="/s?a=1&amp;b=2" rel="nofollow noopener ugc">q</a>'],
            'empty' => ['  ', ''],
        ];
    }

    #[DataProvider('vectors')]
    public function testVector(string $input, string $expected): void
    {
        $clean = $this->clean($input);
        self::assertSame($expected, $clean);
        self::assertSame($clean, $this->clean($clean), 'clean() must be idempotent');
    }

    public function testLinksGetRelAndExternalLinksOpenInANewTab(): void
    {
        self::assertSame(
            '<a href="https://ext.test/a" rel="nofollow noopener ugc" target="_blank">e</a>'
            . '<a href="/local" rel="nofollow noopener ugc">l</a>'
            . '<a href="mailto:a@b.test" rel="nofollow noopener ugc">m</a>'
            . '<a href="https://XAR.test/x" rel="nofollow noopener ugc">s</a>',
            $this->clean('<a href="https://ext.test/a" target="_self" rel="me">e</a><a href="/local">l</a><a href="mailto:a@b.test">m</a><a href="https://XAR.test/x">s</a>'),
        );
        self::assertSame('<img src="/x.png">', $this->clean('<img src="/x.png" href="mailto:a@b.test">'));
        self::assertSame('<blockquote>q</blockquote>', $this->clean('<blockquote cite="mailto:a@b.test">q</blockquote>'), 'mailto: is for href only');
        self::assertSame(
            '<a href="https://xar.test/" rel="nofollow noopener ugc" target="_blank">a</a>',
            (new HtmlSanitizer())->clean('<a href="https://xar.test/">a</a>'),
            'with no site host every absolute link is external',
        );
    }

    public function testUtf8RoundTrips(): void
    {
        $html = '<p>Café — naïve “quotes” 日本語 😀</p>';
        self::assertSame($html, $this->clean($html));
    }

    public function testBrokenUtf8AndNulBytesComeBackAsValidUtf8(): void
    {
        $clean = $this->clean("<p>bad \xff\xfe bytes\0 here</p>");
        self::assertTrue(mb_check_encoding($clean, 'UTF-8'));
        self::assertStringStartsWith('<p>bad ', $clean);
        self::assertStringEndsWith(' bytes here</p>', $clean);
        self::assertSame($clean, $this->clean($clean));
    }

    public function testHugeNestingIsCutAndStaysSafe(): void
    {
        $html = str_repeat('<span>', 5000) . 'deep<script>alert(1)</script>' . str_repeat('</span>', 5000) . '<p>after</p>';
        $clean = $this->clean($html);
        self::assertStringNotContainsString('script', $clean);
        self::assertLessThanOrEqual(HtmlSanitizer::MAX_DEPTH, substr_count($clean, '<span>'));
        self::assertSame($clean, $this->clean($clean));
    }

    public function testIsSafeUrl(): void
    {
        foreach (['https://a.test/x', 'http://a.test', '/root', 'relative/path', '#frag', '?q=1', '//cdn.test/x'] as $url) {
            self::assertTrue(HtmlSanitizer::isSafeUrl($url), $url);
        }
        foreach (['javascript:x', ' JavaScript:x', "java\nscript:x", 'data:x', 'vbscript:x', 'file:///etc/passwd', 'mailto:a@b.test'] as $url) {
            self::assertFalse(HtmlSanitizer::isSafeUrl($url), $url);
        }
        self::assertTrue(HtmlSanitizer::isSafeUrl('mailto:a@b.test', true));
    }

    public function testTheAppBindsItWithTheSiteHost(): void
    {
        $app = $this->boot([], ['app.url' => 'https://blog.example.test/sub']);
        self::assertSame(
            '<a href="https://BLOG.example.test/a" rel="nofollow noopener ugc">x</a>',
            $app->container()->get(HtmlSanitizer::class)->clean('<a href="https://BLOG.example.test/a">x</a>'),
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Support/HtmlSanitizerTest.php`
Expected: errors, `Class "Xaraya\Kernel\Support\HtmlSanitizer" not found`.

- [ ] **Step 3: Implement**

`src/Kernel/Support/HtmlSanitizer.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Support;

use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allowlist HTML sanitiser for untrusted fragments (blog posts, descriptions). Parses with DOMDocument as UTF-8,
 * keeps only the allowed elements and attributes, checks every URL, and serialises again. Idempotent:
 * clean(clean($x)) === clean($x).
 */
final class HtmlSanitizer
{
    /** Elements nested deeper than this are dropped with their content. */
    public const MAX_DEPTH = 64;

    /** @var array<string, list<string>> element => allowed attributes */
    public const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'u' => [], 's' => [],
        'blockquote' => ['cite'], 'q' => ['cite'], 'cite' => [], 'code' => [], 'pre' => [],
        'ul' => [], 'ol' => [], 'li' => [], 'a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'],
        'figure' => [], 'figcaption' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'hr' => [],
        'sup' => [], 'sub' => [], 'span' => [],
    ];

    /** Removed together with everything inside them. */
    public const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'object', 'embed', 'form', 'input', 'button', 'textarea',
        'select', 'svg', 'math', 'template', 'noscript',
    ];

    private const URL_ATTRIBUTES = ['href', 'src', 'cite'];

    public const LINK_REL = 'nofollow noopener ugc';

    /** @param ?string $siteHost links to any other host open in a new tab */
    public function __construct(private readonly ?string $siteHost = null) {}

    public function clean(string $html): string
    {
        $html = str_replace("\0", '', mb_scrub($html, 'UTF-8'));
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            // The XML declaration makes libxml read the fragment as UTF-8 instead of Latin-1.
            $doc->loadHTML('<?xml encoding="UTF-8"?><html><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }
        $this->children($body, 0);
        $out = '';
        foreach ($body->childNodes as $node) {
            $out .= (string) $doc->saveHTML($node);
        }

        return $out;
    }

    /** True for http:, https:, mailto: (when allowed) and scheme-less URLs, after decoding entities and removing whitespace and control characters. */
    public static function isSafeUrl(string $value, bool $allowMailto = false): bool
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $check = (string) preg_replace('/[\s\x00-\x1f\x7f]+/u', '', $decoded);
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $check, $m) !== 1) {
            return true;
        }
        $scheme = strtolower($m[1]);

        return $scheme === 'http' || $scheme === 'https' || ($allowMailto && $scheme === 'mailto');
    }

    private function children(DOMNode $parent, int $depth): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            $this->node($node, $depth);
        }
    }

    private function node(DOMNode $node, int $depth): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }
        if ($node instanceof DOMCdataSection) {
            $parent->replaceChild(new DOMText($node->data), $node);

            return;
        }
        if ($node instanceof DOMText) {
            return;
        }
        if (!$node instanceof DOMElement) {
            $parent->removeChild($node); // comments, processing instructions

            return;
        }
        $name = strtolower($node->nodeName);
        if (in_array($name, self::DROPPED, true) || $depth >= self::MAX_DEPTH) {
            $parent->removeChild($node);

            return;
        }
        $this->children($node, $depth + 1);
        if ($name === 'h1') {
            $node = $this->rename($node, 'h2');
            $name = 'h2';
        }
        if (!array_key_exists($name, self::ALLOWED)) {
            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);

            return;
        }
        $this->attributes($node, self::ALLOWED[$name]);
        if ($name === 'img' && !$node->hasAttribute('src')) {
            $parent->removeChild($node);
        } elseif ($name === 'a') {
            $this->link($node);
        }
    }

    private function rename(DOMElement $node, string $name): DOMElement
    {
        $renamed = new DOMElement($name);
        $node->parentNode?->replaceChild($renamed, $node);
        while ($node->firstChild !== null) {
            $renamed->appendChild($node->firstChild);
        }

        return $renamed;
    }

    /** @param list<string> $allowed */
    private function attributes(DOMElement $node, array $allowed): void
    {
        $names = [];
        foreach ($node->attributes as $attribute) {
            $names[] = $attribute->nodeName;
        }
        foreach ($names as $attribute) {
            $key = strtolower($attribute);
            $value = $node->getAttribute($attribute);
            $keep = in_array($key, $allowed, true) && match (true) {
                in_array($key, self::URL_ATTRIBUTES, true) => self::isSafeUrl($value, $key === 'href'),
                $key === 'width', $key === 'height' => preg_match('/^\d{1,5}$/D', $value) === 1,
                default => true,
            };
            if (!$keep) {
                $node->removeAttribute($attribute);
            }
        }
    }

    private function link(DOMElement $node): void
    {
        $node->setAttribute('rel', self::LINK_REL);
        $host = $node->hasAttribute('href') ? parse_url($node->getAttribute('href'), PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '' && ($this->siteHost === null || strcasecmp($host, $this->siteHost) !== 0)) {
            $node->setAttribute('target', '_blank');
        }
    }
}
```

In `src/Kernel/App.php`, add `use Xaraya\Kernel\Support\HtmlSanitizer;` after `use Xaraya\Kernel\Routing\UrlGenerator;`, and bind it right after the `Cache::class` binding:
```php
        $c->set(HtmlSanitizer::class, static function () use ($config): HtmlSanitizer {
            $host = parse_url((string) $config->get('app.url', ''), PHP_URL_HOST);

            return new HtmlSanitizer(is_string($host) && $host !== '' ? $host : null);
        });
```

In `composer.json`'s `require`, add `"ext-dom": "*",` between `"php": ">=8.3",` and `"ext-fileinfo": "*",`. Then run `composer update --lock`. It only refreshes `composer.lock`'s `content-hash` and its `platform` list, and installs nothing.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Support/HtmlSanitizerTest.php && composer validate`
Expected: PASS (29 tests), then `./composer.json is valid`.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock src/Kernel/Support/HtmlSanitizer.php src/Kernel/App.php tests/Kernel/Support/HtmlSanitizerTest.php
git commit -m "feat(kernel): add the DOM allowlist HtmlSanitizer and require ext-dom" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `SearchText`: normalisation, terms, index body and snippets

**Files:**
- Create: `modules/blog/src/Search/SearchText.php`
- Test: `tests/Module/Blog/Search/SearchTextTest.php`

**Interfaces:**
- **Consumes:** `Post` (`title`, `contentHtml`, `externalUrl`, `doc`, `tags`), `Support\Text::plain()`.
- **Produces `Xaraya\Module\Blog\Search\SearchText`** (all static):
  - `MAX_TERMS = 8`, `MIN_TERM_LENGTH = 2`
  - `normalize(string $text): string`: lower-case ASCII letters and digits, separated by single spaces.
  - `terms(string $query): list<string>`: distinct normalised terms of at least 2 characters, at most 8, in query order.
  - `body(Post $post): string`: the normalised text of the title, plain content, quote, plain comment, tag names, `_source.name`, the external URL's host, podcast show and episode, and song channel.
  - `snippet(string $plain, list<string> $terms, int $width = 160): string`: HTML. A window of about `$width` characters around the first match, cut at word boundaries with `…`, escaped, with every match wrapped in `<mark>`.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Search/SearchTextTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Search;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Search\SearchText;

final class SearchTextTest extends TestCase
{
    public function testNormalizeFoldsCaseAccentsAndPunctuation(): void
    {
        self::assertSame('creme brulee', SearchText::normalize('  Crème   Brûlée!! '));
        self::assertSame('strasse aesir', SearchText::normalize('Straße — Æsir'));
        self::assertSame('100 cotton x', SearchText::normalize('100% _cotton_ !x'));
        self::assertSame('text', SearchText::normalize('日本語 text'), 'scripts with no ASCII form are dropped');
        self::assertSame('', SearchText::normalize('%_!'));
        self::assertSame('bad utf', SearchText::normalize("bad \xff utf"));
    }

    public function testTermsAreDistinctAtLeastTwoCharactersAndAtMostEight(): void
    {
        self::assertSame(['red', 'apple'], SearchText::terms('Red a APPLE red'));
        self::assertSame([], SearchText::terms('% _ ! a'));
        self::assertSame(['cd', 'ef', 'gh', 'ij', 'kl', 'mn', 'op', 'qr'], SearchText::terms('a cd ef gh ij kl mn op qr st uv'));
    }

    public function testSnippetCentresOnTheFirstMatchAndMarksEveryMatch(): void
    {
        $plain = str_repeat('lorem ipsum dolor ', 20) . 'the Crème brûlée recipe ' . str_repeat('sit amet consectetur ', 20);
        $snippet = SearchText::snippet($plain, SearchText::terms('creme BRULEE'));
        self::assertStringStartsWith('…', $snippet);
        self::assertStringEndsWith('…', $snippet);
        self::assertStringContainsString('the <mark>Crème</mark> <mark>brûlée</mark> recipe', $snippet);
        $text = strip_tags($snippet);
        self::assertLessThanOrEqual(162, mb_strlen($text), '160 characters plus the two ellipses');
        self::assertStringStartsWith('…ipsum', $text, 'cut at a word boundary');
        self::assertStringEndsWith(' sit…', $text);
    }

    public function testSnippetWithoutAMatchStartsAtTheBeginning(): void
    {
        self::assertSame('short text', SearchText::snippet('short text', ['zz']));
        self::assertSame('', SearchText::snippet('', ['zz']));
        self::assertSame(str_repeat('word ', 31) . 'word…', SearchText::snippet(str_repeat('word ', 60), ['zz']));
    }

    public function testSnippetEscapesHostileText(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(&quot;<mark>Café</mark>&quot;)&lt;/script&gt; &amp; a <mark>cafe</mark>',
            SearchText::snippet('<script>alert("Café")</script> & a cafe', ['cafe']),
        );
    }

    public function testBodyCollectsEverySearchableField(): void
    {
        $post = Post::fromRow([
            'id' => '01m3t19wn8reaww81zg1m47tjq',
            'blog_id' => 1,
            'item_id' => 'x',
            'kind' => 'link',
            'title' => 'The Title',
            'external_url' => 'https://www.Example.test/path',
            'content_html' => '<p>Body <b>text</b></p>',
            'date_published' => '2026-10-01 12:00:00',
            'status' => 'published',
            'doc' => json_encode([
                '_source' => ['name' => 'Source Name'],
                '_athenana' => [
                    'quote' => 'A quote',
                    'comment' => '<p>My comment</p>',
                    'podcast' => ['show' => 'Show', 'episode' => 'Episode'],
                    'song' => ['channel' => 'Channel'],
                ],
            ]),
        ], [['name' => 'Tag Name', 'slug' => 'tag-name']]);
        self::assertSame(
            'the title body text a quote my comment tag name source name www example test show episode channel',
            SearchText::body($post),
        );
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Search/SearchTextTest.php`
Expected: errors, `Class "Xaraya\Module\Blog\Search\SearchText" not found`.

- [ ] **Step 3: Implement**

The fold works one character at a time and records which source character each normalised byte came from. That map is what lets `snippet()` find a match in normalised text and highlight the same characters in the original.

`modules/blog/src/Search/SearchText.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use Normalizer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Support\Text;

/**
 * Search normalisation: lower-case ASCII letters and digits separated by single spaces. The same per-character
 * fold builds the index body, the query terms and the snippet highlighting, so the three always agree.
 */
final class SearchText
{
    public const MAX_TERMS = 8;
    public const MIN_TERM_LENGTH = 2;

    /** @var array<string, string> folded forms of multi-byte characters */
    private static array $folds = [];

    public static function normalize(string $text): string
    {
        return self::fold($text)[0];
    }

    /** @return list<string> distinct terms of at least 2 characters, at most 8, in query order */
    public static function terms(string $query): array
    {
        $terms = [];
        foreach (explode(' ', self::normalize($query)) as $term) {
            if (strlen($term) >= self::MIN_TERM_LENGTH && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /** The normalised text a post is found by. */
    public static function body(Post $post): string
    {
        $doc = $post->doc;
        $athenana = is_array($doc['_athenana'] ?? null) ? $doc['_athenana'] : [];
        $parts = [$post->title, Text::plain($post->contentHtml ?? '')];
        $parts[] = self::string($athenana, 'quote');
        $parts[] = Text::plain(self::string($athenana, 'comment'));
        foreach ($post->tags as $tag) {
            $parts[] = $tag['name'];
        }
        $source = is_array($doc['_source'] ?? null) ? $doc['_source'] : [];
        $parts[] = self::string($source, 'name');
        $host = $post->externalUrl === null ? null : parse_url($post->externalUrl, PHP_URL_HOST);
        $parts[] = is_string($host) ? $host : '';
        $podcast = is_array($athenana['podcast'] ?? null) ? $athenana['podcast'] : [];
        $parts[] = self::string($podcast, 'show');
        $parts[] = self::string($podcast, 'episode');
        $song = is_array($athenana['song'] ?? null) ? $athenana['song'] : [];
        $parts[] = self::string($song, 'channel');

        return self::normalize(implode(' ', $parts));
    }

    /**
     * A plain-text window of about $width characters around the first match, cut at word boundaries with "…",
     * HTML-escaped, with every term match wrapped in <mark>. Returns HTML.
     *
     * @param list<string> $terms normalised terms, as terms() returns them
     */
    public static function snippet(string $plain, array $terms, int $width = 160): string
    {
        $plain = mb_scrub($plain, 'UTF-8');
        $chars = mb_str_split($plain);
        $count = count($chars);
        $width = max(1, $width);
        [$normal, $map] = self::fold($plain);
        $first = null;
        foreach ($terms as $term) {
            $pos = $term === '' ? false : strpos($normal, $term);
            if ($pos !== false && ($first === null || $map[$pos] < $first)) {
                $first = $map[$pos];
            }
        }
        $start = $first === null ? 0 : max(0, $first - intdiv($width, 3));
        $end = min($count, $start + $width);
        $start = max(0, min($start, $end - $width));
        if ($start > 0) {
            // Begin at a word start, but never after the match.
            $limit = $first ?? $end;
            while ($start < $limit && !self::isSpace($chars[$start - 1])) {
                $start++;
            }
        }
        if ($end < $count && !self::isSpace($chars[$end])) {
            // End at a word end, but never inside the match.
            for ($k = $end - 1; $k > ($first ?? $start); $k--) {
                if (self::isSpace($chars[$k])) {
                    $end = $k;
                    break;
                }
            }
        }
        $cutStart = $start > 0;
        $cutEnd = $end < $count;
        while ($start < $end && self::isSpace($chars[$start])) {
            $start++;
        }
        while ($end > $start && self::isSpace($chars[$end - 1])) {
            $end--;
        }
        $window = array_slice($chars, $start, $end - $start);
        [$windowNormal, $windowMap] = self::fold(implode('', $window));
        $marked = array_fill(0, count($window), false);
        foreach ($terms as $term) {
            $offset = 0;
            while ($term !== '' && ($pos = strpos($windowNormal, $term, $offset)) !== false) {
                for ($k = $windowMap[$pos]; $k <= $windowMap[$pos + strlen($term) - 1]; $k++) {
                    $marked[$k] = true;
                }
                $offset = $pos + 1;
            }
        }
        $html = $cutStart ? '…' : '';
        $open = false;
        foreach ($window as $k => $char) {
            if ($marked[$k] !== $open) {
                $html .= $open ? '</mark>' : '<mark>';
                $open = !$open;
            }
            $html .= htmlspecialchars($char, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $html . ($open ? '</mark>' : '') . ($cutEnd ? '…' : '');
    }

    /**
     * Folds $text character by character and collapses spaces.
     *
     * @return array{0: string, 1: list<int>} the normalised text, and for each of its bytes the index of the
     *                                        source character it came from
     */
    private static function fold(string $text): array
    {
        $out = '';
        $map = [];
        $space = true; // true drops leading spaces
        foreach (mb_str_split(mb_scrub($text, 'UTF-8')) as $index => $char) {
            foreach (str_split(self::foldChar($char)) as $byte) {
                if ($byte === ' ') {
                    if ($space) {
                        continue;
                    }
                    $space = true;
                } else {
                    $space = false;
                }
                $out .= $byte;
                $map[] = $index;
            }
        }
        if ($space && $out !== '') {
            $out = substr($out, 0, -1);
            array_pop($map);
        }

        return [$out, $map];
    }

    /** One character as lower-case ASCII letters and digits: "É" => "e", "ß" => "ss", "-" => " ", "日" => "". */
    private static function foldChar(string $char): string
    {
        if (strlen($char) === 1) {
            $lower = strtolower($char);

            return ctype_alnum($lower) ? $lower : ' ';
        }
        if (isset(self::$folds[$char])) {
            return self::$folds[$char];
        }
        $ascii = mb_strtolower($char, 'UTF-8');
        if (class_exists(Normalizer::class)) {
            // Accents first: macOS iconv transliterates "é" as "'e".
            $decomposed = Normalizer::normalize($ascii, Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $ascii = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        }
        if (preg_match('/[^\x00-\x7f]/', $ascii) === 1) {
            $translit = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii);
            $ascii = is_string($translit) ? $translit : '';
        }

        return self::$folds[$char] = (string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii));
    }

    private static function isSpace(string $char): bool
    {
        return preg_match('/^\s$/u', $char) === 1;
    }

    /** @param array<mixed> $data */
    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/Search/SearchTextTest.php`
Expected: PASS (6 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Search/SearchText.php tests/Module/Blog/Search/SearchTextTest.php
git commit -m "feat(blog): add SearchText normalisation, terms and highlighted snippets" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Search index: `xar_post_search`, `LikeSearchIndex` and the subscribers

**Files:**
- Create: `modules/blog/migrations/2026_10_11_100003_create_post_search.php`, `modules/blog/src/Search/SearchIndex.php`, `modules/blog/src/Search/LikeSearchIndex.php`, `modules/blog/src/Search/SearchIndexer.php`
- Modify: `modules/blog/module.json` (`subscribers`), `modules/blog/src/BlogServiceProvider.php` (binding), `tests/Module/Blog/BlogTestCase.php` (shared helpers)
- Test: `tests/Module/Blog/Search/LikeSearchIndexTest.php`

**Interfaces:**
- **Consumes:**
  - `SearchText::terms()` and `SearchText::body()` (Task 3).
  - `Connection::upsert/delete/fetchValue/fetchAll`, and `{table}` expansion in raw SQL.
  - `PostRepository::find()` and `PostRepository::tombstone()`.
  - The kernel events `ItemCreated`, `ItemUpdated` and `ItemDeleted` (`module`, `itemtype`, `id`), dispatched by `Syncer` after each page's transaction.
- **Produces the table `post_search`** (`xar_post_search`): `post_id` ULID primary key and foreign key to `posts` with cascade, `blog_id` int, `body` text, and an index on `blog_id`.
- **Produces `interface Xaraya\Module\Blog\Search\SearchIndex`:**
  - `index(Post $post): void`. A post that is not published is removed instead.
  - `remove(string $postId): void`
  - `clear(int $blogId): void`
  - `search(int $blogId, string $query, int $limit, int $offset): array{total: int, ids: list<string>}`. Live posts only, newest first by `(date_published, id)`.
- **Produces `LikeSearchIndex implements SearchIndex`** (`__construct(Connection $db)`), with `static escapeLike(string $value): string`.
- **Produces `SearchIndexer`** (invokable on `ItemEvent`; `module === 'blog'` and `itemtype === 'post'` only). It logs failures and never throws, so a sync never stops for the index.
- **Produces the binding:** `SearchIndex::class` → `LikeSearchIndex`.
- **Produces these `BlogTestCase` helpers, used by every later test:**
  - `nativeBlog(App $app, array $fields = []): Blog`: handle `notes`, title `Notes`, author `Ada`, description `<p>Short <b>notes</b> &amp; links.</p>`.
  - `addPost(App $app, Blog $blog, PostRecord $record): string`: saves the record and dispatches `ItemCreated`, as sync does.
  - `static postRecord(int $n, array $o = []): PostRecord`: "Post {n}", item id `item-{n}`, body `<p>Body {n}</p>`, published `{n}` hours before 2026-10-01 12:00 UTC. The keys `date`, `itemId`, `kind`, `title`, `url`, `externalUrl`, `content`, `image`, `doc` and `tags` override those values.

- [ ] **Step 1: Add the test helpers and write the failing test**

Replace `tests/Module/Blog/BlogTestCase.php` with:

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
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRecord;
use Xaraya\Module\Blog\Post\PostRepository;
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

    /**
     * Creates a native blog, "notes" unless $fields says otherwise.
     *
     * @param array<string, mixed> $fields
     */
    protected function nativeBlog(App $app, array $fields = []): Blog
    {
        return $this->blogs($app)->create([
            'handle' => 'notes',
            'mode' => 'native',
            'title' => 'Notes',
            'author_name' => 'Ada',
            'description_html' => '<p>Short <b>notes</b> &amp; links.</p>',
            ...$fields,
        ], $this->now());
    }

    /** Saves a post and dispatches ItemCreated, as sync does; returns the post id. */
    protected function addPost(App $app, Blog $blog, PostRecord $record): string
    {
        $id = $app->container()->get(PostRepository::class)->save($blog->id, $record, $this->now())->id;
        $app->container()->get(EventDispatcher::class)->dispatch(
            new ItemCreated('blog', 'post', $id, ['blog' => $blog->handle, 'item_id' => $record->itemId]),
        );

        return $id;
    }

    /**
     * Post $n of a test blog: "Post {n}", item id "item-{n}", published {n} hours before 1 Oct 2026 12:00 UTC.
     * Keys of $o: date, itemId, kind, title, url, externalUrl, content, image, doc, tags.
     *
     * @param array<string, mixed> $o
     */
    protected static function postRecord(int $n, array $o = []): PostRecord
    {
        $date = $o['date'] ?? (new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC')))->modify("-{$n} hours");
        /** @var list<array{name: string, slug: string}> $tags */
        $tags = $o['tags'] ?? [];
        /** @var array<string, mixed> $doc */
        $doc = $o['doc'] ?? [];

        return new PostRecord(
            (string) ($o['itemId'] ?? "item-{$n}"),
            null,
            (string) ($o['kind'] ?? 'note'),
            (string) ($o['title'] ?? "Post {$n}"),
            isset($o['url']) ? (string) $o['url'] : null,
            isset($o['externalUrl']) ? (string) $o['externalUrl'] : null,
            array_key_exists('content', $o) ? ($o['content'] === null ? null : (string) $o['content']) : "<p>Body {$n}</p>",
            isset($o['image']) ? (string) $o['image'] : null,
            $date instanceof DateTimeImmutable ? $date : new DateTimeImmutable((string) $date, new DateTimeZone('UTC')),
            null,
            $doc,
            $tags,
            null,
        );
    }
}
```

`tests/Module/Blog/Search/LikeSearchIndexTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Search;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Events\EventDispatcher;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\LikeSearchIndex;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class LikeSearchIndexTest extends BlogTestCase
{
    private App $app;

    private Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = $this->enableBlog();
        $this->blog = $this->nativeBlog($this->app);
    }

    private function index(): SearchIndex
    {
        return $this->app->container()->get(SearchIndex::class);
    }

    /** @return list<string> titles of the matching posts, newest first */
    private function titles(string $query, int $limit = 20, int $offset = 0): array
    {
        $ids = $this->index()->search($this->blog->id, $query, $limit, $offset)['ids'];

        return array_map(static fn($post): string => $post->title, array_map(
            fn(string $id) => $this->app->container()->get(PostRepository::class)->find($id) ?? self::fail("no post {$id}"),
            $ids,
        ));
    }

    public function testTheBindingIsTheLikeIndex(): void
    {
        self::assertInstanceOf(LikeSearchIndex::class, $this->index());
    }

    public function testEveryTermMustMatchAndNewestComesFirst(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => 'Red apple']));
        $this->addPost($this->app, $this->blog, self::postRecord(2, ['title' => 'Green apple']));
        $this->addPost($this->app, $this->blog, self::postRecord(3, ['title' => 'Red car', 'content' => '<p>Fast</p>']));
        self::assertSame(['Red apple'], $this->titles('red APPLE'));
        self::assertSame(['Red apple', 'Green apple'], $this->titles('apple'));
        self::assertSame(['Red car'], $this->titles('fast'), 'content is indexed');
        self::assertSame([], $this->titles('red banana'));
        self::assertSame(['total' => 2, 'ids' => []], $this->index()->search($this->blog->id, 'apple', 0, 0));
        self::assertSame(['Green apple'], $this->titles('apple', 1, 1));
    }

    public function testAccentsAndCaseAreFolded(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => 'Crème brûlée']));
        self::assertSame(['Crème brûlée'], $this->titles('CREME brulee'));
        self::assertSame(['Crème brûlée'], $this->titles('crème'));
    }

    public function testWildcardsMatchLiterallyOrNotAtAll(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => '100% cotton']));
        $this->addPost($this->app, $this->blog, self::postRecord(2, ['title' => 'Plain']));
        self::assertSame(['100% cotton'], $this->titles('100%'));
        foreach (['%', '_', '%_!', '!!', '% %', '__a'] as $query) {
            self::assertSame(['total' => 0, 'ids' => []], $this->index()->search($this->blog->id, $query, 20, 0), "'{$query}' has no usable terms");
        }
        self::assertSame('100!%!_a!!b', LikeSearchIndex::escapeLike('100%_a!b'));
    }

    public function testHiddenPostsAndOtherBlogsAreExcluded(): void
    {
        $this->addPost($this->app, $this->blog, self::postRecord(1, ['title' => 'Visible match']));
        $this->addPost($this->app, $this->blog, self::postRecord(2, ['title' => 'Scheduled match', 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($this->app, $this->blog, self::postRecord(3, ['title' => 'Deleted match']));
        $other = $this->nativeBlog($this->app, ['handle' => 'other']);
        $this->addPost($this->app, $other, self::postRecord(4, ['title' => 'Other match']));
        $posts = $this->app->container()->get(PostRepository::class);
        foreach ($posts->tombstone($this->blog->id, ['item-3'], $this->now()) as $id) {
            $this->app->container()->get(EventDispatcher::class)->dispatch(new ItemDeleted('blog', 'post', $id, ['blog' => 'notes']));
        }
        self::assertSame(['Visible match'], $this->titles('match'));
        self::assertSame(1, $this->index()->search($this->blog->id, 'match', 20, 0)['total']);
        self::assertSame(3, $this->db($this->app)->select('post_search')->count(), 'the deleted post left the index; the scheduled one waits in it');
    }

    public function testSyncKeepsTheIndexCurrent(): void
    {
        $mirror = $this->blogs($this->app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena',
        ], $this->now());
        $this->app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(3))));
        $this->app->container()->get(Syncer::class)->sync($mirror, true, $this->now());
        $found = $this->index()->search($mirror->id, 'body t1', 20, 0); // tag t1 is on post 1 only
        self::assertSame(1, $found['total']);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Search/LikeSearchIndexTest.php`
Expected: errors. The container cannot build the `SearchIndex` interface, and the `post_search` table does not exist.

- [ ] **Step 3: Implement**

`modules/blog/migrations/2026_10_11_100003_create_post_search.php`:
```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('post_search', function (Blueprint $t): void {
            $t->ulid('post_id')->primary();
            $t->int('blog_id');
            $t->text('body');
            $t->foreign('post_id', 'posts');
            $t->index('blog_id');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('post_search');
    }
};
```

`modules/blog/src/Search/SearchIndex.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use Xaraya\Module\Blog\Post\Post;

/** Full-text search over a blog's live posts. LikeSearchIndex is the portable binding. */
interface SearchIndex
{
    /** Adds or replaces the post's entry; a post that is not published is removed instead. */
    public function index(Post $post): void;

    public function remove(string $postId): void;

    /** Removes every entry of the blog (blog:reindex rebuilds it). */
    public function clear(int $blogId): void;

    /** @return array{total: int, ids: list<string>} live posts matching every term, newest first */
    public function search(int $blogId, string $query, int $limit, int $offset): array;
}
```

`modules/blog/src/Search/LikeSearchIndex.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\Post\Post;

/**
 * Search on any database: xar_post_search holds each post's normalised text, and a query ANDs one
 * `body LIKE '%term%'` per term. Terms are normalised first, so they never hold % or _; escaping them is defence
 * in depth.
 */
final class LikeSearchIndex implements SearchIndex
{
    public function __construct(private readonly Connection $db) {}

    public function index(Post $post): void
    {
        if ($post->status !== 'published') {
            $this->remove($post->id);

            return;
        }
        $this->db->upsert('post_search', ['post_id' => $post->id, 'blog_id' => $post->blogId, 'body' => SearchText::body($post)], ['post_id']);
    }

    public function remove(string $postId): void
    {
        $this->db->delete('post_search', ['post_id' => $postId]);
    }

    public function clear(int $blogId): void
    {
        $this->db->delete('post_search', ['blog_id' => $blogId]);
    }

    public function search(int $blogId, string $query, int $limit, int $offset): array
    {
        $terms = SearchText::terms($query);
        if ($terms === []) {
            return ['total' => 0, 'ids' => []];
        }
        $where = ['s.blog_id = ?', 'p.blog_id = ?', 'p.status = ?', 'p.date_published <= ?'];
        $params = [$blogId, $blogId, 'published', new DateTimeImmutable('now', new DateTimeZone('UTC'))];
        foreach ($terms as $term) {
            $where[] = "s.body LIKE ? ESCAPE '!'";
            $params[] = '%' . self::escapeLike($term) . '%';
        }
        $from = 'FROM {post_search} s JOIN {posts} p ON p.id = s.post_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) {$from}", $params);
        $rows = $this->db->fetchAll(
            "SELECT p.id AS id {$from} ORDER BY p.date_published DESC, p.id DESC LIMIT " . max(0, $limit) . ' OFFSET ' . max(0, $offset),
            $params,
        );

        return ['total' => $total, 'ids' => array_map(static fn(array $row): string => (string) $row['id'], $rows)];
    }

    /** Escapes LIKE wildcards with "!", the ESCAPE character every supported database accepts. */
    public static function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }
}
```

`modules/blog/src/Search/SearchIndexer.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Search;

use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Events\ItemCreated;
use Xaraya\Kernel\Events\ItemDeleted;
use Xaraya\Kernel\Events\ItemEvent;
use Xaraya\Kernel\Events\ItemUpdated;
use Xaraya\Module\Blog\Post\PostRepository;

/** Keeps the search index current: subscribed to ItemCreated, ItemUpdated and ItemDeleted (module.json). */
final class SearchIndexer
{
    public function __construct(
        private readonly SearchIndex $index,
        private readonly PostRepository $posts,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ItemEvent $event): void
    {
        if ($event->module !== 'blog' || $event->itemtype !== 'post') {
            return;
        }
        try {
            if ($event instanceof ItemDeleted) {
                $this->index->remove($event->id);
            } elseif ($event instanceof ItemCreated || $event instanceof ItemUpdated) {
                $post = $this->posts->find($event->id);
                $post === null ? $this->index->remove($event->id) : $this->index->index($post);
            }
        } catch (Throwable $e) {
            // A failing index must never stop a sync; blog:reindex repairs it.
            $this->logger->error('Search index update for post {id} failed: {reason}', ['id' => $event->id, 'reason' => $e->getMessage(), 'exception' => $e]);
        }
    }
}
```

Replace `modules/blog/module.json` with:

`modules/blog/module.json`:
```json
{
  "name": "blog",
  "version": "0.1.0",
  "content": true,
  "requires": { "kernel": "^0.1", "modules": {} },
  "provider": "Xaraya\\Module\\Blog\\BlogServiceProvider",
  "routes": "Xaraya\\Module\\Blog\\Routes",
  "migrations": "migrations",
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" },
    { "event": "Xaraya\\Kernel\\Events\\ItemUpdated", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" },
    { "event": "Xaraya\\Kernel\\Events\\ItemDeleted", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" }
  ],
  "commands": [
    "Xaraya\\Module\\Blog\\Cli\\BlogCreateCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogListCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogModeCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogSyncCommand"
  ]
}
```

Replace `modules/blog/src/BlogServiceProvider.php` with:

`modules/blog/src/BlogServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Psr\Log\LoggerInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Search\LikeSearchIndex;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\StreamFetcher;
use Xaraya\Module\Blog\Sync\SyncLock;

final class BlogServiceProvider implements ServiceProvider
{
    /** Kept in sync with modules/blog/module.json (asserted by StreamFetcherTest). */
    public const VERSION = '0.1.0';

    public function register(Container $container): void
    {
        $container->set(HttpFetcher::class, static fn(Container $c): HttpFetcher => new StreamFetcher(
            allowPrivateHosts: (bool) $c->get(Config::class)->get('blog.fetch_allow_private', false),
        ));
        $container->set(SyncLock::class, static fn(Container $c): SyncLock => new SyncLock(
            (string) $c->get(Config::class)->get('blog.locks', $c->get(App::class)->path('var/locks')),
        ));
        $container->set(MediaStore::class, static fn(Container $c): MediaStore => new MediaStore(
            $c->get(Connection::class),
            $c->get(HttpFetcher::class),
            $c->get(LoggerInterface::class),
            (string) $c->get(Config::class)->get('blog.uploads', $c->get(App::class)->path('var/uploads')),
        ));
        $container->set(SearchIndex::class, static fn(Container $c): SearchIndex => $c->get(LikeSearchIndex::class));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/Search`
Expected: PASS (12 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL run of `tests/Module/Blog/Search`.
Expected: all green. `AthenaParityTest` still passes: the subscribers only write `post_search`.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/migrations/2026_10_11_100003_create_post_search.php modules/blog/src/Search modules/blog/module.json modules/blog/src/BlogServiceProvider.php tests/Module/Blog/BlogTestCase.php tests/Module/Blog/Search/LikeSearchIndexTest.php
git commit -m "feat(blog): add the portable LIKE search index, kept current by item events" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `xar blog:reindex`

**Files:**
- Create: `modules/blog/src/Cli/BlogReindexCommand.php`
- Modify: `modules/blog/src/Post/PostRepository.php` (`publishedAfter`), `modules/blog/module.json` (command), `README.md`
- Test: `tests/Module/Blog/Cli/BlogReindexCommandTest.php`

**Interfaces:**
- **Consumes:** `SearchIndex::clear/index/search` (Task 4), `Connection::transaction()`, `BlogRepository::find/all`, and the `BlogTestCase` helpers (Task 4).
- **Produces `PostRepository::publishedAfter(int $blogId, ?string $afterId, int $limit): list<Post>`:** published posts, scheduled ones included, in id order after `$afterId`.
- **Produces the CLI command:** `xar blog:reindex <handle>|--all`. It rebuilds each blog's index inside one transaction and prints `"{handle}: indexed {n} post(s)"` per blog. With no argument it prints the usage line and exits 1; an unknown handle exits 1 with `Unknown blog '…'`.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Cli/BlogReindexCommandTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Cli;

use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\FeedFactory;

final class BlogReindexCommandTest extends BlogTestCase
{
    public function testRebuildsOneBlogOrAll(): void
    {
        $app = $this->enableBlog();
        $mirror = $this->blogs($app)->create([
            'handle' => 'src', 'mode' => 'mirror', 'source_url' => 'https://src.test/blog/src/feed.json', 'source_format' => 'athena',
        ], $this->now());
        $app->container()->instance(HttpFetcher::class, FeedFactory::fetcher(FeedFactory::pages(FeedFactory::items(250))));
        $app->container()->get(Syncer::class)->sync($mirror, true, $this->now());
        $notes = $this->nativeBlog($app);
        $this->addPost($app, $notes, self::postRecord(1, ['title' => 'Scheduled', 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $notes, self::postRecord(2, ['title' => 'Deleted']));
        $app->container()->get(PostRepository::class)->tombstone($notes->id, ['item-2'], $this->now());

        // An install from before the index existed: the table is empty.
        $this->db($app)->pdo()->exec('DELETE FROM ' . $this->db($app)->table('post_search'));
        self::assertSame(0, $app->container()->get(SearchIndex::class)->search($mirror->id, 'post', 20, 0)['total']);

        [$code, $out, $err] = $this->xar(['blog:reindex', 'src'], $app);
        self::assertSame(0, $code, $err);
        self::assertSame("src: indexed 250 post(s)\n", $out);
        self::assertSame(250, $app->container()->get(SearchIndex::class)->search($mirror->id, 'post', 20, 0)['total']);

        [$code, $out] = $this->xar(['blog:reindex', '--all'], $app);
        self::assertSame(0, $code);
        self::assertSame("notes: indexed 1 post(s)\nsrc: indexed 250 post(s)\n", $out, 'the scheduled post is indexed (search hides it until it is due); the deleted one is not');
        self::assertSame(251, $this->db($app)->select('post_search')->count());
    }

    public function testErrors(): void
    {
        $app = $this->enableBlog();
        [$code, , $err] = $this->xar(['blog:reindex'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString('Usage: xar blog:reindex <handle>|--all', $err);

        [$code, , $err] = $this->xar(['blog:reindex', 'nope'], $app);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown blog 'nope'", $err);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Cli/BlogReindexCommandTest.php`
Expected: FAIL. `blog:reindex` is an unknown command (exit code 1).

- [ ] **Step 3: Implement**

`modules/blog/src/Cli/BlogReindexCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use InvalidArgumentException;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;

final class BlogReindexCommand extends Command
{
    private const CHUNK = 200;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly SearchIndex $index,
        private readonly Connection $db,
    ) {}

    public function name(): string
    {
        return 'blog:reindex';
    }

    public function description(): string
    {
        return 'Rebuild the search index of one blog or of every blog';
    }

    public function usage(): string
    {
        return 'blog:reindex <handle>|--all';
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
            : $this->blogs->all();
        foreach ($targets as $blog) {
            // One transaction per blog: searches keep seeing the old index until the new one is complete.
            $count = $this->db->transaction(function () use ($blog): int {
                $this->index->clear($blog->id);
                $count = 0;
                $after = null;
                while (($chunk = $this->posts->publishedAfter($blog->id, $after, self::CHUNK)) !== []) {
                    foreach ($chunk as $post) {
                        $this->index->index($post);
                        $count++;
                    }
                    $after = $chunk[count($chunk) - 1]->id;
                }

                return $count;
            });
            $output->line("{$blog->handle}: indexed {$count} post(s)");
        }

        return 0;
    }
}
```

In `modules/blog/src/Post/PostRepository.php`, add this method just before `liveItemIds()`:
```php
    /**
     * Published posts of a blog, scheduled ones included, in id order: the chunks blog:reindex walks.
     *
     * @return list<Post>
     */
    public function publishedAfter(int $blogId, ?string $afterId, int $limit): array
    {
        $query = $this->db->select('posts')->where('blog_id', '=', $blogId)->where('status', '=', 'published');
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        return $this->hydrate($query->orderBy('id')->limit($limit)->all());
    }
```

In `modules/blog/module.json`'s `commands`, add `"Xaraya\\Module\\Blog\\Cli\\BlogReindexCommand",` after the `BlogModeCommand` line.

In `README.md`, after the line `` `bin/xar blog:mode <handle> native` stops mirroring; post ids never change. ``, add:
```markdown

Search uses its own index (`xar_post_search`), kept current by sync. `bin/xar blog:reindex <handle>|--all`
rebuilds it. **Upgrading an install that already has blogs:** run `bin/xar migrate`, then
`bin/xar blog:reindex --all` once, or older posts won't be found.
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/Cli`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL run of `tests/Module/Blog/Cli/BlogReindexCommandTest.php`.
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Cli/BlogReindexCommand.php modules/blog/src/Post/PostRepository.php modules/blog/module.json README.md tests/Module/Blog/Cli/BlogReindexCommandTest.php
git commit -m "feat(blog): add xar blog:reindex to rebuild the search index" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: `PostView` and `PostViewFactory`

**Files:**
- Create: `modules/blog/src/View/PostView.php`, `modules/blog/src/View/PostViewFactory.php`
- Modify: `modules/blog/src/Post/Post.php` (`updatedAt`), `modules/blog/src/BlogServiceProvider.php` (binding)
- Test: `tests/Module/Blog/View/PostViewFactoryTest.php`

**Interfaces:**
- **Consumes:**
  - `HtmlSanitizer::clean()` (Task 2).
  - `Cache::get/set` (kernel; `set` may throw `RuntimeException`, which the factory ignores).
  - `UrlGenerator::generate()` with the route names `blog.post` (`/s/{id}`) and `blog.tag` (`/blog/{handle}/tag/{slug}`); Task 8 registers those routes, and the unit test declares them itself.
  - `ItemSerializer::url(Blog, Post, string $appUrl)`, `SafeUrl::isSafe()`, `Text::plain()`, `JsonFeedAdapter::KINDS`.
  - The media map from `MediaUrls::map(Blog, list<Post>)`.
- **Produces `Post::$updatedAt`:** `?DateTimeImmutable`, the trailing constructor argument (default `null`), read from `updated_at` by `fromRow()`. Every existing `new Post(...)` call stays valid.
- **Produces `Xaraya\Module\Blog\View\PostView`:** readonly properties in this constructor order:
  `id`, `kind`, `title`, `url`, `canonicalUrl`, `?externalUrl`, `contentHtml`, `excerpt`, `?image`, `publishedIso`, `publishedDisplay`, `tags list<{name, slug, url}>`, `?source {name, url: ?string}`, `?quote`, `?commentHtml`, `images list<{url, width: ?int, height: ?int}>`, `?video {provider, id, thumbnail: ?string, watchUrl}`, `?podcast {show, episode, durationDisplay, audioUrl, cover}` (all `?string`), `?song {channel, durationDisplay}` (both `?string`), `mentions list<{source, title, date: ?string}>`, `pinned bool`.
- **Produces `PostViewFactory`:**
  - `__construct(HtmlSanitizer, Cache, UrlGenerator, ItemSerializer, string $appUrl)`, with `EXCERPT_LENGTH = 160` and `DATE_FORMAT = 'j M Y'`.
  - `make(Blog $blog, Post $post, array $media = []): PostView`
  - `link(Blog $blog, Post $post): string`: the mirror's pattern URL, or `/s/{id}`.
  - `plainText(Post $post): string`: the sanitised content as plain text, else the quote.
  - `static cacheKey(Post $post, string $field, string $html): string`
  - `static display(DateTimeImmutable $date): string`
  - `static duration(mixed $seconds): ?string`, giving `m:ss` or `h:mm:ss`.
- **Produces the binding:** `PostViewFactory::class`, with `appUrl` = `rtrim(app.url, '/')`.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/View/PostViewFactoryTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\View;

use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Routing\Route;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\View\PostViewFactory;
use Xaraya\Tests\Support\AppTestCase;

final class PostViewFactoryTest extends AppTestCase
{
    private const ID = '01m3t19wn8reaww81zg1m47tjq';

    private function factory(): PostViewFactory
    {
        // The two routes the factory links to, as modules/blog/src/Routes.php declares them (Task 8).
        $noop = static fn(): null => null;
        $urls = new UrlGenerator([
            new Route(['GET'], '/s/{id:[0-9a-hjkmnp-tv-z]{26}}', $noop, 'blog.post'),
            new Route(['GET'], '/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', $noop, 'blog.tag'),
        ], 'http://xar.test');

        return new PostViewFactory(new HtmlSanitizer('xar.test'), new Cache($this->tmp . '/cache'), $urls, new ItemSerializer(), 'http://xar.test');
    }

    /** @param array<string, mixed> $row */
    private static function blog(array $row = []): Blog
    {
        return Blog::fromRow(['id' => 1, 'handle' => 'notes', 'title' => 'Notes', 'author_name' => 'Ada', 'mode' => 'native', ...$row]);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $doc
     * @param list<array{name: string, slug: string}> $tags
     */
    private static function post(array $row = [], array $doc = [], array $tags = []): Post
    {
        return Post::fromRow([
            'id' => self::ID,
            'blog_id' => 1,
            'item_id' => 'item-1',
            'kind' => 'note',
            'title' => 'A title',
            'content_html' => '<p>Hello <script>alert(1)</script><b>world</b></p>',
            'date_published' => '2026-10-01 09:05:00',
            'updated_at' => '2026-10-02 10:00:00',
            'status' => 'published',
            'doc' => json_encode($doc, JSON_THROW_ON_ERROR),
            ...$row,
        ], $tags);
    }

    public function testANoteIsSanitisedDatedAndLinked(): void
    {
        $view = $this->factory()->make(self::blog(), self::post([], [], [
            ['name' => 'Café', 'slug' => 'café'],
            ['name' => 'Odd', 'slug' => 'a/b'],
        ]));
        self::assertSame('note', $view->kind);
        self::assertSame('A title', $view->title);
        self::assertSame('<p>Hello <b>world</b></p>', $view->contentHtml);
        self::assertSame('Hello world', $view->excerpt);
        self::assertSame('/s/' . self::ID, $view->url);
        self::assertSame('http://xar.test/s/' . self::ID, $view->canonicalUrl);
        self::assertSame('2026-10-01T09:05:00+00:00', $view->publishedIso);
        self::assertSame('1 Oct 2026', $view->publishedDisplay);
        self::assertSame([['name' => 'Café', 'slug' => 'café', 'url' => '/blog/notes/tag/caf%C3%A9']], $view->tags, 'a slug with "/" has no route and is left out');
        self::assertNull($view->source);
        self::assertNull($view->commentHtml);
        self::assertSame([], $view->images);
        self::assertNull($view->video);
        self::assertNull($view->podcast);
        self::assertNull($view->song);
        self::assertSame([], $view->mentions);
        self::assertFalse($view->pinned);
    }

    public function testExcerptIsTheFirst160CharactersOfPlainText(): void
    {
        $view = $this->factory()->make(self::blog(), self::post(['content_html' => '<p>' . str_repeat('é', 200) . '</p>']));
        self::assertSame(str_repeat('é', 160), $view->excerpt);
    }

    public function testALinkSplitsTheOwnersCommentFromTheDescription(): void
    {
        $doc = [
            '_source' => ['name' => 'example.test', 'url' => 'https://example.test/story'],
            '_athenana' => ['comment' => '<p>My take.</p>'],
        ];
        $view = $this->factory()->make(self::blog(), self::post([
            'kind' => 'link',
            'external_url' => 'https://example.test/story',
            'content_html' => "<p>My take.</p>\n<p>The description.</p>",
        ], $doc));
        self::assertSame('link', $view->kind);
        self::assertSame('https://example.test/story', $view->externalUrl);
        self::assertSame('<p>My take.</p>', $view->commentHtml);
        self::assertSame('<p>The description.</p>', $view->contentHtml);
        self::assertSame(['name' => 'example.test', 'url' => 'https://example.test/story'], $view->source);

        $kept = $this->factory()->make(self::blog(), self::post(['kind' => 'link', 'content_html' => '<p>Other text.</p>'], $doc));
        self::assertSame('<p>Other text.</p>', $kept->contentHtml, 'a comment that is not a prefix leaves the content alone');
    }

    public function testAHighlightKeepsItsQuoteAndDropsUnsafeUrls(): void
    {
        $view = $this->factory()->make(self::blog(), self::post([
            'kind' => 'highlight',
            'content_html' => null,
            'external_url' => 'javascript:alert(1)',
        ], [
            '_source' => ['name' => 'Someone', 'url' => 'javascript:alert(1)'],
            '_athenana' => ['quote' => 'To be <or> not', 'comment' => '<p onclick="x()">Yes</p>'],
        ]));
        self::assertSame('To be <or> not', $view->quote, 'plain text: templates escape it');
        self::assertSame('To be <or> not', $view->excerpt);
        self::assertSame(['name' => 'Someone', 'url' => null], $view->source);
        self::assertNull($view->externalUrl);
        self::assertSame('<p>Yes</p>', $view->commentHtml);
    }

    public function testMediaGoesThroughTheMediaMap(): void
    {
        $media = [
            'https://cdn.test/a.jpg' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta1.jpg',
            'https://cdn.test/cover.jpg' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta2.jpg',
        ];
        $view = $this->factory()->make(self::blog(), self::post(['kind' => 'photo', 'image' => 'https://cdn.test/a.jpg'], [
            '_athenana' => [
                'images' => [
                    ['url' => 'https://cdn.test/a.jpg', 'width' => 800, 'height' => 600],
                    ['url' => 'https://cdn.test/b.jpg', 'width' => '9', 'height' => null],
                    ['url' => 'data:image/png;base64,AAAA'],
                    'junk',
                ],
                'podcast' => ['show' => 'Show', 'episode' => 'Ep 1', 'duration_seconds' => 3725, 'audio_url' => 'https://cdn.test/ep.mp3', 'cover' => 'https://cdn.test/cover.jpg'],
            ],
        ]), $media);
        self::assertSame('http://xar.test/media/01m3t19wn8reaww81zg1m47ta1.jpg', $view->image);
        self::assertSame([
            ['url' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta1.jpg', 'width' => 800, 'height' => 600],
            ['url' => 'https://cdn.test/b.jpg', 'width' => null, 'height' => null],
        ], $view->images);
        self::assertSame([
            'show' => 'Show',
            'episode' => 'Ep 1',
            'durationDisplay' => '1:02:05',
            'audioUrl' => 'https://cdn.test/ep.mp3',
            'cover' => 'http://xar.test/media/01m3t19wn8reaww81zg1m47ta2.jpg',
        ], $view->podcast);
    }

    public function testVideoSongAndMentions(): void
    {
        $view = $this->factory()->make(self::blog(), self::post([], [
            '_video' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'thumbnail' => 'https://cdn.test/t.jpg', 'source' => 'https://youtu.be/dQw4w9WgXcQ'],
            '_athenana' => [
                'song' => ['channel' => 'Rick Astley', 'duration_seconds' => 213],
                'mentions' => [
                    ['source' => 'https://other.test/post', 'title' => 'A reply', 'date' => '2026-10-03T08:00:00+00:00'],
                    ['source' => 'https://other.test/untitled'],
                    ['source' => 'javascript:alert(1)', 'title' => 'Bad'],
                ],
            ],
        ]));
        self::assertSame([
            'provider' => 'youtube',
            'id' => 'dQw4w9WgXcQ',
            'thumbnail' => 'https://cdn.test/t.jpg',
            'watchUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ], $view->video);
        self::assertSame(['channel' => 'Rick Astley', 'durationDisplay' => '3:33'], $view->song);
        self::assertSame([
            ['source' => 'https://other.test/post', 'title' => 'A reply', 'date' => '3 Oct 2026'],
            ['source' => 'https://other.test/untitled', 'title' => 'https://other.test/untitled', 'date' => null],
        ], $view->mentions);

        foreach ([['provider' => 'vimeo', 'id' => '123456'], ['provider' => 'youtube', 'id' => '"><script>']] as $video) {
            self::assertNull($this->factory()->make(self::blog(), self::post([], ['_video' => $video]))->video);
        }
    }

    public function testTheMirrorPatternDrivesLinksAndThePinnedFlag(): void
    {
        $mirror = self::blog([
            'mode' => 'mirror',
            'source_url' => 'https://src.test/feed.json',
            'source_format' => 'athena',
            'post_url_pattern' => 'https://www.example.test/post?id={id}',
            'pinned_item_id' => 'item-1',
        ]);
        $view = $this->factory()->make($mirror, self::post());
        self::assertSame('https://www.example.test/post?id=' . self::ID, $view->url);
        self::assertSame('https://www.example.test/post?id=' . self::ID, $view->canonicalUrl);
        self::assertTrue($view->pinned);

        $native = self::blog(['post_url_pattern' => 'https://www.example.test/post?id={id}']);
        $view = $this->factory()->make($native, self::post());
        self::assertSame('/s/' . self::ID, $view->url, 'only a mirror links to its pattern');
        self::assertSame('https://www.example.test/post?id=' . self::ID, $view->canonicalUrl, 'the canonical is always ItemSerializer::url()');
    }

    public function testAnUnknownKindRendersAsANote(): void
    {
        self::assertSame('note', $this->factory()->make(self::blog(), self::post(['kind' => '../../x']))->kind);
    }

    public function testSanitisedHtmlIsCachedByPostUpdateAndContent(): void
    {
        $post = self::post();
        $cache = new Cache($this->tmp . '/cache');
        $key = PostViewFactory::cacheKey($post, 'content', (string) $post->contentHtml);
        self::assertStringStartsWith('sanitized/' . self::ID . '-20261002100000-content-', $key);
        self::assertFalse($cache->has($key));
        $this->factory()->make(self::blog(), $post);
        self::assertSame('<p>Hello <b>world</b></p>', $cache->get($key));

        $cache->set($key, '<p>from the cache</p>');
        self::assertSame('<p>from the cache</p>', $this->factory()->make(self::blog(), $post)->contentHtml);
        $edited = self::post(['content_html' => '<p>Edited</p>']);
        self::assertSame('<p>Edited</p>', $this->factory()->make(self::blog(), $edited)->contentHtml, 'new content, new key');
    }

    public function testGarbledContentStillGivesValidUtf8(): void
    {
        $html = "<p>bad \xff bytes</p>" . str_repeat('<div>', 3000) . '<script>x</script>' . str_repeat('</div>', 3000);
        $view = $this->factory()->make(self::blog(), self::post(['content_html' => $html]));
        self::assertTrue(mb_check_encoding($view->contentHtml, 'UTF-8'));
        self::assertTrue(mb_check_encoding($view->excerpt, 'UTF-8'));
        self::assertStringNotContainsString('<script', $view->contentHtml);
    }

    public function testPlainTextIsTheSanitisedContentOrTheQuote(): void
    {
        self::assertSame('Hello world', $this->factory()->plainText(self::post()));
        self::assertSame('Q', $this->factory()->plainText(self::post(['content_html' => null], ['_athenana' => ['quote' => 'Q']])));
        self::assertSame('', $this->factory()->plainText(self::post(['content_html' => '<script>x</script>'])));
    }

    public function testDurations(): void
    {
        self::assertSame('0:59', PostViewFactory::duration(59));
        self::assertSame('1:00:00', PostViewFactory::duration(3600));
        self::assertSame('2:05', PostViewFactory::duration(124.6));
        self::assertNull(PostViewFactory::duration(0));
        self::assertNull(PostViewFactory::duration('60'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/View/PostViewFactoryTest.php`
Expected: errors, `Class "Xaraya\Module\Blog\View\PostViewFactory" not found`.

- [ ] **Step 3: Implement**

In `modules/blog/src/Post/Post.php`:
- Add a last constructor parameter: `public readonly ?DateTimeImmutable $updatedAt = null,` after `public readonly array $tags,`.
- In `fromRow()`, after `$modified = $s('date_modified');`, add `$updated = $s('updated_at');`.
- Pass `$updated === null ? null : new DateTimeImmutable($updated, $utc),` as the last constructor argument, after `$tags,`.

`modules/blog/src/View/PostView.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\View;

/**
 * Engine-neutral template data for one post: scalars, arrays and nulls only. HTML fields (contentHtml,
 * commentHtml) are sanitised; every URL passed SafeUrl. PHP templates read $post->title, Twig post.title.
 */
final class PostView
{
    /**
     * @param list<array{name: string, slug: string, url: string}> $tags
     * @param array{name: string, url: ?string}|null $source
     * @param list<array{url: string, width: ?int, height: ?int}> $images
     * @param array{provider: string, id: string, thumbnail: ?string, watchUrl: string}|null $video
     * @param array{show: ?string, episode: ?string, durationDisplay: ?string, audioUrl: ?string, cover: ?string}|null $podcast
     * @param array{channel: ?string, durationDisplay: ?string}|null $song
     * @param list<array{source: string, title: string, date: ?string}> $mentions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $url,
        public readonly string $canonicalUrl,
        public readonly ?string $externalUrl,
        public readonly string $contentHtml,
        public readonly string $excerpt,
        public readonly ?string $image,
        public readonly string $publishedIso,
        public readonly string $publishedDisplay,
        public readonly array $tags,
        public readonly ?array $source,
        public readonly ?string $quote,
        public readonly ?string $commentHtml,
        public readonly array $images,
        public readonly ?array $video,
        public readonly ?array $podcast,
        public readonly ?array $song,
        public readonly array $mentions,
        public readonly bool $pinned,
    ) {}
}
```

`modules/blog/src/View/PostViewFactory.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\View;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Kernel\Support\SafeUrl;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Source\JsonFeedAdapter;
use Xaraya\Module\Blog\Support\Text;

/** Builds PostView template data from stored posts. The only caller of the sanitiser for post HTML. */
final class PostViewFactory
{
    public const EXCERPT_LENGTH = 160;
    public const DATE_FORMAT = 'j M Y';

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly Cache $cache,
        private readonly UrlGenerator $urls,
        private readonly ItemSerializer $items,
        private readonly string $appUrl,
    ) {}

    /** @param array<string, string> $media source media URL => local URL, from MediaUrls::map() */
    public function make(Blog $blog, Post $post, array $media = []): PostView
    {
        $doc = $post->doc;
        $athenana = self::map($doc, '_athenana');
        $comment = self::str($athenana, 'comment');
        $content = $post->contentHtml ?? '';
        if ($comment !== null && str_starts_with($content, $comment)) {
            // The wyome rule: the owner's comment repeats the start of content_html; show each once.
            $content = ltrim(substr($content, strlen($comment)));
        }
        $contentHtml = $this->clean($post, 'content', $content);
        $commentHtml = $comment === null ? '' : $this->clean($post, 'comment', $comment);
        $quote = self::str($athenana, 'quote');
        $plain = Text::plain($contentHtml);
        if ($plain === '') {
            $plain = $quote ?? Text::plain($commentHtml);
        }
        $local = static fn(?string $url): ?string => self::safe($url === null ? null : ($media[$url] ?? $url));
        $source = self::map($doc, '_source');
        $sourceName = self::str($source, 'name');

        return new PostView(
            id: $post->id,
            kind: in_array($post->kind, JsonFeedAdapter::KINDS, true) ? $post->kind : 'note',
            title: $post->title,
            url: $this->link($blog, $post),
            canonicalUrl: $this->items->url($blog, $post, $this->appUrl),
            externalUrl: self::safe($post->externalUrl),
            contentHtml: $contentHtml,
            excerpt: mb_substr($plain, 0, self::EXCERPT_LENGTH),
            image: $local($post->image),
            publishedIso: $post->datePublished->format(DATE_ATOM),
            publishedDisplay: self::display($post->datePublished),
            tags: $this->tags($blog, $post),
            source: $sourceName === null ? null : ['name' => $sourceName, 'url' => self::safe(self::str($source, 'url'))],
            quote: $quote,
            commentHtml: $commentHtml === '' ? null : $commentHtml,
            images: self::images($athenana, $local),
            video: self::video(self::map($doc, '_video'), $local),
            podcast: self::podcast(self::map($athenana, 'podcast'), $local),
            song: self::song(self::map($athenana, 'song')),
            mentions: self::mentions($athenana),
            pinned: $blog->pinnedItemId !== null && $blog->pinnedItemId === $post->itemId,
        );
    }

    /** The post's visible text: its sanitised content as plain text, else its quote. Search snippets are cut from it. */
    public function plainText(Post $post): string
    {
        $plain = Text::plain($this->clean($post, 'content', $post->contentHtml ?? ''));

        return $plain !== '' ? $plain : (self::str(self::map($post->doc, '_athenana'), 'quote') ?? '');
    }

    /** Where pages link a post: a mirror's post_url_pattern (the canonical address), otherwise /s/{id}. */
    public function link(Blog $blog, Post $post): string
    {
        if ($blog->isMirror() && $blog->postUrlPattern !== null) {
            return str_replace('{id}', $post->id, $blog->postUrlPattern);
        }

        return $this->urls->generate('blog.post', ['id' => $post->id]);
    }

    public static function cacheKey(Post $post, string $field, string $html): string
    {
        // updated_at has one-second resolution, so the input's hash keeps two writes in one second apart.
        return 'sanitized/' . $post->id . '-' . ($post->updatedAt?->format('YmdHis') ?? '0') . '-' . $field . '-' . substr(sha1($html), 0, 12);
    }

    public static function display(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format(self::DATE_FORMAT);
    }

    /** "m:ss", or "h:mm:ss" from an hour up; null for anything but a positive number of seconds. */
    public static function duration(mixed $seconds): ?string
    {
        $seconds = is_int($seconds) ? $seconds : (is_float($seconds) && is_finite($seconds) ? (int) round($seconds) : 0);
        if ($seconds <= 0) {
            return null;
        }
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $seconds % 60)
            : sprintf('%d:%02d', $minutes, $seconds % 60);
    }

    private function clean(Post $post, string $field, string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $key = self::cacheKey($post, $field, $html);
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached;
        }
        $clean = $this->sanitizer->clean($html);
        try {
            $this->cache->set($key, $clean);
        } catch (RuntimeException) {
            // The cache is an optimisation: an unwritable var/cache must not break the page.
        }

        return $clean;
    }

    /** @return list<array{name: string, slug: string, url: string}> */
    private function tags(Blog $blog, Post $post): array
    {
        $out = [];
        foreach ($post->tags as $tag) {
            if ($tag['slug'] === '' || str_contains($tag['slug'], '/')) {
                continue; // no tag route can match it
            }
            $out[] = [
                'name' => $tag['name'],
                'slug' => $tag['slug'],
                'url' => $this->urls->generate('blog.tag', ['handle' => $blog->handle, 'slug' => $tag['slug']]),
            ];
        }

        return $out;
    }

    /**
     * @param array<mixed> $athenana
     * @param Closure(?string): ?string $local
     * @return list<array{url: string, width: ?int, height: ?int}>
     */
    private static function images(array $athenana, Closure $local): array
    {
        $out = [];
        foreach (is_array($athenana['images'] ?? null) ? $athenana['images'] : [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $url = $local(self::str($image, 'url'));
            if ($url !== null) {
                $out[] = ['url' => $url, 'width' => self::size($image, 'width'), 'height' => self::size($image, 'height')];
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $video
     * @param Closure(?string): ?string $local
     * @return array{provider: string, id: string, thumbnail: ?string, watchUrl: string}|null
     */
    private static function video(array $video, Closure $local): ?array
    {
        $id = self::str($video, 'id');
        if (self::str($video, 'provider') !== 'youtube' || $id === null || preg_match('/^[A-Za-z0-9_-]{6,32}$/D', $id) !== 1) {
            return null;
        }

        return [
            'provider' => 'youtube',
            'id' => $id,
            'thumbnail' => $local(self::str($video, 'thumbnail')),
            'watchUrl' => 'https://www.youtube.com/watch?v=' . $id,
        ];
    }

    /**
     * @param array<mixed> $podcast
     * @param Closure(?string): ?string $local
     * @return array{show: ?string, episode: ?string, durationDisplay: ?string, audioUrl: ?string, cover: ?string}|null
     */
    private static function podcast(array $podcast, Closure $local): ?array
    {
        $view = [
            'show' => self::str($podcast, 'show'),
            'episode' => self::str($podcast, 'episode'),
            'durationDisplay' => self::duration($podcast['duration_seconds'] ?? null),
            'audioUrl' => $local(self::str($podcast, 'audio_url')),
            'cover' => $local(self::str($podcast, 'cover')),
        ];

        return $view['show'] === null && $view['episode'] === null && $view['audioUrl'] === null ? null : $view;
    }

    /**
     * @param array<mixed> $song
     * @return array{channel: ?string, durationDisplay: ?string}|null
     */
    private static function song(array $song): ?array
    {
        $view = ['channel' => self::str($song, 'channel'), 'durationDisplay' => self::duration($song['duration_seconds'] ?? null)];

        return $view['channel'] === null && $view['durationDisplay'] === null ? null : $view;
    }

    /**
     * @param array<mixed> $athenana
     * @return list<array{source: string, title: string, date: ?string}>
     */
    private static function mentions(array $athenana): array
    {
        $out = [];
        foreach (is_array($athenana['mentions'] ?? null) ? $athenana['mentions'] : [] as $mention) {
            $source = is_array($mention) ? self::safe(self::str($mention, 'source')) : null;
            if (!is_array($mention) || $source === null) {
                continue;
            }
            $date = null;
            $when = self::str($mention, 'date');
            if ($when !== null) {
                try {
                    $date = self::display(new DateTimeImmutable($when));
                } catch (Exception) {
                    $date = null;
                }
            }
            $out[] = ['source' => $source, 'title' => self::str($mention, 'title') ?? $source, 'date' => $date];
        }

        return $out;
    }

    private static function safe(?string $url): ?string
    {
        return $url !== null && SafeUrl::isSafe($url) ? $url : null;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function map(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /** @param array<mixed> $data */
    private static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<mixed> $data */
    private static function size(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) && $value > 0 && $value < 100000 ? $value : null;
    }
}
```

Replace `modules/blog/src/BlogServiceProvider.php` with:

`modules/blog/src/BlogServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Psr\Log\LoggerInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cache\Cache;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Container\ServiceProvider;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Media\MediaStore;
use Xaraya\Module\Blog\Search\LikeSearchIndex;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Source\StreamFetcher;
use Xaraya\Module\Blog\Sync\SyncLock;
use Xaraya\Module\Blog\View\PostViewFactory;

final class BlogServiceProvider implements ServiceProvider
{
    /** Kept in sync with modules/blog/module.json (asserted by StreamFetcherTest). */
    public const VERSION = '0.1.0';

    public function register(Container $container): void
    {
        $container->set(HttpFetcher::class, static fn(Container $c): HttpFetcher => new StreamFetcher(
            allowPrivateHosts: (bool) $c->get(Config::class)->get('blog.fetch_allow_private', false),
        ));
        $container->set(SyncLock::class, static fn(Container $c): SyncLock => new SyncLock(
            (string) $c->get(Config::class)->get('blog.locks', $c->get(App::class)->path('var/locks')),
        ));
        $container->set(MediaStore::class, static fn(Container $c): MediaStore => new MediaStore(
            $c->get(Connection::class),
            $c->get(HttpFetcher::class),
            $c->get(LoggerInterface::class),
            (string) $c->get(Config::class)->get('blog.uploads', $c->get(App::class)->path('var/uploads')),
        ));
        $container->set(SearchIndex::class, static fn(Container $c): SearchIndex => $c->get(LikeSearchIndex::class));
        $container->set(PostViewFactory::class, static fn(Container $c): PostViewFactory => new PostViewFactory(
            $c->get(HtmlSanitizer::class),
            $c->get(Cache::class),
            $c->get(UrlGenerator::class),
            $c->get(ItemSerializer::class),
            rtrim((string) $c->get(Config::class)->get('app.url', ''), '/'),
        ));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/View/PostViewFactoryTest.php tests/Module/Blog/Feed`
Expected: PASS. `ItemSerializerTest`, `FeedBuilderTest` and `RssRendererTest` still build `Post` without `updatedAt`.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green, `AthenaParityTest` included.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/View modules/blog/src/Post/Post.php modules/blog/src/BlogServiceProvider.php tests/Module/Blog/View/PostViewFactoryTest.php
git commit -m "feat(blog): build engine-neutral PostViews with sanitised, cached HTML" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Post partials: post, kinds and extras in both engines

**Files:**
- Create, each as `.php` and `.twig`, under `modules/blog/templates/`: `partials/post`, `partials/kinds/{note,link,highlight,photo}` and `partials/extras/{video,podcast,song,gallery,mentions,tags}`
- Create: `tests/Module/Blog/Support/PostViews.php`
- Test: `tests/Module/Blog/View/PostTemplatesTest.php`

**Interfaces:**
- **Consumes:**
  - `PostView` (Task 6).
  - `View::render(name, data)`, `Helpers::render/e/t`, and `Html::normalize`.
  - `Fixtures::theme($base, $name, $json, $files)`.
  - The config keys `themes.paths`, `app.theme` and `view.engine`, through `BlogTestCase::app($overrides)`.
- **Produces these template contracts. Later tasks render them by name and themes override them:**
  - `blog::partials/post` with `{post: PostView, full: bool}`. `full` gives an `<h1>` title and shows mentions; cards get an `<h2>` link to `post.url`. The title is left to `kinds/link` for links. It renders `blog::partials/kinds/{kind}` with `{post, full}`, then the extras `video`, `podcast` and `song` when set, a footer with the permalink `<time>` and `extras/tags`, and, when full, `extras/mentions`.
  - `blog::partials/kinds/note|link|highlight|photo` with `{post, full}`.
  - The extras, each with one variable: `extras/video` `{video}`, `extras/podcast` `{podcast}`, `extras/song` `{song}`, `extras/gallery` `{images}`, `extras/mentions` `{mentions}` and `extras/tags` `{tags}`.
- **Produces CSS class names, which Task 14 styles:**
  - `post`, `post-{kind}`, `post-pinned`, `post-pinned-label`, `post-title`, `post-content`, `post-comment`, `post-via`, `post-quote`, `post-source`, `post-photo`, `post-footer`, `post-meta`, `post-permalink`, `post-tags`, `post-mentions`, `mention-date`
  - `gallery`, `post-video`, `video-link`, `video-play`, `visually-hidden`, `post-podcast`, `podcast-cover`, `podcast-show`, `podcast-episode`, `podcast-duration`, `post-song`, `song-channel`, `song-duration`
- **Produces the test helper `PostViews`:** `make(array $overrides = []): PostView` (a plain note) and `everyKind(): array<string, PostView>` (note, link, highlight and photo, with every extra somewhere).

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Support/PostViews.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Support;

use Xaraya\Module\Blog\View\PostView;

/** PostViews for template tests: a plain note, with any constructor argument overridden by name. */
final class PostViews
{
    /** @param array<string, mixed> $overrides PostView constructor arguments by name */
    public static function make(array $overrides = []): PostView
    {
        $defaults = [
            'id' => '01m3t19wn8reaww81zg1m47tjq',
            'kind' => 'note',
            'title' => 'A <title>',
            'url' => '/s/01m3t19wn8reaww81zg1m47tjq',
            'canonicalUrl' => 'http://xar.test/s/01m3t19wn8reaww81zg1m47tjq',
            'externalUrl' => null,
            'contentHtml' => '<p>Body &amp; soul</p>',
            'excerpt' => 'Body & soul',
            'image' => null,
            'publishedIso' => '2026-10-01T09:05:00+00:00',
            'publishedDisplay' => '1 Oct 2026',
            'tags' => [],
            'source' => null,
            'quote' => null,
            'commentHtml' => null,
            'images' => [],
            'video' => null,
            'podcast' => null,
            'song' => null,
            'mentions' => [],
            'pinned' => false,
        ];

        return new PostView(...[...$defaults, ...$overrides]);
    }

    /** @return array<string, PostView> one post of every kind, with every extra somewhere */
    public static function everyKind(): array
    {
        return [
            'note' => self::make([
                'tags' => [['name' => 'Café', 'slug' => 'café', 'url' => '/blog/notes/tag/caf%C3%A9']],
                'pinned' => true,
                'mentions' => [
                    ['source' => 'https://other.test/a', 'title' => 'A "reply"', 'date' => '3 Oct 2026'],
                    ['source' => 'https://other.test/b', 'title' => 'https://other.test/b', 'date' => null],
                ],
                'song' => ['channel' => 'Rick & Co', 'durationDisplay' => '3:33'],
            ]),
            'link' => self::make([
                'kind' => 'link',
                'externalUrl' => 'https://example.test/story?a=1&b=2',
                'source' => ['name' => 'example.test', 'url' => 'https://example.test/'],
                'commentHtml' => '<p>My take.</p>',
                'contentHtml' => '<p>The description.</p>',
                'video' => ['provider' => 'youtube', 'id' => 'dQw4w9WgXcQ', 'thumbnail' => 'https://cdn.test/t.jpg', 'watchUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ]),
            'highlight' => self::make([
                'kind' => 'highlight',
                'quote' => 'To be <or> not',
                'source' => ['name' => 'Someone', 'url' => null],
                'commentHtml' => '<p>Indeed.</p>',
                'podcast' => ['show' => 'Show', 'episode' => 'Ep 1', 'durationDisplay' => '1:02:05', 'audioUrl' => 'https://cdn.test/ep.mp3', 'cover' => 'https://cdn.test/c.jpg'],
            ]),
            'photo' => self::make([
                'kind' => 'photo',
                'contentHtml' => '',
                'image' => 'https://cdn.test/a.jpg',
                'images' => [
                    ['url' => 'https://cdn.test/a.jpg', 'width' => 800, 'height' => 600],
                    ['url' => 'https://cdn.test/b.jpg', 'width' => null, 'height' => null],
                ],
            ]),
        ];
    }
}
```

`tests/Module/Blog/View/PostTemplatesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\View;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Xaraya\Kernel\View\View;
use Xaraya\Module\Blog\View\PostView;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Module\Blog\Support\PostViews;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

final class PostTemplatesTest extends BlogTestCase
{
    /** @param array<string, mixed> $overrides */
    private function view(string $engine, array $overrides = []): View
    {
        return $this->app(['themes.paths' => ['themes'], 'app.theme' => 'phoenix', 'app.locale' => 'en', 'view.engine' => $engine, ...$overrides])
            ->container()->get(View::class);
    }

    /** Renders the post partial with both engines, asserts identical normalised HTML and returns it. */
    private function both(PostView $post, bool $full): string
    {
        $php = Html::normalize($this->view('php')->render('blog::partials/post', ['post' => $post, 'full' => $full]));
        $twig = Html::normalize($this->view('twig')->render('blog::partials/post', ['post' => $post, 'full' => $full]));
        self::assertSame($php, $twig);

        return $php;
    }

    public function testEveryTemplateShipsInBothEngines(): void
    {
        $dir = dirname(__DIR__, 4) . '/modules/blog/templates';
        $names = ['php' => [], 'twig' => []];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            /** @var SplFileInfo $file */
            $extension = $file->getExtension();
            self::assertContains($extension, ['php', 'twig'], $file->getPathname());
            $names[$extension][] = substr($file->getPathname(), strlen($dir) + 1, -strlen($extension) - 1);
        }
        sort($names['php']);
        sort($names['twig']);
        self::assertSame($names['php'], $names['twig']);
        self::assertContains('partials/post', $names['php']);
    }

    public function testEveryKindAndExtraIsIdenticalInBothEngines(): void
    {
        $posts = PostViews::everyKind();

        $note = $this->both($posts['note'], true);
        foreach ([
            '<article class="post post-note post-pinned"><p class="post-pinned-label">Pinned</p><h1 class="post-title">A &lt;title&gt;</h1>',
            '<div class="post-content"><p>Body &amp; soul</p></div>',
            '<p class="post-song"><span class="song-channel">Rick &amp; Co</span><span class="song-duration">3:33</span></p>',
            '<footer class="post-footer"><p class="post-meta"><a class="post-permalink" href="/s/01m3t19wn8reaww81zg1m47tjq"><time datetime="2026-10-01T09:05:00+00:00">1 Oct 2026</time></a></p>',
            '<ul class="post-tags"><li><a href="/blog/notes/tag/caf%C3%A9" rel="tag">Café</a></li></ul></footer>',
            '<section class="post-mentions"><h2>Mentions</h2><ul><li><a href="https://other.test/a" rel="nofollow noopener ugc">A &quot;reply&quot;</a><span class="mention-date">3 Oct 2026</span></li>',
            '<li><a href="https://other.test/b" rel="nofollow noopener ugc">https://other.test/b</a></li></ul></section></article>',
        ] as $needle) {
            self::assertStringContainsString($needle, $note);
        }
        self::assertStringNotContainsString('post-mentions', $this->both($posts['note'], false), 'mentions show on the post page only');
        self::assertStringContainsString('<h2 class="post-title"><a href="/s/01m3t19wn8reaww81zg1m47tjq">A &lt;title&gt;</a></h2>', $this->both($posts['note'], false));

        $link = $this->both($posts['link'], false);
        self::assertStringContainsString(
            '<article class="post post-link"><h2 class="post-title"><a href="https://example.test/story?a=1&amp;b=2" rel="nofollow noopener">A &lt;title&gt;</a></h2>'
            . '<p class="post-via">via<a href="https://example.test/" rel="nofollow noopener">example.test</a></p>'
            . '<div class="post-comment"><p>My take.</p></div><div class="post-content"><p>The description.</p></div>'
            . '<p class="post-video"><a class="video-link" href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" rel="nofollow noopener"><img src="https://cdn.test/t.jpg" alt="" loading="lazy"><span class="video-play" aria-hidden="true">▶</span><span class="visually-hidden">Watch the video</span></a></p>',
            $link,
        );
        self::assertStringContainsString('<h1 class="post-title"><a href="https://example.test/story?a=1&amp;b=2"', $this->both($posts['link'], true));

        $highlight = $this->both($posts['highlight'], true);
        self::assertStringContainsString(
            '<h1 class="post-title">A &lt;title&gt;</h1><blockquote class="post-quote"><p>To be &lt;or&gt; not</p></blockquote>'
            . '<p class="post-source">— Someone</p><div class="post-comment"><p>Indeed.</p></div>'
            . '<div class="post-podcast"><img class="podcast-cover" src="https://cdn.test/c.jpg" alt="" loading="lazy"><p class="podcast-show">Show</p>'
            . '<p class="podcast-episode">Ep 1</p><audio controls preload="none" src="https://cdn.test/ep.mp3"></audio><p class="podcast-duration">1:02:05</p></div>',
            $highlight,
        );

        $photo = $this->both($posts['photo'], false);
        self::assertStringContainsString(
            '<div class="gallery"><img src="https://cdn.test/a.jpg" alt="" loading="lazy" width="800" height="600"><img src="https://cdn.test/b.jpg" alt="" loading="lazy"></div>',
            $photo,
        );
        self::assertStringNotContainsString('post-content', $photo);
        $single = $this->both(PostViews::make(['kind' => 'photo', 'images' => [['url' => 'https://cdn.test/a.jpg', 'width' => 8, 'height' => 6]]]), false);
        self::assertStringContainsString('<figure class="post-photo"><img src="https://cdn.test/a.jpg" alt="" width="8" height="6"></figure>', $single);
        $fallback = $this->both(PostViews::make(['kind' => 'photo', 'image' => 'https://cdn.test/only.jpg']), false);
        self::assertStringContainsString('<figure class="post-photo"><img src="https://cdn.test/only.jpg" alt=""></figure>', $fallback);

        foreach ($posts as $kind => $post) {
            foreach ([true, false] as $full) {
                $html = $this->both($post, $full);
                self::assertStringNotContainsString('<script', $html, $kind);
                self::assertStringNotContainsString('<iframe', $html, $kind);
                self::assertStringNotContainsString('style=', $html, $kind);
            }
        }
    }

    public function testAThemeOverrideInTheOtherEngineIsStillUsed(): void
    {
        $themes = $this->tmp . '/themes';
        Fixtures::theme($themes, 'phpkinds', ['parent' => 'phoenix', 'engine' => 'twig'], [
            'templates/modules/blog/partials/kinds/link.php' => '<p class="custom-link"><?= $x->e($post->title) ?></p>',
        ]);
        Fixtures::theme($themes, 'twigkinds', ['parent' => 'phoenix', 'engine' => 'php'], [
            'templates/modules/blog/partials/kinds/link.twig' => '<p class="custom-link">{{ post.title }}</p>',
        ]);
        $link = PostViews::everyKind()['link'];
        foreach (['phpkinds' => 'twig', 'twigkinds' => 'php'] as $theme => $engine) {
            $html = Html::normalize($this->view($engine, ['themes.paths' => [$themes, 'themes'], 'app.theme' => $theme])
                ->render('blog::partials/post', ['post' => $link, 'full' => false]));
            self::assertStringContainsString('<article class="post post-link"><p class="custom-link">A &lt;title&gt;</p>', $html, "{$theme} with {$engine}");
            self::assertStringNotContainsString('post-via', $html, $theme);
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/View/PostTemplatesTest.php`
Expected: errors. `modules/blog/templates` does not exist, and `Template 'blog::partials/post' not found`.

- [ ] **Step 3: Implement the templates**

Every value goes through `$x->e()` in PHP; only `contentHtml` and `commentHtml` (already sanitised) and `render()` results are printed raw. Partials that themes may override are always pulled with `render()`, never `include`. Wherever text sits next to an output, such as `via` before the source link, it stays on one line in both engines.

`modules/blog/templates/partials/post.php`:
```php
<article class="post post-<?= $x->e($post->kind) ?><?= $post->pinned ? ' post-pinned' : '' ?>">
<?php if ($post->pinned): ?>
<p class="post-pinned-label"><?= $x->e($x->t('Pinned')) ?></p>
<?php endif ?>
<?php if ($post->kind !== 'link'): ?>
<?php if ($full): ?>
<h1 class="post-title"><?= $x->e($post->title) ?></h1>
<?php else: ?>
<h2 class="post-title"><a href="<?= $x->e($post->url) ?>"><?= $x->e($post->title) ?></a></h2>
<?php endif ?>
<?php endif ?>
<?= $x->render('blog::partials/kinds/' . $post->kind, ['post' => $post, 'full' => $full]) ?>
<?php if ($post->video !== null): ?>
<?= $x->render('blog::partials/extras/video', ['video' => $post->video]) ?>
<?php endif ?>
<?php if ($post->podcast !== null): ?>
<?= $x->render('blog::partials/extras/podcast', ['podcast' => $post->podcast]) ?>
<?php endif ?>
<?php if ($post->song !== null): ?>
<?= $x->render('blog::partials/extras/song', ['song' => $post->song]) ?>
<?php endif ?>
<footer class="post-footer">
<p class="post-meta"><a class="post-permalink" href="<?= $x->e($post->url) ?>"><time datetime="<?= $x->e($post->publishedIso) ?>"><?= $x->e($post->publishedDisplay) ?></time></a></p>
<?php if ($post->tags !== []): ?>
<?= $x->render('blog::partials/extras/tags', ['tags' => $post->tags]) ?>
<?php endif ?>
</footer>
<?php if ($full && $post->mentions !== []): ?>
<?= $x->render('blog::partials/extras/mentions', ['mentions' => $post->mentions]) ?>
<?php endif ?>
</article>
```

`modules/blog/templates/partials/post.twig`:
```twig
<article class="post post-{{ post.kind }}{{ post.pinned ? ' post-pinned' : '' }}">
{% if post.pinned %}
<p class="post-pinned-label">{{ t('Pinned') }}</p>
{% endif %}
{% if post.kind != 'link' %}
{% if full %}
<h1 class="post-title">{{ post.title }}</h1>
{% else %}
<h2 class="post-title"><a href="{{ post.url }}">{{ post.title }}</a></h2>
{% endif %}
{% endif %}
{{ render('blog::partials/kinds/' ~ post.kind, {post: post, full: full}) }}
{% if post.video is not null %}
{{ render('blog::partials/extras/video', {video: post.video}) }}
{% endif %}
{% if post.podcast is not null %}
{{ render('blog::partials/extras/podcast', {podcast: post.podcast}) }}
{% endif %}
{% if post.song is not null %}
{{ render('blog::partials/extras/song', {song: post.song}) }}
{% endif %}
<footer class="post-footer">
<p class="post-meta"><a class="post-permalink" href="{{ post.url }}"><time datetime="{{ post.publishedIso }}">{{ post.publishedDisplay }}</time></a></p>
{% if post.tags is not empty %}
{{ render('blog::partials/extras/tags', {tags: post.tags}) }}
{% endif %}
</footer>
{% if full and post.mentions is not empty %}
{{ render('blog::partials/extras/mentions', {mentions: post.mentions}) }}
{% endif %}
</article>
```

`modules/blog/templates/partials/kinds/note.php`:
```php
<?php if ($post->contentHtml !== ''): ?>
<div class="post-content"><?= $post->contentHtml ?></div>
<?php endif ?>
```

`modules/blog/templates/partials/kinds/note.twig`:
```twig
{% if post.contentHtml != '' %}
<div class="post-content">{{ post.contentHtml|raw }}</div>
{% endif %}
```

`modules/blog/templates/partials/kinds/link.php`:
```php
<?php $heading = $full ? 'h1' : 'h2'; ?>
<<?= $heading ?> class="post-title"><a href="<?= $x->e($post->externalUrl ?? $post->url) ?>"<?= $post->externalUrl !== null ? ' rel="nofollow noopener"' : '' ?>><?= $x->e($post->title) ?></a></<?= $heading ?>>
<?php if ($post->source !== null): ?>
<p class="post-via"><?= $x->e($x->t('via')) ?> <?php if ($post->source['url'] !== null): ?><a href="<?= $x->e($post->source['url']) ?>" rel="nofollow noopener"><?= $x->e($post->source['name']) ?></a><?php else: ?><?= $x->e($post->source['name']) ?><?php endif ?></p>
<?php endif ?>
<?php if ($post->commentHtml !== null): ?>
<div class="post-comment"><?= $post->commentHtml ?></div>
<?php endif ?>
<?php if ($post->contentHtml !== ''): ?>
<div class="post-content"><?= $post->contentHtml ?></div>
<?php endif ?>
```

`modules/blog/templates/partials/kinds/link.twig`:
```twig
{% set heading = full ? 'h1' : 'h2' %}
<{{ heading }} class="post-title"><a href="{{ post.externalUrl ?? post.url }}"{% if post.externalUrl is not null %} rel="nofollow noopener"{% endif %}>{{ post.title }}</a></{{ heading }}>
{% if post.source is not null %}
<p class="post-via">{{ t('via') }} {% if post.source.url is not null %}<a href="{{ post.source.url }}" rel="nofollow noopener">{{ post.source.name }}</a>{% else %}{{ post.source.name }}{% endif %}</p>
{% endif %}
{% if post.commentHtml is not null %}
<div class="post-comment">{{ post.commentHtml|raw }}</div>
{% endif %}
{% if post.contentHtml != '' %}
<div class="post-content">{{ post.contentHtml|raw }}</div>
{% endif %}
```

`modules/blog/templates/partials/kinds/highlight.php`:
```php
<?php if ($post->quote !== null): ?>
<blockquote class="post-quote"<?php if ($post->source !== null && $post->source['url'] !== null): ?> cite="<?= $x->e($post->source['url']) ?>"<?php endif ?>><p><?= $x->e($post->quote) ?></p></blockquote>
<?php elseif ($post->contentHtml !== ''): ?>
<div class="post-content"><?= $post->contentHtml ?></div>
<?php endif ?>
<?php if ($post->source !== null): ?>
<p class="post-source">— <?php if ($post->source['url'] !== null): ?><a href="<?= $x->e($post->source['url']) ?>" rel="nofollow noopener"><?= $x->e($post->source['name']) ?></a><?php else: ?><?= $x->e($post->source['name']) ?><?php endif ?></p>
<?php endif ?>
<?php if ($post->commentHtml !== null): ?>
<div class="post-comment"><?= $post->commentHtml ?></div>
<?php endif ?>
```

`modules/blog/templates/partials/kinds/highlight.twig`:
```twig
{% if post.quote is not null %}
<blockquote class="post-quote"{% if post.source is not null and post.source.url is not null %} cite="{{ post.source.url }}"{% endif %}><p>{{ post.quote }}</p></blockquote>
{% elseif post.contentHtml != '' %}
<div class="post-content">{{ post.contentHtml|raw }}</div>
{% endif %}
{% if post.source is not null %}
<p class="post-source">— {% if post.source.url is not null %}<a href="{{ post.source.url }}" rel="nofollow noopener">{{ post.source.name }}</a>{% else %}{{ post.source.name }}{% endif %}</p>
{% endif %}
{% if post.commentHtml is not null %}
<div class="post-comment">{{ post.commentHtml|raw }}</div>
{% endif %}
```

`modules/blog/templates/partials/kinds/photo.php`:
```php
<?php if (count($post->images) > 1): ?>
<?= $x->render('blog::partials/extras/gallery', ['images' => $post->images]) ?>
<?php elseif ($post->images !== []): ?>
<?php $photo = $post->images[0]; ?>
<figure class="post-photo"><img src="<?= $x->e($photo['url']) ?>" alt=""<?php if ($photo['width'] !== null && $photo['height'] !== null): ?> width="<?= $x->e($photo['width']) ?>" height="<?= $x->e($photo['height']) ?>"<?php endif ?>></figure>
<?php elseif ($post->image !== null): ?>
<figure class="post-photo"><img src="<?= $x->e($post->image) ?>" alt=""></figure>
<?php endif ?>
<?php if ($post->contentHtml !== ''): ?>
<div class="post-content"><?= $post->contentHtml ?></div>
<?php endif ?>
```

`modules/blog/templates/partials/kinds/photo.twig`:
```twig
{% if post.images|length > 1 %}
{{ render('blog::partials/extras/gallery', {images: post.images}) }}
{% elseif post.images is not empty %}
{% set photo = post.images|first %}
<figure class="post-photo"><img src="{{ photo.url }}" alt=""{% if photo.width is not null and photo.height is not null %} width="{{ photo.width }}" height="{{ photo.height }}"{% endif %}></figure>
{% elseif post.image is not null %}
<figure class="post-photo"><img src="{{ post.image }}" alt=""></figure>
{% endif %}
{% if post.contentHtml != '' %}
<div class="post-content">{{ post.contentHtml|raw }}</div>
{% endif %}
```

`modules/blog/templates/partials/extras/video.php`:
```php
<p class="post-video"><a class="video-link" href="<?= $x->e($video['watchUrl']) ?>" rel="nofollow noopener"><?php if ($video['thumbnail'] !== null): ?><img src="<?= $x->e($video['thumbnail']) ?>" alt="" loading="lazy"><?php endif ?><span class="video-play" aria-hidden="true">▶</span><span class="visually-hidden"><?= $x->e($x->t('Watch the video')) ?></span></a></p>
```

`modules/blog/templates/partials/extras/video.twig`:
```twig
<p class="post-video"><a class="video-link" href="{{ video.watchUrl }}" rel="nofollow noopener">{% if video.thumbnail is not null %}<img src="{{ video.thumbnail }}" alt="" loading="lazy">{% endif %}<span class="video-play" aria-hidden="true">▶</span><span class="visually-hidden">{{ t('Watch the video') }}</span></a></p>
```

`modules/blog/templates/partials/extras/podcast.php`:
```php
<div class="post-podcast">
<?php if ($podcast['cover'] !== null): ?>
<img class="podcast-cover" src="<?= $x->e($podcast['cover']) ?>" alt="" loading="lazy">
<?php endif ?>
<?php if ($podcast['show'] !== null): ?>
<p class="podcast-show"><?= $x->e($podcast['show']) ?></p>
<?php endif ?>
<?php if ($podcast['episode'] !== null): ?>
<p class="podcast-episode"><?= $x->e($podcast['episode']) ?></p>
<?php endif ?>
<?php if ($podcast['audioUrl'] !== null): ?>
<audio controls preload="none" src="<?= $x->e($podcast['audioUrl']) ?>"></audio>
<?php endif ?>
<?php if ($podcast['durationDisplay'] !== null): ?>
<p class="podcast-duration"><?= $x->e($podcast['durationDisplay']) ?></p>
<?php endif ?>
</div>
```

`modules/blog/templates/partials/extras/podcast.twig`:
```twig
<div class="post-podcast">
{% if podcast.cover is not null %}
<img class="podcast-cover" src="{{ podcast.cover }}" alt="" loading="lazy">
{% endif %}
{% if podcast.show is not null %}
<p class="podcast-show">{{ podcast.show }}</p>
{% endif %}
{% if podcast.episode is not null %}
<p class="podcast-episode">{{ podcast.episode }}</p>
{% endif %}
{% if podcast.audioUrl is not null %}
<audio controls preload="none" src="{{ podcast.audioUrl }}"></audio>
{% endif %}
{% if podcast.durationDisplay is not null %}
<p class="podcast-duration">{{ podcast.durationDisplay }}</p>
{% endif %}
</div>
```

`modules/blog/templates/partials/extras/song.php`:
```php
<p class="post-song"><?php if ($song['channel'] !== null): ?><span class="song-channel"><?= $x->e($song['channel']) ?></span><?php endif ?><?php if ($song['durationDisplay'] !== null): ?><span class="song-duration"><?= $x->e($song['durationDisplay']) ?></span><?php endif ?></p>
```

`modules/blog/templates/partials/extras/song.twig`:
```twig
<p class="post-song">{% if song.channel is not null %}<span class="song-channel">{{ song.channel }}</span>{% endif %}{% if song.durationDisplay is not null %}<span class="song-duration">{{ song.durationDisplay }}</span>{% endif %}</p>
```

`modules/blog/templates/partials/extras/gallery.php`:
```php
<div class="gallery">
<?php foreach ($images as $image): ?>
<img src="<?= $x->e($image['url']) ?>" alt="" loading="lazy"<?php if ($image['width'] !== null && $image['height'] !== null): ?> width="<?= $x->e($image['width']) ?>" height="<?= $x->e($image['height']) ?>"<?php endif ?>>
<?php endforeach ?>
</div>
```

`modules/blog/templates/partials/extras/gallery.twig`:
```twig
<div class="gallery">
{% for image in images %}
<img src="{{ image.url }}" alt="" loading="lazy"{% if image.width is not null and image.height is not null %} width="{{ image.width }}" height="{{ image.height }}"{% endif %}>
{% endfor %}
</div>
```

`modules/blog/templates/partials/extras/mentions.php`:
```php
<section class="post-mentions">
<h2><?= $x->e($x->t('Mentions')) ?></h2>
<ul>
<?php foreach ($mentions as $mention): ?>
<li><a href="<?= $x->e($mention['source']) ?>" rel="nofollow noopener ugc"><?= $x->e($mention['title']) ?></a><?php if ($mention['date'] !== null): ?> <span class="mention-date"><?= $x->e($mention['date']) ?></span><?php endif ?></li>
<?php endforeach ?>
</ul>
</section>
```

`modules/blog/templates/partials/extras/mentions.twig`:
```twig
<section class="post-mentions">
<h2>{{ t('Mentions') }}</h2>
<ul>
{% for mention in mentions %}
<li><a href="{{ mention.source }}" rel="nofollow noopener ugc">{{ mention.title }}</a>{% if mention.date is not null %} <span class="mention-date">{{ mention.date }}</span>{% endif %}</li>
{% endfor %}
</ul>
</section>
```

`modules/blog/templates/partials/extras/tags.php`:
```php
<ul class="post-tags">
<?php foreach ($tags as $tag): ?>
<li><a href="<?= $x->e($tag['url']) ?>" rel="tag"><?= $x->e($tag['name']) ?></a></li>
<?php endforeach ?>
</ul>
```

`modules/blog/templates/partials/extras/tags.twig`:
```twig
<ul class="post-tags">
{% for tag in tags %}
<li><a href="{{ tag.url }}" rel="tag">{{ tag.name }}</a></li>
{% endfor %}
</ul>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/View`
Expected: PASS. `PostTemplatesTest` has 3 tests; the two theme fixtures prove that a `.php` override is used while Twig renders, and a `.twig` override while PHP renders.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green. PHPStan and php-cs-fixer skip `templates/`.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/templates tests/Module/Blog/Support/PostViews.php tests/Module/Blog/View/PostTemplatesTest.php
git commit -m "feat(blog): add post, kind and extra partials in PHP and Twig" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Page head, `/s/{id}` and the tag listing

**Files:**
- Create: `modules/blog/src/Http/BlogPage.php`, `modules/blog/src/Http/Paging.php`, `modules/blog/src/Http/PostPageController.php`, `modules/blog/src/Http/BlogPagesController.php`
- Create, each as `.php` and `.twig`, under `modules/blog/templates/`: `partials/pager`, `post` and `tag`
- Modify: `modules/blog/src/Post/PostRepository.php` (`neighbours`, `pageByTag`), `modules/blog/src/Routes.php` (`blog.tag`, `blog.post`)
- Create: `tests/Module/Blog/Http/BlogPageTestCase.php`
- Test: `tests/Module/Blog/Http/PostPageTest.php`, `tests/Module/Blog/Http/TagPageTest.php`

**Interfaces:**
- **Consumes:**
  - `PostViewFactory::make/link` and `PostView` (Task 6); `blog::partials/post` (Task 7).
  - `AssetUrls::url()` (Task 1) and `HtmlSanitizer::clean()` (Task 2).
  - `MediaUrls::map()`, `Feed\Cursor::encode/decode`, `ItemSerializer`.
  - The kernel `Page(template, data, title, meta, feeds, canonical, lang, styles, status, headers)`, `NotFound`, and `HttpException(400, …)`.
  - The `BlogTestCase` helpers (Task 4).
- **Produces `Http\BlogPage`:**
  - `PAGE_SIZE = 20` and `static now(): DateTimeImmutable` (UTC).
  - `__construct(BlogRepository, UrlGenerator, AssetUrls, HtmlSanitizer)`
  - `blog(string $handle): Blog`: throws `NotFound`.
  - `page(Blog $blog, string $template, array $data, string $name, string $canonical, ?string $description = null, string $ogType = 'website', ?string $image = null): Page`. It adds `blog` (the summary) to `$data` and sets the title `"{name} · {blog title}"` (the blog title alone when `$name === ''`), the description, `og:*`, both feed links (absolute), the canonical, `lang`, `styles` (`/assets/module/blog/blog.css`) and `Cache-Control: public, max-age=60`.
  - `summary(Blog): array{handle: string, title: string, description: string}` and `descriptionHtml(Blog): string` (sanitised).
- **Produces `Http\Paging`** (static):
  - `before(ServerRequestInterface): ?array{0: DateTimeImmutable, 1: string}`: 400 `Invalid before cursor`.
  - `page(ServerRequestInterface): int`: 1 when absent, 400 `Invalid page number` unless `[1-9][0-9]{0,5}`.
  - `cursorParam(?array $before): array<string, string>` and `query(ServerRequestInterface): array<mixed>`.
- **Produces the pager contract** `pager: array{prev: ?string, next: ?string, prevLabel: string, nextLabel: string}`, rendered by `blog::partials/pager`. Labels are translation keys.
- **Produces `PostRepository`:**
  - `neighbours(Post $post, DateTimeImmutable $now): array{0: ?Post, 1: ?Post}`: older and newer live posts by `(date_published, id)`.
  - `pageByTag(int $blogId, string $slug, DateTimeImmutable $now, ?array $before, int $limit): list<Post>`
- **Produces the routes** `blog.tag` → `BlogPagesController::tag` and `blog.post` → `PostPageController::show`, each with `secureHtml` and `conditional`.
- **Produces `BlogPagesController`:** `__construct(PostRepository, PostViewFactory, MediaUrls, BlogPage, UrlGenerator)`, with `tag()` and the private `listing()`, which Task 9 reuses for `home()`.
- **Produces `BlogPageTestCase extends BlogTestCase`:**
  - `site(string $engine = 'php', array $overrides = []): App`: phoenix theme, `app.name` "Phoenix", `view.engine`.
  - `get(string $uri, string $engine = 'php', array $headers = []): ResponseInterface`
  - `both(string $uri, int $status = 200): array{0: ResponseInterface, 1: string}`: asserts the same status and the same normalised HTML in both engines.
  - `static main(string $html): string` and `static assertSecureHtml(ResponseInterface)`.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/Http/BlogPageTestCase.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Tests\Module\Blog\BlogTestCase;
use Xaraya\Tests\Support\Html;

/** HTTP tests of the blog pages, rendered in the phoenix theme with either engine. */
abstract class BlogPageTestCase extends BlogTestCase
{
    /** @param array<string, mixed> $overrides */
    protected function site(string $engine = 'php', array $overrides = []): App
    {
        return $this->app([
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.name' => 'Phoenix',
            'app.locale' => 'en',
            'view.engine' => $engine,
            ...$overrides,
        ]);
    }

    /** @param array<string, string> $headers */
    protected function get(string $uri, string $engine = 'php', array $headers = []): ResponseInterface
    {
        $request = new ServerRequest('GET', $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->site($engine)->handle($request);
    }

    /**
     * Requests $uri with the PHP and the Twig engine, asserts the same status and normalised HTML.
     *
     * @return array{0: ResponseInterface, 1: string} the PHP response and the normalised HTML
     */
    protected function both(string $uri, int $status = 200): array
    {
        $php = $this->get($uri, 'php');
        $twig = $this->get($uri, 'twig');
        self::assertSame($status, $php->getStatusCode(), $uri . "\n" . $php->getBody());
        self::assertSame($status, $twig->getStatusCode(), $uri);
        $html = Html::normalize((string) $php->getBody());
        self::assertSame($html, Html::normalize((string) $twig->getBody()), "{$uri}: PHP and Twig differ");

        return [$php, $html];
    }

    /** The normalised contents of <main>, so sidebar blocks never satisfy a needle. */
    protected static function main(string $html): string
    {
        return preg_match('#<main[^>]*>(.*)</main>#s', $html, $m) === 1 ? $m[1] : '';
    }

    protected static function assertSecureHtml(ResponseInterface $response): void
    {
        self::assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        foreach (SecureHtml::HEADERS as $name => $value) {
            self::assertSame($value, $response->getHeaderLine($name), $name);
        }
    }
}
```

`tests/Module/Blog/Http/PostPageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Kernel\Http\Controller;
use Xaraya\Module\Blog\Feed\ItemSerializer;
use Xaraya\Module\Blog\Post\PostRepository;

final class PostPageTest extends BlogPageTestCase
{
    /** @return array{0: string, 1: string, 2: string} the ids of posts 1 (newer), 2 and 3 (older) */
    private function three(): array
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);

        return [
            $this->addPost($app, $blog, self::postRecord(1)),
            $this->addPost($app, $blog, self::postRecord(2, [
                'title' => 'Second <post>',
                'content' => '<p>Second body.</p><script>alert(1)</script>',
                'image' => 'https://cdn.test/2.jpg',
                'tags' => [['name' => 'Café', 'slug' => 'café'], ['name' => 'travel', 'slug' => 'travel']],
            ])),
            $this->addPost($app, $blog, self::postRecord(3)),
        ];
    }

    public function testAPostPageInBothEngines(): void
    {
        [$newer, $id, $older] = $this->three();
        [$response, $html] = $this->both("/s/{$id}");
        self::assertSecureHtml($response);
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine('ETag'));
        self::assertFalse($response->hasHeader('Last-Modified'));
        foreach ([
            '<html lang="en">',
            '<title>Second &lt;post&gt; · Notes</title>',
            '<meta name="description" content="Second body.">',
            '<meta property="og:title" content="Second &lt;post&gt;">',
            '<meta property="og:type" content="article">',
            "<meta property=\"og:url\" content=\"http://xar.test/s/{$id}\">",
            '<meta property="og:image" content="https://cdn.test/2.jpg">',
            "<link rel=\"canonical\" href=\"http://xar.test/s/{$id}\">",
            '<link rel="alternate" type="application/feed+json" title="Notes" href="http://xar.test/blog/notes/feed.json">',
            '<link rel="alternate" type="application/rss+xml" title="Notes" href="http://xar.test/blog/notes/feed.xml">',
            '<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css"><link rel="stylesheet" href="/assets/module/blog/blog.css">',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        $main = self::main($html);
        foreach ([
            '<article class="post post-note"><h1 class="post-title">Second &lt;post&gt;</h1><div class="post-content"><p>Second body.</p></div>',
            '<li><a href="/blog/notes/tag/caf%C3%A9" rel="tag">Café</a></li><li><a href="/blog/notes/tag/travel" rel="tag">travel</a></li>',
            "<nav class=\"post-nav\" aria-label=\"More posts\"><p class=\"post-nav-newer\">Newer:<a href=\"/s/{$newer}\">Post 1</a></p><p class=\"post-nav-older\">Older:<a href=\"/s/{$older}\">Post 3</a></p></nav>",
        ] as $needle) {
            self::assertStringContainsString($needle, $main);
        }
        self::assertStringNotContainsString('<script', $html);

        [, $html] = $this->both("/s/{$newer}");
        self::assertStringNotContainsString('post-nav-newer', $html, 'the newest post has no newer neighbour');
        self::assertSame(304, $this->get("/s/{$id}", 'php', ['If-None-Match' => $response->getHeaderLine('ETag')])->getStatusCode());
    }

    public function testHiddenAndUnknownPostsAre404ThroughTheTheme(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $scheduled = $this->addPost($app, $blog, self::postRecord(1, ['date' => $this->now()->modify('+1 day')]));
        $deleted = $this->addPost($app, $blog, self::postRecord(2));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-2'], $this->now());
        foreach ([$scheduled, $deleted, '01m3t19wn8reaww81zg1m47tjq'] as $id) {
            [$response, $html] = $this->both("/s/{$id}", 404);
            self::assertSecureHtml($response);
            self::assertStringContainsString('<title>404 Not Found</title>', $html);
            self::assertStringContainsString('<h1>Page not found</h1>', $html);
        }
        $visible = $this->addPost($app, $blog, self::postRecord(3));
        self::assertStringNotContainsString('post-nav', self::main($this->both("/s/{$visible}")[1]), 'hidden posts are never neighbours');
    }

    public function testTheJsonRouteIsUnchanged(): void
    {
        [, $id] = $this->three();
        $app = $this->site();
        $response = $this->get("/s/{$id}.json");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        $post = $app->container()->get(PostRepository::class)->find($id) ?? self::fail('post missing');
        $blog = $this->blogs($app)->find('notes') ?? self::fail('blog missing');
        $expected = Controller::jsonResponse((new ItemSerializer())->item($blog, $post, 'http://xar.test'));
        self::assertSame((string) $expected->getBody(), (string) $response->getBody());
    }

    public function testAMirrorPatternIsTheCanonicalAndTheLink(): void
    {
        $app = $this->enableBlog();
        $blog = $this->blogs($app)->create([
            'handle' => 'mirror', 'mode' => 'mirror', 'title' => 'Mirror',
            'source_url' => 'https://src.test/feed.json', 'source_format' => 'athena',
            'post_url_pattern' => 'https://www.example.test/post.html?id={id}',
        ], $this->now());
        $older = $this->addPost($app, $blog, self::postRecord(2));
        $id = $this->addPost($app, $blog, self::postRecord(1, ['url' => 'https://src.test/s/1']));
        [, $html] = $this->both("/s/{$id}");
        $canonical = "https://www.example.test/post.html?id={$id}";
        self::assertStringContainsString("<link rel=\"canonical\" href=\"{$canonical}\">", $html);
        self::assertStringContainsString("<meta property=\"og:url\" content=\"{$canonical}\">", $html);
        self::assertStringContainsString("<a class=\"post-permalink\" href=\"{$canonical}\">", $html);
        self::assertStringContainsString("Older:<a href=\"https://www.example.test/post.html?id={$older}\">Post 2</a>", $html);
    }
}
```

`tests/Module/Blog/Http/TagPageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Module\Blog\Post\PostRepository;

final class TagPageTest extends BlogPageTestCase
{
    public function testATagListsItsPostsNewestFirstTwentyAPage(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        for ($n = 0; $n < 30; $n++) { // every fifth post is untagged
            $this->addPost($app, $blog, self::postRecord($n, ['tags' => $n % 5 === 4 ? [] : [['name' => 'Travel', 'slug' => 'travel']]]));
        }
        [$response, $html] = $this->both('/blog/notes/tag/travel');
        self::assertSecureHtml($response);
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<title>Travel · Notes</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/tag/travel">', $html);
        $main = self::main($html);
        self::assertStringContainsString('<header class="blog-header"><p class="blog-name">Notes</p><h1 class="blog-title">Tagged “Travel”</h1></header>', $main);
        self::assertSame(20, substr_count($main, '<article '));
        self::assertStringContainsString('>Post 0</a></h2>', $main);
        self::assertStringNotContainsString('>Post 4</a>', $main, 'untagged');
        self::assertMatchesRegularExpression('#<a class="pager-next" rel="next" href="/blog/notes/tag/travel\?before=([A-Za-z0-9_-]+)">Older posts</a>#', $main);
        self::assertStringNotContainsString('pager-prev', $main);

        preg_match('#href="(/blog/notes/tag/travel\?before=[A-Za-z0-9_-]+)"#', $main, $m);
        [, $html] = $this->both($m[1]);
        $page2 = self::main($html);
        self::assertSame(['Post 25', 'Post 26', 'Post 27', 'Post 28'], self::titles($page2));
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes/tag/travel">Newest posts</a>', $page2);
        self::assertStringNotContainsString('pager-next', $page2);
    }

    public function testATagWithNoLivePostsIs404(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $tag = [['name' => 'gone', 'slug' => 'gone']];
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => $tag, 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(2, ['tags' => $tag]));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-2'], $this->now());
        [$response, $html] = $this->both('/blog/notes/tag/gone', 404);
        self::assertSecureHtml($response);
        self::assertStringContainsString('<h1>Page not found</h1>', $html);
        $this->both('/blog/notes/tag/never', 404);
        $this->both('/blog/nope/tag/gone', 404);
    }

    public function testSlugsAreDecodedOnceAndComparedExactly(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['title' => 'Percent', 'tags' => [['name' => '100%', 'slug' => '100%']]]));
        $this->addPost($app, $blog, self::postRecord(2, ['title' => 'Unicode', 'tags' => [['name' => 'Café', 'slug' => 'café']]]));
        $this->addPost($app, $blog, self::postRecord(3, ['title' => 'Plus', 'tags' => [['name' => 'a+b', 'slug' => 'a+b']]]));
        self::assertSame(['Percent'], self::titles(self::main($this->both('/blog/notes/tag/100%25')[1])));
        self::assertSame(['Unicode'], self::titles(self::main($this->both('/blog/notes/tag/caf%C3%A9')[1])));
        self::assertSame(['Plus'], self::titles(self::main($this->both('/blog/notes/tag/a%2Bb')[1])));
        $this->both('/blog/notes/tag/100%2525', 404); // decoded once: "100%25", not "100%"
        $this->both('/blog/notes/tag/a%2Fb', 404);    // "/" never matches a slug segment
        $this->both('/blog/notes/tag/%FF', 404);      // not UTF-8: refused before the database sees it
        $this->both('/blog/notes/tag/%25', 404);
    }

    public function testMalformedCursorsAre400ThroughTheTheme(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => [['name' => 't', 'slug' => 't']]]));
        foreach (['?before=!!!', '?before=', '?before[]=x', '?before=' . rtrim(strtr(base64_encode('2026-13-45 99:99:99|01m3t19wn8reaww81zg1m47tjq'), '+/', '-_'), '=')] as $query) {
            [$response, $html] = $this->both('/blog/notes/tag/t' . $query, 400);
            self::assertSecureHtml($response);
            self::assertStringContainsString('<h1>400 Bad Request</h1><p>Invalid before cursor</p>', $html, $query);
        }
    }

    /** @return list<string> the titles of the post cards, in page order */
    private static function titles(string $html): array
    {
        preg_match_all('#<h2 class="post-title"><a href="[^"]*">([^<]*)</a></h2>#', $html, $m);

        return $m[1];
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/Http/PostPageTest.php tests/Module/Blog/Http/TagPageTest.php`
Expected: failures, the `/s/{id}` and `/blog/notes/tag/…` requests return 404. `testTheJsonRouteIsUnchanged` already passes, and must keep passing.

- [ ] **Step 3: Implement the page support classes**

`modules/blog/src/Http/BlogPage.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\HtmlSanitizer;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Support\Text;

/** What every blog page shares: the blog lookup, the <head> (title, meta, feeds, canonical, lang), blog.css and caching. */
final class BlogPage
{
    public const PAGE_SIZE = 20;

    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly UrlGenerator $urls,
        private readonly AssetUrls $assets,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function blog(string $handle): Blog
    {
        return $this->blogs->find($handle) ?? throw new NotFound("No blog '{$handle}'");
    }

    /**
     * @param array<string, mixed> $data body template variables; "blog" is added
     * @param string $name the page name: "<name> · <blog title>"; '' for the blog home
     * @param ?string $description plain text; null uses the blog's description
     */
    public function page(
        Blog $blog,
        string $template,
        array $data,
        string $name,
        string $canonical,
        ?string $description = null,
        string $ogType = 'website',
        ?string $image = null,
    ): Page {
        $description ??= Text::plain($this->descriptionHtml($blog));
        $meta = [];
        if ($description !== '') {
            $meta['description'] = $description;
        }
        $meta['og:title'] = $name === '' ? $blog->title : $name;
        $meta['og:type'] = $ogType;
        $meta['og:url'] = $canonical;
        if ($image !== null) {
            $meta['og:image'] = $image;
        }

        return new Page(
            $template,
            ['blog' => $this->summary($blog), ...$data],
            title: $name === '' ? $blog->title : $name . ' · ' . $blog->title,
            meta: $meta,
            feeds: [
                ['type' => 'application/feed+json', 'title' => $blog->title, 'href' => $this->urls->generate('blog.feed.json', ['handle' => $blog->handle], true)],
                ['type' => 'application/rss+xml', 'title' => $blog->title, 'href' => $this->urls->generate('blog.feed.xml', ['handle' => $blog->handle], true)],
            ],
            canonical: $canonical,
            lang: $blog->language,
            styles: [$this->assets->url('module/blog/blog.css')],
            headers: ['Cache-Control' => 'public, max-age=60'],
        );
    }

    /** @return array{handle: string, title: string, description: string} */
    public function summary(Blog $blog): array
    {
        return ['handle' => $blog->handle, 'title' => $blog->title, 'description' => Text::plain($this->descriptionHtml($blog))];
    }

    /** The blog's description_html, sanitised (so a <script>'s text never reaches the plain-text description either). */
    public function descriptionHtml(Blog $blog): string
    {
        return $this->sanitizer->clean($blog->descriptionHtml ?? '');
    }
}
```

`modules/blog/src/Http/Paging.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Module\Blog\Feed\Cursor;

/** Query parameters of paged pages; anything malformed is a 400. */
final class Paging
{
    /** @return array{0: DateTimeImmutable, 1: string}|null the decoded ?before= cursor */
    public static function before(ServerRequestInterface $request): ?array
    {
        $value = self::query($request)['before'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new HttpException(400, 'Invalid before cursor');
        }
        try {
            return Cursor::decode($value);
        } catch (InvalidArgumentException) {
            throw new HttpException(400, 'Invalid before cursor');
        }
    }

    /** The 1-based ?page= number; 1 when absent. */
    public static function page(ServerRequestInterface $request): int
    {
        $value = self::query($request)['page'] ?? null;
        if ($value === null) {
            return 1;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,5}$/D', $value) !== 1) {
            throw new HttpException(400, 'Invalid page number');
        }

        return (int) $value;
    }

    /**
     * Route parameters for a link to the same page: ['before' => cursor] or [].
     *
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return array<string, string>
     */
    public static function cursorParam(?array $before): array
    {
        return $before === null ? [] : ['before' => Cursor::encode($before[0], $before[1])];
    }

    /** @return array<mixed> */
    public static function query(ServerRequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }
}
```

- [ ] **Step 4: Add the repository queries**

In `modules/blog/src/Post/PostRepository.php`, add `use Xaraya\Kernel\Db\Select;` after `use Xaraya\Kernel\Db\Connection;`, and add these methods just before `publishedAfter()`:
```php
    /**
     * The live posts just before (older) and just after (newer) $post in its blog, by (date_published, id).
     *
     * @return array{0: ?Post, 1: ?Post}
     */
    public function neighbours(Post $post, DateTimeImmutable $now): array
    {
        $live = fn(): Select => $this->db->select('posts')
            ->where('blog_id', '=', $post->blogId)
            ->where('status', '=', 'published')
            ->where('date_published', '<=', $now);
        $at = ['date_published' => $post->datePublished, 'id' => $post->id];
        $older = $live()->cursor($at, '<')->orderBy('date_published', 'desc')->orderBy('id', 'desc')->first();
        $newer = $live()->cursor($at, '>')->orderBy('date_published')->orderBy('id')->first();

        return [
            $older === null ? null : $this->hydrate([$older])[0],
            $newer === null ? null : $this->hydrate([$newer])[0],
        ];
    }

    /**
     * A blog's live posts carrying the tag $slug (compared exactly), newest first, keyset-paged like page().
     *
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return list<Post>
     */
    public function pageByTag(int $blogId, string $slug, DateTimeImmutable $now, ?array $before, int $limit): array
    {
        $sql = 'SELECT p.* FROM {posts} p JOIN {post_tags} t ON t.post_id = p.id'
            . ' WHERE t.blog_id = ? AND t.slug = ? AND p.blog_id = ? AND p.status = ? AND p.date_published <= ?';
        $params = [$blogId, $slug, $blogId, 'published', $now];
        if ($before !== null) {
            $sql .= ' AND (p.date_published < ? OR (p.date_published = ? AND p.id < ?))';
            array_push($params, $before[0], $before[0], $before[1]);
        }
        $sql .= ' ORDER BY p.date_published DESC, p.id DESC LIMIT ' . max(0, $limit);

        return $this->hydrate($this->db->fetchAll($sql, $params));
    }
```

- [ ] **Step 5: Add the controllers, routes and templates**

`modules/blog/src/Http/PostPageController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\PostViewFactory;

/** GET /s/{id}: one post in full. GET /s/{id}.json stays with PostController. */
final class PostPageController extends Controller
{
    public function __construct(
        private readonly BlogRepository $blogs,
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
        private readonly MediaUrls $media,
        private readonly BlogPage $pages,
    ) {}

    /** @param array<string, string> $params */
    public function show(ServerRequestInterface $request, array $params): Page
    {
        $now = BlogPage::now();
        $post = $this->posts->find($params['id']);
        if ($post === null || $post->status !== 'published' || $post->datePublished > $now) {
            throw new NotFound();
        }
        $blog = $this->blogs->findById($post->blogId) ?? throw new NotFound();
        $view = $this->factory->make($blog, $post, $this->media->map($blog, [$post]));
        [$older, $newer] = $this->posts->neighbours($post, $now);

        return $this->pages->page(
            $blog,
            'blog::post',
            ['post' => $view, 'older' => $this->neighbour($blog, $older), 'newer' => $this->neighbour($blog, $newer)],
            $view->title,
            $view->canonicalUrl,
            $view->excerpt !== '' ? $view->excerpt : null,
            'article',
            $view->image,
        );
    }

    /** @return array{title: string, url: string}|null */
    private function neighbour(Blog $blog, ?Post $post): ?array
    {
        return $post === null ? null : ['title' => $post->title, 'url' => $this->factory->link($blog, $post)];
    }
}
```

`modules/blog/src/Http/BlogPagesController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\Cursor;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\PostView;
use Xaraya\Module\Blog\View\PostViewFactory;

/** The paged post lists: a tag's posts (and, from Task 9, the blog home). */
final class BlogPagesController extends Controller
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
        private readonly MediaUrls $media,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, string> $params */
    public function tag(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $slug = $params['slug'];
        if (!mb_check_encoding($slug, 'UTF-8')) {
            throw new NotFound(); // no stored slug can match, and databases reject the bytes
        }
        $before = Paging::before($request);
        $rows = $this->posts->pageByTag($blog->id, $slug, BlogPage::now(), $before, BlogPage::PAGE_SIZE + 1);
        if ($rows === [] && $before === null) {
            throw new NotFound('No published posts have this tag');
        }
        $name = $slug;
        foreach ($rows === [] ? [] : $rows[0]->tags as $tag) {
            if ($tag['slug'] === $slug) {
                $name = $tag['name'];
                break;
            }
        }
        $route = ['handle' => $blog->handle, 'slug' => $slug];
        [$posts, $pager] = $this->listing($blog, $rows, 'blog.tag', $route, $before);

        return $this->pages->page(
            $blog,
            'blog::tag',
            ['tag' => ['name' => $name, 'slug' => $slug], 'posts' => $posts, 'pager' => $pager],
            $name,
            $this->urls->generate('blog.tag', $route + Paging::cursorParam($before), true),
        );
    }

    /**
     * One page of a keyset-paged list.
     *
     * @param list<Post> $rows up to PAGE_SIZE + 1 posts; the extra one only says there is a next page
     * @param array<string, string> $route the list route's parameters
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return array{0: list<PostView>, 1: array{prev: ?string, next: ?string, prevLabel: string, nextLabel: string}}
     */
    private function listing(Blog $blog, array $rows, string $routeName, array $route, ?array $before): array
    {
        $page = array_slice($rows, 0, BlogPage::PAGE_SIZE);
        $next = null;
        if (count($rows) > BlogPage::PAGE_SIZE) {
            $last = $page[BlogPage::PAGE_SIZE - 1];
            $next = $this->urls->generate($routeName, $route + ['before' => Cursor::encode($last->datePublished, $last->id)]);
        }
        $media = $this->media->map($blog, $page);

        return [
            array_map(fn(Post $post): PostView => $this->factory->make($blog, $post, $media), $page),
            [
                'prev' => $before === null ? null : $this->urls->generate($routeName, $route),
                'next' => $next,
                'prevLabel' => 'Newest posts',
                'nextLabel' => 'Older posts',
            ],
        ];
    }
}
```

Replace `modules/blog/src/Routes.php` with this. The JSON, RSS and media routes are unchanged.

`modules/blog/src/Routes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
use Xaraya\Module\Blog\Http\PostController;
use Xaraya\Module\Blog\Http\PostPageController;

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
        $routes->get('/media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}', [MediaController::class, 'show'], 'blog.media');

        // HTML pages.
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', [BlogPagesController::class, 'tag'], 'blog.tag')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');
    }
}
```

`modules/blog/templates/partials/pager.php`:
```php
<?php if ($pager['prev'] !== null || $pager['next'] !== null): ?>
<nav class="pager" aria-label="<?= $x->e($x->t('Pages')) ?>">
<?php if ($pager['prev'] !== null): ?>
<a class="pager-prev" href="<?= $x->e($pager['prev']) ?>"><?= $x->e($x->t($pager['prevLabel'])) ?></a>
<?php endif ?>
<?php if ($pager['next'] !== null): ?>
<a class="pager-next" rel="next" href="<?= $x->e($pager['next']) ?>"><?= $x->e($x->t($pager['nextLabel'])) ?></a>
<?php endif ?>
</nav>
<?php endif ?>
```

`modules/blog/templates/partials/pager.twig`:
```twig
{% if pager.prev is not null or pager.next is not null %}
<nav class="pager" aria-label="{{ t('Pages') }}">
{% if pager.prev is not null %}
<a class="pager-prev" href="{{ pager.prev }}">{{ t(pager.prevLabel) }}</a>
{% endif %}
{% if pager.next is not null %}
<a class="pager-next" rel="next" href="{{ pager.next }}">{{ t(pager.nextLabel) }}</a>
{% endif %}
</nav>
{% endif %}
```

`modules/blog/templates/post.php`:
```php
<?= $x->render('blog::partials/post', ['post' => $post, 'full' => true]) ?>
<?php if ($newer !== null || $older !== null): ?>
<nav class="post-nav" aria-label="<?= $x->e($x->t('More posts')) ?>">
<?php if ($newer !== null): ?>
<p class="post-nav-newer"><?= $x->e($x->t('Newer:')) ?> <a href="<?= $x->e($newer['url']) ?>"><?= $x->e($newer['title']) ?></a></p>
<?php endif ?>
<?php if ($older !== null): ?>
<p class="post-nav-older"><?= $x->e($x->t('Older:')) ?> <a href="<?= $x->e($older['url']) ?>"><?= $x->e($older['title']) ?></a></p>
<?php endif ?>
</nav>
<?php endif ?>
```

`modules/blog/templates/post.twig`:
```twig
{{ render('blog::partials/post', {post: post, full: true}) }}
{% if newer is not null or older is not null %}
<nav class="post-nav" aria-label="{{ t('More posts') }}">
{% if newer is not null %}
<p class="post-nav-newer">{{ t('Newer:') }} <a href="{{ newer.url }}">{{ newer.title }}</a></p>
{% endif %}
{% if older is not null %}
<p class="post-nav-older">{{ t('Older:') }} <a href="{{ older.url }}">{{ older.title }}</a></p>
{% endif %}
</nav>
{% endif %}
```

`modules/blog/templates/tag.php`:
```php
<header class="blog-header">
<p class="blog-name"><?= $x->e($blog['title']) ?></p>
<h1 class="blog-title"><?= $x->e($x->t('Tagged “{tag}”', ['tag' => $tag['name']])) ?></h1>
</header>
<?php if ($posts === []): ?>
<p class="blog-empty"><?= $x->e($x->t('No more posts.')) ?></p>
<?php else: ?>
<div class="post-list">
<?php foreach ($posts as $post): ?>
<?= $x->render('blog::partials/post', ['post' => $post, 'full' => false]) ?>
<?php endforeach ?>
</div>
<?php endif ?>
<?= $x->render('blog::partials/pager', ['pager' => $pager]) ?>
```

`modules/blog/templates/tag.twig`:
```twig
<header class="blog-header">
<p class="blog-name">{{ blog.title }}</p>
<h1 class="blog-title">{{ t('Tagged “{tag}”', {tag: tag.name}) }}</h1>
</header>
{% if posts is empty %}
<p class="blog-empty">{{ t('No more posts.') }}</p>
{% else %}
<div class="post-list">
{% for post in posts %}
{{ render('blog::partials/post', {post: post, full: false}) }}
{% endfor %}
</div>
{% endif %}
{{ render('blog::partials/pager', {pager: pager}) }}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/Http tests/Module/Blog/View tests/Module/Blog/AthenaParityTest.php`
Expected: PASS. `PostPageTest` has 4 tests and `TagPageTest` 4.

- [ ] **Step 7: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL run of `tests/Module/Blog/Http`.
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add modules/blog/src/Http modules/blog/src/Post/PostRepository.php modules/blog/src/Routes.php modules/blog/templates tests/Module/Blog/Http
git commit -m "feat(blog): serve /s/{id} and tag listings as themed HTML pages" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Home page with paging and the pinned post

**Files:**
- Create: `modules/blog/templates/home.php`, `modules/blog/templates/home.twig`
- Modify: `modules/blog/src/Http/BlogPagesController.php` (`home`), `modules/blog/src/Post/PostRepository.php` (`findLiveByItemId`), `modules/blog/src/Routes.php` (`blog.home`)
- Test: `tests/Module/Blog/Http/HomePageTest.php`

**Interfaces:**
- **Consumes:**
  - `BlogPagesController::listing()`, `BlogPage`, `Paging` and `BlogPageTestCase` (Task 8).
  - `PostRepository::page(blogId, now, before, limit)` (Plan A) and `Blog::$pinnedItemId` (a source item id).
  - `BlogRepository::update($blog, ['pinned_item_id' => …], $now)`.
- **Produces `PostRepository::findLiveByItemId(int $blogId, string $itemId, DateTimeImmutable $now): ?Post`.**
- **Produces the route** `blog.home` `GET /blog/{handle}` → `BlogPagesController::home`, with `secureHtml` and `conditional`.
- **Produces the template contract** `blog::home` `{blog, posts: list<PostView>, pinned: ?PostView, pager}`. The pinned post is only set on page 1 (no cursor), and only when it is live.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Http/HomePageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Kernel\App;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\Cursor;
use Xaraya\Module\Blog\Post\PostRepository;

final class HomePageTest extends BlogPageTestCase
{
    private App $site;

    private Blog $blog;

    /** Posts 0 (newest) to $count - 1, an hour apart. */
    private function posts(int $count): void
    {
        $this->site = $this->enableBlog();
        $this->blog = $this->nativeBlog($this->site);
        for ($n = 0; $n < $count; $n++) {
            $this->addPost($this->site, $this->blog, self::postRecord($n));
        }
    }

    private function pin(string $itemId): void
    {
        $this->blogs($this->site)->update($this->blog, ['pinned_item_id' => $itemId], $this->now());
    }

    /** @return list<string> the titles of the post cards, in page order */
    private static function titles(string $html): array
    {
        preg_match_all('#<h2 class="post-title"><a href="[^"]*">([^<]*)</a></h2>#', $html, $m);

        return $m[1];
    }

    private static function next(string $html): string
    {
        self::assertSame(1, preg_match('#<a class="pager-next" rel="next" href="(/blog/notes\?before=[A-Za-z0-9_-]+)">Older posts</a>#', $html, $m), 'no next link');

        return $m[1];
    }

    public function testTheHomePagePagesThroughEveryPostNewestFirst(): void
    {
        $this->posts(45);
        [$response, $html] = $this->both('/blog/notes');
        self::assertSecureHtml($response);
        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
        foreach ([
            '<html lang="en">',
            '<title>Notes</title>',
            '<meta name="description" content="Short notes &amp; links.">',
            '<meta property="og:title" content="Notes">',
            '<meta property="og:type" content="website">',
            '<meta property="og:url" content="http://xar.test/blog/notes">',
            '<link rel="canonical" href="http://xar.test/blog/notes">',
            '<link rel="alternate" type="application/feed+json" title="Notes" href="http://xar.test/blog/notes/feed.json">',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        $main = self::main($html);
        self::assertStringStartsWith('<header class="blog-header"><h1 class="blog-title">Notes</h1><p class="blog-description">Short notes &amp; links.</p></header>', $main);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(0, 19)), self::titles($main));
        self::assertStringNotContainsString('pager-prev', $main);

        [, $html] = $this->both(self::next($main));
        $page2 = self::main($html);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(20, 39)), self::titles($page2));
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes">Newest posts</a>', $page2);

        [, $html] = $this->both(self::next($page2));
        $page3 = self::main($html);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(40, 44)), self::titles($page3));
        self::assertStringNotContainsString('pager-next', $page3);
    }

    public function testAnEmptyBlogAndAnUnknownBlog(): void
    {
        $this->posts(0);
        [, $html] = $this->both('/blog/notes');
        self::assertStringContainsString('<p class="blog-empty">No posts yet.</p>', self::main($html));
        self::assertStringNotContainsString('class="pager"', $html);
        [$response, $html] = $this->both('/blog/nope', 404);
        self::assertSecureHtml($response);
        self::assertStringContainsString('<h1>Page not found</h1>', $html);
    }

    public function testThePinnedPostSitsAbovePageOneOnly(): void
    {
        $this->posts(25);
        $this->pin('item-22');
        [, $html] = $this->both('/blog/notes');
        $main = self::main($html);
        self::assertStringContainsString(
            '<section class="blog-pinned" aria-label="Pinned post"><article class="post post-note post-pinned"><p class="post-pinned-label">Pinned</p><h2 class="post-title"><a href="/s/',
            $main,
        );
        self::assertSame('Post 22', self::titles($main)[0]);
        self::assertSame(array_map(static fn(int $n): string => "Post {$n}", range(0, 19)), array_slice(self::titles($main), 1));
        [, $html] = $this->both(self::next($main));
        self::assertStringNotContainsString('blog-pinned', $html);
    }

    public function testAPinnedPostThatIsHiddenIsNotShown(): void
    {
        $this->posts(3);
        $this->addPost($this->site, $this->blog, self::postRecord(9, ['itemId' => 'later', 'date' => $this->now()->modify('+1 day')]));
        $this->site->container()->get(PostRepository::class)->tombstone($this->blog->id, ['item-2'], $this->now());
        foreach (['later', 'item-2', 'no-such-item'] as $itemId) {
            $this->pin($itemId);
            [, $html] = $this->both('/blog/notes');
            self::assertStringNotContainsString('blog-pinned', $html, $itemId);
            self::assertSame(['Post 0', 'Post 1'], self::titles(self::main($html)), $itemId);
        }
    }

    public function testCursorTampering(): void
    {
        $this->posts(3);
        $scheduled = $this->addPost($this->site, $this->blog, self::postRecord(9, ['title' => 'Scheduled', 'date' => $this->now()->modify('+1 year')]));
        foreach (['?before=!!!', '?before=', '?before[]=x', '?before=' . str_repeat('A', 201)] as $query) {
            [$response, $html] = $this->both('/blog/notes' . $query, 400);
            self::assertSecureHtml($response);
            self::assertStringContainsString('<h1>400 Bad Request</h1><p>Invalid before cursor</p>', $html, $query);
        }
        // A well-formed cursor from the far future still never reaches a scheduled post.
        $future = Cursor::encode($this->now()->modify('+10 years'), $scheduled);
        [, $html] = $this->both('/blog/notes?before=' . $future);
        self::assertSame(['Post 0', 'Post 1', 'Post 2'], self::titles(self::main($html)));
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes?before=' . $future . '">', $html);
        // One from before the oldest post is an empty page, not an error.
        [, $html] = $this->both('/blog/notes?before=' . Cursor::encode($this->now()->modify('-10 years'), $scheduled));
        self::assertStringContainsString('<p class="blog-empty">No posts yet.</p>', self::main($html));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Http/HomePageTest.php`
Expected: failures, `/blog/notes` returns 404.

- [ ] **Step 3: Implement**

In `modules/blog/src/Post/PostRepository.php`, add this method just before `neighbours()`:
```php
    /** The blog's post with this source item id, if it is live: published and not scheduled. */
    public function findLiveByItemId(int $blogId, string $itemId, DateTimeImmutable $now): ?Post
    {
        $row = $this->db->select('posts')
            ->where('blog_id', '=', $blogId)
            ->where('item_id', '=', $itemId)
            ->where('status', '=', 'published')
            ->where('date_published', '<=', $now)
            ->first();

        return $row === null ? null : $this->hydrate([$row])[0];
    }
```

Replace `modules/blog/src/Http/BlogPagesController.php` with:

`modules/blog/src/Http/BlogPagesController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\NotFound;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\Feed\Cursor;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\PostView;
use Xaraya\Module\Blog\View\PostViewFactory;

/** The paged post lists: the blog home and a tag's posts. */
final class BlogPagesController extends Controller
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
        private readonly MediaUrls $media,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
    ) {}

    /** @param array<string, string> $params */
    public function home(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $before = Paging::before($request);
        $now = BlogPage::now();
        $rows = $this->posts->page($blog->id, $now, $before, BlogPage::PAGE_SIZE + 1);
        $route = ['handle' => $blog->handle];
        [$posts, $pager] = $this->listing($blog, $rows, 'blog.home', $route, $before);
        $pinned = null;
        if ($before === null && $blog->pinnedItemId !== null) {
            // A pinned post that is deleted, scheduled or unknown is simply not shown.
            $post = $this->posts->findLiveByItemId($blog->id, $blog->pinnedItemId, $now);
            $pinned = $post === null ? null : $this->factory->make($blog, $post, $this->media->map($blog, [$post]));
        }

        return $this->pages->page(
            $blog,
            'blog::home',
            ['posts' => $posts, 'pinned' => $pinned, 'pager' => $pager],
            '',
            $this->urls->generate('blog.home', $route + Paging::cursorParam($before), true),
        );
    }

    /** @param array<string, string> $params */
    public function tag(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $slug = $params['slug'];
        if (!mb_check_encoding($slug, 'UTF-8')) {
            throw new NotFound(); // no stored slug can match, and databases reject the bytes
        }
        $before = Paging::before($request);
        $rows = $this->posts->pageByTag($blog->id, $slug, BlogPage::now(), $before, BlogPage::PAGE_SIZE + 1);
        if ($rows === [] && $before === null) {
            throw new NotFound('No published posts have this tag');
        }
        $name = $slug;
        foreach ($rows === [] ? [] : $rows[0]->tags as $tag) {
            if ($tag['slug'] === $slug) {
                $name = $tag['name'];
                break;
            }
        }
        $route = ['handle' => $blog->handle, 'slug' => $slug];
        [$posts, $pager] = $this->listing($blog, $rows, 'blog.tag', $route, $before);

        return $this->pages->page(
            $blog,
            'blog::tag',
            ['tag' => ['name' => $name, 'slug' => $slug], 'posts' => $posts, 'pager' => $pager],
            $name,
            $this->urls->generate('blog.tag', $route + Paging::cursorParam($before), true),
        );
    }

    /**
     * One page of a keyset-paged list.
     *
     * @param list<Post> $rows up to PAGE_SIZE + 1 posts; the extra one only says there is a next page
     * @param array<string, string> $route the list route's parameters
     * @param array{0: DateTimeImmutable, 1: string}|null $before
     * @return array{0: list<PostView>, 1: array{prev: ?string, next: ?string, prevLabel: string, nextLabel: string}}
     */
    private function listing(Blog $blog, array $rows, string $routeName, array $route, ?array $before): array
    {
        $page = array_slice($rows, 0, BlogPage::PAGE_SIZE);
        $next = null;
        if (count($rows) > BlogPage::PAGE_SIZE) {
            $last = $page[BlogPage::PAGE_SIZE - 1];
            $next = $this->urls->generate($routeName, $route + ['before' => Cursor::encode($last->datePublished, $last->id)]);
        }
        $media = $this->media->map($blog, $page);

        return [
            array_map(fn(Post $post): PostView => $this->factory->make($blog, $post, $media), $page),
            [
                'prev' => $before === null ? null : $this->urls->generate($routeName, $route),
                'next' => $next,
                'prevLabel' => 'Newest posts',
                'nextLabel' => 'Older posts',
            ],
        ];
    }
}
```

Replace `modules/blog/src/Routes.php` with:

`modules/blog/src/Routes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
use Xaraya\Module\Blog\Http\PostController;
use Xaraya\Module\Blog\Http\PostPageController;

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
        $routes->get('/media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}', [MediaController::class, 'show'], 'blog.media');

        // HTML pages.
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}', [BlogPagesController::class, 'home'], 'blog.home')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', [BlogPagesController::class, 'tag'], 'blog.tag')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');
    }
}
```

`modules/blog/templates/home.php`:
```php
<header class="blog-header">
<h1 class="blog-title"><?= $x->e($blog['title']) ?></h1>
<?php if ($blog['description'] !== ''): ?>
<p class="blog-description"><?= $x->e($blog['description']) ?></p>
<?php endif ?>
</header>
<?php if ($pinned !== null): ?>
<section class="blog-pinned" aria-label="<?= $x->e($x->t('Pinned post')) ?>">
<?= $x->render('blog::partials/post', ['post' => $pinned, 'full' => false]) ?>
</section>
<?php endif ?>
<?php if ($posts === []): ?>
<p class="blog-empty"><?= $x->e($x->t('No posts yet.')) ?></p>
<?php else: ?>
<div class="post-list">
<?php foreach ($posts as $post): ?>
<?= $x->render('blog::partials/post', ['post' => $post, 'full' => false]) ?>
<?php endforeach ?>
</div>
<?php endif ?>
<?= $x->render('blog::partials/pager', ['pager' => $pager]) ?>
```

`modules/blog/templates/home.twig`:
```twig
<header class="blog-header">
<h1 class="blog-title">{{ blog.title }}</h1>
{% if blog.description != '' %}
<p class="blog-description">{{ blog.description }}</p>
{% endif %}
</header>
{% if pinned is not null %}
<section class="blog-pinned" aria-label="{{ t('Pinned post') }}">
{{ render('blog::partials/post', {post: pinned, full: false}) }}
</section>
{% endif %}
{% if posts is empty %}
<p class="blog-empty">{{ t('No posts yet.') }}</p>
{% else %}
<div class="post-list">
{% for post in posts %}
{{ render('blog::partials/post', {post: post, full: false}) }}
{% endfor %}
</div>
{% endif %}
{{ render('blog::partials/pager', {pager: pager}) }}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/Http`
Expected: PASS. `HomePageTest` has 5 tests.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL run of `tests/Module/Blog/Http/HomePageTest.php`.
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Http/BlogPagesController.php modules/blog/src/Post/PostRepository.php modules/blog/src/Routes.php modules/blog/templates/home.php modules/blog/templates/home.twig tests/Module/Blog/Http/HomePageTest.php
git commit -m "feat(blog): add the blog home page with cursor paging and the pinned post" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Tag cloud page

**Files:**
- Create: `modules/blog/src/View/TagCloud.php`, `modules/blog/src/Http/TagsController.php`
- Create, each as `.php` and `.twig`, under `modules/blog/templates/`: `partials/tagcloud` and `tags`
- Modify: `modules/blog/src/Post/PostRepository.php` (`tagCounts`), `modules/blog/src/Routes.php` (`blog.tags`)
- Test: `tests/Module/Blog/View/TagCloudTest.php`, `tests/Module/Blog/Http/TagsPageTest.php`

**Interfaces:**
- **Consumes:** `BlogPage`, `BlogPageTestCase` (Task 8), and `Translator::t()` for the page name.
- **Produces `PostRepository::tagCounts(int $blogId, DateTimeImmutable $now, ?int $limit = null): list<array{name: string, slug: string, count: int}>`:** live posts only, most used first, then by slug. A slug's name is `MIN(name)`.
- **Produces `View\TagCloud::build(list<{name, slug, count}>): list<{name, slug, count, step: int}>`:** steps run from 1 to 5 by count quantile (`1 + intdiv(4 * below, n - 1)`, where `below` counts the tags used less often), and the list is sorted by lower-cased name, then slug. A single tag, or tags that all share one count, get step 1.
- **Produces the route** `blog.tags` → `TagsController::tags`.
- **Produces the template contract** `blog::partials/tagcloud` `{tags: list<{name, slug, count, step, url}>}`, rendered as `<ul class="tag-cloud">` with `tag-step-{1..5}` items. Task 13's block reuses it.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Blog/View/TagCloudTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\View;

use PHPUnit\Framework\TestCase;
use Xaraya\Module\Blog\View\TagCloud;

final class TagCloudTest extends TestCase
{
    public function testStepsFollowCountQuantilesAndTagsSortByName(): void
    {
        $cloud = TagCloud::build([
            ['name' => 'zeta', 'slug' => 'zeta', 'count' => 10],
            ['name' => 'Alpha', 'slug' => 'alpha', 'count' => 1],
            ['name' => 'beta', 'slug' => 'beta', 'count' => 1],
            ['name' => 'gamma', 'slug' => 'gamma', 'count' => 2],
            ['name' => 'delta', 'slug' => 'delta', 'count' => 3],
        ]);
        self::assertSame(
            ['Alpha' => 1, 'beta' => 1, 'delta' => 4, 'gamma' => 3, 'zeta' => 5],
            array_column($cloud, 'step', 'name'),
        );
        self::assertSame(['name' => 'Alpha', 'slug' => 'alpha', 'count' => 1, 'step' => 1], $cloud[0]);
    }

    public function testEqualCountsShareTheSmallestStep(): void
    {
        self::assertSame([1, 1], array_column(TagCloud::build([
            ['name' => 'a', 'slug' => 'a', 'count' => 4],
            ['name' => 'b', 'slug' => 'b', 'count' => 4],
        ]), 'step'));
        self::assertSame([], TagCloud::build([]));
        self::assertSame([1], array_column(TagCloud::build([['name' => 'a', 'slug' => 'a', 'count' => 9]]), 'step'));
        self::assertSame([1, 5], array_column(TagCloud::build([
            ['name' => 'a', 'slug' => 'a', 'count' => 1],
            ['name' => 'b', 'slug' => 'b', 'count' => 2],
        ]), 'step'), 'the most used tag always gets the largest step');
    }
}
```

`tests/Module/Blog/Http/TagsPageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Module\Blog\Post\PostRepository;

final class TagsPageTest extends BlogPageTestCase
{
    public function testTheCloudCountsLivePostsAndSortsByName(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $tag = static fn(string $name): array => ['name' => $name, 'slug' => strtolower($name)];
        $this->addPost($app, $blog, self::postRecord(1, ['tags' => [$tag('Travel'), $tag('Food')]]));
        $this->addPost($app, $blog, self::postRecord(2, ['tags' => [$tag('Travel')]]));
        $this->addPost($app, $blog, self::postRecord(3, ['tags' => [$tag('Travel'), $tag('Art')]]));
        $this->addPost($app, $blog, self::postRecord(4, ['tags' => [$tag('Hidden'), $tag('Travel')], 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(5, ['tags' => [$tag('Gone'), $tag('Food')]]));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-5'], $this->now());
        $other = $this->nativeBlog($app, ['handle' => 'other']);
        $this->addPost($app, $other, self::postRecord(6, ['tags' => [$tag('Elsewhere')]]));

        [$response, $html] = $this->both('/blog/notes/tags');
        self::assertSecureHtml($response);
        self::assertStringContainsString('<title>Tags · Notes</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/tags">', $html);
        self::assertStringContainsString(
            '<h1 class="blog-title">Tags</h1></header><ul class="tag-cloud">'
            . '<li class="tag-step-1"><a href="/blog/notes/tag/art" rel="tag">Art</a><span class="tag-count">1</span></li>'
            . '<li class="tag-step-1"><a href="/blog/notes/tag/food" rel="tag">Food</a><span class="tag-count">1</span></li>'
            . '<li class="tag-step-5"><a href="/blog/notes/tag/travel" rel="tag">Travel</a><span class="tag-count">3</span></li></ul>',
            self::main($html),
        );
        foreach (['Hidden', 'Gone', 'Elsewhere'] as $absent) {
            self::assertStringNotContainsString($absent, self::main($html));
        }
    }

    public function testABlogWithNoTags(): void
    {
        $app = $this->enableBlog();
        $this->nativeBlog($app);
        [, $html] = $this->both('/blog/notes/tags');
        self::assertStringContainsString('<p class="blog-empty">No tags yet.</p>', self::main($html));
        $this->both('/blog/nope/tags', 404);
    }
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Blog/View/TagCloudTest.php tests/Module/Blog/Http/TagsPageTest.php`
Expected: errors (`TagCloud` not found) and failures (`/blog/notes/tags` is 404).

- [ ] **Step 3: Implement**

`modules/blog/src/View/TagCloud.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\View;

/** Sizes tags in five steps by count quantile and sorts them by name. */
final class TagCloud
{
    public const STEPS = 5;

    /**
     * @param list<array{name: string, slug: string, count: int}> $tags
     * @return list<array{name: string, slug: string, count: int, step: int}> by name, then slug
     */
    public static function build(array $tags): array
    {
        $counts = array_column($tags, 'count');
        sort($counts);
        $total = count($counts);
        $out = [];
        foreach ($tags as $tag) {
            // The share of the other tags used less often than this one picks the step: 1 (least used) to 5 (most).
            $below = 0;
            while ($below < $total && $counts[$below] < $tag['count']) {
                $below++;
            }
            $out[] = [...$tag, 'step' => $total > 1 ? 1 + intdiv((self::STEPS - 1) * $below, $total - 1) : 1];
        }
        usort($out, static fn(array $a, array $b): int => [mb_strtolower($a['name']), $a['slug']] <=> [mb_strtolower($b['name']), $b['slug']]);

        return $out;
    }
}
```

In `modules/blog/src/Post/PostRepository.php`, add this method just before `publishedAfter()`:
```php
    /**
     * Tags with at least one live post, most used first, then by slug. A slug's name is its smallest stored name.
     *
     * @return list<array{name: string, slug: string, count: int}>
     */
    public function tagCounts(int $blogId, DateTimeImmutable $now, ?int $limit = null): array
    {
        $sql = 'SELECT t.slug AS slug, MIN(t.name) AS name, COUNT(*) AS uses FROM {post_tags} t JOIN {posts} p ON p.id = t.post_id'
            . ' WHERE t.blog_id = ? AND p.blog_id = ? AND p.status = ? AND p.date_published <= ?'
            . ' GROUP BY t.slug ORDER BY uses DESC, t.slug ASC'
            . ($limit === null ? '' : ' LIMIT ' . max(0, $limit));
        $rows = $this->db->fetchAll($sql, [$blogId, $blogId, 'published', $now]);

        return array_map(static fn(array $row): array => [
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['uses'],
        ], $rows);
    }
```

`modules/blog/src/Http/TagsController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\Translator;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\TagCloud;

/** GET /blog/{handle}/tags: every tag with at least one live post. */
final class TagsController extends Controller
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /** @param array<string, string> $params */
    public function tags(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $tags = [];
        foreach (TagCloud::build($this->posts->tagCounts($blog->id, BlogPage::now())) as $tag) {
            $tags[] = [...$tag, 'url' => $this->urls->generate('blog.tag', ['handle' => $blog->handle, 'slug' => $tag['slug']])];
        }

        return $this->pages->page(
            $blog,
            'blog::tags',
            ['tags' => $tags],
            $this->translator->t('Tags'),
            $this->urls->generate('blog.tags', ['handle' => $blog->handle], true),
        );
    }
}
```

Replace `modules/blog/src/Routes.php` with:

`modules/blog/src/Routes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
use Xaraya\Module\Blog\Http\PostController;
use Xaraya\Module\Blog\Http\PostPageController;
use Xaraya\Module\Blog\Http\TagsController;

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
        $routes->get('/media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}', [MediaController::class, 'show'], 'blog.media');

        // HTML pages.
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}', [BlogPagesController::class, 'home'], 'blog.home')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tags', [TagsController::class, 'tags'], 'blog.tags')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', [BlogPagesController::class, 'tag'], 'blog.tag')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');
    }
}
```

`modules/blog/templates/partials/tagcloud.php`:
```php
<ul class="tag-cloud">
<?php foreach ($tags as $tag): ?>
<li class="tag-step-<?= $x->e($tag['step']) ?>"><a href="<?= $x->e($tag['url']) ?>" rel="tag"><?= $x->e($tag['name']) ?></a> <span class="tag-count"><?= $x->e($tag['count']) ?></span></li>
<?php endforeach ?>
</ul>
```

`modules/blog/templates/partials/tagcloud.twig`:
```twig
<ul class="tag-cloud">
{% for tag in tags %}
<li class="tag-step-{{ tag.step }}"><a href="{{ tag.url }}" rel="tag">{{ tag.name }}</a> <span class="tag-count">{{ tag.count }}</span></li>
{% endfor %}
</ul>
```

`modules/blog/templates/tags.php`:
```php
<header class="blog-header">
<p class="blog-name"><?= $x->e($blog['title']) ?></p>
<h1 class="blog-title"><?= $x->e($x->t('Tags')) ?></h1>
</header>
<?php if ($tags === []): ?>
<p class="blog-empty"><?= $x->e($x->t('No tags yet.')) ?></p>
<?php else: ?>
<?= $x->render('blog::partials/tagcloud', ['tags' => $tags]) ?>
<?php endif ?>
```

`modules/blog/templates/tags.twig`:
```twig
<header class="blog-header">
<p class="blog-name">{{ blog.title }}</p>
<h1 class="blog-title">{{ t('Tags') }}</h1>
</header>
{% if tags is empty %}
<p class="blog-empty">{{ t('No tags yet.') }}</p>
{% else %}
{{ render('blog::partials/tagcloud', {tags: tags}) }}
{% endif %}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog/View tests/Module/Blog/Http`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL run of `tests/Module/Blog/Http/TagsPageTest.php`.
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/View/TagCloud.php modules/blog/src/Http/TagsController.php modules/blog/src/Post/PostRepository.php modules/blog/src/Routes.php modules/blog/templates tests/Module/Blog/View/TagCloudTest.php tests/Module/Blog/Http/TagsPageTest.php
git commit -m "feat(blog): add the tag cloud page" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: About page

**Files:**
- Create: `modules/blog/src/Http/AboutController.php`, `modules/blog/templates/about.php`, `modules/blog/templates/about.twig`
- Modify: `modules/blog/src/Routes.php` (`blog.about`)
- Test: `tests/Module/Blog/Http/AboutPageTest.php`

**Interfaces:**
- **Consumes:**
  - `BlogPage::blog/page/descriptionHtml` and `BlogPageTestCase` (Task 8).
  - `SafeUrl::isSafe()`, `Translator::t()`, and `Blog::$authorName/$authorUrl/$extra`.
  - `extra._athenana.subscribe_url`, written by sync's `feedMeta()`.
- **Produces the route** `blog.about` → `AboutController::about`.
- **Produces the template contract** `blog::about` `{blog, descriptionHtml: string (sanitised), authorName: string, authorUrl: ?string, feeds: {json, rss} (absolute), subscribeUrl: ?string}`. Unsafe author and subscribe URLs become null.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Http/AboutPageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

final class AboutPageTest extends BlogPageTestCase
{
    public function testTheAboutPageInBothEngines(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app, [
            'description_html' => '<p>Notes &amp; <a href="https://ext.test/" onclick="x()">links</a>.</p><script>alert(1)</script>',
            'author_url' => 'https://ada.test/',
        ]);
        $this->blogs($app)->update($blog, ['extra' => ['_athenana' => ['subscribe_url' => 'https://ada.test/subscribe']]], $this->now());
        [$response, $html] = $this->both('/blog/notes/about');
        self::assertSecureHtml($response);
        self::assertStringContainsString('<title>About · Notes</title>', $html);
        self::assertStringContainsString('<meta name="description" content="Notes &amp; links.">', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/about">', $html);
        self::assertSame(
            '<article class="blog-about"><h1 class="blog-title">Notes</h1>'
            . '<div class="blog-description"><p>Notes &amp;<a href="https://ext.test/" rel="nofollow noopener ugc" target="_blank">links</a>.</p></div>'
            . '<p class="blog-author">By<a href="https://ada.test/" rel="author">Ada</a></p><h2>Follow</h2><ul class="blog-feeds">'
            . '<li><a href="http://xar.test/blog/notes/feed.json" type="application/feed+json">JSON Feed</a></li>'
            . '<li><a href="http://xar.test/blog/notes/feed.xml" type="application/rss+xml">RSS</a></li>'
            . '<li><a href="https://ada.test/subscribe">Subscribe by email</a></li></ul></article>',
            self::main($html),
        );
    }

    public function testMissingOrUnsafeLinksAreLeftOut(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app, ['description_html' => null, 'author_url' => null]);
        $this->blogs($app)->update($blog, ['extra' => ['_athenana' => ['subscribe_url' => 'javascript:alert(1)']]], $this->now());
        [, $html] = $this->both('/blog/notes/about');
        $main = self::main($html);
        self::assertStringNotContainsString('blog-description', $main);
        self::assertStringContainsString('<p class="blog-author">By Ada</p>', $main);
        self::assertStringNotContainsString('Subscribe', $main);
        self::assertStringNotContainsString('<meta name="description"', $html);
        $this->both('/blog/nope/about', 404);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Http/AboutPageTest.php`
Expected: failures, `/blog/notes/about` returns 404.

- [ ] **Step 3: Implement**

`modules/blog/src/Http/AboutController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\Support\SafeUrl;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\Translator;

/** GET /blog/{handle}/about: the blog's description, author, feeds and subscribe link. */
final class AboutController extends Controller
{
    public function __construct(
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /** @param array<string, string> $params */
    public function about(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $athenana = is_array($blog->extra['_athenana'] ?? null) ? $blog->extra['_athenana'] : [];
        $subscribe = $athenana['subscribe_url'] ?? null;
        $handle = ['handle' => $blog->handle];

        return $this->pages->page(
            $blog,
            'blog::about',
            [
                'descriptionHtml' => $this->pages->descriptionHtml($blog),
                'authorName' => $blog->authorName,
                'authorUrl' => $blog->authorUrl !== null && SafeUrl::isSafe($blog->authorUrl) ? $blog->authorUrl : null,
                'feeds' => [
                    'json' => $this->urls->generate('blog.feed.json', $handle, true),
                    'rss' => $this->urls->generate('blog.feed.xml', $handle, true),
                ],
                'subscribeUrl' => is_string($subscribe) && SafeUrl::isSafe($subscribe) ? $subscribe : null,
            ],
            $this->translator->t('About'),
            $this->urls->generate('blog.about', $handle, true),
        );
    }
}
```

Replace `modules/blog/src/Routes.php` with:

`modules/blog/src/Routes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\AboutController;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
use Xaraya\Module\Blog\Http\PostController;
use Xaraya\Module\Blog\Http\PostPageController;
use Xaraya\Module\Blog\Http\TagsController;

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
        $routes->get('/media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}', [MediaController::class, 'show'], 'blog.media');

        // HTML pages.
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}', [BlogPagesController::class, 'home'], 'blog.home')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/about', [AboutController::class, 'about'], 'blog.about')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tags', [TagsController::class, 'tags'], 'blog.tags')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', [BlogPagesController::class, 'tag'], 'blog.tag')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');
    }
}
```

`modules/blog/templates/about.php`:
```php
<article class="blog-about">
<h1 class="blog-title"><?= $x->e($blog['title']) ?></h1>
<?php if ($descriptionHtml !== ''): ?>
<div class="blog-description"><?= $descriptionHtml ?></div>
<?php endif ?>
<p class="blog-author"><?= $x->e($x->t('By')) ?> <?php if ($authorUrl !== null): ?><a href="<?= $x->e($authorUrl) ?>" rel="author"><?= $x->e($authorName) ?></a><?php else: ?><?= $x->e($authorName) ?><?php endif ?></p>
<h2><?= $x->e($x->t('Follow')) ?></h2>
<ul class="blog-feeds">
<li><a href="<?= $x->e($feeds['json']) ?>" type="application/feed+json"><?= $x->e($x->t('JSON Feed')) ?></a></li>
<li><a href="<?= $x->e($feeds['rss']) ?>" type="application/rss+xml"><?= $x->e($x->t('RSS')) ?></a></li>
<?php if ($subscribeUrl !== null): ?>
<li><a href="<?= $x->e($subscribeUrl) ?>"><?= $x->e($x->t('Subscribe by email')) ?></a></li>
<?php endif ?>
</ul>
</article>
```

`modules/blog/templates/about.twig`:
```twig
<article class="blog-about">
<h1 class="blog-title">{{ blog.title }}</h1>
{% if descriptionHtml != '' %}
<div class="blog-description">{{ descriptionHtml|raw }}</div>
{% endif %}
<p class="blog-author">{{ t('By') }} {% if authorUrl is not null %}<a href="{{ authorUrl }}" rel="author">{{ authorName }}</a>{% else %}{{ authorName }}{% endif %}</p>
<h2>{{ t('Follow') }}</h2>
<ul class="blog-feeds">
<li><a href="{{ feeds.json }}" type="application/feed+json">{{ t('JSON Feed') }}</a></li>
<li><a href="{{ feeds.rss }}" type="application/rss+xml">{{ t('RSS') }}</a></li>
{% if subscribeUrl is not null %}
<li><a href="{{ subscribeUrl }}">{{ t('Subscribe by email') }}</a></li>
{% endif %}
</ul>
</article>
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/Http/AboutPageTest.php`
Expected: PASS (2 tests). The `<script>` in the description is gone from the page body and from `<meta name="description">`.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Http/AboutController.php modules/blog/src/Routes.php modules/blog/templates/about.php modules/blog/templates/about.twig tests/Module/Blog/Http/AboutPageTest.php
git commit -m "feat(blog): add the about page with feeds and the subscribe link" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Search page

**Files:**
- Create: `modules/blog/src/Http/SearchController.php`
- Create, each as `.php` and `.twig`, under `modules/blog/templates/`: `partials/searchform` and `search`
- Modify: `modules/blog/src/Post/PostRepository.php` (`findMany`), `modules/blog/src/Routes.php` (`blog.search`)
- Test: `tests/Module/Blog/Http/SearchPageTest.php`

**Interfaces:**
- **Consumes:**
  - `SearchIndex::search()` (Task 4), `SearchText::terms/snippet` (Task 3), and `PostViewFactory::link/plainText/display` (Task 6).
  - `BlogPage`, `Paging::query/page` and `BlogPageTestCase` (Task 8), plus `Translator::t()`.
- **Produces `PostRepository::findMany(list<string> $ids): list<Post>`:** in the order of `$ids`; unknown ids are skipped.
- **Produces `SearchController::MAX_QUERY_LENGTH = 200`**, and the route `blog.search` → `SearchController::search`.
- **Produces the template contracts:**
  - `blog::partials/searchform` `{action: string, q: string}`: a GET form with `name="q"` and `maxlength="200"`. Task 13's block reuses it.
  - `blog::search` `{blog, action, q, total: int, results: list<{title, url, snippet (HTML), publishedIso, publishedDisplay}>, pager}`.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Http/SearchPageTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Http;

use Xaraya\Module\Blog\Post\PostRepository;

final class SearchPageTest extends BlogPageTestCase
{
    private function seed(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        $this->addPost($app, $blog, self::postRecord(1, ['title' => 'Crème brûlée', 'content' => '<p>A <b>classic</b> dessert &amp; more.</p>']));
        $this->addPost($app, $blog, self::postRecord(2, ['title' => 'Dessert wine', 'content' => '<p>Sweet <script>alert(1)</script>wine.</p>']));
        $this->addPost($app, $blog, self::postRecord(3, ['title' => 'Scheduled dessert', 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(4, ['title' => 'Deleted dessert']));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-4'], $this->now());
        $other = $this->nativeBlog($app, ['handle' => 'other']);
        $this->addPost($app, $other, self::postRecord(5, ['title' => 'Other dessert']));
    }

    public function testHitsAreHighlightedInBothEngines(): void
    {
        $this->seed();
        [$response, $html] = $this->both('/blog/notes/search?q=DESSERT');
        self::assertSecureHtml($response);
        self::assertStringContainsString('<title>Search · Notes</title>', $html);
        self::assertStringContainsString('<link rel="canonical" href="http://xar.test/blog/notes/search?q=DESSERT">', $html);
        $main = self::main($html);
        self::assertStringContainsString(
            '<form class="search-form" role="search" method="get" action="/blog/notes/search"><label><span class="visually-hidden">Search this blog</span>'
            . '<input type="search" name="q" value="DESSERT" maxlength="200" required></label><button type="submit">Search</button></form>'
            . '<p class="search-count">2 results</p><ol class="search-results">',
            $main,
        );
        self::assertStringContainsString('<h2 class="search-result-title"><a href="/s/', $main);
        self::assertStringContainsString('>Crème brûlée</a></h2><p class="search-snippet">A classic<mark>dessert</mark>&amp; more.</p><p class="post-meta"><time datetime="2026-10-01T11:00:00+00:00">1 Oct 2026</time></p>', $main);
        self::assertStringContainsString('<p class="search-snippet">Sweet wine.</p>', $main, 'snippets come from the sanitised text');
        foreach (['Scheduled', 'Deleted', 'Other dessert'] as $absent) {
            self::assertStringNotContainsString($absent, $main);
        }
        self::assertStringNotContainsString('<script', $html);

        [, $html] = $this->both('/blog/notes/search?q=creme+brulee');
        self::assertStringContainsString('<p class="search-count">1 result</p>', self::main($html));
        self::assertStringContainsString('<a href="/s/', self::main($html));
    }

    public function testMissesEmptyQueriesAndPunctuation(): void
    {
        $this->seed();
        [, $html] = $this->both('/blog/notes/search');
        $main = self::main($html);
        self::assertStringContainsString('value=""', $main);
        self::assertStringNotContainsString('search-count', $main);
        foreach (['?q=nothing', '?q=%25_%21', '?q=%25', '?q=a'] as $query) {
            [, $html] = $this->both('/blog/notes/search' . $query);
            self::assertStringContainsString('<p class="search-count">0 results</p>', self::main($html), $query);
            self::assertStringNotContainsString('search-results', self::main($html), $query);
        }
        [, $html] = $this->both('/blog/notes/search?q=%22%3E%3Cscript%3E');
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;"', $html);
        $this->both('/blog/nope/search?q=x', 404);
    }

    public function testOffsetPaging(): void
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app);
        for ($n = 0; $n < 45; $n++) {
            $this->addPost($app, $blog, self::postRecord($n, ['title' => "Match {$n}"]));
        }
        [, $html] = $this->both('/blog/notes/search?q=match');
        $main = self::main($html);
        self::assertStringContainsString('<p class="search-count">45 results</p>', $main);
        self::assertSame(20, substr_count($main, '<li class="search-result">'));
        self::assertStringContainsString('<a class="pager-next" rel="next" href="/blog/notes/search?q=match&amp;page=2">Next page</a>', $main);
        self::assertStringNotContainsString('pager-prev', $main);

        [, $html] = $this->both('/blog/notes/search?q=match&page=3');
        $main = self::main($html);
        self::assertSame(5, substr_count($main, '<li class="search-result">'));
        self::assertStringContainsString('>Match 40</a>', $main);
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes/search?q=match&amp;page=2">Previous page</a>', $main);
        self::assertStringNotContainsString('pager-next', $main);
        [, $html] = $this->both('/blog/notes/search?q=match&page=2');
        self::assertStringContainsString('<a class="pager-prev" href="/blog/notes/search?q=match">Previous page</a>', self::main($html));
        [, $html] = $this->both('/blog/notes/search?q=match&page=9');
        self::assertStringNotContainsString('search-results', self::main($html), 'past the end is an empty page');
    }

    public function testBadParametersAre400(): void
    {
        $this->seed();
        foreach ([
            '?q=x&page=0' => 'Invalid page number',
            '?q=x&page=-1' => 'Invalid page number',
            '?q=x&page=abc' => 'Invalid page number',
            '?q=x&page[]=2' => 'Invalid page number',
            '?q[]=x' => 'Invalid search query',
            '?q=' . str_repeat('é', 201) => 'The search query is longer than 200 characters',
        ] as $query => $message) {
            [$response, $html] = $this->both('/blog/notes/search' . $query, 400);
            self::assertSecureHtml($response);
            self::assertStringContainsString("<h1>400 Bad Request</h1><p>{$message}</p>", $html, $query);
        }
        $this->both('/blog/notes/search?q=' . str_repeat('é', 200));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Http/SearchPageTest.php`
Expected: failures, `/blog/notes/search` returns 404.

- [ ] **Step 3: Implement**

In `modules/blog/src/Post/PostRepository.php`, add this method just before `findLiveByItemId()`:
```php
    /**
     * @param list<string> $ids
     * @return list<Post> in the order of $ids; unknown ids are skipped
     */
    public function findMany(array $ids): array
    {
        $byId = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach ($this->hydrate($this->db->select('posts')->whereIn('id', $chunk)->all()) as $post) {
                $byId[$post->id] = $post;
            }
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }
```

`modules/blog/src/Http/SearchController.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Http;

use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\HttpException;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Page;
use Xaraya\Kernel\View\Translator;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Search\SearchIndex;
use Xaraya\Module\Blog\Search\SearchText;
use Xaraya\Module\Blog\View\PostViewFactory;

/** GET /blog/{handle}/search?q=&page=: offset-paged search results with highlighted snippets. */
final class SearchController extends Controller
{
    public const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private readonly SearchIndex $index,
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /** @param array<string, string> $params */
    public function search(ServerRequestInterface $request, array $params): Page
    {
        $blog = $this->pages->blog($params['handle']);
        $q = Paging::query($request)['q'] ?? '';
        if (!is_string($q)) {
            throw new HttpException(400, 'Invalid search query');
        }
        $q = trim(mb_scrub($q, 'UTF-8'));
        if (mb_strlen($q) > self::MAX_QUERY_LENGTH) {
            throw new HttpException(400, 'The search query is longer than ' . self::MAX_QUERY_LENGTH . ' characters');
        }
        $page = Paging::page($request);
        $total = 0;
        $results = [];
        if ($q !== '') {
            $found = $this->index->search($blog->id, $q, BlogPage::PAGE_SIZE, ($page - 1) * BlogPage::PAGE_SIZE);
            $total = $found['total'];
            $terms = SearchText::terms($q);
            foreach ($this->posts->findMany($found['ids']) as $post) {
                $results[] = [
                    'title' => $post->title,
                    'url' => $this->factory->link($blog, $post),
                    'snippet' => SearchText::snippet($this->factory->plainText($post), $terms),
                    'publishedIso' => $post->datePublished->format(DATE_ATOM),
                    'publishedDisplay' => PostViewFactory::display($post->datePublished),
                ];
            }
        }
        $route = fn(int $n): array => ['handle' => $blog->handle] + ($q === '' ? [] : ['q' => $q]) + ($n > 1 ? ['page' => (string) $n] : []);

        return $this->pages->page(
            $blog,
            'blog::search',
            [
                'action' => $this->urls->generate('blog.search', ['handle' => $blog->handle]),
                'q' => $q,
                'total' => $total,
                'results' => $results,
                'pager' => [
                    'prev' => $page > 1 ? $this->urls->generate('blog.search', $route($page - 1)) : null,
                    'next' => $page * BlogPage::PAGE_SIZE < $total ? $this->urls->generate('blog.search', $route($page + 1)) : null,
                    'prevLabel' => 'Previous page',
                    'nextLabel' => 'Next page',
                ],
            ],
            $this->translator->t('Search'),
            $this->urls->generate('blog.search', $route($page), true),
        );
    }
}
```

Replace `modules/blog/src/Routes.php` with:

`modules/blog/src/Routes.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Module\Blog\Http\AboutController;
use Xaraya\Module\Blog\Http\BlogPagesController;
use Xaraya\Module\Blog\Http\FeedController;
use Xaraya\Module\Blog\Http\MediaController;
use Xaraya\Module\Blog\Http\PostController;
use Xaraya\Module\Blog\Http\PostPageController;
use Xaraya\Module\Blog\Http\SearchController;
use Xaraya\Module\Blog\Http\TagsController;

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
        $routes->get('/media/{id:[0-9a-hjkmnp-tv-z]{26}}.{ext:[a-z0-9]{2,5}}', [MediaController::class, 'show'], 'blog.media');

        // HTML pages.
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}', [BlogPagesController::class, 'home'], 'blog.home')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/about', [AboutController::class, 'about'], 'blog.about')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/search', [SearchController::class, 'search'], 'blog.search')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tags', [TagsController::class, 'tags'], 'blog.tags')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/blog/{handle:[a-z0-9_-]{2,32}}/tag/{slug:[^/]{1,128}}', [BlogPagesController::class, 'tag'], 'blog.tag')
            ->middleware('secureHtml', 'conditional');
        $routes->get('/s/{id:[0-9a-hjkmnp-tv-z]{26}}', [PostPageController::class, 'show'], 'blog.post')
            ->middleware('secureHtml', 'conditional');
    }
}
```

`modules/blog/templates/partials/searchform.php`:
```php
<form class="search-form" role="search" method="get" action="<?= $x->e($action) ?>">
<label><span class="visually-hidden"><?= $x->e($x->t('Search this blog')) ?></span><input type="search" name="q" value="<?= $x->e($q) ?>" maxlength="200" required></label>
<button type="submit"><?= $x->e($x->t('Search')) ?></button>
</form>
```

`modules/blog/templates/partials/searchform.twig`:
```twig
<form class="search-form" role="search" method="get" action="{{ action }}">
<label><span class="visually-hidden">{{ t('Search this blog') }}</span><input type="search" name="q" value="{{ q }}" maxlength="200" required></label>
<button type="submit">{{ t('Search') }}</button>
</form>
```

`modules/blog/templates/search.php`:
```php
<header class="blog-header">
<p class="blog-name"><?= $x->e($blog['title']) ?></p>
<h1 class="blog-title"><?= $x->e($x->t('Search')) ?></h1>
</header>
<?= $x->render('blog::partials/searchform', ['action' => $action, 'q' => $q]) ?>
<?php if ($q !== ''): ?>
<p class="search-count"><?= $x->e($total === 1 ? $x->t('1 result') : $x->t('{count} results', ['count' => $total])) ?></p>
<?php endif ?>
<?php if ($results !== []): ?>
<ol class="search-results">
<?php foreach ($results as $result): ?>
<li class="search-result">
<h2 class="search-result-title"><a href="<?= $x->e($result['url']) ?>"><?= $x->e($result['title']) ?></a></h2>
<?php if ($result['snippet'] !== ''): ?>
<p class="search-snippet"><?= $result['snippet'] ?></p>
<?php endif ?>
<p class="post-meta"><time datetime="<?= $x->e($result['publishedIso']) ?>"><?= $x->e($result['publishedDisplay']) ?></time></p>
</li>
<?php endforeach ?>
</ol>
<?php endif ?>
<?= $x->render('blog::partials/pager', ['pager' => $pager]) ?>
```

`modules/blog/templates/search.twig`:
```twig
<header class="blog-header">
<p class="blog-name">{{ blog.title }}</p>
<h1 class="blog-title">{{ t('Search') }}</h1>
</header>
{{ render('blog::partials/searchform', {action: action, q: q}) }}
{% if q != '' %}
<p class="search-count">{{ total == 1 ? t('1 result') : t('{count} results', {count: total}) }}</p>
{% endif %}
{% if results is not empty %}
<ol class="search-results">
{% for result in results %}
<li class="search-result">
<h2 class="search-result-title"><a href="{{ result.url }}">{{ result.title }}</a></h2>
{% if result.snippet != '' %}
<p class="search-snippet">{{ result.snippet|raw }}</p>
{% endif %}
<p class="post-meta"><time datetime="{{ result.publishedIso }}">{{ result.publishedDisplay }}</time></p>
</li>
{% endfor %}
</ol>
{% endif %}
{{ render('blog::partials/pager', {pager: pager}) }}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/Http/SearchPageTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL run of `tests/Module/Blog/Http/SearchPageTest.php`.
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/src/Http/SearchController.php modules/blog/src/Post/PostRepository.php modules/blog/src/Routes.php modules/blog/templates tests/Module/Blog/Http/SearchPageTest.php
git commit -m "feat(blog): add the search page with highlighted snippets and offset paging" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Sidebar blocks, `blockDefaults` and `xar blog:blocks`

**Files:**
- Create: `modules/blog/src/Blocks/CurrentBlog.php`, `InfoBlock.php`, `TagCloudBlock.php`, `SearchBoxBlock.php`, `RecentBlock.php`
- Create, each as `.php` and `.twig`, under `modules/blog/templates/`: `blocks/info`, `blocks/tagcloud`, `blocks/searchbox` and `blocks/recent`
- Create: `modules/blog/src/Cli/BlogBlocksCommand.php`
- Modify: `modules/blog/module.json` (`commands`, `blocks`, `blockDefaults`)
- Test: `tests/Module/Blog/Blocks/BlogBlocksTest.php`

**Interfaces:**
- **Consumes:**
  - The kernel `Block` interface (`render(array $config, BlockContext $context): string`). `BlockContext` gives `helpers`, `routeName`, `params` and `request()`.
  - The theme `block` wrapper (`<section class="block block-blog-info" aria-labelledby="block-{id}-title">`).
  - `BlockRepository::seed(Manifest)` and `forRegion()`, and `ModuleRegistry::get()`.
  - `PostRepository::page/find/tagCounts`, `TagCloud::build`, `PostViewFactory::link/display`, `BlogPage::summary/now` and `Paging::query`.
  - The partials `blog::partials/tagcloud` (Task 10) and `blog::partials/searchform` (Task 12).
- **Produces `CurrentBlog::of(BlockContext): ?Blog`:** the route's `handle`, or on `blog.post` the post's blog.
- **Produces the block types:**
  - `blog.info`: the first 200 plain-text characters of the description (`InfoBlock::SNIPPET_LENGTH`), then the about page and both feeds.
  - `blog.tagcloud`: config `limit`, 1 to 100, default 30.
  - `blog.searchbox`: keeps `q` on `blog.search`.
  - `blog.recent`: config `limit`, 1 to 20, default 5.

  Each renders `blog::blocks/<name>` and returns `''` when no blog can be found.
- **Produces `blockDefaults`:** searchbox (sort 10), info (20), recent (30) and tagcloud (40), all in region `sidebar` with visibility `{"routes": ["blog.*"]}`.
- **Produces the CLI command:** `xar blog:blocks`. It seeds the defaults when no `blog.%` block exists, and prints `Created 4 blog block(s).` or `{n} blog block(s) already exist; nothing to do.`

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/Blocks/BlogBlocksTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog\Blocks;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Tests\Module\Blog\Http\BlogPageTestCase;
use Xaraya\Tests\Support\Html;

final class BlogBlocksTest extends BlogPageTestCase
{
    /** @return string the id of the newest post */
    private function seed(): string
    {
        $app = $this->enableBlog();
        $blog = $this->nativeBlog($app, ['description_html' => '<p>' . str_repeat('word ', 60) . '</p><script>alert(1)</script>']);
        $tag = static fn(string $name): array => ['name' => $name, 'slug' => strtolower($name)];
        for ($n = 1; $n <= 7; $n++) {
            $newest = $this->addPost($app, $blog, self::postRecord($n, ['title' => "Post {$n}", 'tags' => $n <= 3 ? [$tag('Travel')] : [$tag('Art')]]));
            if ($n === 1) {
                $id = $newest;
            }
        }
        $this->addPost($app, $blog, self::postRecord(0, ['title' => 'Scheduled', 'tags' => [$tag('Hidden')], 'date' => $this->now()->modify('+1 day')]));
        $this->addPost($app, $blog, self::postRecord(8, ['title' => 'Deleted', 'tags' => [$tag('Gone')]]));
        $app->container()->get(PostRepository::class)->tombstone($blog->id, ['item-8'], $this->now());

        return $id ?? self::fail('no post');
    }

    private static function sidebar(string $html): string
    {
        return preg_match('#<aside class="site-sidebar" aria-label="Sidebar">(.*)</aside>#s', $html, $m) === 1 ? $m[1] : '';
    }

    public function testEnablingSeedsFourSidebarBlocksForBlogRoutes(): void
    {
        $this->seed();
        $blocks = $this->site()->container()->get(BlockRepository::class)->forRegion('sidebar');
        self::assertSame(['blog.searchbox', 'blog.info', 'blog.recent', 'blog.tagcloud'], array_map(static fn($b): string => $b->type, $blocks));
        foreach ($blocks as $block) {
            self::assertSame(['routes' => ['blog.*']], $block->visibility);
        }
    }

    public function testTheSidebarOnTheHomePageInBothEngines(): void
    {
        $this->seed();
        [, $html] = $this->both('/blog/notes');
        $sidebar = self::sidebar($html);
        $description = rtrim(str_repeat('word ', 40)) . '…';
        foreach ([
            '<section class="block block-blog-searchbox" aria-labelledby="block-1-title"><h2 class="block-title" id="block-1-title">Search</h2>'
            . '<form class="search-form" role="search" method="get" action="/blog/notes/search">',
            '<section class="block block-blog-info" aria-labelledby="block-2-title"><h2 class="block-title" id="block-2-title">About</h2>'
            . '<p class="blog-info-description">' . $description . '</p><ul class="blog-info-links"><li><a href="/blog/notes/about">About Notes</a></li>'
            . '<li><a href="/blog/notes/feed.json" type="application/feed+json">JSON Feed</a></li><li><a href="/blog/notes/feed.xml" type="application/rss+xml">RSS</a></li></ul>',
            '<h2 class="block-title" id="block-3-title">Recent posts</h2><ul class="blog-recent"><li><a href="/s/',
            '<h2 class="block-title" id="block-4-title">Tags</h2><ul class="tag-cloud">'
            . '<li class="tag-step-5"><a href="/blog/notes/tag/art" rel="tag">Art</a><span class="tag-count">4</span></li>'
            . '<li class="tag-step-1"><a href="/blog/notes/tag/travel" rel="tag">Travel</a><span class="tag-count">3</span></li>'
            . '</ul><p class="blog-tags-all"><a href="/blog/notes/tags">All tags</a></p>',
        ] as $needle) {
            self::assertStringContainsString($needle, $sidebar);
        }
        preg_match_all('#<ul class="blog-recent">(.*?)</ul>#s', $sidebar, $recent);
        self::assertSame(5, substr_count($recent[1][0], '<li>'));
        foreach (['Scheduled', 'Deleted', 'Hidden', 'Gone', 'alert'] as $absent) {
            self::assertStringNotContainsString($absent, $html);
        }
    }

    public function testBlocksFindTheBlogOnPostAndSearchPages(): void
    {
        $id = $this->seed();
        self::assertStringContainsString('<a href="/blog/notes/about">About Notes</a>', self::sidebar($this->both("/s/{$id}")[1]));
        $sidebar = self::sidebar($this->both('/blog/notes/search?q=post')[1]);
        self::assertStringContainsString('<input type="search" name="q" value="post" maxlength="200" required>', $sidebar);
        self::assertStringContainsString('<input type="search" name="q" value="" maxlength="200" required>', self::sidebar($this->both('/blog/notes/tags')[1]));
    }

    public function testBlocksStayOffOtherPages(): void
    {
        $this->seed();
        $home = Html::normalize((string) $this->site()->handle(new ServerRequest('GET', '/'))->getBody());
        self::assertStringNotContainsString('<aside', $home);
        [, $html] = $this->both('/blog/nope', 404);
        self::assertStringNotContainsString('<aside', $html, 'error pages have no route, so route-limited blocks hide');
    }

    public function testBlogBlocksSeedsOnlyWhenNoneExist(): void
    {
        $app = $this->enableBlog();
        [$code, $out] = $this->xar(['blog:blocks'], $app);
        self::assertSame(0, $code);
        self::assertSame("4 blog block(s) already exist; nothing to do.\n", $out);

        $db = $this->db($app);
        $db->pdo()->exec('DELETE FROM ' . $db->table('blocks'));
        [$code, $out] = $this->xar(['blog:blocks'], $app);
        self::assertSame(0, $code);
        self::assertSame("Created 4 blog block(s).\n", $out);
        self::assertSame(4, $db->select('blocks')->where('type', 'like', 'blog.%')->count());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/Blocks/BlogBlocksTest.php`
Expected: failures. No sidebar blocks are seeded, and `blog:blocks` is an unknown command.

- [ ] **Step 3: Implement the blocks and their templates**

`modules/blog/src/Blocks/CurrentBlog.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Module\Blog\Blog;
use Xaraya\Module\Blog\BlogRepository;
use Xaraya\Module\Blog\Post\PostRepository;

/** The blog a sidebar block is about: the route's {handle}, or on /s/{id} the post's blog. */
final class CurrentBlog
{
    public function __construct(private readonly BlogRepository $blogs, private readonly PostRepository $posts) {}

    public function of(BlockContext $context): ?Blog
    {
        $handle = $context->params['handle'] ?? null;
        if (is_string($handle)) {
            return $this->blogs->find($handle);
        }
        $id = $context->params['id'] ?? null;
        if ($context->routeName === 'blog.post' && is_string($id)) {
            $post = $this->posts->find($id);

            return $post === null ? null : $this->blogs->findById($post->blogId);
        }

        return null;
    }
}
```

`modules/blog/src/Blocks/InfoBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Http\BlogPage;

/** blog.info: the start of the blog's description, the about page and the feeds. */
final class InfoBlock implements Block
{
    public const SNIPPET_LENGTH = 200;

    public function __construct(
        private readonly CurrentBlog $current,
        private readonly BlogPage $pages,
        private readonly UrlGenerator $urls,
    ) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $description = $this->pages->summary($blog)['description'];
        if (mb_strlen($description) > self::SNIPPET_LENGTH) {
            $description = rtrim(mb_substr($description, 0, self::SNIPPET_LENGTH - 1)) . '…';
        }
        $handle = ['handle' => $blog->handle];

        return $context->helpers->render('blog::blocks/info', [
            'title' => $blog->title,
            'description' => $description,
            'aboutUrl' => $this->urls->generate('blog.about', $handle),
            'jsonUrl' => $this->urls->generate('blog.feed.json', $handle),
            'rssUrl' => $this->urls->generate('blog.feed.xml', $handle),
        ]);
    }
}
```

`modules/blog/src/Blocks/TagCloudBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Http\BlogPage;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\TagCloud;

/** blog.tagcloud: the most used tags ({"limit": 30}), sized and sorted like the tags page. */
final class TagCloudBlock implements Block
{
    public function __construct(
        private readonly CurrentBlog $current,
        private readonly PostRepository $posts,
        private readonly UrlGenerator $urls,
    ) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $limit = is_int($config['limit'] ?? null) ? max(1, min(100, $config['limit'])) : 30;
        $tags = [];
        foreach (TagCloud::build($this->posts->tagCounts($blog->id, BlogPage::now(), $limit)) as $tag) {
            $tags[] = [...$tag, 'url' => $this->urls->generate('blog.tag', ['handle' => $blog->handle, 'slug' => $tag['slug']])];
        }
        if ($tags === []) {
            return '';
        }

        return $context->helpers->render('blog::blocks/tagcloud', [
            'tags' => $tags,
            'allUrl' => $this->urls->generate('blog.tags', ['handle' => $blog->handle]),
        ]);
    }
}
```

`modules/blog/src/Blocks/SearchBoxBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Module\Blog\Http\Paging;

/** blog.searchbox: a GET form to the blog's search page. */
final class SearchBoxBlock implements Block
{
    public function __construct(private readonly CurrentBlog $current, private readonly UrlGenerator $urls) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $request = $context->request();
        $q = $context->routeName === 'blog.search' && $request !== null ? (Paging::query($request)['q'] ?? '') : '';

        return $context->helpers->render('blog::blocks/searchbox', [
            'action' => $this->urls->generate('blog.search', ['handle' => $blog->handle]),
            'q' => is_string($q) ? trim(mb_scrub($q, 'UTF-8')) : '',
        ]);
    }
}
```

`modules/blog/src/Blocks/RecentBlock.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Blocks;

use Xaraya\Kernel\Blocks\Block;
use Xaraya\Kernel\Blocks\BlockContext;
use Xaraya\Module\Blog\Http\BlogPage;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\View\PostViewFactory;

/** blog.recent: the newest live posts ({"limit": 5}). */
final class RecentBlock implements Block
{
    public function __construct(
        private readonly CurrentBlog $current,
        private readonly PostRepository $posts,
        private readonly PostViewFactory $factory,
    ) {}

    public function render(array $config, BlockContext $context): string
    {
        $blog = $this->current->of($context);
        if ($blog === null) {
            return '';
        }
        $limit = is_int($config['limit'] ?? null) ? max(1, min(20, $config['limit'])) : 5;
        $items = [];
        foreach ($this->posts->page($blog->id, BlogPage::now(), null, $limit) as $post) {
            $items[] = [
                'title' => $post->title,
                'url' => $this->factory->link($blog, $post),
                'publishedIso' => $post->datePublished->format(DATE_ATOM),
                'publishedDisplay' => PostViewFactory::display($post->datePublished),
            ];
        }

        return $items === [] ? '' : $context->helpers->render('blog::blocks/recent', ['posts' => $items]);
    }
}
```

`modules/blog/templates/blocks/info.php`:
```php
<?php if ($description !== ''): ?>
<p class="blog-info-description"><?= $x->e($description) ?></p>
<?php endif ?>
<ul class="blog-info-links">
<li><a href="<?= $x->e($aboutUrl) ?>"><?= $x->e($x->t('About {title}', ['title' => $title])) ?></a></li>
<li><a href="<?= $x->e($jsonUrl) ?>" type="application/feed+json"><?= $x->e($x->t('JSON Feed')) ?></a></li>
<li><a href="<?= $x->e($rssUrl) ?>" type="application/rss+xml"><?= $x->e($x->t('RSS')) ?></a></li>
</ul>
```

`modules/blog/templates/blocks/info.twig`:
```twig
{% if description != '' %}
<p class="blog-info-description">{{ description }}</p>
{% endif %}
<ul class="blog-info-links">
<li><a href="{{ aboutUrl }}">{{ t('About {title}', {title: title}) }}</a></li>
<li><a href="{{ jsonUrl }}" type="application/feed+json">{{ t('JSON Feed') }}</a></li>
<li><a href="{{ rssUrl }}" type="application/rss+xml">{{ t('RSS') }}</a></li>
</ul>
```

`modules/blog/templates/blocks/tagcloud.php`:
```php
<?= $x->render('blog::partials/tagcloud', ['tags' => $tags]) ?>
<p class="blog-tags-all"><a href="<?= $x->e($allUrl) ?>"><?= $x->e($x->t('All tags')) ?></a></p>
```

`modules/blog/templates/blocks/tagcloud.twig`:
```twig
{{ render('blog::partials/tagcloud', {tags: tags}) }}
<p class="blog-tags-all"><a href="{{ allUrl }}">{{ t('All tags') }}</a></p>
```

`modules/blog/templates/blocks/searchbox.php`:
```php
<?= $x->render('blog::partials/searchform', ['action' => $action, 'q' => $q]) ?>
```

`modules/blog/templates/blocks/searchbox.twig`:
```twig
{{ render('blog::partials/searchform', {action: action, q: q}) }}
```

`modules/blog/templates/blocks/recent.php`:
```php
<ul class="blog-recent">
<?php foreach ($posts as $post): ?>
<li><a href="<?= $x->e($post['url']) ?>"><?= $x->e($post['title']) ?></a> <time datetime="<?= $x->e($post['publishedIso']) ?>"><?= $x->e($post['publishedDisplay']) ?></time></li>
<?php endforeach ?>
</ul>
```

`modules/blog/templates/blocks/recent.twig`:
```twig
<ul class="blog-recent">
{% for post in posts %}
<li><a href="{{ post.url }}">{{ post.title }}</a> <time datetime="{{ post.publishedIso }}">{{ post.publishedDisplay }}</time></li>
{% endfor %}
</ul>
```

- [ ] **Step 4: Add the command and the manifest keys**

`modules/blog/src/Cli/BlogBlocksCommand.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Blog\Cli;

use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;

/** Seeds the blog's default sidebar blocks on an install that enabled blog before they existed. */
final class BlogBlocksCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly BlockRepository $blocks,
        private readonly ModuleRegistry $modules,
    ) {}

    public function name(): string
    {
        return 'blog:blocks';
    }

    public function description(): string
    {
        return 'Create the default blog sidebar blocks, unless blog blocks already exist';
    }

    public function run(Input $input, Output $output): int
    {
        $existing = $this->db->select('blocks')->where('type', 'like', 'blog.%')->count();
        if ($existing > 0) {
            $output->line("{$existing} blog block(s) already exist; nothing to do.");

            return 0;
        }
        $manifest = $this->modules->get('blog');
        $this->blocks->seed($manifest);
        $output->line(sprintf('Created %d blog block(s).', count($manifest->blockDefaults())));

        return 0;
    }
}
```

Replace `modules/blog/module.json` with:

`modules/blog/module.json`:
```json
{
  "name": "blog",
  "version": "0.1.0",
  "content": true,
  "requires": { "kernel": "^0.1", "modules": {} },
  "provider": "Xaraya\\Module\\Blog\\BlogServiceProvider",
  "routes": "Xaraya\\Module\\Blog\\Routes",
  "migrations": "migrations",
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" },
    { "event": "Xaraya\\Kernel\\Events\\ItemUpdated", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" },
    { "event": "Xaraya\\Kernel\\Events\\ItemDeleted", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" }
  ],
  "commands": [
    "Xaraya\\Module\\Blog\\Cli\\BlogBlocksCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogCreateCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogListCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogModeCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogReindexCommand",
    "Xaraya\\Module\\Blog\\Cli\\BlogSyncCommand"
  ],
  "blocks": {
    "blog.info": "Xaraya\\Module\\Blog\\Blocks\\InfoBlock",
    "blog.tagcloud": "Xaraya\\Module\\Blog\\Blocks\\TagCloudBlock",
    "blog.searchbox": "Xaraya\\Module\\Blog\\Blocks\\SearchBoxBlock",
    "blog.recent": "Xaraya\\Module\\Blog\\Blocks\\RecentBlock"
  },
  "blockDefaults": [
    { "type": "blog.searchbox", "region": "sidebar", "title": "Search", "visibility": { "routes": ["blog.*"] }, "sort": 10 },
    { "type": "blog.info", "region": "sidebar", "title": "About", "visibility": { "routes": ["blog.*"] }, "sort": 20 },
    { "type": "blog.recent", "region": "sidebar", "title": "Recent posts", "config": { "limit": 5 }, "visibility": { "routes": ["blog.*"] }, "sort": 30 },
    { "type": "blog.tagcloud", "region": "sidebar", "title": "Tags", "config": { "limit": 30 }, "visibility": { "routes": ["blog.*"] }, "sort": 40 }
  ]
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Blog`
Expected: PASS. The earlier page tests still pass with the sidebar present, because their needles come from `main()`.

- [ ] **Step 6: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL run of `tests/Module/Blog/Blocks`.
Expected: all green.

- [ ] **Step 7: Commit**

```bash
git add modules/blog/src/Blocks modules/blog/src/Cli/BlogBlocksCommand.php modules/blog/templates/blocks modules/blog/module.json tests/Module/Blog/Blocks/BlogBlocksTest.php
git commit -m "feat(blog): add the info, tag cloud, search box and recent posts sidebar blocks" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: `blog.css`

**Files:**
- Create: `modules/blog/assets/blog.css`
- Test: `tests/Module/Blog/BlogAssetsTest.php`

**Interfaces:**
- **Consumes:**
  - The class names produced by Tasks 7, 8 and 10 to 13.
  - The phoenix theme's custom properties (`--fg`, `--muted`, `--accent`, `--border`, `--focus`). The stylesheet falls back to neutral values under any other theme.
  - `AssetPublisher::publish(bool $copy)`, which publishes every discovered module's `assets/` to `{assets.path}/module/<name>`.
- **Produces `/assets/module/blog/blog.css`.** `BlogPage` has linked it since Task 8.

- [ ] **Step 1: Write the failing test**

`tests/Module/Blog/BlogAssetsTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use Xaraya\Kernel\Assets\AssetPublisher;

final class BlogAssetsTest extends BlogTestCase
{
    private function css(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/modules/blog/assets/blog.css');
    }

    public function testTheStylesheetCoversThePartialsAndUserPreferences(): void
    {
        $css = $this->css();
        foreach ([
            '.visually-hidden', '.post-title', '.post-pinned', '.post-comment', '.post-quote', '.gallery', '.video-play',
            '.post-podcast', '.post-tags', '.post-nav', '.pager', '.tag-step-1', '.tag-step-5', '.search-form',
            '.search-snippet mark', '.blog-recent', ':focus-visible', 'prefers-color-scheme: dark', 'prefers-reduced-motion: reduce',
        ] as $needle) {
            self::assertStringContainsString($needle, $css);
        }
        self::assertStringNotContainsString('@import', $css);
        self::assertStringNotContainsString('url(', $css, 'no fonts or images: the CSP allows only same-origin styles');
    }

    public function testAssetPublishPublishesItUnderModuleBlog(): void
    {
        $app = $this->app(['assets.path' => $this->tmp . '/public/assets']);
        $done = $app->container()->get(AssetPublisher::class)->publish(copy: true);
        self::assertContains(['module', 'blog'], array_map(static fn(array $row): array => [$row['kind'], $row['name']], $done));
        self::assertSame($this->css(), file_get_contents($this->tmp . '/public/assets/module/blog/blog.css'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Module/Blog/BlogAssetsTest.php`
Expected: failures. The file does not exist, and nothing is published for `module/blog`.

- [ ] **Step 3: Implement**

`modules/blog/assets/blog.css`:
```css
/* Blog pages. Builds on the theme's custom properties (--fg, --muted, --accent, --border, --focus) and falls back
   to neutral values under any theme. No web fonts, no JavaScript, no third-party URLs. */
.visually-hidden {
  position: absolute !important;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
  clip-path: inset(50%);
  white-space: nowrap;
}

.blog-header { margin: 0 0 2rem; }
.blog-name { margin: 0; color: var(--muted, #5c5c66); font-size: 0.9375rem; }
.blog-title { margin: 0.25rem 0; line-height: 1.2; }
.blog-description, .blog-empty { color: var(--muted, #5c5c66); }

.post-list { display: grid; gap: 2.5rem; }
.post { overflow-wrap: anywhere; }
.post-title { margin: 0 0 0.5rem; line-height: 1.25; }
.post-title a { color: inherit; text-decoration-thickness: 1px; }
.post-pinned { padding: 1rem; border: 1px solid var(--border, #d9d9e0); border-radius: 0.5rem; }
.post-pinned-label, .post-via, .post-source, .post-meta, .mention-date, .podcast-duration, .song-duration {
  margin: 0.25rem 0;
  color: var(--muted, #5c5c66);
  font-size: 0.9375rem;
}
.post-content > :first-child, .post-comment > :first-child { margin-top: 0; }
.post-comment { padding-left: 1rem; border-left: 3px solid var(--accent, #0b57d0); }
.post-quote { margin: 0 0 0.75rem; padding-left: 1rem; border-left: 3px solid var(--border, #d9d9e0); font-style: italic; }
.post-photo { margin: 0 0 1rem; }
.post-footer { margin-top: 0.75rem; }
.post-tags { display: flex; flex-wrap: wrap; gap: 0.5rem; margin: 0.5rem 0 0; padding: 0; list-style: none; }
.post-tags a::before { content: "#"; }
.post-mentions { margin-top: 2rem; }
.post-mentions h2 { font-size: 1.125rem; }

.gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); gap: 0.5rem; margin: 0 0 1rem; }
.gallery img { width: 100%; height: 100%; object-fit: cover; }

.video-link { position: relative; display: inline-block; max-width: 100%; }
.video-link img { display: block; }
.video-play {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  padding: 0.5rem 1rem;
  border-radius: 0.5rem;
  background: rgb(0 0 0 / 70%);
  color: #fff;
  font-size: 1.5rem;
  line-height: 1;
}
.video-link:hover .video-play, .video-link:focus-visible .video-play { background: var(--accent, #0b57d0); }

.post-podcast { display: grid; gap: 0.25rem; margin: 0 0 1rem; }
.podcast-cover { max-width: 8rem; border-radius: 0.25rem; }
.podcast-show { margin: 0; font-weight: 600; }
.podcast-episode { margin: 0; }
.post-podcast audio { width: 100%; }
.post-song { display: flex; gap: 0.75rem; }

.post-nav, .pager { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; margin: 2rem 0 0; }
.pager-next { margin-left: auto; }

.tag-cloud { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.25rem 0.75rem; margin: 0; padding: 0; list-style: none; }
.tag-count { color: var(--muted, #5c5c66); font-size: 0.8125rem; }
.tag-step-1 { font-size: 0.875rem; }
.tag-step-2 { font-size: 1rem; }
.tag-step-3 { font-size: 1.125rem; }
.tag-step-4 { font-size: 1.3125rem; }
.tag-step-5 { font-size: 1.5rem; font-weight: 600; }

.search-form { display: flex; gap: 0.5rem; margin: 0 0 1rem; }
.search-form label { flex: 1; }
.search-form input { width: 100%; padding: 0.375rem 0.5rem; border: 1px solid var(--border, #d9d9e0); border-radius: 0.25rem; background: transparent; color: inherit; font: inherit; }
.search-form button { padding: 0.375rem 0.75rem; border: 1px solid var(--border, #d9d9e0); border-radius: 0.25rem; background: transparent; color: inherit; font: inherit; cursor: pointer; }
.search-form input:focus-visible, .search-form button:focus-visible { outline: 3px solid var(--focus, #ff9e1b); outline-offset: 2px; }
.search-count { color: var(--muted, #5c5c66); }
.search-results { display: grid; gap: 1.5rem; padding-left: 1.25rem; }
.search-result-title { margin: 0; font-size: 1.125rem; }
.search-snippet { margin: 0.25rem 0; }
.search-snippet mark { background: color-mix(in srgb, var(--accent, #0b57d0) 25%, transparent); color: inherit; }

.blog-recent, .blog-info-links, .blog-feeds { margin: 0; padding: 0; list-style: none; }
.blog-recent li, .blog-info-links li, .blog-feeds li { margin: 0.25rem 0; }
.blog-recent time { display: block; color: var(--muted, #5c5c66); font-size: 0.8125rem; }

a:focus-visible { outline: 3px solid var(--focus, #ff9e1b); outline-offset: 2px; }

@media (prefers-color-scheme: dark) {
  .video-play { background: rgb(0 0 0 / 80%); }
}

@media (prefers-reduced-motion: reduce) {
  .video-play { transition: none; }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Module/Blog/BlogAssetsTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add modules/blog/assets/blog.css tests/Module/Blog/BlogAssetsTest.php
git commit -m "feat(blog): style the blog pages, partials and blocks" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 15: Athena fixture smoke test, definition of done, docs

**Files:**
- Test: `tests/Module/Blog/BlogPagesSmokeTest.php`
- Modify: `README.md` (Blog pages section), `docs/superpowers/plans/2026-10-09-phoenix-kernel-views-followups.md` ("Blog pages plan" section)

**Interfaces:**
- **Consumes:**
  - The Athena fixture: 150 items in `tests/fixtures/athena/page-{1,2,3}.json`. They are 149 links and 1 note; 4 carry `_video`, 3 carry `_athenana.song`, and the tags include `travel`.
  - `FixtureFetcher`, `Syncer`, the `blog:create` and `blog:reindex` commands, `BlogPageTestCase` (Task 8), and `Fixtures::theme`.
- **Produces:** the spec §5 fixture smoke test and the §6 definition-of-done checks. Definition-of-done item 3 is checked at page level here; Task 7 checked it at partial level.

- [ ] **Step 1: Write the smoke test**

`tests/Module/Blog/BlogPagesSmokeTest.php`:
```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Blog;

use DOMDocument;
use LibXMLError;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Module\Blog\Post\Post;
use Xaraya\Module\Blog\Post\PostRepository;
use Xaraya\Module\Blog\Source\HttpFetcher;
use Xaraya\Module\Blog\Sync\Syncer;
use Xaraya\Tests\Module\Blog\Http\BlogPageTestCase;
use Xaraya\Tests\Module\Blog\Support\FixtureFetcher;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

/** Definition of done: the recorded Athena blog (150 posts) browsed end to end in both engines. */
final class BlogPagesSmokeTest extends BlogPageTestCase
{
    private const SOURCE = 'https://athenana.com/blog/wyome/feed.json';
    private const DIR = __DIR__ . '/../../fixtures/athena';

    /** libxml2's HTML 4 parser reports every HTML5 element (main, article, time, ...) as unknown: code 801. */
    private const HTML5_TAG_UNKNOWN = 801;

    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::DIR . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private function mirrorAthena(): void
    {
        $pages = [self::fixture('page-1'), self::fixture('page-2'), self::fixture('page-3')];
        $app = $this->enableBlog();
        [$code, $out, $err] = $this->xar([
            'blog:create', 'wyome', '--mode=mirror', '--format=athena', '--source=' . self::SOURCE,
            '--post-url=https://www.wyome.com/blog/post.html?id={id}',
        ], $app);
        self::assertSame(0, $code, $out . $err);
        $app = $this->app();
        $app->container()->instance(HttpFetcher::class, (new FixtureFetcher())
            ->on(self::SOURCE, FixtureFetcher::json($pages[0]))
            ->on((string) $pages[0]['next_url'], FixtureFetcher::json($pages[1]))
            ->on((string) $pages[1]['next_url'], FixtureFetcher::json($pages[2])));
        $blog = $this->blogs($app)->find('wyome') ?? self::fail('no blog');
        self::assertSame(150, $app->container()->get(Syncer::class)->sync($blog, true, $this->now())->created);
        [$code, $out] = $this->xar(['blog:reindex', '--all'], $this->app());
        self::assertSame(0, $code);
        self::assertSame("wyome: indexed 150 post(s)\n", $out);
    }

    /**
     * The id of the first post (by id) that $match accepts.
     *
     * @param callable(Post): bool $match
     */
    private function postId(callable $match): string
    {
        $app = $this->app();
        $blog = $this->blogs($app)->find('wyome') ?? self::fail('no blog');
        foreach ($app->container()->get(PostRepository::class)->publishedAfter($blog->id, null, 200) as $post) {
            if ($match($post)) {
                return $post->id;
            }
        }
        self::fail('no matching post');
    }

    private static function assertParses(string $html, string $uri): void
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML($html);
            $errors = array_values(array_filter(libxml_get_errors(), static fn(LibXMLError $e): bool => $e->code !== self::HTML5_TAG_UNKNOWN));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        self::assertSame([], array_map(static fn(LibXMLError $e): string => "line {$e->line}: " . trim($e->message), $errors), $uri);
    }

    public function testEveryPageOfTheMirroredAthenaBlogRenders(): void
    {
        $this->mirrorAthena();
        $uris = [
            '/blog/wyome',
            '/s/' . $this->postId(static fn(Post $p): bool => $p->kind === 'note'),
            '/s/' . $this->postId(static fn(Post $p): bool => $p->kind === 'link' && isset($p->doc['_source'])),
            '/s/' . $this->postId(static fn(Post $p): bool => isset($p->doc['_video'])),
            '/s/' . $this->postId(static fn(Post $p): bool => isset($p->doc['_athenana']['song'])),
            '/blog/wyome/tag/travel',
            '/blog/wyome/tags',
            '/blog/wyome/about',
            '/blog/wyome/search?q=visa',
        ];
        preg_match('#<a class="pager-next" rel="next" href="([^"]+)"#', (string) $this->get('/blog/wyome')->getBody(), $next);
        $uris[] = html_entity_decode($next[1] ?? self::fail('no second page'));
        foreach ($uris as $uri) {
            [$response, $html] = $this->both($uri);
            self::assertSecureHtml($response);
            self::assertParses((string) $response->getBody(), $uri);
            self::assertStringNotContainsString('<script', $html, $uri);
            self::assertStringNotContainsString('<iframe', $html, $uri);
            self::assertStringNotContainsString(' style=', $html, $uri);
        }
        $home = self::main($this->both('/blog/wyome')[1]);
        self::assertSame(20, substr_count($home, '<article '));
        self::assertStringContainsString('<a class="post-permalink" href="https://www.wyome.com/blog/post.html?id=', $home, 'drop-in: cards link the canonical post page');
        self::assertStringContainsString('<p class="post-via">via<a href=', $home);
        self::assertStringContainsString('<a href="https://athenana.com/blog/wyome/subscribe">Subscribe by email</a>', self::main($this->both('/blog/wyome/about')[1]));
        self::assertMatchesRegularExpression('#<p class="search-count">[1-9]\d* results?</p>#', self::main($this->both('/blog/wyome/search?q=visa')[1]));
        self::assertStringContainsString('<p class="post-video"><a class="video-link" href="https://www.youtube.com/watch?v=', $this->both('/s/' . $this->postId(static fn(Post $p): bool => isset($p->doc['_video'])))[1]);
    }

    public function testAThemeOverridesTheLinkPartialInEitherEngineWithoutModuleChanges(): void
    {
        $this->mirrorAthena();
        Fixtures::theme($this->tmp . '/themes', 'custom', ['parent' => 'phoenix', 'engine' => 'php'], [
            'templates/modules/blog/partials/kinds/link.php' => '<p class="custom-link"><?= $x->e($post->title) ?></p>',
        ]);
        foreach (['php', 'twig'] as $engine) {
            $app = $this->site($engine, ['themes.paths' => [$this->tmp . '/themes', 'themes'], 'app.theme' => 'custom']);
            $html = Html::normalize((string) $app->handle(new ServerRequest('GET', '/blog/wyome'))->getBody());
            self::assertStringContainsString('<article class="post post-link"><p class="custom-link">', $html, $engine);
            self::assertStringNotContainsString('post-via', $html, $engine);
        }
    }
}
```

- [ ] **Step 2: Run it**

Run: `vendor/bin/phpunit tests/Module/Blog/BlogPagesSmokeTest.php`
Expected: PASS (2 tests). If a page fails to parse, fix the template that emits the broken markup; never add another ignored libxml code. Only 801 is ignored, and why is explained beside the constant.

- [ ] **Step 3: Document the pages**

In `README.md`, insert this immediately before the paragraph `Search uses its own index (`xar_post_search`)…` that Task 5 added:
```markdown
### Blog pages

Every blog also has HTML pages in the active theme (`phoenix` by default):

- `/blog/{handle}`: posts, newest first, 20 a page, with the pinned post on top
- `/blog/{handle}/tag/{slug}` and `/blog/{handle}/tags` (the tag cloud)
- `/blog/{handle}/about` and `/blog/{handle}/search?q=`
- `/s/{id}`: one post. A mirror with `--post-url` links its cards to that canonical address instead.

Run `bin/xar asset:publish` once so `/assets/module/blog/blog.css` exists. Post HTML is sanitised when it
is rendered (and cached under `var/cache/data`); stored posts and the JSON/RSS feeds are never changed. Pages
send a strict `Content-Security-Policy` and load no scripts or iframes: a video is a link to YouTube.

New installs get four sidebar blocks (search, about, recent posts, tags) on every `blog.*` page. An install
that enabled the blog module before they existed creates them with `bin/xar blog:blocks`.

Themes override any blog template at `themes/<theme>/templates/modules/blog/<path>.php` (or `.twig`), for
example `partials/kinds/link`, without touching the module. The blog templates pull every overridable
partial with `render()`, so an override in either engine is found.

```

In `docs/superpowers/plans/2026-10-09-phoenix-kernel-views-followups.md`, replace the whole `## Blog pages plan: decide or carry` section (its heading and four bullets) with:
```markdown
## Blog pages plan: decided (2026-10-11-phoenix-blog-pages-plan.md)

- [Done, Task 1] Controllers have no asset-URL service: `Xaraya\Kernel\Assets\AssetUrls` now holds the logic, `Helpers::asset` delegates to it, and blog controllers fill `Page::$styles` with it.
- [Carried] `layout.php`/`layout.twig` hardcode `phoenix.css`, so a child theme inheriting the layout cannot add its own sheet without an override. The `href="/"` home link breaks subdirectory installs; it stays as it is. `url('home')` is not safe as a replacement, because a module may claim `/` under another name.
- [Carried] ErrorPages always uses `app.locale`, so a blog 404 can't carry `blog.language`.
- [Done] Overridable partials: the blog module pulls every overridable partial (post, kinds/*, extras/*, pager, tagcloud, searchform and the block templates) with `render()`, never `include`, so a theme override in either engine is found (tested in `PostTemplatesTest` and `BlogPagesSmokeTest`).
```

- [ ] **Step 4: Run the final verification**

Run each of these:
- `composer test`: PASS, about 548 tests. Repeat with the MySQL env vars: PASS. `AthenaParityTest` and `FeedHttpTest` are unchanged and pass, which covers definition-of-done item 4.
- `composer stan`: `[OK] No errors`.
- `composer cs`: exit 0.
- `composer validate`: valid.
- `grep -rn "include(" modules/blog/templates; grep -rn "{% include" modules/blog/templates`: no output.
- `grep -rln "<script\|<iframe\|style=" modules/blog/templates modules/blog/assets`: no output.
- Manual smoke test:
  - Run `rm -f var/database.sqlite && bin/xar migrate && bin/xar module:enable blog && bin/xar asset:publish`.
  - Run `bin/xar blog:create wyome --mode=mirror --format=athena --source=https://athenana.com/blog/wyome/feed.json`, then `bin/xar blog:sync wyome --full && bin/xar blog:reindex --all && bin/xar serve`.
  - Open `http://localhost:8080/blog/wyome`, page back, then open a post, a tag, `/blog/wyome/tags`, `/blog/wyome/about` and a search. Every page is styled, the browser console shows no CSP violations, and the response headers carry the CSP.
- `git status --short`: only the files of this task.

- [ ] **Step 5: Commit**

```bash
git add tests/Module/Blog/BlogPagesSmokeTest.php README.md docs/superpowers/plans/2026-10-09-phoenix-kernel-views-followups.md
git commit -m "test(blog): smoke-test the mirrored Athena blog pages; document blog pages" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Do not push. The controller pushes and checks CI; Postgres runs only there.

---

## Self-review

**1. Spec coverage**

| Requirement | Task |
|---|---|
| §1 `blog.home`: 20 a page, `?before=` cursor, pinned post above page 1 | 9 (listing from 8) |
| §1 `blog.tag`: published posts with the tag, paged the same way | 8 |
| §1 `blog.tags`: tags with ≥ 1 published post, with counts, 5 quantile steps, sorted by name | 10 |
| §1 `blog.about`: title, sanitised description, author (linked), JSON and RSS links, subscribe link | 11 |
| §1 `blog.search`: highlighted snippets, `?page=N`, 20 a page, "N results" | 12 |
| §1 `blog.post`: full post, tags, mentions, previous/next by `(date_published, id)` | 8 (template partials 7) |
| §1 handle, id and slug patterns; slug decoded once and compared exactly | 8 (routes), Global Constraints |
| §1 `conditional` + `secureHtml` on every page route | 8–12 (routes), `assertSecureHtml` in every page test |
| §1 post links follow `post_url_pattern` on mirrors, else `/s/{id}`; canonical is `ItemSerializer::url()` | 6 (`link`, `canonicalUrl`), 8 (`testAMirrorPatternIsTheCanonicalAndTheLink`), 15 |
| §1 head: lang, title, description, alternate feeds, OG tags | 8 (`BlogPage`), asserted in 8, 9, 10, 11, 12 |
| §1 errors: 404 and 400 cases, rendered through `error/{status}` | 8 (post, tag, cursor), 9 (blog, cursor), 10, 11, 12 (`page`, `q`) |
| §1 caching: `public, max-age=60` + ETag, no `Last-Modified` | 8 (`BlogPage`, 304 test) |
| §1 sidebar blocks `blog.info/tagcloud/searchbox/recent`, `blocks` map and `blockDefaults`, blog from `handle` or the post | 13 |
| §2.1 `PostViewFactory::make` and every `PostView` field; media map; comment de-duplication | 6 |
| §2.2 templates for pages, post, kinds, extras and pager in both engines; overridable | 7, 8, 9, 10, 11, 12, 13 |
| §2.2 partial outputs (link, highlight, photo, note, video, podcast, gallery) | 7 |
| §2.2 accessible, no-JS styling with landmarks, focus, colour scheme and reduced motion | 14 (module `blog.css`, deviation 2); the landmarks come from the phoenix layout |
| §3.1 `HtmlSanitizer` rules, ext-dom, idempotency, UTF-8 | 2 |
| §3.2 sanitising only at render time; stored data and feeds untouched | 6, 8 (`BlogPage::descriptionHtml`), 15 (`AthenaParityTest`) |
| §3.3 sanitised output cached in `var/cache` under a versioned key; `cache:clear` removes it | 6 (`Cache` lives under `var/cache/data`, which `App::clearCache()` already removes) |
| §3.4 `secureHtml` headers on every HTML route | Kernel Plan 2, asserted in 8–15 |
| §4 `xar_post_search` migration and index | 4 |
| §4 `SearchText::normalize`, body contents and `snippet` | 3 |
| §4 `SearchIndex` and `LikeSearchIndex`: terms, escaping, joins, order, total, no usable terms | 4 |
| §4 subscribers on `ItemCreated/ItemUpdated/ItemDeleted`; `blog:reindex`; README note | 4, 5 |
| §5 unit tests: sanitiser vectors, `SearchText`, factory, `LikeSearchIndex` | 2, 3, 6, 4 |
| §5 HTTP tests: every page, paging, pinned, tags, cloud, about, search, 404/400, headers, hidden posts, URL pattern | 8–13 |
| §5 engine parity | 7 (partials), `both()` in every page test |
| §5 fixture smoke test with zero libxml errors | 15 (code 801 ignored, deviation 12) |
| §6.1 browse a mirrored blog end to end after `blog:reindex --all` | 15 |
| §6.2 no third-party scripts or iframes; §3.4 headers on every page | 7, 15 |
| §6.3 override `kinds/link` from a theme; switch engines | 7 (partials, both directions), 15 (page) |
| §6.4 JSON and RSS byte-for-byte unchanged | 8 (`testTheJsonRouteIsUnchanged`), 15 (`AthenaParityTest`, `FeedHttpTest`) |
| §6.5 CI green | Global Constraints, final verification in 15 |
| Follow-ups: `AssetUrls`; `render()` convention; error pages keep `app.locale`; `href="/"` stays | 1, 7 and Global Constraints, 15 (followups file) |

**2. Placeholder scan:** every code step holds the complete file or the exact method to add, together with where it goes. There is no "TBD", no "similar to Task N", and no step without its command and expected result.

**3. Type consistency:** these names are used the same way in every task where they appear:
- `BlogPage::PAGE_SIZE/now/blog/page/summary/descriptionHtml`
- `Paging::before/page/cursorParam/query`
- `PostViewFactory::make/link/plainText/cacheKey/display/duration`
- the `PostView` constructor order, and `PostViews::make/everyKind`
- `SearchIndex::index/remove/clear/search`, `LikeSearchIndex::escapeLike`, `SearchText::normalize/terms/body/snippet`
- `PostRepository::publishedAfter/neighbours/pageByTag/findLiveByItemId/tagCounts/findMany`
- `TagCloud::build`, `CurrentBlog::of`
- the `BlogTestCase` helpers `nativeBlog/addPost/postRecord`, and the `BlogPageTestCase` helpers `site/get/both/main/assertSecureHtml`
- the pager shape `{prev, next, prevLabel, nextLabel}`
- the route names `blog.home`, `blog.tag`, `blog.tags`, `blog.about`, `blog.search`, `blog.post`, `blog.post.json`, `blog.feed.json` and `blog.feed.xml`

**4. Review Focus:** all five inputs have a test in the task that owns the behaviour (listed under Review Focus). Covered elsewhere and therefore left out of the five: a mirror whose `post_url_pattern` gives an external canonical (Task 8, Task 15), scheduled and deleted posts on every page (Tasks 8–13), hostile description HTML (Task 11), and hostile `q` echoed into the form (Task 12).

**5. Known limits, recorded rather than built:**
- `SearchText` keeps ASCII letters and digits only, so CJK and other scripts with no ASCII transliteration cannot be searched with the portable index. This is the spec's normalisation.
- `ItemSerializer::url()` applies `post_url_pattern` to native blogs too, so a native blog with a pattern has an external canonical while its cards link `/s/{id}`. That follows spec §1 to the letter.
- Block titles seeded by `blockDefaults` are stored in English and are not translated.
- HTML nested deeper than 64 elements loses the deeper content (libxml stops building the tree even sooner).
- Error pages keep `app.locale` and the layout's `href="/"`, as decided.
