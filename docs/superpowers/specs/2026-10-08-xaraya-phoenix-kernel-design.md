# Xaraya Phoenix — Kernel Design

- **Date:** 2026-10-08
- **Status:** Approved in brainstorming, pending written-spec review
- **Branch:** `next` on `jcc2010/xaraya-phoenix` (fork of `xaraya/core`, branch `com.xaraya.core.bermuda`)
- **License:** GPL-2.0-or-later (inherited)

## 0. Context

Xaraya Phoenix is a modernised, much smaller fork of Xaraya. Its first product goal is an open-source blogging engine that is a drop-in for Athena's blog format, and a content manager that can import other JSON sources.

Athena's "blogging JSON" is **JSON Feed 1.1** with Athena extensions:

- `_video` and `_source` on items.
- An `_athenana` object that carries:
  - on the feed: `pinned_id` and `subscribe_url`;
  - on items: `kind`, `pinned`, `images`, `song`, `podcast`, `quote`, `comment`, `tags` and `mentions`.

It is served from two places:

- `GET /blog/{handle}/feed.json`: pages of 50 items, newest first, with a `next_url` built from a `?before=` cursor. The cursor is unpadded base64url of `"Y-m-d H:i:s|<ulid>"`.
- `GET /s/{id}.json`: a single item.

Both are sent with `Access-Control-Allow-Origin: *`. The reference definition is `athena-portable-blog/app/Support/BlogFeedItem.php`. The consumer contract is in `wyome-portable-blog/docs/athenana-feed-parity.md`.

Phoenix adopts this format as its **native** content format. It both imports and re-serves it, and it accepts other JSON sources through dynamic data.

### 0.1 Fork approach

The fork is a **new kernel inside the fork**:

- The repository keeps Xaraya's full git history.
- `next` builds a clean PSR-4 kernel.
- Xaraya concepts are ported onto it one at a time, reusing old code only where it still fits.
- The legacy `html/` tree moves to `legacy/` as read-only reference and is deleted before 1.0.

### 0.2 Naming

- **Product:** Xaraya Phoenix.
- **Namespace:** `Xaraya\`.
- **CLI:** `xar`.
- **Release codenames:** X-Men characters in order (1.0 *Cyclops*, 1.1 *Storm*, 1.2 *Nightcrawler*, …). These are internal labels only.
- **Legacy constant:** `VERSION_ID = 'Bermuda'` from upstream is retired.

### 0.3 Sub-project roadmap

Each sub-project gets its own spec, plan and implementation cycle.

| # | Sub-project | Spec |
|---|---|---|
| 1+2 | Kernel, plus users and access, plus blocks | **this document** |
| 4 | Blog module (Athena-native model, admin, public pages, `feed.json`, `/s/{id}.json`, RSS) | next |
| 5a | Athena feed importer and incremental sync | |
| 3 | Dynamic data and categories modules (storage model fixed in §2.4 below) | |
| 5b | Generic JSON → dynamic-data mapper; WordPress and Ghost importers | |
| 6 | Docker image, docs, 1.0 *Cyclops* release | |

Build order: kernel → blog → Athena import → DD and categories → generic JSON → packaging.

### 0.4 Non-goals for the kernel

The kernel does not include:

- ActivityPub, Webmention receiving or Micropub. Each is a possible future module; Micropub is a priority because Athena clients already speak it.
- 2FA, OAuth or social login.
- Compatibility with legacy `index.php?module=&type=&func=` URLs.
- The BlockLayout/XSL template engine.
- Creole.
- Realms.
- Multiple role inheritance.
- Block groups.
- API hooks and transform hooks.
- Promoting DD objects to real tables.

---

## 1. Architecture and layout

**Platform and conventions**

- PHP ≥ 8.3.
- Composer and PSR-4 under `Xaraya\`.
- No `xar*` globals or static facades.
- No classmap autoloading.

**Repository layout**

```
bin/xar                 CLI entry point
public/index.php        front controller (only web-exposed directory)
public/assets/          published module/theme assets
src/Kernel/             App, Container, Config, Http, Routing, Db, Events, View, Auth, Cli, Module
modules/<name>/         module.json, src/, templates/, migrations/, lang/, assets/
themes/<name>/          theme.json, templates/, lang/, assets/
config/app.php          plain PHP array; .env overrides
var/                    cache/, logs/, database.sqlite, uploads/
examples/               sample modules (hello, hello-hooks)
tests/
legacy/                 former html/ tree, reference only, removed before 1.0
```

**Request flow**

1. `public/index.php` calls `App::boot()`.
2. Boot loads config, builds the container and registers enabled modules (routes, event subscribers, block types, commands).
3. The request passes through the PSR-15 middleware pipeline (§3.3).
4. A controller handles it and returns a PSR-7 response, which is then emitted.

**Container**

- Autowires constructors by type.
- Supports explicit factories and shared (singleton) bindings.
- Modules can register bindings through an optional `ServiceProvider` class named in the manifest.

**Runtime dependencies**

The kernel targets **5 or fewer** runtime Composer packages:

- `nyholm/psr7`
- `nyholm/psr7-server`
- `nikic/fast-route`
- `psr/log`
- `twig/twig`, optional and listed under `suggest`

The container, config, events, database, CLI and PHP view engine are our own code.

**Module manifest (`module.json`)**

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
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Blog\\Listeners\\Ping", "priority": 0 }
  ],
  "displayHooks": { "item.display": "Xaraya\\Module\\Blog\\Hooks\\Display" },
  "hookDefaults": [ { "subject": "blog", "itemtype": "post" } ],
  "blocks": [ "Xaraya\\Module\\Blog\\Blocks\\RecentPosts" ],
  "commands": [ "Xaraya\\Module\\Blog\\Cli\\Republish" ],
  "grants": [ { "role": "Authors", "component": "post", "instance": "own", "level": "delete" } ]
}
```

Module state (enabled or disabled, installed version) is stored in `xar_modules`.

---

## 2. Database, migrations and storage

### 2.1 Connection

`Xaraya\Kernel\Db\Connection` is a thin wrapper over PDO. There is no ORM.

- **Methods:** `query`, `fetchOne`, `fetchAll`, `fetchValue`, `insert`, `update`, `delete`, `transaction(callable)`.
- **Select builder:** `select()` builds portable queries with `where`, `whereIn`, `orderBy`, `limit` and tuple-cursor comparison (`(date, id) < (?, ?)`), which keyset paging needs. On SQLite and MySQL the tuple comparison is expanded to `a < ? OR (a = ? AND b < ?)`.
- **Dialects:** `Sqlite` (the default), `Mysql` (MySQL 8 and MariaDB 10.6 or later) and `Pgsql` (Postgres 14 or later). They cover identifier quoting, the JSON type, upsert, the current-time expression and limit syntax.
- **Table prefix:** configurable, default `xar_`.
- **Config:** `db.dsn`, `db.user`, `db.password`, `db.prefix`.

### 2.2 Migrations

- Each module keeps PHP classes in `migrations/`, named `YYYY_MM_DD_NNNNNN_description.php`. Each class has `up(Schema $s)` and an optional `down(Schema $s)`.
- `Schema` provides `create`, `table` (alter), `drop` and `rename`.
- **Column types:** `ulid` (CHAR(26)), `string(len)`, `text`, `int`, `bigint`, `bool`, `datetime`, `json`. Columns can be marked `nullable`, given a `default` or made `unique`, and tables can have `index`, `foreign`, `primary` and composite indexes.
- **JSON storage per dialect:** SQLite TEXT, MySQL JSON, Postgres JSONB.
- **Tracking table:** `xar_migrations` (`module`, `name`, `batch`, `ran_at`).
- **Commands:** `xar migrate`, `xar migrate:rollback [--step=N]`, `xar migrate:status`. `module:enable` runs the module's pending migrations.

### 2.3 Conventions

- **IDs:** lower-case ULIDs generated by the kernel's `Ulid` class. Imported records **keep their source ID** when it is a valid ULID. Athena IDs therefore survive a migration, so `/s/{id}` URLs and feed `id`s stay stable and feed readers don't receive everything again.
- **Times:** stored in UTC as `datetime`, written out in ISO 8601 with an offset, e.g. `2026-10-08T15:07:16+00:00`.
- **Lossless extras:** every content table has an `extra` JSON column that stores source fields Phoenix does not model, such as unknown `_athenana` keys or other `_`-prefixed JSON Feed extensions. They are written back out on re-serialisation.

### 2.4 Dynamic-data storage model

The modules themselves are sub-project 3. The kernel's schema builder must support this model:

| Table | Columns | Purpose |
|---|---|---|
| `xar_dd_objects` | `id`, `name` (unique), `label`, `module`, `itemtype`, `config` json | defines object types |
| `xar_dd_properties` | `id`, `object_id`, `name`, `label`, `type`, `required`, `filterable`, `sortable`, `sort`, `config` json | defines the properties of each object type |
| `xar_dd_items` | `id` ulid, `object_id`, `data` json, `owner_id`, `created`, `updated` | one row per item, stored as a JSON document |
| `xar_dd_index` | `item_id`, `property`, `value_string`, `value_number`, `value_date` | written only for properties marked filterable or sortable; gives portable querying |

---

## 3. HTTP, routing, middleware and hooks

### 3.1 Routing

- Built on `nikic/fast-route`.
- Each module's `Routes` class receives a `RouteCollector`, e.g. `$r->get('/blog/{handle}/feed.json', FeedController::class, 'blog.feed')->middleware('cors', 'conditional');`
- **Supported methods:** GET, POST, PUT, PATCH, DELETE and `any`. Routes can be grouped with a shared prefix and shared middleware.
- **Named routes:** `url($name, $params, $absolute = false)` is available in controllers and in both template engines.
- In production the route table is cached to `var/cache/routes.php`.

### 3.2 Controllers

- Invokable classes or `Class::method`, resolved from the container.
- Signature: `(ServerRequestInterface $req, array $params): ResponseInterface`.
- The `Controller` base class is optional and provides `view()`, `json($data, $status = 200, $contentType = 'application/json')`, `redirect()` and `notFound()`.

### 3.3 Middleware (PSR-15)

**Global order**

1. `ErrorHandler`
2. `Session`
3. `Auth` (identifies the user; does not enforce)
4. `Csrf` (all non-GET/HEAD/OPTIONS requests except routes marked `csrf:off`, such as token-authenticated APIs)
5. Router dispatch

**Per-route middleware**

| Middleware | Behaviour |
|---|---|
| `auth` | require login |
| `auth:<module>.<component>.<level>` | require that level for the component; the instance check happens in the controller when an item is involved |
| `cors` | `Access-Control-Allow-Origin: *` plus preflight handling |
| `conditional` | computes an ETag from the response body, honours `Last-Modified` set by the controller, answers `If-None-Match`/`If-Modified-Since` with 304 |
| `throttle:<key>,<max>,<seconds>` | rate limiting, stored in the DB |

### 3.4 Hooks (simplified Xaraya hooks)

**Events**

- Synchronous and in-process.
- Typed event classes: `ItemCreated`, `ItemUpdated`, `ItemDeleted` and `ItemDisplayed`. Each carries `module`, `itemtype`, `id` and an `item` snapshot.
- Modules may define their own events.
- Subscribers declared in manifests run in priority order (higher priority first).
- A listener may call `stopPropagation()`.

**Display hooks**

- `hooks('item.display', $item)` and `hooks('item.form', $item)` return the concatenated HTML fragments from each observer module that is **bound** to the item's module and itemtype.
- A `hooks('item.form.save', $item, $input)` event lets observers persist their own form fields.

**Bindings**

- Stored in the `xar_hooks` table (`observer_module`, `subject_module`, `itemtype` (`*` allowed), `enabled`).
- Seeded from each manifest's `hookDefaults` when the module is enabled.
- Toggled from the admin UI or with `xar hook:enable|disable`.
- Event subscribers that are not display hooks run regardless of bindings.

---

## 4. Users, roles and privileges

### 4.1 Tables

| Table | Columns |
|---|---|
| `xar_users` | `id` ulid, `handle` unique (`[a-z0-9_-]{2,32}`), `name`, `email` unique, `password_hash`, `status` (`active`/`pending`/`disabled`), `created`, `last_login` |
| `xar_roles` | `id`, `name` unique, `parent_id` nullable (single inheritance; a child gets its parent's grants) |
| `xar_user_roles` | `user_id`, `role_id` |
| `xar_grants` | `id`, `role_id`, `module`, `component`, `instance`, `level` |
| `xar_tokens` | `id`, `user_id`, `name`, `token_hash` (sha256), `scopes` json, `last_used`, `expires_at` nullable |
| `xar_throttle` | `key`, `hits`, `reset_at` |

### 4.2 Roles and levels

**Seeded roles:** Administrators, Editors, Authors, Members and Anonymous. Every request implicitly has Anonymous; signed-in users also have Members.

**Access levels:** none 0, overview 100, read 200, comment 300, moderate 400, edit 500, add 600, delete 700, admin 800.

**Default grants**

| Role | Grant | Level |
|---|---|---|
| Administrators | `* · * · *` | admin |
| Editors | `* · * · *` on every module whose manifest sets `"content": true` (seeded when the module is enabled) | delete |
| Authors | `blog · post · own` | delete |
| Authors | `blog · post · *` | read |
| Anonymous | `* · * · *` | read |

### 4.3 Checks

- `Auth::can(string $level, string $module, string $component, mixed $item = null): bool`.
- Matching grants are gathered from the user's roles and their ancestors.
- A grant matches when its `module` and `component` are equal or `*` and its `instance` matches. Instance matching:
  - `*` matches anything;
  - an exact ID matches that item;
  - `own` matches when `$item->owner_id === user.id`.
- The highest matching level must be at least the requested level.
- Results are memoised for the request.

### 4.4 Authentication

**Sessions**

- Login with handle or email plus password.
- PHP `password_hash` (default algorithm), rehashed when needed.
- The session ID is regenerated at login.
- Cookies are HttpOnly, Secure (unless in debug mode on HTTP) and SameSite=Lax.

**Login throttling:** 5 failures per 15 minutes, counted per IP plus account.

**Bearer tokens**

- `Authorization: Bearer xar_<random>`.
- Only the hash is stored.
- Scopes are strings such as `blog:write` and `import:run`.
- `last_used` is updated at most once per minute.

**Password reset**

- Sends a signed, single-use link that expires in 60 minutes, through `Mailer` (SMTP or `mail()`; configured under `mail.*`).
- CLI fallback: `xar user:password <handle>`.

### 4.5 Install

`xar install`:

1. Checks the PHP version and the `pdo`, `mbstring` and `json` extensions.
2. Writes `.env` if it is missing.
3. Creates the SQLite file or verifies the DSN.
4. Runs the kernel migrations.
5. Seeds roles and grants.
6. Prompts for the first administrator (handle, email, password).
7. Enables the default theme.

Non-interactive flags are supported for Docker and CI.

---

## 5. Templates, themes and blocks

### 5.1 View

- `View::render(string $name, array $data = []): string`.
- **The file extension selects the engine:** `.twig` → `TwigEngine` (only when `twig/twig` is installed; otherwise a clear exception), `.php` → `PhpEngine`.

**Lookup order** (first hit wins):

1. `themes/<active>/templates/modules/<module>/<name>.*`
2. `themes/<parent>/templates/modules/<module>/<name>.*` (a theme may have one parent)
3. `modules/<module>/templates/<name>.*`

Within one location, when both extensions exist, the theme's `engine` preference breaks the tie.

**Layout wrapping**

- The page body renders first.
- The theme's `layout` template then renders with `content` (already HTML) plus page metadata (`title`, `meta`, `feeds`).
- Engines may differ between layout and body.
- Inside a template, `include` stays in the same engine. `render()` crosses engines.

### 5.2 Helpers

Both engines get the same helper set:

| Helper | Purpose |
|---|---|
| `url` | named-route URLs |
| `asset` | URLs for published module/theme assets |
| `can` | privilege check (§4.3) |
| `hooks` | display-hook output (§3.4) |
| `blocks(region)` | renders a theme region's blocks |
| `csrf` | CSRF hidden field / token |
| `t(key, params)` | translation |
| `e` | HTML escape |
| `user` | the current user |
| `render` | render another template (crosses engines) |
| `config(key)` | read a config value |

**Twig** auto-escapes.

**PHP templates** receive variables directly and helpers as `$x->helper()`. They do **not** auto-escape, so `e()` is mandatory; this is documented, and the default theme follows it.

### 5.3 Translation

- `t()` looks up PHP array catalogs at `modules/*/lang/<locale>.php` and `themes/*/lang/<locale>.php`.
- Placeholders are written `{name}`.
- Locale comes from config.
- English source strings are the fallback.

### 5.4 Themes

`theme.json` fields:

| Field | Meaning |
|---|---|
| `name`, `version` | identity |
| `parent` | optional parent theme |
| `engine` | `twig` or `php` |
| `regions` | e.g. `["header", "sidebar", "footer"]` |
| `settings` | schema of admin-editable values (`accent`, `logo`, …) |

- Settings values are stored in `xar_settings` (`scope`, `key`, `value` json). This is also the general key-value store for module settings.
- `xar asset:publish` symlinks `modules/*/assets` and `themes/*/assets` into `public/assets/<module|theme>/<name>/`, falling back to copying.
- No front-end build step is required.

### 5.5 Blocks

- **Block types** are module classes implementing `Block`:
  - `render(array $config, Context $ctx): string`
  - optionally `form(array $config): string` and `validate(array $input): array`
- **Instances** live in `xar_blocks`: `id`, `type`, `region`, `title`, `config` json, `sort`, `visibility` json (`{"routes": ["blog.*"], "roles": ["Members"]}`), `enabled`.
- **Built-in types:** text (HTML or Markdown), menu, login and recent-items. Recent-items asks modules through a `RecentItemsQuery` event.

### 5.6 Default theme `phoenix`

- Clean, accessible and mobile-first.
- Ships the same templates in **both** Twig and PHP.
- A test renders each page with both engines and asserts the HTML is identical after whitespace normalisation.

---

## 6. CLI, config, errors and quality

### 6.1 CLI

`bin/xar` is our own small command runner. It parses arguments and options, prints help and asks interactive questions. Modules register commands through their manifest.

**Kernel commands**

- `install`, `serve`
- `migrate`, `migrate:rollback`, `migrate:status`
- `module:list`, `module:enable`, `module:disable`
- `hook:enable`, `hook:disable`
- `user:create`, `user:password`
- `token:create`, `token:revoke`
- `asset:publish`, `cache:clear`

### 6.2 Config

- `config/app.php` returns an array and reads `env('KEY', default)`.
- `.env` is read by a built-in parser that handles `KEY=value`, quoted values and `#` comments.
- **Keys:** `app.url`, `app.debug`, `app.timezone`, `app.locale`, `app.theme`, `db.*`, `mail.*`, `session.*`.
- With `app.debug=false`, config is cached in `var/cache/config.php`.

### 6.3 Errors and logging

- `HttpException` subclasses (`NotFound`, `Forbidden`, `Unauthorized`, `MethodNotAllowed`, `TooManyRequests`) map to status codes.
- `ErrorHandler` renders the theme template `error/<status>` (or a built-in fallback). On routes ending in `.json`, or when the client accepts JSON, it returns `{"error": {"status", "message"}}` instead.
- Stack traces appear only in debug mode.
- The logger is a PSR-3 `FileLogger` writing to `var/logs/xaraya-YYYY-MM-DD.log`, and can be replaced through the container.

### 6.4 Quality

- PHPUnit 11; the default test DB is in-memory SQLite.
- GitHub Actions runs PHP 8.3 and 8.4 against SQLite, MySQL 8 and Postgres 16.
- PHPStan level 8 on `src/`, `modules/` and `examples/`.
- PER-CS coding style, enforced with php-cs-fixer.

### 6.5 Legacy

- The first commit on `next` moves `html/`, `templates/`, `developer/` and the legacy PHPStan/PHPUnit config into `legacy/`.
- Nothing under `legacy/` is autoloaded.
- `legacy/` is deleted before 1.0 *Cyclops*, once dynamic data and categories have been ported.

---

## 7. Definition of done (kernel)

1. On a fresh clone, `composer install && bin/xar install && bin/xar serve` serves the `phoenix` home page on SQLite.
2. An administrator can log in and out. Login throttling, CSRF rejection, password reset by email, and `user:password` all work.
3. `examples/hello` and `examples/hello-hooks` show that:
   - `module:enable` runs migrations and seeds grants;
   - routes render identical HTML through the Twig and PHP templates;
   - `hello-hooks` adds a display-hook fragment to `hello` pages, and disabling the binding removes it;
   - a `hello` sidebar block renders, and hides when its visibility rule excludes the route;
   - Members get 403 on `hello`'s admin route and Administrators get 200;
   - a bearer token with the `hello:write` scope can POST to a `csrf:off` API route, and one without the scope cannot.
4. The CI matrix (PHP 8.3/8.4 × SQLite/MySQL/Postgres) is green, and PHPStan level 8 is clean.
5. Size budget: 5 or fewer runtime packages, and `src/Kernel` under about 6,000 lines.
