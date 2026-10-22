# Phoenix Kernel Plan 3 (Users, Roles, Privileges and Authentication) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Phoenix kernel users, roles and grants, database sessions, CSRF protection, the `auth` and `throttle` route middleware, login, logout and password-reset pages, a `Mailer`, the `install`, `user:create` and `user:password` commands, role-aware blocks, and bearer tokens that act as a user.

**Architecture:**

- **Data (Tasks 1, 3, 7, 10):** four kernel migrations create `xar_users`, `xar_roles` (seeded with the five roles), `xar_user_roles` and `xar_grants` (seeded with the two role-wide grants), then `xar_sessions`, `xar_throttle` and `xar_password_resets`. `UserRepository` owns users and their roles. `Grants` owns roles, grants and the per-module grant seeding that runs on every `module:enable`.
- **Identity (Tasks 2 and 4):** `Auth` implements the existing `Access` interface and replaces `GuestAccess`. The new global middleware runs after `ErrorHandler` in this order: `SessionMiddleware` (a PSR-7 cookie and a database row keyed by the cookie's sha256), `Authenticate` (the session's user becomes `Auth`'s identity), then `VerifyCsrf`. `App::handle()` resets the identity at the start of every request.
- **Route middleware (Tasks 5-7):** `csrf:off` keeps working as a marker. `auth` and `auth:<module>.<component>.<level>` (`RequireAuth`) redirect a guest to `/login?next=…` from an HTML GET, answer 401 otherwise (JSON on JSON routes), and answer 403 to a signed-in user. `throttle:<key>,<max>,<seconds>` (`ThrottleRequests`) counts per client IP (`ClientIp`: `REMOTE_ADDR`, or `X-Forwarded-For` from `http.trusted_proxies` only).
- **Pages, mail and CLI (Tasks 8-12):** `AuthRoutes` adds `/login`, `/logout`, `/password/forgot` and `/password/reset/{token}`. They render `auth/login`, `auth/forgot` and `auth/reset` in the `phoenix` theme, in `.php` and `.twig`. `Mailer` has `log`, `mail()` and `memory` drivers. `PasswordResets` stores only the sha256 of a single-use, 60-minute link. `Output::ask()` gives the CLI hidden prompts, which `user:create`, `user:password` and `install` use.
- **Integration (Tasks 13-15):** a built-in `login` block; `user()` is typed `?User`. `xar_tokens.user_id` lets a token act as its user, and module manifests can declare extra token scopes. `examples/hello` gains an admin route and a `token:hello:write` API route, and a test pins the definition of done.
- **Docs and smoke (Task 16).**

**Tech Stack:** PHP ≥ 8.3, the Phoenix kernel (PSR-7/15 through nyholm/psr7, FastRoute, PDO), PHPUnit 11, PHPStan level 8, php-cs-fixer (PER-CS), SQLite, MySQL 8 and Postgres 16. No new Composer package. Passwords use `password_hash(PASSWORD_DEFAULT)`; tokens and ids use `random_bytes`.

**Spec:** `docs/superpowers/specs/2026-10-08-xaraya-phoenix-kernel-design.md`, covering:
- §3.3: the global Session, Auth and Csrf middleware, and the per-route `auth`, `auth:` and `throttle`.
- §4: all of it.
- §5.5: block visibility `roles`, and the built-in `login` block type.
- §6.1: `install`, `user:create` and `user:password`.
- §6.3: `Forbidden`, `Unauthorized` and `TooManyRequests`.
- §7: items 1 and 2, the last two bullets of item 3, and item 4.

Every task's code was built and run on SQLite and MySQL in a throwaway worktree while this plan was written. The suite went from 696 to 783 tests, all green with PHPStan and php-cs-fixer, and the Task 16 smoke run passed in a `/tmp` clone. Every code step below is the exact file, or the exact replace/with text, from that build.

**Size:** Plan 3 adds about 2,050 lines to `src/Kernel`, taking it from 7,007 to 9,059 against the spec's target of about 6,000. That is a deviation (number 15 below). Of those lines:
- `Auth/*`: about 1,120 (users, grants, sessions, throttle, resets, controllers).
- Middleware: 310.
- CLI: 310.
- Mail: 115.
- Migrations: 145.
- Wiring: 50.

**What earlier plans already shipped (checked in the code; not re-planned):**

- §6.3's exceptions: `Forbidden` (403), `Unauthorized` (401) and `TooManyRequests(int $retryAfter)` (429, with `Retry-After`) exist in `src/Kernel/Http/Exception/`. `HttpException::reason()` knows 401, 403, 419 and 429. `ErrorHandler` renders them as themed HTML (with the `secureHtml` headers) or as JSON.
- The `csrf` alias is registered as a marker, so `csrf:off` on a route builds no middleware. Global middleware reads it from the route's middleware list.
- `Access` (with `LEVELS`, `can()`, `user()` and `roles()`) is what `Helpers::can()`, `Helpers::user()` and `BlockRenderer` call. `App` binds it to `GuestAccess`.
- `BlockInstance::visibleFor($routeName, $roles)` already applies a `roles` rule. `BlockInstance::fromRow()` disables a block whose visibility JSON is malformed (fail-closed). `Manifest::blockDefaults()` validates `roles`.
- `Helpers::TWIG` already lists `csrf` (`is_safe`), `can` and `user`. `Helpers::csrf()` is a stub that returns `''`.
- `Manifest::isContent()`, the manifest `middleware` key, and the `tokens` module come from Plan B:
  - `xar_tokens` holds `blog_id`.
  - `Token::SCOPES` is `['blog:write', 'media:write']`.
  - `TokenMiddleware` answers `TokenError` 401/403.
  - `token:create`, `token:list` and `token:revoke` exist.
  - `tokens` requires `blog`.

**Facts this plan relies on (checked in the code):**

- `App::handle()` matches the route **before** the global middleware runs, so a 404 or 405 never reaches them and `ErrorHandler` renders it directly. `pushMiddleware()` appends to `$globalMiddleware`. Each global middleware class comes from the container: `get()` autowires any class and shares it, and `has()` is true for any existing class.
- `MiddlewareRegistry::resolve()` splits the spec at the first `:` and its arguments at `,`, and passes them to a constructor parameter named `$args`. So `auth:hello.greeting.admin` gives `['hello.greeting.admin']`, `throttle:password-forgot,5,900` gives `['password-forgot', '5', '900']`, and `token:hello:write` gives `['hello:write']`.
- `ModuleRegistry::enable()` validates every manifest key before it changes anything. It always runs the kernel migrations first, then the module's, and then calls each `onEnable` listener on every enable, with `$firstInstall` true only the first time.
- `ErrorHandler` answers in JSON when the path ends in `.json`, the path is `/api` or under `/api/`, or `Accept` contains `json` without `text/html`. That logic is a private `wantsJson()` today; Task 6 makes it `public static`.
- `RouteHandler::invoke()` renders a `Page` with the request it was given, so a template's `$x->request()` carries the request attributes that global middleware set.
- PSR-7 cookies: `ServerRequestCreator` fills `getCookieParams()` from `$_COOKIE`. Tests set cookies with `withCookieParams()`, and form bodies with `withParsedBody()`.
- `Schema::table()` refuses foreign keys, so Task 14 adds `tokens.user_id` by rebuilding the table. `key` is a reserved word in MySQL, so raw SQL on `xar_throttle` quotes it with `Connection::ident('key')`. Postgres stores `json` as `JSONB` (it reorders keys), so `sessions.data` is `TEXT` holding our own `json_encode()`.
- In tests, the app root is the repository root (`AppTestCase::boot()` sets `app.debug=true` and `app.url=http://xar.test`). So anything that writes files takes its path from config: `install.env_file`, `assets.path` and `mail.path`. Tests set `auth.password_options` to `['cost' => 4]`, because bcrypt at cost 10 or more makes the suite slow.
- The CLI never reads `var/cache/config.php` (commit 1af761bac). Web requests do, unless `app.debug` is true.
- Nyholm's `ServerRequest` gives `''` as the URI scheme for a path-only URI. `SessionMiddleware` treats anything but `https` as plain HTTP.

**Decisions and deviations from the spec:**

1. **Tokens (spec deviation, §4.1 and §6.1).** The kernel gets no `xar_tokens`, and §4.1's `xar_tokens` row is superseded: Plan B's `tokens` module stays the one bearer-token implementation.
   - A `tokens` migration adds a nullable `user_id` (FK to users, cascade on delete) by rebuilding the table.
   - `token:create --user=<handle>` sets it.
   - A token whose user is disabled, pending or deleted is answered as an invalid token (401).
   - Modules declare extra scopes in their manifest (`"scopes": ["hello:write"]`, always `<module>:<name>`). `TokenRepository::scopes()` is `Token::SCOPES` plus the scopes of the enabled modules.
   - `token:create` and `token:revoke` stay module commands and keep their Plan B names. `tokens` no longer requires `blog` (Task 14 decouples it; the blog pin is checked only while blog is enabled). The remaining loose ends are in `2026-10-21-phoenix-kernel-auth-followups.md`.
2. **Token identity.** On a `token:` route the token decides the request's identity, whatever session cookie came with it. A token with a user makes `Auth` that user, so `can()` uses that user's roles. A token without a user makes the request a guest (Anonymous); it acts only through its scopes.
3. **Sessions (spec addition).** Sessions live in a kernel table, `xar_sessions`, which §4.1 does not list:
   - `id` is the sha256 of the cookie value, so the cookie value itself is never stored.
   - The other columns are `user_id` (nullable, FK cascade), `data` (TEXT holding JSON), `last_activity` and `expires_at`.
   - The cookie is `xar_session` (`session.cookie`): 32 random bytes in base64url, sent with `Path=/; HttpOnly; SameSite=Lax`, and with `Secure` unless `app.debug` is true and the scheme is not `https`. It carries no `Max-Age`.
   - A session expires after `session.lifetime` idle minutes (default 120). An unchanged session's `last_activity` is written at most once a minute.
   - Expired rows are deleted on about 1 in 100 session writes (`session.gc_probability`). There is no GC command.
   - A session with no user and no data is never stored, so a guest who views a page with no form gets no cookie.
   - The id is regenerated at login and logout, and the old row is deleted.
   - A response that used a session gets `Cache-Control: private, no-cache` (unless it says `no-store`), so a shared cache never serves a page that holds a user's name or CSRF token.
   - No `session_*()` functions are used.
4. **CSRF.** The token is a synchronizer token kept in the session (`_csrf`) and created on first use by `$x->csrf()`. It is checked on every method except GET, HEAD and OPTIONS, from the form field `_csrf` or the `X-CSRF-Token` header, with `hash_equals`. Routes marked `csrf:off` are skipped. The token rotates at login and logout. **A failure is a 403**, not Laravel's 419: 419 is not a registered HTTP status, clients and proxies understand 403, and Django and Rails use it. The message is "The form has expired or was not sent from this site. Reload the page and try again." Login itself is CSRF-protected: `GET /login` starts an anonymous session that holds the token.
5. **Checks without an item.** `Auth::can()` with `$item === null` lets every instance match, so the route-level `auth:<module>.<component>.<level>` asks "does the user have this level on some instance" (§3.3: "the instance check happens in the controller"). With an item:
   - `*` matches anything.
   - `own` matches when the item's `owner_id` (a property or an array key) equals the user's id.
   - Any other value matches the item's `id` (or a scalar item) compared as strings.
6. **Roles and grants.**
   - The five roles are seeded with no parents.
   - Administrators `* · * · *` admin and Anonymous `* · * · *` read are seeded by the migration.
   - A module's manifest `"grants"` (role, component, optional instance, level; the module is the manifest's own name) and, for `"content": true` modules, Editors `<module> · * · *` delete, are seeded on **every** `module:enable`. An existing grant keeps its level, so re-enabling restores a deleted default grant.
   - A manifest may name a role that does not exist yet; `Grants::ensureRole()` creates it.
   - Levels are stored as their integers.
   - Parent cycles are cut at the first role seen twice.
7. **The blog's Authors grants** (`blog · post · own` delete and `blog · post · *` read) are seeded by `modules/blog/module.json`. Posts have no `owner_id` today, so the `own` grant matches nothing until a later blog-admin plan adds ownership. This plan builds no blog admin UI.
8. **Throttle (§4.4 and §3.3).**
   - `xar_throttle(key, hits, reset_at)` stores the **sha256** of each key, because keys hold IP addresses. Windows are fixed and open at the first hit.
   - Login: 5 failures in 15 minutes per client IP and account. The account is the **user id** when the login names a user, so a handle, its email and any letter case share one counter; for an unknown login it is the lower-cased login. Failures are counted; a success clears the counter. A locked attempt is a 429 page with `Retry-After`, and the password is not checked.
   - `throttle:` counts every request per IP. `/password/forgot` POST uses `throttle:password-forgot,5,900`.
   - The IP is `REMOTE_ADDR`. `X-Forwarded-For` is read only when `REMOTE_ADDR` is listed in `http.trusted_proxies` (env `TRUSTED_PROXIES`, exact addresses, no CIDR); the right-most hop that is not a trusted proxy wins.
9. **Login responses.**
   - A failure is a 422 page with one message for every case: "The handle, email or password is wrong." That covers an unknown login, a wrong password, and a pending or disabled account.
   - An unknown login still costs one bcrypt verification (`UserRepository::verifyNothing()`), so its timing matches a wrong password.
   - A right password with an outdated hash is rehashed.
   - Success is a 303 to `next`. `next` is kept only when it is a local path (`/…`, not `//…`, with no backslash or control character, at most 2000 bytes); otherwise it is `/`.
   - Logout is a CSRF-checked `POST /logout`, then a 303 to `/`.
10. **Password reset (spec wording).** §4.4's "signed" link is a 256-bit random token in the path (`/password/reset/<43 base64url chars>`) whose sha256 is stored in a new table, `xar_password_resets`. There is no app key or HMAC: an unguessable random token with a stored hash is single-use and revocable, and it needs no secret configuration.
    - The link lasts 60 minutes, and asking again replaces it.
    - Using it sets the password, deletes all of the user's links and **ends all of the user's sessions**.
    - The forgot page answers the same way whether the address has no account, an inactive account or an active one; only an active account gets mail. A mail failure is logged, not shown.
    - Reset links must be absolute: `PasswordResets::send()` throws when `app.url` is empty. This partly answers Plan B's "Require `app.url`" follow-up.
11. **Mailer (§4.4 deviation).**
    - `Mailer::send(Message)`. `Message` refuses a recipient that is not a valid address and a subject with CR or LF.
    - Drivers come from `mail.driver`: `log` (the default) writes `var/mail/<UTC>-<random>.eml` with mode 0600; `mail` uses PHP's `mail()`, with `mb_encode_mimeheader` on the subject and CRLF line ends; `memory` is for tests.
    - `mail.from` is the sender.
    - **No SMTP client.** A socket client that is small enough and testable against a fake server would still add about 150 kernel lines to a budget that is already over. `mail()` covers sendmail and Postfix relays. SMTP is recorded as a follow-up.
12. **Install (§4.5).** `xar install [--admin=<handle>] [--email=<email>] [--name=<name>] [--password=<password>] [--force]`:
    - It checks PHP ≥ 8.3 and the `pdo`, `mbstring` and `json` extensions.
    - It copies `.env.example` to `.env` when `.env` is missing (the path comes from `install.env_file`, so tests can redirect it).
    - It checks the connection (PDO creates the SQLite file).
    - It runs every pending migration (kernel and enabled modules) and seeds the grants of the enabled modules.
    - It asks for any administrator detail its flags leave out; the password is typed twice and hidden.
    - "Enables the default theme" means it checks that the active theme exists and publishes its assets with `AssetPublisher`; the theme itself is chosen by config (`APP_THEME`).
    - **A database that has any user is refused** unless `--force`. With `--force`, an existing administrator handle is left as it is. A database with modules but no users (an install from before this plan, such as the live blog) is not refused, so `xar install` is also the upgrade path.
13. **Identity reset.** `App::handle()` resets `Auth` to a guest at the start of each request when it was already built (`Container::resolved()`, new). So a 404 or 405, which skips the global middleware, renders as a guest and never as the previous request's user.
14. **Kernel routes.** `AuthRoutes` registers `auth.login` (GET `/login`), `auth.login.submit` (POST `/login`), `auth.logout` (POST `/logout`), `auth.forgot` and `auth.forgot.submit` (`/password/forgot`), and `auth.reset` and `auth.reset.submit` (`/password/reset/{token:[A-Za-z0-9_-]{43}}`), all with `secureHtml`. They are always registered, after the modules' routes, so a module cannot claim these paths.
15. **Size budget (§7 item 5 deviation).** This plan adds about 2,050 lines to `src/Kernel` (to about 9,050 against the target of about 6,000). Everything added is in §4 or §6, which the spec places in the kernel. Only `Mail/` (115 lines) is new territory, and §4.4 asks for it.
16. **Smaller choices.**
    - Emails are stored lower-cased, so their uniqueness ignores case.
    - A password is at least 8 characters.
    - `Access::user()` and `Helpers::user()` return `?User`, which never carries the password hash.
    - `user:password` also deletes the user's reset links and sessions.
    - Session, throttle and reset tables are guarded by `hasTable()`, so a site that is not installed never fails on a stale cookie.

## Global Constraints

- **Never touch the dev database.** No task, test or manual step may read, replace, migrate or delete `var/database.sqlite`, which holds the user's live synced blog.
  - Tests use their own database (`XAR_TEST_DSN`, else SQLite in the test's temp dir).
  - The Task 16 smoke run happens in a `/tmp` clone of the repository, with `DB_DSN=sqlite:/tmp/phoenix-auth-smoke.sqlite` and `APP_DEBUG=true` exported.
  - **A stale `var/cache/config.php` shadows `DB_DSN` for web requests** (the CLI ignores it). Never point `php -S` at a checkout whose `var/cache/config.php` exists while overriding `DB_DSN`; the fresh clone has none, and `APP_DEBUG=true` stops one being written.
- **PHP ≥ 8.3; 5 or fewer runtime Composer packages; no new package.** `composer.json` `require` does not change.
- **Spec values, verbatim (§4):**
  - Handle: `[a-z0-9_-]{2,32}`. Status: `active`/`pending`/`disabled`.
  - Levels: none 0, overview 100, read 200, comment 300, moderate 400, edit 500, add 600, delete 700, admin 800.
  - Seeded roles: Administrators, Editors, Authors, Members, Anonymous. Every request has Anonymous; signed-in users also have Members.
  - Default grants: Administrators `* · * · *` admin; Editors `* · * · *` on `"content": true` modules, delete; Authors `blog · post · own` delete and `blog · post · *` read; Anonymous `* · * · *` read.
  - Login: handle or email plus password; `password_hash` (default algorithm), rehashed when needed; the session id regenerated at login; cookies HttpOnly, Secure (unless in debug mode on HTTP) and SameSite=Lax; login throttling: 5 failures per 15 minutes, per IP plus account.
  - Bearer tokens: `Authorization: Bearer xar_<random>`; only the hash stored; `last_used` at most once per minute (unchanged from Plan B).
  - Password reset: a single-use link that expires in 60 minutes, sent through `Mailer`; CLI fallback `xar user:password <handle>`.
- **Templates:** every page template ships as `.php` and `.twig` in `themes/phoenix/templates/` (or the module's `templates/`), and a test compares both engines' HTML after `Html::normalize`. In PHP templates every value goes through `$x->e()`. HTML routes use `secureHtml`. Forms put a `<label for>` on every control, set `autocomplete`, and mark an error with `role="alert"` (plus `aria-invalid` and `aria-describedby` on the field it concerns).
- **Code conventions:** PHP files start with `<?php`, a blank line, then `declare(strict_types=1);`. Raw SQL uses `{table}` placeholders. Times are UTC `DateTimeImmutable`. Tests extend `AuthTestCase` (new) or another `AppTestCase` subclass; test properties are never `readonly`, because `tearDown()` unsets them.
- **Commits:** one per task. Every commit message ends with a blank line and `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, used exactly, passed as a second `-m`. Do not push.
- **Checks before every commit:** `composer test && composer stan && composer cs`. Then run the task's tests on MySQL: `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit <task test paths>`. Postgres runs only in CI (PHP 8.3/8.4 × SQLite/MySQL/Postgres).

## Review Focus

1. **Session fixation:** a session id planted before login (a shared machine, a cookie set by a sibling subdomain) must die at login, and the user must get a new id. Tests: Task 3 `testLoginGivesANewIdAndTheOldOneStopsWorking`, Task 8 `testAUserLogsInAndOut`.
2. **Login CSRF:** a cross-site form that posts an attacker's credentials to `/login` would sign the victim into the attacker's account. A POST to `/login` without the token of its own session, or with a token from another session, must be a 403. The same goes for `/logout`. Test: Task 8 `testLoginAndLogoutNeedTheCsrfToken`.
3. **Throttle bypass by switching between handle, email and letter case:** five failures against `ada`, `ADA@example.com` and `Ada` from one IP must lock that IP out of the account, even with the right password, while another IP can still log in. Test: Task 8 `testFiveFailuresLockAnAccountForAnIpWhetherItIsNamedByHandleOrEmail`.
4. **A disabled or pending user who still holds a session or a token:** the next request must be a guest's, the session must be deleted and its cookie expired, and the user's token must get a 401 `invalid_token`. Tests: Task 4 `testADisabledOrPendingUserIsSignedOutOfTheirSession`, Task 14 `testADisabledOrDeletedUsersTokensStopWorking`.
5. **An open redirect through `?next=`:** `//evil.example/`, `/\evil.example`, `https://evil.example/`, `javascript:` and paths with control characters must all land on `/` after login, while `/blog/notes?page=2` is kept. Test: Task 8 `testNextOnlyLeadsToALocalPath`.

---

## File Map

```
src/Kernel/migrations/2026_10_21_000001_create_users.php      users, roles, user_roles, grants + seeds   [Task 1]
src/Kernel/Auth/User.php, UserRepository.php, Grants.php                                                 [Task 1]
src/Kernel/Module/Manifest.php, ModuleRegistry.php            grants() [1]; scopes() [14]
src/Kernel/App.php                                            wiring in Tasks 1-9 and 13
modules/blog/module.json                                      Authors grants                             [Task 1]
src/Kernel/Auth/Auth.php, Access.php (GuestAccess.php deleted)                                            [Task 2; Access::user(): ?User in 13]
src/Kernel/migrations/2026_10_21_000002_create_sessions.php, Auth/Session.php, Auth/SessionStore.php      [Task 3]
src/Kernel/Http/Middleware/SessionMiddleware.php, config/app.php (session.*)                              [Task 3]
src/Kernel/Http/Middleware/Authenticate.php, Container/Container.php (resolved())                        [Task 4]
src/Kernel/Http/Middleware/VerifyCsrf.php, View/Helpers.php (csrf())                                      [Task 5]
src/Kernel/Http/Middleware/RequireAuth.php, ErrorHandler.php (public static wantsJson())                 [Task 6]
src/Kernel/migrations/2026_10_21_000003_create_throttle.php, Auth/Throttle.php, Http/ClientIp.php         [Task 7]
src/Kernel/Http/Middleware/ThrottleRequests.php, config/app.php (http.trusted_proxies)                    [Task 7]
src/Kernel/Auth/Http/AuthRoutes.php, LoginController.php, themes/phoenix/templates/auth/login.{php,twig}  [Task 8]
themes/phoenix/assets/phoenix.css                                                                        [Tasks 8, 10]
src/Kernel/Mail/{Message,Mailer,LogMailer,MailFunctionMailer,MemoryMailer}.php, config/app.php (mail.*)   [Task 9]
src/Kernel/migrations/2026_10_21_000004_create_password_resets.php, Auth/PasswordResets.php              [Task 10]
src/Kernel/Auth/Http/PasswordController.php, themes/phoenix/templates/auth/{forgot,reset}.{php,twig}      [Task 10]
src/Kernel/Cli/Output.php (ask()), Cli/Commands/{AsksForPassword,UserCreateCommand,UserPasswordCommand}.php [Task 11]
src/Kernel/Cli/Commands/InstallCommand.php, Cli/Application.php                                           [Tasks 11, 12]
src/Kernel/Blocks/LoginBlock.php                                                                          [Task 13]
modules/tokens/migrations/2026_10_21_200001_add_user_to_tokens.php, modules/tokens/src/*                  [Task 14]
examples/hello/module.json, src/Routes.php, src/HelloController.php, templates/admin.{php,twig}           [Task 15]
README.md, .env.example, docs/superpowers/plans/2026-10-11-phoenix-blog-plan-b-followups.md               [Task 16]
tests/Support/AuthTestCase.php [1, 4, 11], Browser.php [3, 8], AppTestCase.php (withCsrf()) [5]
tests/fixtures/auth/probe/ (module "probe": routes exposing session, identity, CSRF, auth, throttle)      [Tasks 3-7]
tests/Kernel/Auth/{UserRepository,Grants}Test.php [1], AuthTest [2], SessionTest [3], AuthenticateTest [4],
  VerifyCsrfTest [5], RequireAuthTest [6], ThrottleTest [7], LoginTest [8], PasswordResetTest [10],
  UserCommandsTest [11], InstallCommandTest [12], BlocksAndHelpersTest [13]
tests/Kernel/Mail/MailerTest.php [9], tests/Module/Tokens/TokenUsersTest.php [14], tests/Kernel/KernelAuthDoneTest.php [15]
tests/Kernel/View/{ViewTest [2], PhoenixThemeTest [8, 10]}.php, tests/Kernel/HelloModuleTest.php [5]
```

### Task 1: Users, roles and grants: schema, repositories and seeding

**Files:**
- Create: `src/Kernel/migrations/2026_10_21_000001_create_users.php`, `src/Kernel/Auth/User.php`, `src/Kernel/Auth/UserRepository.php`, `src/Kernel/Auth/Grants.php`
- Modify: `src/Kernel/Module/Manifest.php`, `src/Kernel/Module/ModuleRegistry.php`, `src/Kernel/App.php`, `modules/blog/module.json`
- Test: `tests/Support/AuthTestCase.php` (new), `tests/Kernel/Auth/UserRepositoryTest.php`, `tests/Kernel/Auth/GrantsTest.php`

**Interfaces:**
- **Consumes:** `Access::LEVELS`; `Connection::{insert, update, select, fetchValue, fetchAll, transaction, hasTable}`; `Ulid::generate()`; `ModuleRegistry::onEnable()`; `Manifest::isContent()`; `Fixtures::module()`; `AppTestCase::{boot, migrateKernel}`.
- **Produces:**
  - Tables:
    - `xar_users`: `id` ulid pk, `handle` string(32) unique, `name` string(255), `email` string(254) unique, `password_hash` string(255), `status` string(16) default `active`, `created` datetime, `last_login` datetime nullable.
    - `xar_roles`: `id` increments, `name` string(64) unique, `parent_id` int nullable, FK roles, `set null`.
    - `xar_user_roles`: `user_id` and `role_id`, pk (user_id, role_id), both FKs cascade.
    - `xar_grants`: `id` increments, `role_id` FK cascade, `module`, `component` and `instance` string(64), `level` int, unique (role_id, module, component, instance).
  - `Xaraya\Kernel\Auth\User` (readonly): `id`, `handle`, `name`, `email`, `status`, `DateTimeImmutable created`, `?DateTimeImmutable lastLogin`. It has `STATUSES`, `fromRow(array): self`, `isActive(): bool` and `displayName(): string`.
  - `Xaraya\Kernel\Auth\UserRepository(Connection $db, array $hashOptions = [])`, bound in `App` with `auth.password_options`. Constants `HANDLE` and `MIN_PASSWORD = 8`. Methods:
    - `create(string $handle, string $email, string $password, string $name = '', string $status = 'active', list<string> $roles = []): User`. It throws `InvalidArgumentException` with a message that names the problem.
    - Lookups: `find(string $id): ?User`, `findByHandle(string): ?User`, and `findByLogin(string $login): ?User`, which reads an email when the login contains `@` and a handle otherwise, ignoring case.
    - Passwords: `verifyPassword(User, string): bool` (it rehashes an outdated hash), `verifyNothing(string): void`, `setPassword(User, string): void` and `static checkPassword(string): void`.
    - Others: `setStatus(User, string): void`, `recordLogin(User, DateTimeImmutable): void`, `rolesOf(User): list<string>`, `addRole(User, string): void` and `count(): int` (0 when the table is missing).
  - `Xaraya\Kernel\Auth\Grants(Connection $db)`:
    - `ensureRole(string $name, ?string $parent = null): int` and `setParent(string $role, ?string $parent): void`.
    - `grant(string $role, string $module, string $component, string $instance, string $level): void` creates a missing role and keeps an existing grant's level.
    - `seed(Manifest): void` seeds the manifest's grants, plus Editors · module · * · * delete when the module is `content`.
    - `forRoles(list<string>): list<array{module, component, instance, level: int}>` follows the role ancestors and stops at a cycle.
    - `static isValidPart(string): bool`.
  - `Manifest::grants(): list<array{role, component, instance, level}>` (instance defaults to `*`). It throws `ModuleException("{name}: grants[{i}] needs …")`, and `ModuleRegistry::enable()` validates it first. `App`'s `onEnable` listener calls `Grants::seed()` on every enable.
  - Test base `Xaraya\Tests\Support\AuthTestCase extends AppTestCase`:
    - `PASSWORD = 'correct horse battery'`.
    - `app(array $overrides = [], array $modulePaths = []): App` uses the phoenix theme and bcrypt cost 4; `installed(...)` also runs the kernel migrations.
    - `users(App)`, `db(App)`, and `user(App, string $handle, list<string> $roles = [], string $status = 'active', string $password = self::PASSWORD): User` (the email is `<handle>@example.com` and the name is `ucfirst(handle)`).

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/GrantsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Fixtures;

final class GrantsTest extends AuthTestCase
{
    private function grants(App $app): Grants
    {
        return $app->container()->get(Grants::class);
    }

    /** @return list<string> "Role module.component.instance level" */
    private function rows(App $app, string $module): array
    {
        $rows = $this->db($app)->fetchAll(
            'SELECT r.name, g.component, g.instance, g.level FROM {grants} g JOIN {roles} r ON r.id = g.role_id WHERE g.module = ? ORDER BY r.name, g.component, g.instance',
            [$module],
        );

        return array_map(static fn(array $r): string => "{$r['name']} {$module}.{$r['component']}.{$r['instance']} {$r['level']}", $rows);
    }

    public function testEnableSeedsManifestGrantsAndEditorsOnContentModules(): void
    {
        Fixtures::module($this->tmp . '/modules', 'notes', [
            'content' => true,
            'grants' => [
                ['role' => 'Members', 'component' => 'note', 'level' => 'comment'],
                ['role' => 'Moderators', 'component' => 'note', 'instance' => '*', 'level' => 'moderate'],
            ],
        ]);
        Fixtures::module($this->tmp . '/modules', 'plain', ['grants' => [['role' => 'Authors', 'component' => 'x', 'instance' => 'own', 'level' => 'edit']]]);
        $app = $this->app([], [$this->tmp . '/modules']);
        $modules = $app->container()->get(ModuleRegistry::class);
        $modules->enable('notes');
        $modules->enable('plain');
        self::assertSame(['Editors notes.*.* 700', 'Members notes.note.* 300', 'Moderators notes.note.* 400'], $this->rows($app, 'notes'));
        self::assertSame(['Authors plain.x.own 500'], $this->rows($app, 'plain'), 'no Editors grant without "content": true');

        // Re-enabling keeps changed levels and restores deleted grants, without duplicates.
        $this->db($app)->query("UPDATE {grants} SET level = 100 WHERE module = 'notes' AND component = 'note' AND level = 300");
        $this->db($app)->query("DELETE FROM {grants} WHERE module = 'notes' AND level = 700");
        $this->app([], [$this->tmp . '/modules'])->container()->get(ModuleRegistry::class)->enable('notes');
        self::assertSame(['Editors notes.*.* 700', 'Members notes.note.* 100', 'Moderators notes.note.* 400'], $this->rows($app, 'notes'));
    }

    public function testTheBlogSeedsItsAuthorsGrants(): void
    {
        $modules = dirname(__DIR__, 3) . '/modules';
        $app = $this->app(['blog.locks' => $this->tmp . '/locks'], [$modules]);
        $app->container()->get(ModuleRegistry::class)->enable('blog');
        self::assertSame(['Authors blog.post.* 200', 'Authors blog.post.own 700', 'Editors blog.*.* 700'], $this->rows($app, 'blog'));
    }

    public function testInvalidManifestGrantsStopTheEnable(): void
    {
        Fixtures::module($this->tmp . '/modules', 'bad', ['grants' => [['role' => 'Members', 'component' => 'x', 'level' => 'superuser']]]);
        $app = $this->app([], [$this->tmp . '/modules']);
        try {
            $app->container()->get(ModuleRegistry::class)->enable('bad');
            self::fail('an unknown level must be refused');
        } catch (ModuleException $e) {
            self::assertStringContainsString('bad: grants[0] needs', $e->getMessage());
        }
        self::assertFalse($app->container()->get(ModuleRegistry::class)->isEnabled('bad'));
    }

    public function testForRolesFollowsParentsAndStopsAtACycle(): void
    {
        $app = $this->installed();
        $grants = $this->grants($app);
        $grants->ensureRole('Interns', 'Members');
        $grants->grant('Members', 'wiki', 'page', '*', 'comment');
        $grants->grant('Interns', 'wiki', 'page', 'drafts', 'edit');
        $levels = static fn(array $rows): array => array_map(static fn(array $g): string => "{$g['module']}.{$g['component']}.{$g['instance']}:{$g['level']}", $rows);
        self::assertEqualsCanonicalizing(['wiki.page.drafts:500', 'wiki.page.*:300'], $levels($grants->forRoles(['Interns'])));

        $grants->setParent('Members', 'Interns');
        $fresh = $this->grants($this->app());
        self::assertEqualsCanonicalizing(['wiki.page.drafts:500', 'wiki.page.*:300'], $levels($fresh->forRoles(['Interns'])), 'a parent cycle ends');
        self::assertSame([], $fresh->forRoles(['Nobody']));
    }
}
```

`tests/Kernel/Auth/UserRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use InvalidArgumentException;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Tests\Support\AuthTestCase;

final class UserRepositoryTest extends AuthTestCase
{
    public function testTheMigrationSeedsTheRolesAndTheirRoleWideGrants(): void
    {
        $db = $this->db($this->installed());
        $roles = array_column($db->select('roles')->columns('name')->orderBy('id')->all(), 'name');
        self::assertSame(['Administrators', 'Editors', 'Authors', 'Members', 'Anonymous'], $roles);
        $grants = $db->fetchAll('SELECT r.name, g.module, g.component, g.instance, g.level FROM {grants} g JOIN {roles} r ON r.id = g.role_id ORDER BY g.level DESC');
        self::assertEquals([
            ['name' => 'Administrators', 'module' => '*', 'component' => '*', 'instance' => '*', 'level' => 800],
            ['name' => 'Anonymous', 'module' => '*', 'component' => '*', 'instance' => '*', 'level' => 200],
        ], $grants);
    }

    public function testCreatesAUserWithItsRolesAndALowerCaseEmail(): void
    {
        $app = $this->installed();
        $user = $this->users($app)->create('ada', ' Ada@Example.COM ', self::PASSWORD, 'Ada Lovelace', 'active', ['Editors', 'Authors']);
        self::assertSame('ada', $user->handle);
        self::assertSame('ada@example.com', $user->email);
        self::assertSame('Ada Lovelace', $user->displayName());
        self::assertTrue($user->isActive());
        self::assertNull($user->lastLogin);
        self::assertSame(['Authors', 'Editors'], $this->users($app)->rolesOf($user));

        $hash = (string) $this->db($app)->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$user->id]);
        self::assertTrue(password_verify(self::PASSWORD, $hash));
        self::assertStringNotContainsString(self::PASSWORD, (string) json_encode($user));
        self::assertSame(1, $this->users($app)->count());
    }

    public function testRejectsBadOrTakenHandlesEmailsPasswordsAndRoles(): void
    {
        $app = $this->installed();
        $this->user($app, 'ada');
        foreach ([
            ['A', 'x@example.com', self::PASSWORD, [], 'A handle is'],
            ['Ada', 'x@example.com', self::PASSWORD, [], 'A handle is'],
            ['ada', 'x@example.com', self::PASSWORD, [], "The handle 'ada' is taken"],
            ['bob', 'not-an-email', self::PASSWORD, [], 'The email address is not valid'],
            ['bob', 'ADA@example.com', self::PASSWORD, [], 'The email address is taken'],
            ['bob', 'bob@example.com', 'short', [], 'A password is at least 8 characters'],
            ['bob', 'bob@example.com', self::PASSWORD, ['Wizards'], "Unknown role 'Wizards'"],
        ] as [$handle, $email, $password, $roles, $message]) {
            try {
                $this->users($app)->create($handle, $email, $password, '', 'active', $roles);
                self::fail("accepted {$handle} {$email}");
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        self::assertSame(1, $this->users($app)->count());
    }

    public function testFindsByHandleOrEmailIgnoringCase(): void
    {
        $app = $this->installed();
        $ada = $this->user($app, 'ada');
        $users = $this->users($app);
        self::assertSame($ada->id, $users->findByLogin('ada')?->id);
        self::assertSame($ada->id, $users->findByLogin(' ADA ')?->id);
        self::assertSame($ada->id, $users->findByLogin('Ada@Example.com')?->id);
        self::assertNull($users->findByLogin('nobody'));
        self::assertNull($users->findByLogin(''));
        self::assertSame($ada->id, $users->findByHandle('ada')?->id);
        self::assertSame($ada->id, $users->find($ada->id)?->id);
    }

    public function testAnOutdatedHashIsReplacedWhenThePasswordIsRight(): void
    {
        $app = $this->installed();
        $ada = $this->user($app, 'ada');
        $hash = fn(): string => (string) $this->db($app)->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$ada->id]);
        $old = $hash();
        self::assertStringContainsString('$04$', $old);

        $stronger = new UserRepository($this->db($app), ['cost' => 5]);
        self::assertFalse($stronger->verifyPassword($ada, 'wrong password'));
        self::assertSame($old, $hash(), 'a wrong password never rehashes');
        self::assertTrue($stronger->verifyPassword($ada, self::PASSWORD));
        self::assertStringContainsString('$05$', $hash());
        self::assertTrue($stronger->verifyPassword($ada, self::PASSWORD));
    }

    public function testStatusPasswordAndLastLoginUpdates(): void
    {
        $app = $this->installed();
        $users = $this->users($app);
        $ada = $this->user($app, 'ada');
        $users->setStatus($ada, 'disabled');
        self::assertFalse($users->find($ada->id)?->isActive());
        $users->setPassword($ada, 'a new password');
        self::assertTrue($users->verifyPassword($ada, 'a new password'));
        $now = new \DateTimeImmutable('2026-10-21 12:00:00', new \DateTimeZone('UTC'));
        $users->recordLogin($ada, $now);
        self::assertEquals($now, $users->find($ada->id)?->lastLogin);
        $this->expectException(InvalidArgumentException::class);
        $users->setStatus($ada, 'banned');
    }
}
```

`tests/Support/AuthTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Db\Connection;

/** Boots the app with the phoenix theme and cheap password hashes; installed() also runs the kernel migrations. */
abstract class AuthTestCase extends AppTestCase
{
    public const PASSWORD = 'correct horse battery';

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $modulePaths
     */
    protected function app(array $overrides = [], array $modulePaths = []): App
    {
        return $this->boot($modulePaths, [
            'themes.paths' => ['themes'],
            'app.theme' => 'phoenix',
            'app.locale' => 'en',
            'auth.password_options' => ['cost' => 4],
            ...$overrides,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $modulePaths
     */
    protected function installed(array $overrides = [], array $modulePaths = []): App
    {
        $app = $this->app($overrides, $modulePaths);
        $this->migrateKernel($app);

        return $app;
    }

    protected function users(App $app): UserRepository
    {
        return $app->container()->get(UserRepository::class);
    }

    protected function db(App $app): Connection
    {
        return $app->container()->get(Connection::class);
    }

    /** @param list<string> $roles */
    protected function user(App $app, string $handle, array $roles = [], string $status = 'active', string $password = self::PASSWORD): User
    {
        return $this->users($app)->create($handle, $handle . '@example.com', $password, ucfirst($handle), $status, $roles);
    }
}
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth`
Expected: FAIL (10 tests, 9 errors, 1 failure). The errors are `Cannot resolve 'Xaraya\Kernel\Auth\UserRepository': no binding and no such class` and `Cannot resolve 'Xaraya\Kernel\Auth\Grants'`. The failure is `testInvalidManifestGrantsStopTheEnable`: the bad manifest still enables.

- [ ] **Step 3: Implement**

The migration seeds the roles and the two role-wide grants with plain inserts, so it does not depend on the PHP classes. `Grants::seed()` runs inside one transaction. `verifyNothing()` keeps one dummy hash per options set (a `static` array), so an unknown login costs a single `password_verify()`.

In `modules/blog/module.json`, replace:

```json
  "routes": "Xaraya\\Module\\Blog\\Routes",
  "migrations": "migrations",
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" },
```

with:

```json
  "routes": "Xaraya\\Module\\Blog\\Routes",
  "migrations": "migrations",
  "grants": [
    { "role": "Authors", "component": "post", "instance": "own", "level": "delete" },
    { "role": "Authors", "component": "post", "instance": "*", "level": "read" }
  ],
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Blog\\Search\\SearchIndexer" },
```

In `src/Kernel/App.php` (1 of 3), replace:

```php
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\GuestAccess;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Blocks\BlockRepository;
```

with:

```php
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\GuestAccess;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Blocks\BlockRepository;
```

In `src/Kernel/App.php` (2 of 3), replace:

```php
            $registry->onEnable(static function (Manifest $manifest, bool $firstInstall) use ($c): void {
                $c->get(HookBindings::class)->seed($manifest);
                if ($firstInstall) {
                    $c->get(BlockRepository::class)->seed($manifest);
```

with:

```php
            $registry->onEnable(static function (Manifest $manifest, bool $firstInstall) use ($c): void {
                $c->get(HookBindings::class)->seed($manifest);
                $c->get(Grants::class)->seed($manifest);
                if ($firstInstall) {
                    $c->get(BlockRepository::class)->seed($manifest);
```

In `src/Kernel/App.php` (3 of 3), replace:

```php
            return $registry;
        });
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
```

with:

```php
            return $registry;
        });
        $c->set(UserRepository::class, fn(Container $c): UserRepository => new UserRepository(
            $c->get(Connection::class),
            (array) $config->get('auth.password_options', []),
        ));
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
```

`src/Kernel/Auth/Grants.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use InvalidArgumentException;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\Manifest;

/** xar_roles and xar_grants: single-inheritance roles and the module · component · instance grants they hold. */
final class Grants
{
    /** @var array<string, array{id: int, parent: ?int}>|null role name => id and parent id */
    private ?array $roles = null;

    public function __construct(private readonly Connection $db) {}

    /** Creates the role when it is missing; an existing role keeps its parent. Returns its id. */
    public function ensureRole(string $name, ?string $parent = null): int
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException("Invalid role name '{$name}'");
        }
        $existing = $this->roles()[$name] ?? null;
        if ($existing !== null) {
            return $existing['id'];
        }
        $parentId = $parent === null ? null : ($this->roles()[$parent]['id'] ?? throw new InvalidArgumentException("Unknown role '{$parent}'"));
        $this->db->insert('roles', ['name' => $name, 'parent_id' => $parentId]);
        $this->roles = null;

        return $this->roles()[$name]['id'] ?? throw new InvalidArgumentException("Role '{$name}' was not created");
    }

    /** Sets (or clears) a role's parent. Cycles are allowed in the table; forRoles() stops at the first repeat. */
    public function setParent(string $role, ?string $parent): void
    {
        $id = $this->roles()[$role]['id'] ?? throw new InvalidArgumentException("Unknown role '{$role}'");
        $parentId = $parent === null ? null : ($this->roles()[$parent]['id'] ?? throw new InvalidArgumentException("Unknown role '{$parent}'"));
        $this->db->update('roles', ['parent_id' => $parentId], ['id' => $id]);
        $this->roles = null;
    }

    /** Adds a grant unless the role already has one for that module, component and instance (its level is kept). */
    public function grant(string $role, string $module, string $component, string $instance, string $level): void
    {
        $value = Access::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown access level '{$level}'");
        foreach (['module' => $module, 'component' => $component, 'instance' => $instance] as $part => $text) {
            if (!self::isValidPart($text)) {
                throw new InvalidArgumentException("Invalid grant {$part} '{$text}'");
            }
        }
        $roleId = $this->ensureRole($role);
        $exists = $this->db->select('grants')
            ->where('role_id', '=', $roleId)
            ->where('module', '=', $module)
            ->where('component', '=', $component)
            ->where('instance', '=', $instance)
            ->first();
        if ($exists === null) {
            $this->db->insert('grants', ['role_id' => $roleId, 'module' => $module, 'component' => $component, 'instance' => $instance, 'level' => $value]);
        }
    }

    /**
     * Seeds a module's default grants on enable: its manifest "grants", plus Editors · <module> · * · * delete when the
     * manifest sets "content": true. Existing grants are kept, so re-enabling restores only grants that were deleted.
     */
    public function seed(Manifest $manifest): void
    {
        $this->db->transaction(function () use ($manifest): void {
            foreach ($manifest->grants() as $grant) {
                $this->grant($grant['role'], $manifest->name, $grant['component'], $grant['instance'], $grant['level']);
            }
            if ($manifest->isContent()) {
                $this->grant('Editors', $manifest->name, '*', '*', 'delete');
            }
        });
    }

    /**
     * The grants of these roles and of all their ancestors. A parent cycle is cut at the first role seen twice.
     *
     * @param list<string> $roleNames
     * @return list<array{module: string, component: string, instance: string, level: int}>
     */
    public function forRoles(array $roleNames): array
    {
        if (!$this->db->hasTable('grants')) {
            return [];
        }
        $roles = $this->roles();
        $byId = [];
        foreach ($roles as $role) {
            $byId[$role['id']] = $role;
        }
        $ids = [];
        foreach ($roleNames as $name) {
            for ($id = $roles[$name]['id'] ?? null; $id !== null && !isset($ids[$id]); $id = $byId[$id]['parent'] ?? null) {
                $ids[$id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->select('grants')->columns('module', 'component', 'instance', 'level')->whereIn('role_id', array_keys($ids))->all();

        return array_map(static fn(array $row): array => [
            'module' => (string) $row['module'],
            'component' => (string) $row['component'],
            'instance' => (string) $row['instance'],
            'level' => (int) $row['level'],
        ], $rows);
    }

    /** A module, component or instance: "*", "own", an item id, or a lower-case name. */
    public static function isValidPart(string $value): bool
    {
        return preg_match('/^(?:\*|[A-Za-z0-9][A-Za-z0-9_.:-]{0,63})$/D', $value) === 1;
    }

    /** @return array<string, array{id: int, parent: ?int}> */
    private function roles(): array
    {
        if ($this->roles !== null) {
            return $this->roles;
        }
        $roles = [];
        foreach ($this->db->select('roles')->columns('id', 'name', 'parent_id')->all() as $row) {
            $roles[(string) $row['name']] = ['id' => (int) $row['id'], 'parent' => $row['parent_id'] === null ? null : (int) $row['parent_id']];
        }

        return $this->roles = $roles;
    }
}
```

`src/Kernel/Auth/User.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;

/** One row of xar_users, without its password hash (templates get this object through user()). */
final class User
{
    public const STATUSES = ['active', 'pending', 'disabled'];

    public function __construct(
        public readonly string $id,
        public readonly string $handle,
        public readonly string $name,
        public readonly string $email,
        public readonly string $status,
        public readonly DateTimeImmutable $created,
        public readonly ?DateTimeImmutable $lastLogin = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $utc = new DateTimeZone('UTC');

        return new self(
            (string) $row['id'],
            (string) $row['handle'],
            (string) $row['name'],
            (string) $row['email'],
            (string) $row['status'],
            new DateTimeImmutable((string) $row['created'], $utc),
            isset($row['last_login']) ? new DateTimeImmutable((string) $row['last_login'], $utc) : null,
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** The name to show: the display name, or the handle when it is empty. */
    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->handle;
    }
}
```

`src/Kernel/Auth/UserRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;

/** xar_users and xar_user_roles. Passwords are hashed with password_hash(PASSWORD_DEFAULT, auth.password_options). */
final class UserRepository
{
    public const HANDLE = '/^[a-z0-9_-]{2,32}$/D';
    public const MIN_PASSWORD = 8;

    /** @param array<string, int|string> $hashOptions password_hash() options, e.g. ['cost' => 12] */
    public function __construct(private readonly Connection $db, private readonly array $hashOptions = []) {}

    /**
     * @param list<string> $roles role names; every one must exist
     * @throws InvalidArgumentException for an invalid or taken handle or email, a short password or an unknown role
     */
    public function create(
        string $handle,
        string $email,
        #[SensitiveParameter]
        string $password,
        string $name = '',
        string $status = 'active',
        array $roles = [],
    ): User {
        $email = mb_strtolower(trim($email));
        $name = trim($name);
        if (preg_match(self::HANDLE, $handle) !== 1) {
            throw new InvalidArgumentException('A handle is 2 to 32 lower-case letters, digits, "_" or "-"');
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The email address is not valid');
        }
        if (mb_strlen($name) > 255) {
            throw new InvalidArgumentException('A name is at most 255 characters');
        }
        if (!in_array($status, User::STATUSES, true)) {
            throw new InvalidArgumentException('A status is one of: ' . implode(', ', User::STATUSES));
        }
        self::checkPassword($password);
        if ($this->findByHandle($handle) !== null) {
            throw new InvalidArgumentException("The handle '{$handle}' is taken");
        }
        if ($this->db->select('users')->where('email', '=', $email)->first() !== null) {
            throw new InvalidArgumentException('The email address is taken');
        }
        $roleIds = $this->roleIds($roles);
        $id = Ulid::generate();
        $this->db->transaction(function (Connection $db) use ($id, $handle, $name, $email, $password, $status, $roleIds): void {
            $db->insert('users', [
                'id' => $id,
                'handle' => $handle,
                'name' => $name,
                'email' => $email,
                'password_hash' => $this->hash($password),
                'status' => $status,
                'created' => new DateTimeImmutable(),
                'last_login' => null,
            ]);
            foreach ($roleIds as $roleId) {
                $db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
            }
        });

        return $this->find($id) ?? throw new RuntimeException('User insert failed');
    }

    public function find(string $id): ?User
    {
        $row = $this->db->select('users')->where('id', '=', $id)->first();

        return $row === null ? null : User::fromRow($row);
    }

    public function findByHandle(string $handle): ?User
    {
        $row = $this->db->select('users')->where('handle', '=', $handle)->first();

        return $row === null ? null : User::fromRow($row);
    }

    /** The user a login form names: an email address when it contains "@", else a handle. Case is ignored. */
    public function findByLogin(string $login): ?User
    {
        $login = mb_strtolower(trim($login));
        if ($login === '' || strlen($login) > 254) {
            return null;
        }
        $row = $this->db->select('users')->where(str_contains($login, '@') ? 'email' : 'handle', '=', $login)->first();

        return $row === null ? null : User::fromRow($row);
    }

    /** Checks the password; when it is right and the hash is outdated (algorithm or options), stores a new hash. */
    public function verifyPassword(User $user, #[SensitiveParameter] string $password): bool
    {
        $hash = (string) $this->db->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$user->id]);
        if (!password_verify($password, $hash)) {
            return false;
        }
        if (password_needs_rehash($hash, PASSWORD_DEFAULT, $this->hashOptions)) {
            $this->db->update('users', ['password_hash' => $this->hash($password)], ['id' => $user->id]);
        }

        return true;
    }

    /** Burns the time of one password check, so a login for an unknown user takes as long as a wrong password. */
    public function verifyNothing(#[SensitiveParameter] string $password): void
    {
        static $dummy = [];
        $key = json_encode($this->hashOptions);
        $dummy[$key] ??= password_hash('phoenix-timing-equaliser', PASSWORD_DEFAULT, $this->hashOptions);
        password_verify($password, $dummy[$key]);
    }

    public function setPassword(User $user, #[SensitiveParameter] string $password): void
    {
        self::checkPassword($password);
        $this->db->update('users', ['password_hash' => $this->hash($password)], ['id' => $user->id]);
    }

    public function setStatus(User $user, string $status): void
    {
        if (!in_array($status, User::STATUSES, true)) {
            throw new InvalidArgumentException('A status is one of: ' . implode(', ', User::STATUSES));
        }
        $this->db->update('users', ['status' => $status], ['id' => $user->id]);
    }

    public function recordLogin(User $user, DateTimeImmutable $now): void
    {
        $this->db->update('users', ['last_login' => $now], ['id' => $user->id]);
    }

    /** @return list<string> the role names granted to $user directly (not Anonymous or Members, which are implicit) */
    public function rolesOf(User $user): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.name FROM {user_roles} ur JOIN {roles} r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name',
            [$user->id],
        );

        return array_map(static fn(array $row): string => (string) $row['name'], $rows);
    }

    public function addRole(User $user, string $role): void
    {
        $roleId = $this->roleIds([$role])[0];
        if ($this->db->select('user_roles')->where('user_id', '=', $user->id)->where('role_id', '=', $roleId)->first() === null) {
            $this->db->insert('user_roles', ['user_id' => $user->id, 'role_id' => $roleId]);
        }
    }

    public function count(): int
    {
        return $this->db->hasTable('users') ? $this->db->select('users')->count() : 0;
    }

    public static function checkPassword(#[SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw new InvalidArgumentException('A password is at least ' . self::MIN_PASSWORD . ' characters');
        }
    }

    private function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, $this->hashOptions);
    }

    /**
     * @param list<string> $names
     * @return list<int>
     */
    private function roleIds(array $names): array
    {
        $ids = [];
        foreach (array_unique($names) as $name) {
            $id = $this->db->fetchValue('SELECT id FROM {roles} WHERE name = ?', [$name]);
            $ids[] = $id === null ? throw new InvalidArgumentException("Unknown role '{$name}'") : (int) $id;
        }

        return $ids;
    }
}
```

In `src/Kernel/Module/Manifest.php` (1 of 2), replace:

```php
use JsonException;
use Xaraya\Kernel\Blocks\BlockInstance;
use Xaraya\Kernel\Hooks\DisplayHook;
```

with:

```php
use JsonException;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Blocks\BlockInstance;
use Xaraya\Kernel\Hooks\DisplayHook;
```

In `src/Kernel/Module/Manifest.php` (2 of 2), replace:

```php
    }

    /** @return array<string, string> */
    public function requiredModules(): array
```

with:

```php
    }

    /**
     * Default grants seeded on enable, for this module: [{"role", "component", "instance" (default "*"), "level"}].
     *
     * @return list<array{role: string, component: string, instance: string, level: string}>
     */
    public function grants(): array
    {
        $out = [];
        foreach ((array) ($this->data['grants'] ?? []) as $i => $grant) {
            $role = is_array($grant) ? ($grant['role'] ?? null) : null;
            $component = is_array($grant) ? ($grant['component'] ?? null) : null;
            $instance = is_array($grant) ? ($grant['instance'] ?? '*') : null;
            $level = is_array($grant) ? ($grant['level'] ?? null) : null;
            if (
                !is_string($role) || preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,63}$/D', $role) !== 1
                || !is_string($component) || !Grants::isValidPart($component)
                || !is_string($instance) || !Grants::isValidPart($instance)
                || !is_string($level) || !isset(Access::LEVELS[$level])
            ) {
                throw new ModuleException("{$this->name}: grants[{$i}] needs a \"role\", a \"component\", an optional \"instance\" and a \"level\" (" . implode(', ', array_keys(Access::LEVELS)) . ')');
            }
            $out[] = ['role' => $role, 'component' => $component, 'instance' => $instance, 'level' => $level];
        }

        return $out;
    }

    /** @return array<string, string> */
    public function requiredModules(): array
```

In `src/Kernel/Module/ModuleRegistry.php`, replace:

```php
        $manifest->blockDefaults();
        $manifest->middleware();
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
```

with:

```php
        $manifest->blockDefaults();
        $manifest->middleware();
        $manifest->grants();
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
```

`src/Kernel/migrations/2026_10_21_000001_create_users.php`:

```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Users, roles and grants (kernel spec §4.1), with the seeded roles and the role-wide grants of §4.2. */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('handle', 32)->unique();
            $t->string('name', 255);
            $t->string('email', 254)->unique();
            $t->string('password_hash', 255);
            $t->string('status', 16)->default('active');
            $t->datetime('created');
            $t->datetime('last_login')->nullable();
        });
        $schema->create('roles', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('name', 64)->unique();
            $t->int('parent_id')->nullable();
            $t->foreign('parent_id', 'roles', 'id', 'set null');
        });
        $schema->create('user_roles', function (Blueprint $t): void {
            $t->ulid('user_id');
            $t->int('role_id');
            $t->primary('user_id', 'role_id');
            $t->foreign('user_id', 'users');
            $t->foreign('role_id', 'roles');
        });
        $schema->create('grants', function (Blueprint $t): void {
            $t->increments('id');
            $t->int('role_id');
            $t->string('module', 64);
            $t->string('component', 64);
            $t->string('instance', 64);
            $t->int('level');
            $t->unique('role_id', 'module', 'component', 'instance');
            $t->foreign('role_id', 'roles');
        });

        $db = $schema->connection();
        foreach (['Administrators', 'Editors', 'Authors', 'Members', 'Anonymous'] as $name) {
            $db->insert('roles', ['name' => $name]);
        }
        foreach (['Administrators' => 800, 'Anonymous' => 200] as $role => $level) {
            $id = (int) $db->fetchValue('SELECT id FROM {roles} WHERE name = ?', [$role]);
            $db->insert('grants', ['role_id' => $id, 'module' => '*', 'component' => '*', 'instance' => '*', 'level' => $level]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['grants', 'user_roles', 'roles', 'users'] as $table) {
            $schema->drop($table);
        }
    }
};
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth`
Expected: PASS (10 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then `XAR_TEST_DSN='mysql:host=127.0.0.1;dbname=xaraya_test;charset=utf8mb4' XAR_TEST_DB_USER=root vendor/bin/phpunit tests/Kernel/Auth`
Expected: all green (706 tests); MySQL OK (10 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/migrations/2026_10_21_000001_create_users.php src/Kernel/Auth/User.php src/Kernel/Auth/UserRepository.php src/Kernel/Auth/Grants.php src/Kernel/Module/Manifest.php src/Kernel/Module/ModuleRegistry.php src/Kernel/App.php modules/blog/module.json tests/Support/AuthTestCase.php tests/Kernel/Auth/UserRepositoryTest.php tests/Kernel/Auth/GrantsTest.php
git commit -m "feat(auth): users, roles and grants tables, repositories and grant seeding" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 2: `Auth::can` with role inheritance and instance matching

**Files:**
- Create: `src/Kernel/Auth/Auth.php`
- Modify: `src/Kernel/App.php`, `src/Kernel/Auth/Access.php`
- Delete: `src/Kernel/Auth/GuestAccess.php`
- Test: `tests/Kernel/Auth/AuthTest.php` (new), `tests/Kernel/View/ViewTest.php` (its `GuestAccess` test moves into `AuthTest`)

**Interfaces:**
- **Consumes:** `UserRepository::rolesOf()` and `Grants::{forRoles, grant, ensureRole}` (Task 1); `Access::LEVELS`.
- **Produces:** `Xaraya\Kernel\Auth\Auth implements Access` (shared; `Access::class` resolves to it):
  - `actAs(?User $user): void`. An inactive user is treated as null. It resets the memo.
  - `user(): ?User`; `check(): bool`.
  - `roles(): list<string>`: `['Anonymous']` for a guest, else `['Anonymous', 'Members', …rolesOf]`.
  - `can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool`. It throws `InvalidArgumentException("Unknown access level '…'")`, and it memoises per identity.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/AuthTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use InvalidArgumentException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Tests\Support\AuthTestCase;

final class AuthTest extends AuthTestCase
{
    private function auth(App $app): Auth
    {
        return $app->container()->get(Auth::class);
    }

    public function testAccessIsBoundToAuth(): void
    {
        $app = $this->installed();
        self::assertSame($this->auth($app), $app->container()->get(Access::class));
    }

    public function testAGuestHasAnonymousAndMayRead(): void
    {
        $auth = $this->auth($this->installed());
        self::assertNull($auth->user());
        self::assertFalse($auth->check());
        self::assertSame(['Anonymous'], $auth->roles());
        self::assertTrue($auth->can('overview'));
        self::assertTrue($auth->can('read', 'blog', 'post'));
        self::assertFalse($auth->can('comment', 'blog', 'post'));
        $this->expectException(InvalidArgumentException::class);
        $auth->can('superuser');
    }

    public function testASignedInUserAlsoHasMembersAndTheirRoles(): void
    {
        $app = $this->installed();
        $grants = $app->container()->get(Grants::class);
        $grants->grant('Members', 'wiki', '*', '*', 'comment');
        $grants->grant('Editors', 'wiki', 'page', '*', 'delete');
        $auth = $this->auth($app);

        $auth->actAs($this->user($app, 'ed', ['Editors']));
        self::assertTrue($auth->check());
        self::assertSame(['Anonymous', 'Members', 'Editors'], $auth->roles());
        self::assertTrue($auth->can('delete', 'wiki', 'page'));
        self::assertTrue($auth->can('comment', 'wiki', 'talk'));
        self::assertFalse($auth->can('delete', 'wiki', 'talk'));
        self::assertFalse($auth->can('admin', 'wiki', 'page'));

        $auth->actAs($this->user($app, 'root', ['Administrators']));
        self::assertTrue($auth->can('admin', 'anything', 'at-all', ['id' => 'x']));

        $auth->actAs(null);
        self::assertFalse($auth->can('comment', 'wiki', 'talk'));
    }

    public function testAnInactiveUserIsAGuest(): void
    {
        $app = $this->installed();
        $auth = $this->auth($app);
        $auth->actAs($this->user($app, 'gone', ['Administrators'], 'disabled'));
        self::assertNull($auth->user());
        self::assertSame(['Anonymous'], $auth->roles());
        self::assertFalse($auth->can('admin', 'hello', 'greeting'));
    }

    public function testRolesInheritTheirAncestorsGrants(): void
    {
        $app = $this->installed();
        $grants = $app->container()->get(Grants::class);
        $grants->ensureRole('Seniors', 'Editors');
        $grants->ensureRole('Leads', 'Seniors');
        $grants->grant('Editors', 'wiki', 'page', '*', 'edit');
        $auth = $this->auth($app);
        $auth->actAs($this->user($app, 'lea', ['Leads']));
        self::assertTrue($auth->can('edit', 'wiki', 'page'));
        self::assertFalse($auth->can('add', 'wiki', 'page'));
    }

    public function testInstancesMatchByIdOrOwner(): void
    {
        $app = $this->installed();
        $grants = $app->container()->get(Grants::class);
        $grants->grant('Members', 'wiki', 'page', '42', 'edit');
        $grants->grant('Authors', 'blog', 'post', 'own', 'delete');
        $auth = $this->auth($app);
        $ada = $this->user($app, 'ada', ['Authors']);
        $auth->actAs($ada);

        self::assertTrue($auth->can('edit', 'wiki', 'page', ['id' => 42]));
        self::assertTrue($auth->can('edit', 'wiki', 'page', '42'));
        self::assertFalse($auth->can('edit', 'wiki', 'page', ['id' => 43]));
        self::assertTrue($auth->can('edit', 'wiki', 'page'), 'without an item, any instance counts');

        $mine = (object) ['id' => 'p1', 'owner_id' => $ada->id];
        self::assertTrue($auth->can('delete', 'blog', 'post', $mine));
        self::assertTrue($auth->can('delete', 'blog', 'post', ['id' => 'p1', 'owner_id' => $ada->id]));
        self::assertFalse($auth->can('delete', 'blog', 'post', ['id' => 'p2', 'owner_id' => 'someone-else']));
        self::assertFalse($auth->can('delete', 'blog', 'post', ['id' => 'p3']), 'an item without owner_id is nobody\'s own');
        self::assertTrue($auth->can('read', 'blog', 'post', ['id' => 'p3']), 'Anonymous read still applies');

        $auth->actAs(null);
        self::assertFalse($auth->can('delete', 'blog', 'post', ['id' => 'p1', 'owner_id' => $ada->id]));
    }

    public function testResultsAreMemoisedUntilTheIdentityChanges(): void
    {
        $app = $this->installed();
        $auth = $this->auth($app);
        self::assertFalse($auth->can('edit', 'wiki', 'page'));
        $app->container()->get(Grants::class)->grant('Anonymous', 'wiki', 'page', '*', 'edit');
        self::assertFalse($auth->can('edit', 'wiki', 'page'), 'memoised for the request');
        $auth->actAs(null);
        self::assertTrue($auth->can('edit', 'wiki', 'page'));
    }
}
```

In `tests/Kernel/View/ViewTest.php` (1 of 2), replace:

```php
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\GuestAccess;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\PhpEngine;
```

with:

```php
use Xaraya\Kernel\App;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\PhpEngine;
```

In `tests/Kernel/View/ViewTest.php` (2 of 2), replace:

```php
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
```

with:

```php
    }

    public function testEscapeAndAssetGuards(): void
    {
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/AuthTest.php tests/Kernel/View/ViewTest.php`
Expected: FAIL (7 errors: `Cannot resolve 'Xaraya\Kernel\Auth\Auth': no binding and no such class`).

- [ ] **Step 3: Implement**

`can()` builds a memo key from the level, module, component, whether an item was given, its id and its `owner_id`. It loads the grants once per identity (`Grants::forRoles(roles())`) and takes the highest matching level.

In `src/Kernel/App.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\GuestAccess;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Blocks\BlockRenderer;
```

with:

```php
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Blocks\BlockRenderer;
```

In `src/Kernel/App.php` (2 of 2), replace:

```php
        });

        $c->set(Access::class, fn(): Access => new GuestAccess());
        $c->set(TwigEngine::class, fn(Container $c): TwigEngine => new TwigEngine(
            $c->get(TemplateLocator::class),
```

with:

```php
        });

        $c->set(Access::class, fn(Container $c): Access => $c->get(Auth::class));
        $c->set(TwigEngine::class, fn(Container $c): TwigEngine => new TwigEngine(
            $c->get(TemplateLocator::class),
```

In `src/Kernel/Auth/Access.php`, replace:

```php
namespace Xaraya\Kernel\Auth;

/** Privilege checks for the current request (kernel spec §4.3). Plan 3 binds the real implementation. */
interface Access
{
```

with:

```php
namespace Xaraya\Kernel\Auth;

/** Privilege checks for the current request (kernel spec §4.3). The container binds it to Auth. */
interface Access
{
```

`src/Kernel/Auth/Auth.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use InvalidArgumentException;

/**
 * The current request's identity and privilege checks (kernel spec §4.3). The Auth middleware (or a bearer token)
 * calls actAs(); everyone has Anonymous, and a signed-in user also has Members and their own roles.
 */
final class Auth implements Access
{
    private ?User $user = null;

    /** @var list<string>|null */
    private ?array $roles = null;

    /** @var list<array{module: string, component: string, instance: string, level: int}>|null */
    private ?array $grants = null;

    /** @var array<string, bool> */
    private array $memo = [];

    public function __construct(private readonly UserRepository $users, private readonly Grants $grantStore) {}

    /** Makes $user (null: a guest) the identity of the current request. Only an active user is accepted. */
    public function actAs(?User $user): void
    {
        $this->user = $user !== null && $user->isActive() ? $user : null;
        $this->roles = null;
        $this->grants = null;
        $this->memo = [];
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function roles(): array
    {
        if ($this->roles === null) {
            $this->roles = $this->user === null
                ? ['Anonymous']
                : array_values(array_unique(['Anonymous', 'Members', ...$this->users->rolesOf($this->user)]));
        }

        return $this->roles;
    }

    /**
     * True when the highest level among the matching grants of the request's roles (and their ancestors) is at
     * least $level. Without an $item every instance matches: the route-level check of §3.3 asks whether the user
     * has the level on some instance, and the controller checks the item itself.
     */
    public function can(string $level, string $module = '*', string $component = '*', mixed $item = null): bool
    {
        $required = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown access level '{$level}'");
        $id = self::field($item, 'id');
        $owner = self::field($item, 'owner_id');
        $key = implode("\0", [$required, $module, $component, $item === null ? '' : 'i', $id ?? '', $owner ?? '']);
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $this->grants ??= $this->grantStore->forRoles($this->roles());
        $best = 0;
        foreach ($this->grants as $grant) {
            if (
                ($grant['module'] === '*' || $grant['module'] === $module)
                && ($grant['component'] === '*' || $grant['component'] === $component)
                && $this->instanceMatches($grant['instance'], $item, $id, $owner)
            ) {
                $best = max($best, $grant['level']);
            }
        }

        return $this->memo[$key] = $best >= $required;
    }

    private function instanceMatches(string $instance, mixed $item, ?string $id, ?string $owner): bool
    {
        return match (true) {
            $instance === '*', $item === null => true,
            $instance === 'own' => $this->user !== null && $owner === $this->user->id,
            default => $id !== null && $id === $instance,
        };
    }

    /** $item's id or owner_id: a scalar item is its own id; arrays and objects are read by key or property. */
    private static function field(mixed $item, string $name): ?string
    {
        $value = match (true) {
            is_array($item) => $item[$name] ?? null,
            is_object($item) => $item->{$name} ?? null,
            $name === 'id' => $item,
            default => null,
        };

        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
```

Delete `src/Kernel/Auth/GuestAccess.php` (`git rm src/Kernel/Auth/GuestAccess.php`).


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth tests/Kernel/View/ViewTest.php`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth`.
Expected: all green (712 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Auth/Auth.php src/Kernel/Auth/Access.php src/Kernel/App.php tests/Kernel/Auth/AuthTest.php tests/Kernel/View/ViewTest.php
git rm -q src/Kernel/Auth/GuestAccess.php
git commit -m "feat(auth): Auth::can with role inheritance and instance matching" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 3: Database sessions in a PSR-7 cookie

**Files:**
- Create: `src/Kernel/migrations/2026_10_21_000002_create_sessions.php`, `src/Kernel/Auth/Session.php`, `src/Kernel/Auth/SessionStore.php`, `src/Kernel/Http/Middleware/SessionMiddleware.php`
- Modify: `src/Kernel/App.php` (the first global middleware), `config/app.php` (`session.*`)
- Test: `tests/Support/Browser.php` (new), `tests/fixtures/auth/probe/module.json` and `src/Routes.php` (new fixture module), `tests/Kernel/Auth/SessionTest.php`

**Interfaces:**
- **Consumes:** `AuthTestCase::{installed, app, user, db}` (Task 1); `ModuleRegistry::enable()`.
- **Produces:**
  - Table `xar_sessions`: `id` string(64) pk (sha256 of the cookie), `user_id` ulid nullable FK users cascade, `data` text (JSON), `last_activity` datetime, `expires_at` datetime; indexes on `expires_at` and `user_id`.
  - `Xaraya\Kernel\Auth\Session` (the `Session::class` request attribute):
    - Constant `ID` (`/^[A-Za-z0-9_-]{43}$/D`). Static `fresh()`, `loaded(id, ?userId, array data, DateTimeImmutable lastActivity)`, `newId(): string` and `hash(string): string`.
    - State: `id(): ?string`, `userId(): ?string`, `data(): array`, `isDirty(): bool`, `isEmpty(): bool` and `replaced(): ?string`.
    - Data: `get(string, mixed $default = null): mixed`, `set(string, mixed): void` and `forget(string): void`.
    - `token(): string` returns the CSRF token in `_csrf`.
    - Changes: `login(string $userId): void`, `logout(): void`, `regenerate(): void` and `saved(string $id): void` (internal).
  - `Xaraya\Kernel\Auth\SessionStore(Connection)`, with `TOUCH_SECONDS = 60`:
    - `load(string $id, DateTimeImmutable $now): ?Session` returns null for a bad, unknown or expired id, or when the table is missing.
    - `save(Session, DateTimeImmutable $now, int $lifetime): void` and `touch(Session, $now, int $lifetime): void`.
    - `forget(Session): void`, `deleteForUser(string $userId): int` and `gc(DateTimeImmutable $now): int`.
  - `Xaraya\Kernel\Http\Middleware\SessionMiddleware(SessionStore, Config)`. It is global middleware 1 of 3. It reads `session.cookie`, `session.lifetime` (minutes) and `session.gc_probability`.
  - Test helpers:
    - `Xaraya\Tests\Support\Browser(App $app, string $ip = '203.0.113.7', string $origin = 'http://xar.test')` keeps cookies and sets `REMOTE_ADDR`. It has `get(path, headers)`, `post(path, fields, headers)` (which adds the last page's `_csrf` unless `$fields` has one), `request(method, path, headers, body)`, `send(request)`, `body()`, `csrfField(): ?string`, `$cookies` and `$last`.
    - The fixture module `probe` (namespace `Xaraya\Module\Probe`) has `/probe/plain`, `/probe/count` (increments session `n`), `/probe/session` (JSON user and n), `POST /probe/login/{id}` and `POST /probe/logout`, both `csrf:off`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/SessionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class SessionTest extends AuthTestCase
{
    public const PROBE = __DIR__ . '/../../fixtures/auth';

    /** @param array<string, mixed> $overrides */
    private function probeApp(array $overrides = []): App
    {
        $app = $this->installed(['session.gc_probability' => 0, ...$overrides], [self::PROBE]);
        $app->container()->get(ModuleRegistry::class)->enable('probe');

        return $this->app(['session.gc_probability' => 0, ...$overrides], [self::PROBE]);
    }

    /** @return array{0: string, 1: list<string>} the cookie value and its attributes */
    private static function cookie(Browser $browser): array
    {
        $line = $browser->last?->getHeaderLine('Set-Cookie') ?? '';
        self::assertMatchesRegularExpression('/^xar_session=[A-Za-z0-9_-]{43};/', $line);
        $parts = array_map('trim', explode(';', $line));

        return [substr((string) array_shift($parts), strlen('xar_session=')), $parts];
    }

    private function rows(App $app): int
    {
        return $this->db($app)->select('sessions')->count();
    }

    public function testAPageThatDoesNotUseTheSessionSetsNoCookieAndStoresNothing(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $response = $browser->get('/probe/plain');
        self::assertSame('plain', (string) $response->getBody());
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('Cache-Control'));
        self::assertSame(0, $this->rows($app));
    }

    public function testAChangedSessionIsStoredByTheHashOfItsCookie(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        self::assertSame('1', (string) $browser->get('/probe/count')->getBody());
        [$id, $attributes] = self::cookie($browser);
        self::assertSame(['Path=/', 'HttpOnly', 'SameSite=Lax'], $attributes);
        self::assertSame('private, no-cache', $browser->last?->getHeaderLine('Cache-Control'), 'a session page is never shared-cached');

        $row = $this->db($app)->select('sessions')->first();
        self::assertNotNull($row);
        self::assertSame(hash('sha256', $id), $row['id']);
        self::assertStringNotContainsString($id, (string) json_encode($row));

        self::assertSame('2', (string) $browser->get('/probe/count')->getBody());
        self::assertSame(1, $this->rows($app));
        self::assertSame($id, $browser->cookies['xar_session'], 'the id only changes at login and logout');
    }

    public function testTheCookieIsSecureExceptInDebugModeOverPlainHttp(): void
    {
        $cases = [
            [true, 'http://xar.test', false],
            [true, 'https://xar.test', true],
            [false, 'http://xar.test', true],
            [false, 'https://xar.test', true],
        ];
        foreach ($cases as [$debug, $origin, $secure]) {
            $browser = new Browser($this->probeApp(['app.debug' => $debug]), origin: $origin);
            $browser->get('/probe/count');
            [, $attributes] = self::cookie($browser);
            self::assertContains('HttpOnly', $attributes);
            self::assertContains('SameSite=Lax', $attributes);
            self::assertSame($secure, in_array('Secure', $attributes, true), ($debug ? 'debug ' : '') . $origin);
        }
    }

    public function testLoginGivesANewIdAndTheOldOneStopsWorking(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $planted = $browser->cookies['xar_session'];

        $browser->post('/probe/login/' . $ada->id);
        $fresh = $browser->cookies['xar_session'];
        self::assertNotSame($planted, $fresh);
        self::assertSame(['user' => $ada->id, 'n' => null], json_decode((string) $browser->get('/probe/session')->getBody(), true));

        $attacker = new Browser($app);
        $attacker->cookies['xar_session'] = $planted;
        self::assertSame(['user' => null, 'n' => null], json_decode((string) $attacker->get('/probe/session')->getBody(), true));
        self::assertSame(1, $this->rows($app));
    }

    public function testLogoutDeletesTheSessionAndExpiresTheCookie(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $ada->id);
        $id = $browser->cookies['xar_session'];
        $response = $browser->post('/probe/logout');
        self::assertSame('xar_session=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(0, $this->rows($app));
        self::assertArrayNotHasKey('xar_session', $browser->cookies);
        self::assertNull($app->container()->get(SessionStore::class)->load($id, new DateTimeImmutable()));
    }

    public function testExpiredUnknownAndMalformedCookiesStartAFreshSession(): void
    {
        $app = $this->probeApp(['session.lifetime' => 1]);
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $this->db($app)->query('UPDATE {sessions} SET expires_at = ?', [new DateTimeImmutable('-1 second')]);
        self::assertSame('1', (string) $browser->get('/probe/count')->getBody());

        foreach (['junk', str_repeat('A', 43), "x'; DROP TABLE sessions; --"] as $cookie) {
            $other = new Browser($app);
            $other->cookies['xar_session'] = $cookie;
            self::assertSame('1', (string) $other->get('/probe/count')->getBody());
        }
    }

    public function testExpiredSessionsAreCollected(): void
    {
        $app = $this->probeApp();
        (new Browser($app))->get('/probe/count');
        $this->db($app)->query('UPDATE {sessions} SET expires_at = ?', [new DateTimeImmutable('-1 second')]);
        (new Browser($this->app(['session.gc_probability' => 1], [self::PROBE])))->get('/probe/count');
        self::assertSame(1, $this->rows($app), 'the expired row went; the new one stays');
    }

    public function testAnUnchangedSessionIsTouchedAtMostOnceAMinute(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $browser->get('/probe/count');
        $activity = fn(): string => (string) $this->db($app)->fetchValue('SELECT last_activity FROM {sessions}');
        $old = (new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db($app)->query('UPDATE {sessions} SET last_activity = ?', [$old]);
        $browser->get('/probe/session');
        self::assertSame($old, $activity(), 'not within a minute');
        $older = (new DateTimeImmutable('-90 seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db($app)->query('UPDATE {sessions} SET last_activity = ?', [$older]);
        $browser->get('/probe/session');
        self::assertGreaterThan($older, $activity());
        self::assertFalse($browser->last?->hasHeader('Set-Cookie'));
    }

    public function testSessionTokens(): void
    {
        $session = Session::fresh();
        self::assertTrue($session->isEmpty());
        $token = $session->token();
        self::assertMatchesRegularExpression(Session::ID, $token);
        self::assertSame($token, $session->token());
        $session->login('01J0000000000000000000000A');
        self::assertNotSame($token, $session->token(), 'login starts a new CSRF token');
    }
}
```

`tests/Support/Browser.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Support;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Xaraya\Kernel\App;

/** Sends requests through App::handle() like a browser: it keeps cookies and comes from one IP address. */
final class Browser
{
    /** @var array<string, string> */
    public array $cookies = [];

    public ?ResponseInterface $last = null;

    public function __construct(
        private readonly App $app,
        public string $ip = '203.0.113.7',
        private readonly string $origin = 'http://xar.test',
    ) {}

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->send($this->request('GET', $path, $headers));
    }

    /**
     * Posts a form. Unless $fields has "_csrf", the token of the last page's form is added.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     */
    public function post(string $path, array $fields = [], array $headers = []): ResponseInterface
    {
        if (!array_key_exists('_csrf', $fields) && $this->last !== null && ($token = $this->csrfField()) !== null) {
            $fields['_csrf'] = $token;
        }
        $request = $this->request('POST', $path, ['Content-Type' => 'application/x-www-form-urlencoded', ...$headers], http_build_query($fields));

        return $this->send($request->withParsedBody($fields));
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = [], string $body = ''): ServerRequestInterface
    {
        return new ServerRequest($method, $this->origin . $path, $headers, $body, '1.1', ['REMOTE_ADDR' => $this->ip]);
    }

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->app->handle($request->withCookieParams($this->cookies));
        foreach ($response->getHeader('Set-Cookie') as $line) {
            [$pair] = explode(';', $line, 2);
            [$name, $value] = explode('=', $pair, 2);
            if (str_contains(strtolower($line), 'max-age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }

        return $this->last = $response;
    }

    public function body(): string
    {
        return $this->last === null ? throw new RuntimeException('No request yet') : (string) $this->last->getBody();
    }

    /** The _csrf hidden field of the last page, if it has one. */
    public function csrfField(): ?string
    {
        return preg_match('/name="_csrf" value="([^"]+)"/', $this->body(), $m) === 1 ? $m[1] : null;
    }
}
```

`tests/fixtures/auth/probe/module.json`:

```json
{
  "name": "probe",
  "version": "1.0.0",
  "routes": "Xaraya\\Module\\Probe\\Routes"
}
```

`tests/fixtures/auth/probe/src/Routes.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Module\Probe;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/** Test routes that expose the session and identity machinery. */
final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
            $session = self::session($r);
            $n = (int) $session->get('n', 0) + 1;
            $session->set('n', $n);

            return new Response(200, ['Cache-Control' => 'public, max-age=60'], (string) $n);
        }, 'probe.count');
        $routes->get('/probe/session', static fn(ServerRequestInterface $r): ResponseInterface => new Response(200, [], (string) json_encode([
            'user' => self::session($r)->userId(),
            'n' => self::session($r)->get('n'),
        ])), 'probe.session');
        $routes->post('/probe/login/{id}', static function (ServerRequestInterface $r, array $params): ResponseInterface {
            self::session($r)->login((string) $params['id']);

            return new Response(204);
        }, 'probe.login')->middleware('csrf:off');
        $routes->post('/probe/logout', static function (ServerRequestInterface $r): ResponseInterface {
            self::session($r)->logout();

            return new Response(204);
        }, 'probe.logout')->middleware('csrf:off');
    }

    private static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);

        return $session instanceof Session ? $session : throw new \LogicException('no session');
    }
}
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/SessionTest.php`
Expected: FAIL. There are 5 `Class "Xaraya\Kernel\Auth\Session" not found` errors. The 4 HTTP tests fail because no `Set-Cookie` is sent: `Failed asserting that '' matches PCRE pattern "/^xar_session=[A-Za-z0-9_-]{43};/"`.

- [ ] **Step 3: Implement**

On the way out, the middleware handles the session in this order:
1. Dirty and empty: forget the row, and expire the cookie if one came in.
2. Dirty: save it, send the cookie, and maybe collect garbage.
3. Otherwise: touch it.

Last, it marks the response `private, no-cache` when the session has an id.

In `config/app.php`, replace:

```php
        'url' => env('ASSET_URL', '/assets'),
    ],
    'log' => [
        'path' => $root . '/var/logs',
```

with:

```php
        'url' => env('ASSET_URL', '/assets'),
    ],
    'session' => [
        'cookie' => env('SESSION_COOKIE', 'xar_session'),
        // Idle minutes before a session expires; expired rows are deleted on about 1 in 100 session writes.
        'lifetime' => (int) env('SESSION_LIFETIME', 120),
        'gc_probability' => 0.01,
    ],
    'log' => [
        'path' => $root . '/var/logs',
```

In `src/Kernel/App.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
```

with:

```php
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
```

In `src/Kernel/App.php` (2 of 2), replace:

```php
    private ?array $routes = null;

    /** @var list<string> */
    private array $globalMiddleware = [];

    /**
```

with:

```php
    private ?array $routes = null;

    /** @var list<string> after ErrorHandler, before the router (kernel spec §3.3); pushMiddleware() appends */
    private array $globalMiddleware = [SessionMiddleware::class];

    /**
```

`src/Kernel/Auth/Session.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * The request's session (the Session::class request attribute). SessionMiddleware loads it from the cookie and
 * saves it after the response when it changed. A session with no user and no data is never stored.
 */
final class Session
{
    /** The cookie value: 32 random bytes in unpadded base64url. */
    public const ID = '/^[A-Za-z0-9_-]{43}$/D';

    private bool $dirty = false;

    private ?string $replaced = null;

    /** @param array<string, mixed> $data */
    private function __construct(
        private ?string $id,
        private ?string $userId,
        private array $data,
        public readonly ?DateTimeImmutable $lastActivity,
    ) {}

    public static function fresh(): self
    {
        return new self(null, null, [], null);
    }

    /** @param array<string, mixed> $data */
    public static function loaded(#[SensitiveParameter] string $id, ?string $userId, array $data, DateTimeImmutable $lastActivity): self
    {
        return new self($id, $userId, $data, $lastActivity);
    }

    public static function newId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** What the sessions table stores instead of the cookie value. */
    public static function hash(#[SensitiveParameter] string $id): string
    {
        return hash('sha256', $id);
    }

    /** The cookie value, or null until a new session is first saved. */
    public function id(): ?string
    {
        return $this->id;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** The CSRF synchronizer token, created on first use. */
    public function token(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token) || $token === '') {
            $this->set('_csrf', $token = self::newId());
        }

        return $token;
    }

    /** Signs $userId in under a new session id, with fresh data (and so a fresh CSRF token). */
    public function login(string $userId): void
    {
        $this->regenerate();
        $this->userId = $userId;
        $this->data = [];
    }

    /** Signs out: the stored session is deleted and the cookie expired. */
    public function logout(): void
    {
        $this->regenerate();
        $this->userId = null;
        $this->data = [];
    }

    /** Gives the session a new id when it is next saved; the old row is deleted. */
    public function regenerate(): void
    {
        if ($this->id !== null) {
            $this->replaced = self::hash($this->id);
        }
        $this->id = null;
        $this->dirty = true;
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function isEmpty(): bool
    {
        return $this->userId === null && $this->data === [];
    }

    /** The hash of a row regenerate() left behind, which the store must delete. */
    public function replaced(): ?string
    {
        return $this->replaced;
    }

    /** @internal SessionStore assigns the id of a newly saved session. */
    public function saved(#[SensitiveParameter] string $id): void
    {
        $this->id = $id;
        $this->dirty = false;
        $this->replaced = null;
    }
}
```

`src/Kernel/Auth/SessionStore.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;

/** xar_sessions. Rows are keyed by Session::hash() of the cookie value and expire after an idle lifetime. */
final class SessionStore
{
    /** last_activity is written at most once in this many seconds for an unchanged session. */
    public const TOUCH_SECONDS = 60;

    private bool $ready = false;

    public function __construct(private readonly Connection $db) {}

    public function load(#[SensitiveParameter] string $id, DateTimeImmutable $now): ?Session
    {
        if (preg_match(Session::ID, $id) !== 1 || !$this->ready()) {
            return null;
        }
        $row = $this->db->select('sessions')->where('id', '=', Session::hash($id))->where('expires_at', '>', $now)->first();
        if ($row === null) {
            return null;
        }
        $data = json_decode((string) $row['data'], true);
        /** @var array<string, mixed> $data */
        $data = is_array($data) ? $data : [];

        return Session::loaded(
            $id,
            $row['user_id'] === null ? null : (string) $row['user_id'],
            $data,
            new DateTimeImmutable((string) $row['last_activity'], $now->getTimezone()),
        );
    }

    /** Stores the session (giving a new one its id) and deletes the row a regenerate() replaced. */
    public function save(Session $session, DateTimeImmutable $now, int $lifetime): void
    {
        $replaced = $session->replaced();
        if ($replaced !== null) {
            $this->db->delete('sessions', ['id' => $replaced]);
        }
        $id = $session->id() ?? Session::newId();
        $this->db->upsert('sessions', [
            'id' => Session::hash($id),
            'user_id' => $session->userId(),
            'data' => json_encode($session->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'last_activity' => $now,
            'expires_at' => $now->modify("+{$lifetime} seconds"),
        ], ['id']);
        $session->saved($id);
    }

    /** Extends an unchanged session's idle lifetime, at most once a minute. */
    public function touch(Session $session, DateTimeImmutable $now, int $lifetime): void
    {
        $id = $session->id();
        if ($id === null || ($session->lastActivity !== null && $session->lastActivity > $now->modify('-' . self::TOUCH_SECONDS . ' seconds'))) {
            return;
        }
        $this->db->update('sessions', ['last_activity' => $now, 'expires_at' => $now->modify("+{$lifetime} seconds")], ['id' => Session::hash($id)]);
    }

    /** Deletes the row of a session that ended (logout, or a regenerate with nothing left to save). */
    public function forget(Session $session): void
    {
        if ($session->replaced() !== null) {
            $this->db->delete('sessions', ['id' => $session->replaced()]);
        }
    }

    /** Signs a user out everywhere (a password change, a disabled account). Returns the number of sessions ended. */
    public function deleteForUser(string $userId): int
    {
        return $this->ready() ? $this->db->delete('sessions', ['user_id' => $userId]) : 0;
    }

    /** Deletes expired sessions. */
    public function gc(DateTimeImmutable $now): int
    {
        return $this->db->query('DELETE FROM {sessions} WHERE expires_at <= ?', [$now])->rowCount();
    }

    private function ready(): bool
    {
        return $this->ready || ($this->ready = $this->db->hasTable('sessions'));
    }
}
```

`src/Kernel/Http/Middleware/SessionMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Config\Config;

/**
 * Global middleware: loads the session named by the cookie (session.cookie, default "xar_session") into the
 * Session::class request attribute, and after the response saves it, renews the cookie or expires it. The cookie
 * is HttpOnly and SameSite=Lax, and Secure unless app.debug is on and the request came over plain HTTP.
 * A response that used a session is marked Cache-Control: private, no-cache.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SessionStore $store, private readonly Config $config) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $name = $this->cookieName();
        $cookie = $request->getCookieParams()[$name] ?? null;
        $session = (is_string($cookie) ? $this->store->load($cookie, $now) : null) ?? Session::fresh();
        $response = $handler->handle($request->withAttribute(Session::class, $session));

        $lifetime = 60 * max(1, (int) $this->config->get('session.lifetime', 120));
        if ($session->isDirty() && $session->isEmpty()) {
            $this->store->forget($session);
            if (is_string($cookie)) {
                $response = $response->withAddedHeader('Set-Cookie', $this->cookie($request, $name, '', 0));
            }
        } elseif ($session->isDirty()) {
            $this->store->save($session, $now, $lifetime);
            $response = $response->withAddedHeader('Set-Cookie', $this->cookie($request, $name, (string) $session->id(), null));
            $this->maybeCollectGarbage($now);
        } else {
            $this->store->touch($session, $now, $lifetime);
        }
        if ($session->id() !== null && !str_contains($response->getHeaderLine('Cache-Control'), 'no-store')) {
            $response = $response->withHeader('Cache-Control', 'private, no-cache');
        }

        return $response;
    }

    private function cookieName(): string
    {
        $name = (string) $this->config->get('session.cookie', 'xar_session');

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $name) === 1 ? $name : 'xar_session';
    }

    private function cookie(ServerRequestInterface $request, string $name, string $value, ?int $maxAge): string
    {
        $secure = $request->getUri()->getScheme() === 'https' || $this->config->get('app.debug', false) !== true;

        return $name . '=' . $value . '; Path=/' . ($maxAge === null ? '' : '; Max-Age=' . $maxAge)
            . '; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : '');
    }

    private function maybeCollectGarbage(DateTimeImmutable $now): void
    {
        $probability = (float) $this->config->get('session.gc_probability', 0.01);
        if ($probability > 0 && random_int(1, 1_000_000) <= (int) ($probability * 1_000_000)) {
            $this->store->gc($now);
        }
    }
}
```

`src/Kernel/migrations/2026_10_21_000002_create_sessions.php`:

```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Sessions keyed by the sha256 of their cookie value (the cookie itself is never stored). */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('sessions', function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->ulid('user_id')->nullable();
            $t->text('data');
            $t->datetime('last_activity');
            $t->datetime('expires_at');
            $t->index('expires_at');
            $t->index('user_id');
            $t->foreign('user_id', 'users');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('sessions');
    }
};
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth`
Expected: PASS (26 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth`.
Expected: all green (721 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/migrations/2026_10_21_000002_create_sessions.php src/Kernel/Auth/Session.php src/Kernel/Auth/SessionStore.php src/Kernel/Http/Middleware/SessionMiddleware.php src/Kernel/App.php config/app.php tests/Support/Browser.php tests/fixtures/auth tests/Kernel/Auth/SessionTest.php
git commit -m "feat(auth): database sessions with a hashed id in a PSR-7 cookie" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 4: The `Authenticate` middleware and the request's identity

**Files:**
- Create: `src/Kernel/Http/Middleware/Authenticate.php`
- Modify: `src/Kernel/App.php` (global middleware 2 of 3; the identity reset in `handle()`), `src/Kernel/Container/Container.php` (`resolved()`)
- Test: `tests/Kernel/Auth/AuthenticateTest.php` (new). `tests/Support/AuthTestCase.php` gains `PROBE` and `probeApp()`, moved out of `SessionTest`, and the probe fixture gains `/probe/whoami`.

**Interfaces:**
- **Consumes:** `Session` (Task 3); `Auth::actAs()` (Task 2); `UserRepository::find()` (Task 1).
- **Produces:**
  - `Xaraya\Kernel\Http\Middleware\Authenticate(Auth, UserRepository)`. A session whose user is missing or inactive gets `Session::logout()`.
  - `Container::resolved(string $id): bool`.
  - `AuthTestCase::PROBE` (the `tests/fixtures/auth` path) and `probeApp(array $overrides = []): App`: installed, `probe` enabled, `session.gc_probability = 0`.
  - The probe route `GET /probe/whoami` answers with JSON `{"user": handle|null, "roles": [...]}`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/AuthenticateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class AuthenticateTest extends AuthTestCase
{
    /** @return array{user: ?string, roles: list<string>} */
    private static function whoami(Browser $browser): array
    {
        return json_decode((string) $browser->get('/probe/whoami')->getBody(), true);
    }

    public function testAGuestIsAnonymous(): void
    {
        self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami(new Browser($this->probeApp())));
    }

    public function testTheSessionUserIsTheIdentity(): void
    {
        $app = $this->probeApp();
        $ed = $this->user($app, 'ed', ['Editors']);
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $ed->id);
        self::assertSame(['user' => 'ed', 'roles' => ['Anonymous', 'Members', 'Editors']], self::whoami($browser));
        self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami(new Browser($app)), 'the next request starts as a guest');
    }

    public function testADisabledOrPendingUserIsSignedOutOfTheirSession(): void
    {
        $app = $this->probeApp();
        foreach (['disabled', 'pending'] as $i => $status) {
            $user = $this->user($app, "user{$i}");
            $browser = new Browser($app);
            $browser->post('/probe/login/' . $user->id);
            $this->users($app)->setStatus($user, $status);
            self::assertSame(['user' => null, 'roles' => ['Anonymous']], self::whoami($browser), $status);
            self::assertStringContainsString('Max-Age=0', $browser->last?->getHeaderLine('Set-Cookie') ?? '');
            self::assertSame(0, $this->db($app)->select('sessions')->where('user_id', '=', $user->id)->count());
        }
    }

    public function testRequestsThatMissEveryRouteStartAsAGuest(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $this->user($app, 'ada')->id);
        self::whoami($browser);
        self::assertSame('ada', $app->container()->get(Auth::class)->user()?->handle);
        self::assertSame(404, $app->handle(new ServerRequest('GET', '/no/such/page'))->getStatusCode());
        self::assertNull($app->container()->get(Auth::class)->user());
    }
}
```

In `tests/Kernel/Auth/SessionTest.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
```

with:

```php
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
```

In `tests/Kernel/Auth/SessionTest.php` (2 of 2), replace:

```php
final class SessionTest extends AuthTestCase
{
    public const PROBE = __DIR__ . '/../../fixtures/auth';

    /** @param array<string, mixed> $overrides */
    private function probeApp(array $overrides = []): App
    {
        $app = $this->installed(['session.gc_probability' => 0, ...$overrides], [self::PROBE]);
        $app->container()->get(ModuleRegistry::class)->enable('probe');

        return $this->app(['session.gc_probability' => 0, ...$overrides], [self::PROBE]);
    }

    /** @return array{0: string, 1: list<string>} the cookie value and its attributes */
    private static function cookie(Browser $browser): array
```

with:

```php
final class SessionTest extends AuthTestCase
{
    /** @return array{0: string, 1: list<string>} the cookie value and its attributes */
    private static function cookie(Browser $browser): array
```

In `tests/Support/AuthTestCase.php` (1 of 3), replace:

```php
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Db\Connection;

/** Boots the app with the phoenix theme and cheap password hashes; installed() also runs the kernel migrations. */
```

with:

```php
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;

/** Boots the app with the phoenix theme and cheap password hashes; installed() also runs the kernel migrations. */
```

In `tests/Support/AuthTestCase.php` (2 of 3), replace:

```php
{
    public const PASSWORD = 'correct horse battery';

    /**
```

with:

```php
{
    public const PASSWORD = 'correct horse battery';

    /** The fixture module "probe": routes that expose the session and the identity. */
    public const PROBE = __DIR__ . '/../fixtures/auth';

    /**
```

In `tests/Support/AuthTestCase.php` (3 of 3), replace:

```php
    }

    protected function users(App $app): UserRepository
    {
```

with:

```php
    }

    /**
     * An installed app with the probe module enabled and session garbage collection off.
     *
     * @param array<string, mixed> $overrides
     */
    protected function probeApp(array $overrides = []): App
    {
        $overrides = ['session.gc_probability' => 0, ...$overrides];
        $this->installed($overrides, [self::PROBE])->container()->get(ModuleRegistry::class)->enable('probe');

        return $this->app($overrides, [self::PROBE]);
    }

    protected function users(App $app): UserRepository
    {
```

In `tests/fixtures/auth/probe/src/Routes.php` (1 of 2), replace:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Routing\RouteCollector;
```

with:

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Routing\RouteCollector;
```

In `tests/fixtures/auth/probe/src/Routes.php` (2 of 2), replace:

```php
final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```

with:

```php
final class Routes implements RouteProvider
{
    public function __construct(private readonly Auth $auth) {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/probe/whoami', fn(): ResponseInterface => new Response(200, [], (string) json_encode([
            'user' => $this->auth->user()?->handle,
            'roles' => $this->auth->roles(),
        ])), 'probe.whoami');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/AuthenticateTest.php tests/Kernel/Auth/SessionTest.php`
Expected: FAIL (3 failures):
- `testTheSessionUserIsTheIdentity`: `Failed asserting that two arrays are identical`, because the user stays a guest.
- `testADisabledOrPendingUserIsSignedOutOfTheirSession`: no `Max-Age=0`.
- `testRequestsThatMissEveryRouteStartAsAGuest`: `null is identical to 'ada'`.

- [ ] **Step 3: Implement**

In `src/Kernel/App.php` (1 of 3), replace:

```php
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\HomeController;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Middleware\Cors;
```

with:

```php
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\HomeController;
use Xaraya\Kernel\Http\Middleware\Authenticate;
use Xaraya\Kernel\Http\Middleware\ConditionalGet;
use Xaraya\Kernel\Http\Middleware\Cors;
```

In `src/Kernel/App.php` (2 of 3), replace:

```php
    /** @var list<string> after ErrorHandler, before the router (kernel spec §3.3); pushMiddleware() appends */
    private array $globalMiddleware = [SessionMiddleware::class];

    /**
```

with:

```php
    /** @var list<string> after ErrorHandler, before the router (kernel spec §3.3); pushMiddleware() appends */
    private array $globalMiddleware = [SessionMiddleware::class, Authenticate::class];

    /**
```

In `src/Kernel/App.php` (3 of 3), replace:

```php
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $final = new CallableHandler(function (ServerRequestInterface $request): ResponseInterface {
            $stack = [];
```

with:

```php
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Every request starts as a guest, including those (404, 405) that never reach the Authenticate middleware.
        if ($this->container->resolved(Auth::class)) {
            $this->container->get(Auth::class)->actAs(null);
        }
        $final = new CallableHandler(function (ServerRequestInterface $request): ResponseInterface {
            $stack = [];
```

In `src/Kernel/Container/Container.php`, replace:

```php
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]) || class_exists($id);
    }
```

with:

```php
    {
        return array_key_exists($id, $this->instances) || isset($this->factories[$id]) || class_exists($id);
    }

    /** True once $id has been built (or set with instance()). */
    public function resolved(string $id): bool
    {
        return array_key_exists($id, $this->instances);
    }
```

`src/Kernel/Http/Middleware/Authenticate.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\UserRepository;

/**
 * Global middleware: makes the session's user the request's identity. It never refuses a request; the `auth`
 * route middleware does. A session whose user is gone, pending or disabled is signed out.
 */
final class Authenticate implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth, private readonly UserRepository $users) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        $userId = $session instanceof Session ? $session->userId() : null;
        $user = $userId === null ? null : $this->users->find($userId);
        if ($session instanceof Session && $userId !== null && ($user === null || !$user->isActive())) {
            $session->logout();
            $user = null;
        }
        $this->auth->actAs($user);

        return $handler->handle($request);
    }
}
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth`
Expected: PASS (30 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth`.
Expected: all green (725 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Http/Middleware/Authenticate.php src/Kernel/App.php src/Kernel/Container/Container.php tests/Kernel/Auth/AuthenticateTest.php tests/Kernel/Auth/SessionTest.php tests/Support/AuthTestCase.php tests/fixtures/auth
git commit -m "feat(auth): Authenticate middleware makes the session user the identity" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 5: CSRF: the synchronizer token, `csrf()` and `csrf:off`

**Files:**
- Create: `src/Kernel/Http/Middleware/VerifyCsrf.php`
- Modify: `src/Kernel/App.php` (global middleware 3 of 3), `src/Kernel/View/Helpers.php` (`csrf()`)
- Test: `tests/Kernel/Auth/VerifyCsrfTest.php` (new). `tests/Support/AppTestCase.php` gains `withCsrf()`. `tests/Kernel/HelloModuleTest.php` posts with a token and gains a 403 test. The probe fixture gains `/probe/form` (any method).

**Interfaces:**
- **Consumes:** `Session::token()` and `Session::get('_csrf')` (Task 3); `RouteMatch` (the route's middleware list).
- **Produces:**
  - `Xaraya\Kernel\Http\Middleware\VerifyCsrf`, with `FIELD = '_csrf'` and `HEADER = 'X-CSRF-Token'`. A failure throws `Forbidden('The form has expired or was not sent from this site. Reload the page and try again.')`.
  - `Helpers::csrf(): string` returns `<input type="hidden" name="_csrf" value="…">` (or `''` without a session).
  - `AppTestCase::withCsrf(App $app, ServerRequestInterface $request): ServerRequestInterface` stores a session and adds its cookie and `X-CSRF-Token`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/VerifyCsrfTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class VerifyCsrfTest extends AuthTestCase
{
    public function testTheHelperPrintsTheSessionTokenAsAHiddenField(): void
    {
        $browser = new Browser($this->probeApp());
        $browser->get('/probe/form');
        $token = $browser->csrfField();
        self::assertNotNull($token);
        self::assertMatchesRegularExpression('/^<form method="post"><input type="hidden" name="_csrf" value="[A-Za-z0-9_-]{43}"><\/form>$/', $browser->body());
        self::assertArrayHasKey('xar_session', $browser->cookies, 'the token lives in a stored session');
        $browser->get('/probe/form');
        self::assertSame($token, $browser->csrfField(), 'one token per session');
    }

    public function testUnsafeMethodsNeedTheTokenAsAFieldOrHeader(): void
    {
        $browser = new Browser($this->probeApp());
        $browser->get('/probe/form');
        $token = (string) $browser->csrfField();

        self::assertSame('ok POST', (string) $browser->post('/probe/form')->getBody());
        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $browser->send($browser->request($method, '/probe/form', ['X-CSRF-Token' => $token]));
            self::assertSame("ok {$method}", (string) $response->getBody());
        }
    }

    public function testAMissingWrongOrForeignTokenIs403(): void
    {
        $app = $this->probeApp();
        $victim = new Browser($app);
        $victim->get('/probe/form');
        $attacker = new Browser($app);
        $attacker->get('/probe/form');
        $foreign = (string) $attacker->csrfField();

        $missing = $victim->send($victim->request('POST', '/probe/form'));
        foreach ([$missing, $victim->post('/probe/form', ['_csrf' => '']), $victim->post('/probe/form', ['_csrf' => 'guess']), $victim->post('/probe/form', ['_csrf' => $foreign])] as $i => $response) {
            self::assertSame(403, $response->getStatusCode(), "case {$i}");
            self::assertStringContainsString('The form has expired', (string) $response->getBody());
        }
        $json = $victim->send($victim->request('DELETE', '/probe/form', ['Accept' => 'application/json']));
        self::assertSame(403, $json->getStatusCode());
        self::assertSame('application/json', $json->getHeaderLine('Content-Type'));

        $fresh = new Browser($app);
        self::assertSame(403, $fresh->post('/probe/form', ['_csrf' => $foreign])->getStatusCode(), 'no session, no token');
    }

    public function testSafeMethodsAndCsrfOffRoutesAreNotChecked(): void
    {
        $app = $this->probeApp();
        $browser = new Browser($app);
        self::assertSame(200, $browser->get('/probe/plain')->getStatusCode());
        self::assertSame(200, $browser->send($browser->request('HEAD', '/probe/plain'))->getStatusCode());
        self::assertSame(204, $browser->post('/probe/login/' . $this->user($app, 'ada')->id)->getStatusCode());
    }
}
```

In `tests/Kernel/HelloModuleTest.php` (1 of 2), replace:

```php
    private function greet(App $app, string $name, array $body = []): string
    {
        $request = new ServerRequest('POST', '/hello/' . $name);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
```

with:

```php
    private function greet(App $app, string $name, array $body = []): string
    {
        $request = $this->withCsrf($app, new ServerRequest('POST', '/hello/' . $name));
        if ($body !== []) {
            $request = $request->withParsedBody($body);
```

In `tests/Kernel/HelloModuleTest.php` (2 of 2), replace:

```php
        self::assertSame(404, $app->handle(new ServerRequest('POST', '/hello/Not-Lowercase'))->getStatusCode());
    }
```

with:

```php
        self::assertSame(404, $app->handle(new ServerRequest('POST', '/hello/Not-Lowercase'))->getStatusCode());
    }

    public function testCreatingAGreetingNeedsTheCsrfToken(): void
    {
        $this->enableHello();
        $app = $this->app();
        self::assertSame(403, $app->handle(new ServerRequest('POST', '/hello/ada'))->getStatusCode());
        self::assertSame(['greetings' => []], json_decode((string) $app->handle(new ServerRequest('GET', '/hello.json'))->getBody(), true));
    }
```

In `tests/Support/AppTestCase.php` (1 of 2), replace:

```php
use PHPUnit\Framework\TestCase;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Db\Migrations\Migrator;
```

with:

```php
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Db\Migrations\Migrator;
```

In `tests/Support/AppTestCase.php` (2 of 2), replace:

```php
        }
        self::rmdir($this->tmp);
    }
```

with:

```php
        }
        self::rmdir($this->tmp);
    }

    /**
     * Gives $request a stored session and its CSRF token (cookie and X-CSRF-Token header), as a page with a
     * form would have. The kernel migrations must have run.
     */
    protected function withCsrf(App $app, ServerRequestInterface $request): ServerRequestInterface
    {
        $session = Session::fresh();
        $token = $session->token();
        $app->container()->get(SessionStore::class)->save($session, new \DateTimeImmutable(), 3600);

        return $request->withCookieParams(['xar_session' => (string) $session->id()])->withHeader('X-CSRF-Token', $token);
    }
```

In `tests/fixtures/auth/probe/src/Routes.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/** Test routes that expose the session and identity machinery. */
final class Routes implements RouteProvider
{
    public function __construct(private readonly Auth $auth) {}

    public function routes(RouteCollector $routes): void
```

with:

```php
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
use Xaraya\Kernel\View\View;

/** Test routes that expose the session and identity machinery. */
final class Routes implements RouteProvider
{
    public function __construct(private readonly Auth $auth, private readonly View $view) {}

    public function routes(RouteCollector $routes): void
```

In `tests/fixtures/auth/probe/src/Routes.php` (2 of 2), replace:

```php
            'roles' => $this->auth->roles(),
        ])), 'probe.whoami');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```

with:

```php
            'roles' => $this->auth->roles(),
        ])), 'probe.whoami');
        $routes->any('/probe/form', fn(ServerRequestInterface $r): ResponseInterface => new Response(
            200,
            [],
            $r->getMethod() === 'GET' ? '<form method="post">' . $this->view->helpers($r)->csrf() . '</form>' : 'ok ' . $r->getMethod(),
        ), 'probe.form');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/VerifyCsrfTest.php tests/Kernel/HelloModuleTest.php`
Expected: FAIL (3 failures):
- `testTheHelperPrintsTheSessionTokenAsAHiddenField`: `null is not null`, because the stub prints nothing.
- `testAMissingWrongOrForeignTokenIs403`: `200 is identical to 403`.
- `testCreatingAGreetingNeedsTheCsrfToken`: `201 is identical to 403`.

- [ ] **Step 3: Implement**

In `src/Kernel/App.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
```

with:

```php
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
use Xaraya\Kernel\Http\Middleware\VerifyCsrf;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
```

In `src/Kernel/App.php` (2 of 2), replace:

```php
    /** @var list<string> after ErrorHandler, before the router (kernel spec §3.3); pushMiddleware() appends */
    private array $globalMiddleware = [SessionMiddleware::class, Authenticate::class];

    /**
```

with:

```php
    /** @var list<string> after ErrorHandler, before the router (kernel spec §3.3); pushMiddleware() appends */
    private array $globalMiddleware = [SessionMiddleware::class, Authenticate::class, VerifyCsrf::class];

    /**
```

`src/Kernel/Http/Middleware/VerifyCsrf.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Routing\RouteMatch;

/**
 * Global middleware: a request other than GET, HEAD or OPTIONS must carry the session's synchronizer token, as the
 * form field "_csrf" (the csrf() helper) or the X-CSRF-Token header, unless its route is marked "csrf:off"
 * (token-authenticated APIs). A missing or wrong token is a 403.
 */
final class VerifyCsrf implements MiddlewareInterface
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true) || self::exempt($request)) {
            return $handler->handle($request);
        }
        $session = $request->getAttribute(Session::class);
        $expected = $session instanceof Session ? $session->get('_csrf') : null;
        $body = $request->getParsedBody();
        $sent = is_array($body) && is_string($body[self::FIELD] ?? null) ? $body[self::FIELD] : $request->getHeaderLine(self::HEADER);
        if (!is_string($expected) || $expected === '' || $sent === '' || !hash_equals($expected, $sent)) {
            throw new Forbidden('The form has expired or was not sent from this site. Reload the page and try again.');
        }

        return $handler->handle($request);
    }

    private static function exempt(ServerRequestInterface $request): bool
    {
        $match = $request->getAttribute(RouteMatch::class);

        return $match instanceof RouteMatch && in_array('csrf:off', $match->route->getMiddleware(), true);
    }
}
```

In `src/Kernel/View/Helpers.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Routing\UrlGenerator;
```

with:

```php
use Xaraya\Kernel\Assets\AssetUrls;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Config\Config;
use Xaraya\Kernel\Container\Container;
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Http\Middleware\VerifyCsrf;
use Xaraya\Kernel\Routing\UrlGenerator;
```

In `src/Kernel/View/Helpers.php` (2 of 2), replace:

```php
    }

    /** The CSRF hidden field. A stub until Plan 3 adds sessions: it returns ''. */
    public function csrf(): string
    {
        return '';
    }
```

with:

```php
    }

    /** The CSRF hidden field for a form that posts back to this site ('' outside a request). */
    public function csrf(): string
    {
        $session = $this->request?->getAttribute(Session::class);

        return $session instanceof Session
            ? '<input type="hidden" name="' . VerifyCsrf::FIELD . '" value="' . $this->e($session->token()) . '">'
            : '';
    }
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth tests/Kernel/HelloModuleTest.php`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth tests/Kernel/HelloModuleTest.php`.
Expected: all green (730 tests). The blog API, Micropub and token tests still pass, because their routes are all `csrf:off`.

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Http/Middleware/VerifyCsrf.php src/Kernel/App.php src/Kernel/View/Helpers.php tests/Kernel/Auth/VerifyCsrfTest.php tests/Kernel/HelloModuleTest.php tests/Support/AppTestCase.php tests/fixtures/auth
git commit -m "feat(auth): CSRF synchronizer token, the csrf() helper and csrf:off" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 6: The `auth` and `auth:<module>.<component>.<level>` route middleware

**Files:**
- Create: `src/Kernel/Http/Middleware/RequireAuth.php`
- Modify: `src/Kernel/App.php` (the `auth` alias), `src/Kernel/Http/Middleware/ErrorHandler.php` (`wantsJson()` becomes public static)
- Test: `tests/Kernel/Auth/RequireAuthTest.php` (new). The probe fixture gains `/probe/members`, `/probe/members.json`, `POST /probe/members`, `/probe/admin` (`secureHtml`, `auth:probe.secret.admin`) and `/probe/public` (`auth:probe.secret.read`).

**Interfaces:**
- **Consumes:** `Auth::{check, can}` (Task 2); `Forbidden` and `Unauthorized` (Plan 1).
- **Produces:**
  - `Xaraya\Kernel\Http\Middleware\RequireAuth(Auth $auth, array $args = [])`, alias `auth`, with `LOGIN_PATH = '/login'`.
  - A malformed spec throws `LogicException("The auth middleware takes <module>.<component>.<level>, not '…'")`.
  - A guest on an HTML GET or HEAD gets a 302 to `/login?next=<rawurlencode(path?query)>` with `Cache-Control: no-store`. Any other guest request gets `Unauthorized('Log in to continue')`, and a signed-in user who fails gets `Forbidden()`.
  - `ErrorHandler::wantsJson(ServerRequestInterface): bool` (public static).

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/RequireAuthTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use LogicException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Http\Middleware\RequireAuth;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class RequireAuthTest extends AuthTestCase
{
    /** @param list<string> $roles */
    private function signedIn(App $app, string $handle, array $roles = []): Browser
    {
        $browser = new Browser($app);
        $browser->post('/probe/login/' . $this->user($app, $handle, $roles)->id);

        return $browser;
    }

    public function testAGuestIsSentToTheLoginPageFromAnHtmlGet(): void
    {
        $response = (new Browser($this->probeApp()))->get('/probe/members?tab=a&b=1');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login?next=%2Fprobe%2Fmembers%3Ftab%3Da%26b%3D1', $response->getHeaderLine('Location'));
    }

    public function testAGuestGetsAJson401OnJsonRequestsAndA401OnOtherMethods(): void
    {
        $guest = new Browser($this->probeApp());
        foreach ([$guest->get('/probe/members.json'), $guest->get('/probe/members', ['Accept' => 'application/json'])] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame(['error' => ['status' => 401, 'message' => 'Log in to continue']], json_decode((string) $response->getBody(), true));
        }
        $guest->get('/probe/form');
        self::assertSame(401, $guest->post('/probe/members')->getStatusCode());
    }

    public function testASignedInUserPassesAuth(): void
    {
        $app = $this->probeApp();
        $member = $this->signedIn($app, 'mia');
        self::assertSame('mia', (string) $member->get('/probe/members')->getBody());
        self::assertSame('mia', (string) $member->get('/probe/members.json')->getBody());
    }

    public function testALevelCheckIs403ForMembersAnd200ForAdministrators(): void
    {
        $app = $this->probeApp();
        $denied = $this->signedIn($app, 'mia')->get('/probe/admin');
        self::assertSame(403, $denied->getStatusCode());
        self::assertSame('nosniff', $denied->getHeaderLine('X-Content-Type-Options'), 'secureHtml still wraps the error');
        self::assertSame('root', (string) $this->signedIn($app, 'root', ['Administrators'])->get('/probe/admin')->getBody());
        self::assertSame(302, (new Browser($app))->get('/probe/admin')->getStatusCode(), 'a guest is asked to log in, not refused');
    }

    public function testALevelAnonymousHoldsLetsGuestsIn(): void
    {
        self::assertSame(200, (new Browser($this->probeApp()))->get('/probe/public')->getStatusCode());
    }

    public function testAMalformedSpecIsAProgrammingError(): void
    {
        $auth = $this->installed()->container()->get(Auth::class);
        foreach ([['blog'], ['blog.post'], ['blog.post.superuser'], ['.post.read'], ['a.b.c.read']] as $args) {
            try {
                new RequireAuth($auth, $args);
                self::fail('accepted ' . $args[0]);
            } catch (LogicException $e) {
                self::assertStringContainsString('<module>.<component>.<level>', $e->getMessage());
            }
        }
    }
}
```

In `tests/fixtures/auth/probe/src/Routes.php`, replace:

```php
            $r->getMethod() === 'GET' ? '<form method="post">' . $this->view->helpers($r)->csrf() . '</form>' : 'ok ' . $r->getMethod(),
        ), 'probe.form');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```

with:

```php
            $r->getMethod() === 'GET' ? '<form method="post">' . $this->view->helpers($r)->csrf() . '</form>' : 'ok ' . $r->getMethod(),
        ), 'probe.form');
        $whoami = fn(): ResponseInterface => new Response(200, [], (string) $this->auth->user()?->handle);
        $routes->get('/probe/members', $whoami, 'probe.members')->middleware('auth');
        $routes->get('/probe/members.json', $whoami, 'probe.members.json')->middleware('auth');
        $routes->post('/probe/members', $whoami, 'probe.members.post')->middleware('auth');
        $routes->get('/probe/admin', $whoami, 'probe.admin')->middleware('secureHtml', 'auth:probe.secret.admin');
        $routes->get('/probe/public', $whoami, 'probe.public')->middleware('auth:probe.secret.read');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/RequireAuthTest.php`
Expected: FAIL. There is 1 `Class "Xaraya\Kernel\Http\Middleware\RequireAuth" not found` error. The other 5 tests fail with `500 is identical to 302/401/403/200`, because the `auth` alias is unknown.

- [ ] **Step 3: Implement**

In `src/Kernel/App.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
```

with:

```php
use Xaraya\Kernel\Http\Middleware\Cors;
use Xaraya\Kernel\Http\Middleware\ErrorHandler;
use Xaraya\Kernel\Http\Middleware\RequireAuth;
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
```

In `src/Kernel/App.php` (2 of 2), replace:

```php
            $registry->register('conditional', ConditionalGet::class);
            $registry->register('secureHtml', SecureHtml::class);
            $registry->registerMarker('csrf');
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
```

with:

```php
            $registry->register('conditional', ConditionalGet::class);
            $registry->register('secureHtml', SecureHtml::class);
            $registry->register('auth', RequireAuth::class);
            $registry->registerMarker('csrf');
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
```

In `src/Kernel/Http/Middleware/ErrorHandler.php` (1 of 2), replace:

```php
            ? $e::class . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
            : null;
        if ($this->wantsJson($request)) {
            $error = ['status' => $status, 'message' => $message];
            if ($fields !== []) {
```

with:

```php
            ? $e::class . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()
            : null;
        if (self::wantsJson($request)) {
            $error = ['status' => $status, 'message' => $message];
            if ($fields !== []) {
```

In `src/Kernel/Http/Middleware/ErrorHandler.php` (2 of 2), replace:

```php
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = strtolower($request->getUri()->getPath());
```

with:

```php
    }

    /** Whether errors for $request are answered in JSON: .json and /api paths, or an Accept that prefers JSON. */
    public static function wantsJson(ServerRequestInterface $request): bool
    {
        $path = strtolower($request->getUri()->getPath());
```

`src/Kernel/Http/Middleware/RequireAuth.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use LogicException;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Http\Exception\Forbidden;
use Xaraya\Kernel\Http\Exception\Unauthorized;

/**
 * Route middleware "auth" (require a signed-in user) and "auth:<module>.<component>.<level>" (require that level for
 * the component, on any instance; controllers check items themselves). A guest who fails is sent to the login page
 * on an HTML GET, and gets a 401 otherwise (JSON on JSON routes); a signed-in user who fails gets a 403.
 */
final class RequireAuth implements MiddlewareInterface
{
    public const LOGIN_PATH = '/login';

    /** @var array{0: string, 1: string, 2: string}|null module, component, level */
    private readonly ?array $required;

    /** @param list<string> $args */
    public function __construct(private readonly Auth $auth, array $args = [])
    {
        $spec = implode(',', $args);
        $parts = explode('.', $spec);
        if ($spec !== '' && (count($parts) !== 3 || !isset(Access::LEVELS[$parts[2]]) || in_array('', $parts, true))) {
            throw new LogicException("The auth middleware takes <module>.<component>.<level>, not '{$spec}'");
        }
        $this->required = $spec === '' ? null : [$parts[0], $parts[1], $parts[2]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $allowed = $this->required === null
            ? $this->auth->check()
            : $this->auth->can($this->required[2], $this->required[0], $this->required[1]);
        if ($allowed) {
            return $handler->handle($request);
        }
        if ($this->auth->check()) {
            throw new Forbidden();
        }
        if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && !ErrorHandler::wantsJson($request)) {
            $uri = $request->getUri();
            $next = $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());

            return new Response(302, ['Location' => self::LOGIN_PATH . '?next=' . rawurlencode($next), 'Cache-Control' => 'no-store']);
        }
        throw new Unauthorized('Log in to continue');
    }
}
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth`
Expected: PASS (40 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth`.
Expected: all green (736 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Http/Middleware/RequireAuth.php src/Kernel/Http/Middleware/ErrorHandler.php src/Kernel/App.php tests/Kernel/Auth/RequireAuthTest.php tests/fixtures/auth
git commit -m "feat(auth): auth and auth:<module>.<component>.<level> route middleware" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 7: Throttle: the table, `ClientIp` and `throttle:<key>,<max>,<seconds>`

**Files:**
- Create: `src/Kernel/migrations/2026_10_21_000003_create_throttle.php`, `src/Kernel/Auth/Throttle.php`, `src/Kernel/Http/ClientIp.php`, `src/Kernel/Http/Middleware/ThrottleRequests.php`
- Modify: `src/Kernel/App.php` (the `throttle` alias and the `ClientIp` binding), `config/app.php` (`http.trusted_proxies` from `TRUSTED_PROXIES`)
- Test: `tests/Kernel/Auth/ThrottleTest.php` (new). The probe fixture gains `/probe/limited` (`throttle:probe,2,60`).

**Interfaces:**
- **Consumes:** `TooManyRequests(int $retryAfter)` (Plan 1); `Connection::ident()`.
- **Produces:**
  - Table `xar_throttle`: `key` string(64) pk (sha256), `hits` int, `reset_at` datetime (indexed).
  - `Xaraya\Kernel\Auth\Throttle(Connection)`:
    - `retryAfter(string $key, int $max, DateTimeImmutable $now): int` returns 0 while the key is allowed.
    - `hit(string $key, int $decaySeconds, DateTimeImmutable $now): int` returns the hits in the window; 1 in 100 new windows also deletes expired rows.
    - `clear(string $key): void`.
  - `Xaraya\Kernel\Http\ClientIp(list<string> $trustedProxies = [])` with `of(ServerRequestInterface): string`.
  - `Xaraya\Kernel\Http\Middleware\ThrottleRequests(Throttle, ClientIp, array $args = [])`, alias `throttle`. Its key is `route:<key>|<ip>`. A malformed spec throws `LogicException("The throttle middleware takes <key>,<max>,<seconds>, not '…'")`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/ThrottleTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\Auth\Throttle;
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Middleware\ThrottleRequests;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class ThrottleTest extends AuthTestCase
{
    public function testAKeyIsBlockedAfterMaxHitsUntilItsWindowEnds(): void
    {
        $app = $this->installed();
        $throttle = $app->container()->get(Throttle::class);
        $now = new DateTimeImmutable('2026-10-21 12:00:00', new DateTimeZone('UTC'));
        self::assertSame(0, $throttle->retryAfter('k', 3, $now));
        self::assertSame(1, $throttle->hit('k', 900, $now));
        self::assertSame(2, $throttle->hit('k', 900, $now->modify('+1 minute')));
        self::assertSame(0, $throttle->retryAfter('k', 3, $now->modify('+2 minutes')));
        self::assertSame(3, $throttle->hit('k', 900, $now->modify('+2 minutes')));
        self::assertSame(780, $throttle->retryAfter('k', 3, $now->modify('+2 minutes')), 'the window runs from the first hit');
        self::assertSame(0, $throttle->retryAfter('other', 3, $now));

        $later = $now->modify('+15 minutes');
        self::assertSame(0, $throttle->retryAfter('k', 3, $later));
        self::assertSame(1, $throttle->hit('k', 900, $later), 'a new window starts');
        $throttle->clear('k');
        self::assertSame(0, $this->db($app)->select('throttle')->count());
    }

    public function testKeysAreStoredHashed(): void
    {
        $app = $this->installed();
        $app->container()->get(Throttle::class)->hit('login|198.51.100.4|ada', 60, new DateTimeImmutable());
        self::assertSame(hash('sha256', 'login|198.51.100.4|ada'), $this->db($app)->fetchValue('SELECT ' . $this->db($app)->ident('key') . ' FROM {throttle}'));
    }

    public function testTheMiddlewareAnswers429PerClientIp(): void
    {
        $app = $this->probeApp();
        $client = new Browser($app, '198.51.100.4');
        self::assertSame(200, $client->get('/probe/limited')->getStatusCode());
        self::assertSame(200, $client->get('/probe/limited')->getStatusCode());
        $limited = $client->get('/probe/limited');
        self::assertSame(429, $limited->getStatusCode());
        self::assertGreaterThan(0, (int) $limited->getHeaderLine('Retry-After'));
        self::assertLessThanOrEqual(60, (int) $limited->getHeaderLine('Retry-After'));
        self::assertSame(200, (new Browser($app, '198.51.100.5'))->get('/probe/limited')->getStatusCode());

        $spoofed = new Browser($app, '198.51.100.4');
        self::assertSame(429, $spoofed->get('/probe/limited', ['X-Forwarded-For' => '192.0.2.99'])->getStatusCode(), 'X-Forwarded-For is not trusted by default');
    }

    public function testClientIpBelievesXForwardedForOnlyFromTrustedProxies(): void
    {
        $request = static fn(string $remote, string $xff = ''): ServerRequest => new ServerRequest('GET', '/', $xff === '' ? [] : ['X-Forwarded-For' => $xff], null, '1.1', ['REMOTE_ADDR' => $remote]);
        $direct = new ClientIp();
        self::assertSame('203.0.113.1', $direct->of($request('203.0.113.1', '192.0.2.9')));
        self::assertSame('', $direct->of(new ServerRequest('GET', '/')));

        $proxied = new ClientIp(['10.0.0.1', '10.0.0.2']);
        self::assertSame('192.0.2.9', $proxied->of($request('10.0.0.1', '192.0.2.9')));
        self::assertSame('192.0.2.9', $proxied->of($request('10.0.0.1', '6.6.6.6, 192.0.2.9, 10.0.0.2')), 'the client cannot prepend a fake hop');
        self::assertSame('10.0.0.1', $proxied->of($request('10.0.0.1', 'garbage')));
        self::assertSame('203.0.113.1', $proxied->of($request('203.0.113.1', '192.0.2.9')));
    }

    public function testAMalformedSpecIsAProgrammingError(): void
    {
        $app = $this->installed();
        foreach ([[], ['x'], ['x', '5'], ['x', 'five', '60'], ['x', '0', '60'], ['Bad Key', '5', '60']] as $args) {
            try {
                new ThrottleRequests($app->container()->get(Throttle::class), new ClientIp(), $args);
                self::fail('accepted ' . implode(',', $args));
            } catch (LogicException $e) {
                self::assertStringContainsString('<key>,<max>,<seconds>', $e->getMessage());
            }
        }
    }
}
```

In `tests/fixtures/auth/probe/src/Routes.php`, replace:

```php
        $routes->get('/probe/admin', $whoami, 'probe.admin')->middleware('secureHtml', 'auth:probe.secret.admin');
        $routes->get('/probe/public', $whoami, 'probe.public')->middleware('auth:probe.secret.read');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```

with:

```php
        $routes->get('/probe/admin', $whoami, 'probe.admin')->middleware('secureHtml', 'auth:probe.secret.admin');
        $routes->get('/probe/public', $whoami, 'probe.public')->middleware('auth:probe.secret.read');
        $routes->get('/probe/limited', static fn(): ResponseInterface => new Response(200, [], 'ok'), 'probe.limited')->middleware('throttle:probe,2,60');
        $routes->get('/probe/plain', static fn(): ResponseInterface => new Response(200, [], 'plain'), 'probe.plain');
        $routes->get('/probe/count', static function (ServerRequestInterface $r): ResponseInterface {
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/ThrottleTest.php`
Expected: FAIL. There are 4 errors (`Cannot resolve 'Xaraya\Kernel\Auth\Throttle'`, and `Class "Xaraya\Kernel\Http\ClientIp" not found`). The middleware test fails with `500 is identical to 200`.

- [ ] **Step 3: Implement**

`hit()` first runs a conditional `UPDATE … SET hits = hits + 1 WHERE key = ? AND reset_at > ?`. When no row matched, it upserts a fresh window with `hits = 1`.

In `config/app.php`, replace:

```php
        'gc_probability' => 0.01,
    ],
    'log' => [
        'path' => $root . '/var/logs',
```

with:

```php
        'gc_probability' => 0.01,
    ],
    'http' => [
        // Reverse proxies (exact IPs, comma-separated) whose X-Forwarded-For is believed. Empty: use REMOTE_ADDR only.
        'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),
    ],
    'log' => [
        'path' => $root . '/var/logs',
```

In `src/Kernel/App.php` (1 of 4), replace:

```php
use Xaraya\Kernel\Hooks\HookBindings;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\HomeController;
```

with:

```php
use Xaraya\Kernel\Hooks\HookBindings;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Emitter;
use Xaraya\Kernel\Http\HomeController;
```

In `src/Kernel/App.php` (2 of 4), replace:

```php
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
use Xaraya\Kernel\Http\Middleware\VerifyCsrf;
use Xaraya\Kernel\Http\MiddlewareRegistry;
```

with:

```php
use Xaraya\Kernel\Http\Middleware\SecureHtml;
use Xaraya\Kernel\Http\Middleware\SessionMiddleware;
use Xaraya\Kernel\Http\Middleware\ThrottleRequests;
use Xaraya\Kernel\Http\Middleware\VerifyCsrf;
use Xaraya\Kernel\Http\MiddlewareRegistry;
```

In `src/Kernel/App.php` (3 of 4), replace:

```php
            (array) $config->get('auth.password_options', []),
        ));
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
```

with:

```php
            (array) $config->get('auth.password_options', []),
        ));
        $c->set(ClientIp::class, fn(): ClientIp => new ClientIp(array_values(array_filter((array) $config->get('http.trusted_proxies', []), 'is_string'))));
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
```

In `src/Kernel/App.php` (4 of 4), replace:

```php
            $registry->register('secureHtml', SecureHtml::class);
            $registry->register('auth', RequireAuth::class);
            $registry->registerMarker('csrf');
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
```

with:

```php
            $registry->register('secureHtml', SecureHtml::class);
            $registry->register('auth', RequireAuth::class);
            $registry->register('throttle', ThrottleRequests::class);
            $registry->registerMarker('csrf');
            foreach ($c->get(ModuleRegistry::class)->enabled() as $manifest) {
```

`src/Kernel/Auth/Throttle.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use Xaraya\Kernel\Db\Connection;

/**
 * Fixed-window counters in xar_throttle: a key's window opens at its first hit and lasts $decaySeconds. Keys are
 * stored as their sha256. Login failures and the `throttle` route middleware use it.
 */
final class Throttle
{
    public function __construct(private readonly Connection $db) {}

    /** Seconds until $key may be tried again once it has $max hits in its window; 0 while it is allowed. */
    public function retryAfter(string $key, int $max, DateTimeImmutable $now): int
    {
        $row = $this->db->select('throttle')->where('key', '=', hash('sha256', $key))->where('reset_at', '>', $now)->first();
        if ($row === null || (int) $row['hits'] < $max) {
            return 0;
        }

        return max(1, (new DateTimeImmutable((string) $row['reset_at'], $now->getTimezone()))->getTimestamp() - $now->getTimestamp());
    }

    /** Counts a hit and returns the hits in the current window. */
    public function hit(string $key, int $decaySeconds, DateTimeImmutable $now): int
    {
        $id = hash('sha256', $key);
        $column = $this->db->ident('key');
        if ($this->db->query("UPDATE {throttle} SET hits = hits + 1 WHERE {$column} = ? AND reset_at > ?", [$id, $now])->rowCount() > 0) {
            return (int) $this->db->fetchValue("SELECT hits FROM {throttle} WHERE {$column} = ?", [$id]);
        }
        if (random_int(1, 100) === 1) {
            $this->db->query('DELETE FROM {throttle} WHERE reset_at <= ?', [$now]);
        }
        $this->db->upsert('throttle', ['key' => $id, 'hits' => 1, 'reset_at' => $now->modify("+{$decaySeconds} seconds")], ['key']);

        return 1;
    }

    public function clear(string $key): void
    {
        $this->db->delete('throttle', ['key' => hash('sha256', $key)]);
    }
}
```

`src/Kernel/Http/ClientIp.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The client's IP address: REMOTE_ADDR. Only when REMOTE_ADDR is one of http.trusted_proxies is X-Forwarded-For
 * read, right to left, and the first address that is not a trusted proxy wins.
 */
final class ClientIp
{
    /** @param list<string> $trustedProxies exact addresses of reverse proxies */
    public function __construct(private readonly array $trustedProxies = []) {}

    public function of(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $remote = is_string($remote) ? $remote : '';
        if (!in_array($remote, $this->trustedProxies, true)) {
            return $remote;
        }
        foreach (array_reverse(explode(',', $request->getHeaderLine('X-Forwarded-For'))) as $hop) {
            $hop = trim($hop);
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!in_array($hop, $this->trustedProxies, true)) {
                return $hop;
            }
        }

        return $remote;
    }
}
```

`src/Kernel/Http/Middleware/ThrottleRequests.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Http\Middleware;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Xaraya\Kernel\Auth\Throttle;
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Exception\TooManyRequests;

/** Route middleware "throttle:<key>,<max>,<seconds>": at most <max> requests per client IP in <seconds>, else 429. */
final class ThrottleRequests implements MiddlewareInterface
{
    private readonly string $key;
    private readonly int $max;
    private readonly int $seconds;

    /** @param list<string> $args */
    public function __construct(private readonly Throttle $throttle, private readonly ClientIp $ip, array $args = [])
    {
        if (count($args) !== 3 || preg_match('/^[a-z][a-z0-9_.-]*$/D', $args[0]) !== 1 || !ctype_digit($args[1]) || !ctype_digit($args[2]) || (int) $args[1] < 1 || (int) $args[2] < 1) {
            throw new LogicException("The throttle middleware takes <key>,<max>,<seconds>, not '" . implode(',', $args) . "'");
        }
        [$this->key, $this->max, $this->seconds] = [$args[0], (int) $args[1], (int) $args[2]];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $key = 'route:' . $this->key . '|' . $this->ip->of($request);
        $retryAfter = $this->throttle->retryAfter($key, $this->max, $now);
        if ($retryAfter > 0) {
            throw new TooManyRequests($retryAfter);
        }
        $this->throttle->hit($key, $this->seconds, $now);

        return $handler->handle($request);
    }
}
```

`src/Kernel/migrations/2026_10_21_000003_create_throttle.php`:

```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Rate-limit counters; "key" is the sha256 of the throttle key (which holds IP addresses). */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('throttle', function (Blueprint $t): void {
            $t->string('key', 64)->primary();
            $t->int('hits');
            $t->datetime('reset_at');
            $t->index('reset_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('throttle');
    }
};
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth`
Expected: PASS (45 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth`.
Expected: all green (741 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/migrations/2026_10_21_000003_create_throttle.php src/Kernel/Auth/Throttle.php src/Kernel/Http/ClientIp.php src/Kernel/Http/Middleware/ThrottleRequests.php src/Kernel/App.php config/app.php tests/Kernel/Auth/ThrottleTest.php tests/fixtures/auth
git commit -m "feat(auth): throttle table, ClientIp and the throttle:<key>,<max>,<seconds> middleware" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 8: Login and logout pages

**Files:**
- Create: `src/Kernel/Auth/Http/AuthRoutes.php`, `src/Kernel/Auth/Http/LoginController.php`, `themes/phoenix/templates/auth/login.php`, `themes/phoenix/templates/auth/login.twig`
- Modify: `src/Kernel/App.php` (`routes()` adds `AuthRoutes` after the modules), `themes/phoenix/assets/phoenix.css` (form styles)
- Test: `tests/Kernel/Auth/LoginTest.php` (new). `tests/Support/Browser.php` gains `login()`. `tests/Kernel/View/PhoenixThemeTest.php` lists `auth/login`.

**Interfaces:**
- **Consumes:**
  - `UserRepository::{findByLogin, verifyPassword, verifyNothing, recordLogin}` (Task 1).
  - `Session::{login, logout}` (Task 3).
  - `Throttle` and `ClientIp` (Task 7).
  - `VerifyCsrf` (Task 5) protects both POSTs.
- **Produces:**
  - Routes: `auth.login` (GET `/login`), `auth.login.submit` (POST `/login`) and `auth.logout` (POST `/logout`), all `secureHtml`.
  - `Xaraya\Kernel\Auth\Http\LoginController(UserRepository, Throttle, ClientIp)`:
    - Constants `MAX_FAILURES = 5`, `WINDOW = 900`, `FAILED` and `LOCKED` (the message texts).
    - Actions `show(): Page`, `login(): ResponseInterface|Page` and `logout(): ResponseInterface`.
    - `static safeNext(mixed): string` and `static session(ServerRequestInterface): Session`.
    - The throttle key is `login|<ip>|<user id or lower-cased login>`.
  - The theme template `auth/login` takes `next`, `login`, `error` (a message key), `minutes` and `notice`. `GET /login?reset=1` shows the notice "Your password has been changed. Log in with the new one."
  - `Browser::login(string $login, string $password, string $next = ''): ResponseInterface` opens `/login` and posts its form.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/LoginTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use Xaraya\Kernel\Auth\Http\LoginController;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

final class LoginTest extends AuthTestCase
{
    private static function whoami(Browser $browser): ?string
    {
        return json_decode((string) $browser->get('/probe/whoami')->getBody(), true)['user'];
    }

    private static function page(Browser $browser): string
    {
        return Html::normalize((string) preg_replace('/value="[A-Za-z0-9_-]{43}"/', 'value="TOKEN"', $browser->body()));
    }

    public function testTheLoginPageIsTheSameAccessibleFormInBothEngines(): void
    {
        $pages = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = new Browser($this->installed(['view.engine' => $engine]));
            $response = $browser->get('/login?next=/hello');
            self::assertSame(200, $response->getStatusCode());
            self::assertStringContainsString("form-action 'self'", $response->getHeaderLine('Content-Security-Policy'));
            self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
            $pages[$engine] = self::page($browser);
        }
        self::assertSame($pages['php'], $pages['twig']);
        $html = $pages['php'];
        self::assertStringContainsString('<title>Log in</title>', $html);
        self::assertStringContainsString('<form class="auth-form" method="post" action="/login"><input type="hidden" name="_csrf" value="TOKEN"><input type="hidden" name="next" value="/hello">', $html);
        self::assertStringContainsString('<label for="login">Handle or email</label><input id="login" name="login" value="" autocomplete="username" autocapitalize="none" spellcheck="false" required>', $html);
        self::assertStringContainsString('<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>', $html);
    }

    public function testAUserLogsInAndOut(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada', ['Administrators']);
        $browser = new Browser($app);
        $browser->get('/login');
        $anonymous = $browser->cookies['xar_session'];

        $response = $browser->login('ada', self::PASSWORD);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
        self::assertNotSame($anonymous, $browser->cookies['xar_session'], 'login gives the session a new id');
        self::assertSame('ada', self::whoami($browser));
        self::assertNotNull($this->users($app)->find($ada->id)?->lastLogin);

        $planted = new Browser($app);
        $planted->cookies['xar_session'] = $anonymous;
        self::assertNull(self::whoami($planted), 'the pre-login id is dead');

        $browser->get('/login');
        $out = $browser->post('/logout');
        self::assertSame(303, $out->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $out->getHeaderLine('Set-Cookie'));
        self::assertNull(self::whoami($browser));
    }

    public function testLoginAndLogoutNeedTheCsrfToken(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $browser = new Browser($app);
        self::assertSame(403, $browser->post('/login', ['login' => 'ada', 'password' => self::PASSWORD])->getStatusCode());
        $other = new Browser($app);
        $other->get('/login');
        $browser->get('/login');
        $foreign = ['login' => 'ada', 'password' => self::PASSWORD, '_csrf' => (string) $other->csrfField()];
        self::assertSame(403, $browser->post('/login', $foreign)->getStatusCode(), 'a token from another session is refused');
        self::assertNull(self::whoami($browser));

        $browser->login('ada', self::PASSWORD);
        self::assertSame(403, $browser->post('/logout', ['_csrf' => 'x'])->getStatusCode());
        self::assertSame('ada', self::whoami($browser));
    }

    public function testEveryFailureShowsTheSameMessage(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $this->user($app, 'dee', [], 'disabled');
        foreach ([['ada', 'wrong password'], ['nobody', self::PASSWORD], ['dee', self::PASSWORD], ['', '']] as [$login, $password]) {
            $browser = new Browser($app);
            $response = $browser->login($login, $password);
            self::assertSame(422, $response->getStatusCode(), $login);
            self::assertStringContainsString('<p class="form-error" id="login-error" role="alert">The handle, email or password is wrong.</p>', Html::normalize($browser->body()));
            self::assertStringContainsString('aria-invalid="true" aria-describedby="login-error"', $browser->body());
            self::assertNull(self::whoami($browser));
        }
        $browser = new Browser($app);
        $browser->login('<script>alert(1)</script>', 'x');
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $browser->body());
    }

    public function testFiveFailuresLockAnAccountForAnIpWhetherItIsNamedByHandleOrEmail(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $attacker = new Browser($app, '198.51.100.9');
        foreach (['ada', 'ADA@example.com', 'ada', 'ada@example.com', 'Ada'] as $login) {
            self::assertSame(422, $attacker->login($login, 'guess')->getStatusCode());
        }
        $locked = $attacker->login('ada@example.com', self::PASSWORD);
        self::assertSame(429, $locked->getStatusCode());
        self::assertGreaterThan(840, (int) $locked->getHeaderLine('Retry-After'));
        self::assertStringContainsString('Too many failed attempts. Try again in 15 minutes.', $attacker->body());
        self::assertNull(self::whoami($attacker));

        $elsewhere = new Browser($app, '198.51.100.10');
        self::assertSame(303, $elsewhere->login('ada', self::PASSWORD)->getStatusCode(), 'another IP is not locked out');

        $this->db($app)->query('UPDATE {throttle} SET reset_at = ?', [new DateTimeImmutable('-1 second')]);
        self::assertSame(303, $attacker->login('ada', self::PASSWORD)->getStatusCode(), 'the lock ends with its window');
        self::assertSame(0, $this->db($app)->select('throttle')->where('reset_at', '>', new DateTimeImmutable())->count(), 'a success clears the counter');
    }

    public function testAnUnknownLoginCostsAPasswordCheckLikeAWrongPassword(): void
    {
        $app = $this->probeApp(['auth.password_options' => ['cost' => 8]]);
        $this->users($app)->create('ada', 'ada@example.com', self::PASSWORD);
        $time = function (string $login, int $i) use ($app): float {
            $browser = new Browser($app, "198.51.100.{$i}");
            $browser->get('/login');
            $start = hrtime(true);
            $browser->post('/login', ['login' => $login, 'password' => 'wrong password']);

            return (hrtime(true) - $start) / 1e6;
        };
        $wrong = min($time('ada', 1), $time('ada', 2), $time('ada', 3));
        $unknown = min($time('nobody', 4), $time('nobody', 5), $time('nobody', 6));
        self::assertGreaterThan($wrong * 0.25, $unknown, "unknown {$unknown} ms vs wrong password {$wrong} ms");
    }

    public function testNextOnlyLeadsToALocalPath(): void
    {
        $app = $this->probeApp();
        $this->user($app, 'ada');
        $cases = [
            '/blog/notes?page=2' => '/blog/notes?page=2',
            '//evil.example/' => '/',
            '/\\evil.example' => '/',
            'https://evil.example/' => '/',
            'javascript:alert(1)' => '/',
            "/\tevil" => '/',
            '' => '/',
        ];
        foreach ($cases as $next => $expected) {
            self::assertSame($expected, LoginController::safeNext($next), $next);
            $browser = new Browser($app);
            self::assertSame($expected, $browser->login('ada', self::PASSWORD, $next)->getHeaderLine('Location'), $next);
        }
        self::assertSame('/', LoginController::safeNext(['/x']));
    }

    public function testLoginUpgradesAnOutdatedPasswordHash(): void
    {
        $app = $this->probeApp();
        $ada = $this->user($app, 'ada');
        $stronger = $this->app(['auth.password_options' => ['cost' => 5], 'session.gc_probability' => 0], [self::PROBE]);
        (new Browser($stronger))->login('ada', self::PASSWORD);
        self::assertStringStartsWith('$2y$05$', (string) $this->db($app)->fetchValue('SELECT password_hash FROM {users} WHERE id = ?', [$ada->id]));
    }

    public function testTheNoticeAfterAPasswordReset(): void
    {
        $browser = new Browser($this->installed());
        $browser->get('/login?reset=1');
        self::assertStringContainsString('<p class="form-notice" role="status">Your password has been changed. Log in with the new one.</p>', Html::normalize($browser->body()));
    }
}
```

In `tests/Kernel/View/PhoenixThemeTest.php`, replace:

```php
        sort($names['php']);
        sort($names['twig']);
        self::assertSame(['block', 'error/404', 'error/default', 'home', 'layout'], $names['php']);
        self::assertSame($names['php'], $names['twig']);
    }
```

with:

```php
        sort($names['php']);
        sort($names['twig']);
        self::assertSame(['auth/login', 'block', 'error/404', 'error/default', 'home', 'layout'], $names['php']);
        self::assertSame($names['php'], $names['twig']);
    }
```

In `tests/Support/Browser.php`, replace:

```php
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = [], string $body = ''): ServerRequestInterface
```

with:

```php
    }

    /** Opens the login page, then posts the form (with its CSRF token and the "next" it carries). */
    public function login(string $login, string $password, string $next = ''): ResponseInterface
    {
        $this->get('/login' . ($next === '' ? '' : '?next=' . rawurlencode($next)));
        $field = preg_match('/name="next" value="([^"]*)"/', $this->body(), $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : '/';

        return $this->post('/login', ['login' => $login, 'password' => $password, 'next' => $field]);
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, array $headers = [], string $body = ''): ServerRequestInterface
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/LoginTest.php tests/Kernel/View/PhoenixThemeTest.php`
Expected: FAIL. `Class "Xaraya\Kernel\Auth\Http\LoginController" not found`, then `404 is identical to 200/303/403/422` for the page tests, and `PhoenixThemeTest::testEveryTemplateShipsInBothEngines` (the `auth/login` template is missing).

- [ ] **Step 3: Implement**

The order inside `login()` matters:
1. Look up the user and build the throttle key.
2. Refuse a locked key (429) **before** checking the password.
3. Burn a verification for an unknown login.
4. Count every failure, including a right password on an inactive account.
5. On success, clear the key, call `Session::login()` (which gives a new id), record the login, and answer 303 to the safe `next`.

In `src/Kernel/App.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Blocks\BlockRenderer;
```

with:

```php
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\Http\AuthRoutes;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Blocks\BlockRenderer;
```

In `src/Kernel/App.php` (2 of 2), replace:

```php
            $provider->routes($collector);
        }

        $claimed = false;
```

with:

```php
            $provider->routes($collector);
        }
        (new AuthRoutes())->routes($collector);

        $claimed = false;
```

`src/Kernel/Auth/Http/AuthRoutes.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth\Http;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;

/** The kernel's sign-in pages (App::routes() adds them after the modules' routes). */
final class AuthRoutes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
        $routes->get('/login', [LoginController::class, 'show'], 'auth.login')->middleware('secureHtml');
        $routes->post('/login', [LoginController::class, 'login'], 'auth.login.submit')->middleware('secureHtml');
        $routes->post('/logout', [LoginController::class, 'logout'], 'auth.logout')->middleware('secureHtml');
    }
}
```

`src/Kernel/Auth/Http/LoginController.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth\Http;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\Throttle;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Http\ClientIp;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\View\Page;

/**
 * GET/POST /login and POST /logout. Failures are counted per client IP and account (the user id when the login
 * names a user, so a handle and an email share one counter): 5 in 15 minutes lock that pair out. Every failure
 * shows the same message, and an unknown login costs one password check, like a wrong password.
 */
final class LoginController extends Controller
{
    public const MAX_FAILURES = 5;
    public const WINDOW = 900;
    public const FAILED = 'The handle, email or password is wrong.';
    public const LOCKED = 'Too many failed attempts. Try again in {minutes} minutes.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly Throttle $throttle,
        private readonly ClientIp $ip,
    ) {}

    public function show(ServerRequestInterface $request): Page
    {
        parse_str($request->getUri()->getQuery(), $query);
        $notice = ($query['reset'] ?? null) === '1' ? 'Your password has been changed. Log in with the new one.' : null;

        return $this->form(self::safeNext($query['next'] ?? null), '', notice: $notice);
    }

    public function login(ServerRequestInterface $request): ResponseInterface|Page
    {
        $body = (array) $request->getParsedBody();
        $login = is_string($body['login'] ?? null) ? trim($body['login']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $next = self::safeNext($body['next'] ?? null);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $user = $this->users->findByLogin($login);
        $key = 'login|' . $this->ip->of($request) . '|' . ($user->id ?? mb_strtolower($login));
        $retryAfter = $this->throttle->retryAfter($key, self::MAX_FAILURES, $now);
        if ($retryAfter > 0) {
            return $this->form($next, $login, self::LOCKED, (int) ceil($retryAfter / 60), 429, ['Retry-After' => (string) $retryAfter]);
        }
        if ($user === null) {
            $this->users->verifyNothing($password);
        }
        if ($user === null || !$this->users->verifyPassword($user, $password) || !$user->isActive()) {
            $this->throttle->hit($key, self::WINDOW, $now);

            return $this->form($next, $login, self::FAILED, status: 422);
        }
        $this->throttle->clear($key);
        self::session($request)->login($user->id);
        $this->users->recordLogin($user, $now);

        return $this->redirect($next, 303);
    }

    public function logout(ServerRequestInterface $request): ResponseInterface
    {
        self::session($request)->logout();

        return $this->redirect('/', 303);
    }

    /** $next when it is a local path ("/…", not "//…", no backslash or control character), else "/". */
    public static function safeNext(mixed $next): string
    {
        return is_string($next) && strlen($next) <= 2000 && preg_match('#^/(?![/\\\\])[^\\\\\x00-\x1f\x7f]*$#D', $next) === 1 ? $next : '/';
    }

    /** @param array<string, string> $headers */
    private function form(string $next, string $login, ?string $error = null, int $minutes = 0, int $status = 200, array $headers = [], ?string $notice = null): Page
    {
        return new Page('auth/login', [
            'next' => $next,
            'login' => $login,
            'error' => $error,
            'minutes' => $minutes,
            'notice' => $notice,
        ], 'Log in', status: $status, headers: $headers);
    }

    public static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);

        return $session instanceof Session ? $session : throw new LogicException('The session middleware did not run');
    }
}
```

In `themes/phoenix/assets/phoenix.css`, replace:

```css
[aria-current="page"] { font-weight: 700; }

img, video, audio { max-width: 100%; height: auto; }
pre, code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
```

with:

```css
[aria-current="page"] { font-weight: 700; }

.auth-form label { display: block; font-weight: 600; }
.auth-form input { width: 100%; max-width: 24rem; padding: 0.5rem; font: inherit; color: inherit; background: var(--bg); border: 1px solid var(--muted); }
.auth-form button, .logout-form button { padding: 0.5rem 1rem; font: inherit; }
.form-error { padding: 0.5rem 1rem; border-left: 4px solid #c5221f; }
.form-notice { padding: 0.5rem 1rem; border-left: 4px solid var(--accent); }

img, video, audio { max-width: 100%; height: auto; }
pre, code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
```

`themes/phoenix/templates/auth/login.php`:

```php
<h1><?= $x->e($x->t('Log in')) ?></h1>
<?php if ($notice): ?>
<p class="form-notice" role="status"><?= $x->e($x->t($notice)) ?></p>
<?php endif ?>
<?php if ($error): ?>
<p class="form-error" id="login-error" role="alert"><?= $x->e($x->t($error, ['minutes' => $minutes])) ?></p>
<?php endif ?>
<form class="auth-form" method="post" action="<?= $x->e($x->url('auth.login.submit')) ?>">
<?= $x->csrf() ?>
<input type="hidden" name="next" value="<?= $x->e($next) ?>">
<p>
<label for="login"><?= $x->e($x->t('Handle or email')) ?></label>
<input id="login" name="login" value="<?= $x->e($login) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required<?php if ($error): ?> aria-invalid="true" aria-describedby="login-error"<?php endif ?>>
</p>
<p>
<label for="password"><?= $x->e($x->t('Password')) ?></label>
<input id="password" name="password" type="password" autocomplete="current-password" required>
</p>
<p><button type="submit"><?= $x->e($x->t('Log in')) ?></button></p>
</form>
```

`themes/phoenix/templates/auth/login.twig`:

```twig
<h1>{{ t('Log in') }}</h1>
{% if notice %}
<p class="form-notice" role="status">{{ t(notice) }}</p>
{% endif %}
{% if error %}
<p class="form-error" id="login-error" role="alert">{{ t(error, {minutes: minutes}) }}</p>
{% endif %}
<form class="auth-form" method="post" action="{{ url('auth.login.submit') }}">
{{ csrf() }}
<input type="hidden" name="next" value="{{ next }}">
<p>
<label for="login">{{ t('Handle or email') }}</label>
<input id="login" name="login" value="{{ login }}" autocomplete="username" autocapitalize="none" spellcheck="false" required{% if error %} aria-invalid="true" aria-describedby="login-error"{% endif %}>
</p>
<p>
<label for="password">{{ t('Password') }}</label>
<input id="password" name="password" type="password" autocomplete="current-password" required>
</p>
<p><button type="submit">{{ t('Log in') }}</button></p>
</form>
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth/LoginTest.php tests/Kernel/View`
Expected: PASS. `testAnUnknownLoginCostsAPasswordCheckLikeAWrongPassword` uses bcrypt cost 8 and asserts that an unknown login takes more than a quarter of a wrong password's time. Without `verifyNothing()` it measured about 2 ms against 47 ms.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth tests/Kernel/View`.
Expected: all green (750 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Auth/Http src/Kernel/App.php themes/phoenix tests/Kernel/Auth/LoginTest.php tests/Kernel/View/PhoenixThemeTest.php tests/Support/Browser.php
git commit -m "feat(auth): login and logout pages with throttling, in both engines" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 9: The `Mailer` (log, `mail()` and memory drivers)

**Files:**
- Create: `src/Kernel/Mail/Message.php`, `src/Kernel/Mail/Mailer.php`, `src/Kernel/Mail/LogMailer.php`, `src/Kernel/Mail/MailFunctionMailer.php`, `src/Kernel/Mail/MemoryMailer.php`
- Modify: `src/Kernel/App.php` (the `Mailer` binding), `config/app.php` (`mail.driver`, `mail.from` and `mail.path`)
- Test: `tests/Kernel/Mail/MailerTest.php`

**Interfaces:**
- **Consumes:** `Config`.
- **Produces:**
  - `Xaraya\Kernel\Mail\Message(string $to, string $subject, string $text)`. It throws `InvalidArgumentException` for a bad recipient or a subject with CR or LF.
  - `interface Mailer { send(Message): void }`, which throws `RuntimeException` on failure.
  - `LogMailer(string $dir, string $from)`, `MailFunctionMailer(string $from, ?Closure $mail = null)` and `MemoryMailer` (public `list<Message> $sent`).
  - `App` binds `Mailer` from `mail.driver` (`log`, `mail` or `memory`; anything else is a `LogicException`). The default directory is `var/mail`.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/Mail/MailerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Mail;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Xaraya\Kernel\Mail\LogMailer;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MailFunctionMailer;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Kernel\Mail\Message;
use Xaraya\Tests\Support\AppTestCase;

final class MailerTest extends AppTestCase
{
    public function testAMessageCannotSmuggleHeaders(): void
    {
        foreach ([['not-an-address', 'Hi'], ["a@example.com\r\nBcc: x@example.com", 'Hi'], ['a@example.com', "Hi\r\nBcc: x@example.com"], ['a@example.com', "Hi\nthere"]] as [$to, $subject]) {
            try {
                new Message($to, $subject, 'body');
                self::fail('accepted ' . json_encode([$to, $subject]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTheLogDriverWritesAPrivateEmlFile(): void
    {
        $dir = $this->tmp . '/mail';
        (new LogMailer($dir, 'site@example.com'))->send(new Message('ada@example.com', 'Réinitialiser', "Line one\nhttps://xar.test/x"));
        $files = glob($dir . '/*.eml') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^\d{8}-\d{6}-[0-9a-f]{8}\.eml$/', basename($files[0]));
        self::assertSame('0600', substr(sprintf('%o', fileperms($files[0])), -4));
        $eml = (string) file_get_contents($files[0]);
        self::assertStringStartsWith("From: site@example.com\nTo: ada@example.com\nSubject: Réinitialiser\nDate: ", $eml);
        self::assertStringEndsWith("Content-Type: text/plain; charset=utf-8\n\nLine one\nhttps://xar.test/x\n", $eml);
    }

    public function testTheMailDriverHandsAnEncodedMessageToMail(): void
    {
        $calls = [];
        $mailer = new MailFunctionMailer('site@example.com', static function (string $to, string $subject, string $body, array $headers) use (&$calls): bool {
            $calls[] = [$to, $subject, $body, $headers];

            return true;
        });
        $mailer->send(new Message('ada@example.com', 'Réinitialiser', "one\ntwo"));
        self::assertSame([[
            'ada@example.com',
            mb_encode_mimeheader('Réinitialiser', 'UTF-8', 'Q'),
            "one\r\ntwo",
            ['From' => 'site@example.com', 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=utf-8', 'Content-Transfer-Encoding' => '8bit'],
        ]], $calls);

        $this->expectException(RuntimeException::class);
        (new MailFunctionMailer('site@example.com', static fn(): bool => false))->send(new Message('ada@example.com', 'x', 'y'));
    }

    public function testTheDriverComesFromConfig(): void
    {
        self::assertInstanceOf(LogMailer::class, $this->boot()->container()->get(Mailer::class));
        self::assertInstanceOf(MailFunctionMailer::class, $this->boot([], ['mail.driver' => 'mail', 'mail.from' => 'site@example.com'])->container()->get(Mailer::class));
        $memory = $this->boot([], ['mail.driver' => 'memory'])->container()->get(Mailer::class);
        self::assertInstanceOf(MemoryMailer::class, $memory);
        $memory->send(new Message('ada@example.com', 'x', 'y'));
        self::assertCount(1, $memory->sent);
        $this->expectException(LogicException::class);
        $this->boot([], ['mail.driver' => 'smtp'])->container()->get(Mailer::class);
    }
}
```


- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/Mail`
Expected: FAIL (4 errors: `Class "Xaraya\Kernel\Mail\Message" not found` and the like).

- [ ] **Step 3: Implement**

In `config/app.php`, replace:

```php
        'url' => env('ASSET_URL', '/assets'),
    ],
    'session' => [
        'cookie' => env('SESSION_COOKIE', 'xar_session'),
```

with:

```php
        'url' => env('ASSET_URL', '/assets'),
    ],
    'mail' => [
        // "log" writes each message to mail.path as an .eml file; "mail" sends with PHP's mail().
        'driver' => env('MAIL_DRIVER', 'log'),
        'from' => env('MAIL_FROM', 'phoenix@localhost'),
        'path' => $root . '/var/mail',
    ],
    'session' => [
        'cookie' => env('SESSION_COOKIE', 'xar_session'),
```

In `src/Kernel/App.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Log\FileLogger;
use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;
```

with:

```php
use Xaraya\Kernel\Http\RouteHandler;
use Xaraya\Kernel\Log\FileLogger;
use Xaraya\Kernel\Mail\LogMailer;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MailFunctionMailer;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Kernel\Module\Manifest;
use Xaraya\Kernel\Module\ModuleException;
```

In `src/Kernel/App.php` (2 of 2), replace:

```php
        ));
        $c->set(ClientIp::class, fn(): ClientIp => new ClientIp(array_values(array_filter((array) $config->get('http.trusted_proxies', []), 'is_string'))));
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
```

with:

```php
        ));
        $c->set(ClientIp::class, fn(): ClientIp => new ClientIp(array_values(array_filter((array) $config->get('http.trusted_proxies', []), 'is_string'))));
        $c->set(Mailer::class, function () use ($config): Mailer {
            $from = (string) $config->get('mail.from', 'phoenix@localhost');

            return match ($config->get('mail.driver', 'log')) {
                'log' => new LogMailer($this->path((string) $config->get('mail.path', 'var/mail')), $from),
                'mail' => new MailFunctionMailer($from),
                'memory' => new MemoryMailer(),
                default => throw new LogicException('mail.driver must be "log", "mail" or "memory"'),
            };
        });
        $c->set(EventDispatcher::class, fn(Container $c): EventDispatcher => new EventDispatcher($c));
        $c->set(MiddlewareRegistry::class, function (Container $c): MiddlewareRegistry {
```

`src/Kernel/Mail/LogMailer.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use DateTimeImmutable;
use RuntimeException;

/** The "log" driver: writes each message to <mail.path>/<UTC time>-<random>.eml (mode 0600) instead of sending it. */
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $dir, private readonly string $from) {}

    public function send(Message $message): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create the mail directory {$this->dir}");
        }
        $file = $this->dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.eml';
        $content = "From: {$this->from}\nTo: {$message->to}\nSubject: {$message->subject}\n"
            . 'Date: ' . (new DateTimeImmutable())->format(DATE_RFC2822) . "\nContent-Type: text/plain; charset=utf-8\n\n"
            . $message->text . "\n";
        if (file_put_contents($file, $content) !== strlen($content) || !chmod($file, 0600)) {
            throw new RuntimeException("Cannot write the message to {$file}");
        }
    }
}
```

`src/Kernel/Mail/MailFunctionMailer.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/** The "mail" driver: PHP's mail(), so the server's sendmail or MTA delivers. */
final class MailFunctionMailer implements Mailer
{
    /** @param (Closure(string, string, string, array<string, string>): bool)|null $mail replaces mail() in tests */
    public function __construct(private readonly string $from, private readonly ?Closure $mail = null)
    {
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('mail.from must be an email address');
        }
    }

    public function send(Message $message): void
    {
        $headers = [
            'From' => $this->from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        $subject = mb_encode_mimeheader($message->subject, 'UTF-8', 'Q');
        $body = str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message->text);
        $sent = $this->mail !== null ? ($this->mail)($message->to, $subject, $body, $headers) : mail($message->to, $subject, $body, $headers);
        if (!$sent) {
            throw new RuntimeException('mail() did not accept the message');
        }
    }
}
```

`src/Kernel/Mail/Mailer.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

/** Sends mail (config mail.driver: "log" by default, or "mail" for PHP's mail()). */
interface Mailer
{
    /** @throws \RuntimeException when the message could not be handed over */
    public function send(Message $message): void;
}
```

`src/Kernel/Mail/MemoryMailer.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

/** The "memory" driver, for tests: keeps every message in $sent. */
final class MemoryMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];

    public function send(Message $message): void
    {
        $this->sent[] = $message;
    }
}
```

`src/Kernel/Mail/Message.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Mail;

use InvalidArgumentException;

/** A plain-text email to one address. The recipient and subject can never carry extra headers. */
final class Message
{
    public function __construct(public readonly string $to, public readonly string $subject, public readonly string $text)
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('A message needs a valid recipient address');
        }
        if (preg_match('/[\r\n]/', $subject) === 1) {
            throw new InvalidArgumentException('A subject is one line');
        }
    }
}
```


- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Kernel/Mail`
Expected: PASS (4 tests).

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (754 tests). The mail tests touch no database, so no MySQL run is needed.

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Mail src/Kernel/App.php config/app.php tests/Kernel/Mail
git commit -m "feat(mail): Mailer with log, mail() and memory drivers" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 10: Password reset

**Files:**
- Create: `src/Kernel/migrations/2026_10_21_000004_create_password_resets.php`, `src/Kernel/Auth/PasswordResets.php`, `src/Kernel/Auth/Http/PasswordController.php`, `themes/phoenix/templates/auth/forgot.php`, `forgot.twig`, `reset.php`, `reset.twig`
- Modify: `src/Kernel/Auth/Http/AuthRoutes.php`, `themes/phoenix/templates/auth/login.php` and `login.twig` (the "Forgot your password?" link), `themes/phoenix/assets/phoenix.css` (`.form-hint`)
- Test: `tests/Kernel/Auth/PasswordResetTest.php` (new); `tests/Kernel/View/PhoenixThemeTest.php` lists `auth/forgot` and `auth/reset`

**Interfaces:**
- **Consumes:**
  - `UserRepository::{findByLogin, find, setPassword, checkPassword}` (Task 1).
  - `Session::newId()`, `Session::ID` and `SessionStore::deleteForUser()` (Task 3).
  - `Mailer` and `Message` (Task 9), `UrlGenerator::generate(…, true)` and `Translator::t()`.
  - `throttle` (Task 7).
- **Produces:**
  - Table `xar_password_resets`: `token_hash` string(64) pk, `user_id` ulid FK users cascade (indexed), `expires_at`, `created_at`.
  - `Xaraya\Kernel\Auth\PasswordResets(Connection, UserRepository, SessionStore, Mailer, UrlGenerator, Translator)`, with `TTL = 3600`:
    - `send(string $email, DateTimeImmutable $now): void` throws `LogicException` when the link would not be absolute.
    - `find(string $token, DateTimeImmutable $now): ?User`.
    - `reset(string $token, string $password, DateTimeImmutable $now): ?User` throws `InvalidArgumentException` for a short password before it spends the link.
  - Routes `auth.forgot`, `auth.forgot.submit` (with `throttle:password-forgot,5,900`), `auth.reset` and `auth.reset.submit`, all on `PasswordController(PasswordResets, LoggerInterface)`. A successful reset is a 303 to `/login?reset=1`.
  - Templates `auth/forgot` (`sent`) and `auth/reset` (`token`, `valid` and `error`).

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/PasswordResetTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use LogicException;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\PasswordResets;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

final class PasswordResetTest extends AuthTestCase
{
    /** @param array<string, mixed> $overrides */
    private function mailApp(array $overrides = []): App
    {
        return $this->probeApp(['mail.driver' => 'memory', ...$overrides]);
    }

    private function mailer(App $app): MemoryMailer
    {
        $mailer = $app->container()->get(Mailer::class);
        self::assertInstanceOf(MemoryMailer::class, $mailer);

        return $mailer;
    }

    private function ask(App $app, string $email, string $ip = '203.0.113.7'): Browser
    {
        $browser = new Browser($app, $ip);
        $browser->get('/password/forgot');
        $browser->post('/password/forgot', ['email' => $email]);

        return $browser;
    }

    /** The path of the link in the last mail. */
    private function link(App $app): string
    {
        $sent = $this->mailer($app)->sent;
        self::assertNotSame([], $sent);
        self::assertSame(1, preg_match('#http://xar\.test(/password/reset/[A-Za-z0-9_-]{43})\n#', end($sent)->text, $m));

        return $m[1];
    }

    public function testTheAnswerIsTheSameWhetherOrNotTheAccountExists(): void
    {
        $app = $this->mailApp();
        $this->user($app, 'ada');
        $this->user($app, 'dee', [], 'disabled');
        $pages = [];
        foreach (['ada@example.com', 'nobody@example.com', 'dee@example.com', 'not an email'] as $email) {
            $browser = $this->ask($app, $email);
            self::assertSame(200, $browser->last?->getStatusCode());
            $pages[] = Html::normalize($browser->body());
        }
        self::assertCount(1, array_unique($pages));
        self::assertStringContainsString('If an account uses that address, we have sent it a link to choose a new password.', $pages[0]);

        $sent = $this->mailer($app)->sent;
        self::assertCount(1, $sent, 'only the active account gets mail');
        self::assertSame('ada@example.com', $sent[0]->to);
        self::assertSame('Reset your password', $sent[0]->subject);
        self::assertStringContainsString('of the account ada.', $sent[0]->text);
        self::assertStringContainsString('The link works once, for 60 minutes.', $sent[0]->text);
    }

    public function testTheLinkSetsANewPasswordOnceAndSignsTheUserOutEverywhere(): void
    {
        $app = $this->mailApp();
        $ada = $this->user($app, 'ada');
        $elsewhere = new Browser($app);
        $elsewhere->login('ada', self::PASSWORD);
        $this->ask($app, 'ADA@example.com');
        $link = $this->link($app);
        $token = substr($link, strlen('/password/reset/'));
        self::assertSame(hash('sha256', $token), $this->db($app)->fetchValue('SELECT token_hash FROM {password_resets}'));

        $browser = new Browser($app);
        $browser->get($link);
        self::assertStringContainsString('<label for="password">New password</label>', $browser->body());
        self::assertSame(422, $browser->post($link, ['password' => 'a new password', 'password_confirmation' => 'something else'])->getStatusCode());
        self::assertStringContainsString('The two passwords are not the same.', $browser->body());
        self::assertSame(422, $browser->post($link, ['password' => 'short', 'password_confirmation' => 'short'])->getStatusCode());
        self::assertStringContainsString('A password is at least 8 characters', $browser->body());

        $done = $browser->post($link, ['password' => 'a new password', 'password_confirmation' => 'a new password']);
        self::assertSame(303, $done->getStatusCode());
        self::assertSame('/login?reset=1', $done->getHeaderLine('Location'));
        self::assertTrue($this->users($app)->verifyPassword($ada, 'a new password'));
        self::assertFalse($this->users($app)->verifyPassword($ada, self::PASSWORD));
        self::assertNull(json_decode((string) $elsewhere->get('/probe/whoami')->getBody(), true)['user'], 'other sessions end');
        self::assertSame(0, $this->db($app)->select('password_resets')->count());

        $again = new Browser($app);
        $again->get($link);
        self::assertStringContainsString('This link has expired or has already been used.', $again->body());
        $again->get('/password/forgot');
        self::assertSame(200, $again->post($link, ['password' => 'third password', 'password_confirmation' => 'third password'])->getStatusCode());
        self::assertStringContainsString('This link has expired or has already been used.', $again->body());
        self::assertTrue($this->users($app)->verifyPassword($ada, 'a new password'));
    }

    public function testAnExpiredOrReplacedLinkIsRefused(): void
    {
        $app = $this->mailApp();
        $ada = $this->user($app, 'ada');
        $this->ask($app, 'ada@example.com');
        $first = $this->link($app);
        $this->ask($app, 'ada@example.com');
        $second = $this->link($app);
        $resets = $app->container()->get(PasswordResets::class);
        $now = new DateTimeImmutable();
        self::assertNull($resets->find(substr($first, 16), $now), 'asking again replaces the link');
        self::assertSame($ada->id, $resets->find(substr($second, 16), $now)?->id);

        $this->db($app)->query('UPDATE {password_resets} SET expires_at = ?', [new DateTimeImmutable('-1 second')]);
        self::assertNull($resets->reset(substr($second, 16), 'a new password', $now));
        self::assertTrue($this->users($app)->verifyPassword($ada, self::PASSWORD));
        self::assertNull($resets->find('not-a-token', $now));
    }

    public function testRequestsAreThrottledPerIp(): void
    {
        $app = $this->mailApp();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(200, $this->ask($app, "x{$i}@example.com", '198.51.100.20')->last?->getStatusCode());
        }
        self::assertSame(429, $this->ask($app, 'x@example.com', '198.51.100.20')->last?->getStatusCode());
        self::assertSame(200, $this->ask($app, 'x@example.com', '198.51.100.21')->last?->getStatusCode());
    }

    public function testLinksMustBeAbsolute(): void
    {
        $app = $this->mailApp(['app.url' => '']);
        $this->user($app, 'ada');
        $this->expectException(LogicException::class);
        $app->container()->get(PasswordResets::class)->send('ada@example.com', new DateTimeImmutable());
    }

    public function testTheFormsAreTheSameInBothEngines(): void
    {
        $pages = [];
        $this->user($this->mailApp(), 'ada');
        foreach (['php', 'twig'] as $engine) {
            $app = $this->mailApp(['view.engine' => $engine]);
            $browser = $this->ask($app, 'ada@example.com');
            $sent = Html::normalize($browser->body());
            $browser->get('/password/forgot');
            $form = Html::normalize((string) preg_replace('/value="[A-Za-z0-9_-]{43}"/', 'value="T"', $browser->body()));
            $browser->get($this->link($app));
            $reset = Html::normalize((string) preg_replace(['/value="[A-Za-z0-9_-]{43}"/', '#/password/reset/[A-Za-z0-9_-]{43}#'], ['value="T"', '/password/reset/L'], $browser->body()));
            $pages[$engine] = [$sent, $form, $reset];
        }
        self::assertSame($pages['php'], $pages['twig']);
        self::assertStringContainsString('<label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" required>', $pages['php'][1]);
        self::assertStringContainsString('<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="password-hint">', $pages['php'][2]);
    }
}
```

In `tests/Kernel/View/PhoenixThemeTest.php`, replace:

```php
        sort($names['php']);
        sort($names['twig']);
        self::assertSame(['auth/login', 'block', 'error/404', 'error/default', 'home', 'layout'], $names['php']);
        self::assertSame($names['php'], $names['twig']);
    }
```

with:

```php
        sort($names['php']);
        sort($names['twig']);
        self::assertSame(['auth/forgot', 'auth/login', 'auth/reset', 'block', 'error/404', 'error/default', 'home', 'layout'], $names['php']);
        self::assertSame($names['php'], $names['twig']);
    }
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/PasswordResetTest.php tests/Kernel/View/PhoenixThemeTest.php`
Expected: FAIL. The page tests get `404 is identical to 200`, and the link tests fail because no mail was sent (`Failed asserting that two arrays are not identical`). `testLinksMustBeAbsolute` fails with `Cannot resolve 'Xaraya\Kernel\Auth\PasswordResets'`, and `testEveryTemplateShipsInBothEngines` fails too.

- [ ] **Step 3: Implement**

`reset()` validates the password first, then spends the link with a conditional `DELETE … WHERE token_hash = ? AND expires_at > ?`. If that deleted no row, a concurrent request used the link first, and the call returns null.

In `src/Kernel/Auth/Http/AuthRoutes.php`, replace:

```php
        $routes->post('/login', [LoginController::class, 'login'], 'auth.login.submit')->middleware('secureHtml');
        $routes->post('/logout', [LoginController::class, 'logout'], 'auth.logout')->middleware('secureHtml');
    }
}
```

with:

```php
        $routes->post('/login', [LoginController::class, 'login'], 'auth.login.submit')->middleware('secureHtml');
        $routes->post('/logout', [LoginController::class, 'logout'], 'auth.logout')->middleware('secureHtml');
        $routes->get('/password/forgot', [PasswordController::class, 'forgotForm'], 'auth.forgot')->middleware('secureHtml');
        $routes->post('/password/forgot', [PasswordController::class, 'forgot'], 'auth.forgot.submit')
            ->middleware('secureHtml', 'throttle:password-forgot,5,900');
        $token = '{token:[A-Za-z0-9_-]{43}}';
        $routes->get('/password/reset/' . $token, [PasswordController::class, 'resetForm'], 'auth.reset')->middleware('secureHtml');
        $routes->post('/password/reset/' . $token, [PasswordController::class, 'reset'], 'auth.reset.submit')->middleware('secureHtml');
    }
}
```

`src/Kernel/Auth/Http/PasswordController.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth\Http;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Xaraya\Kernel\Auth\PasswordResets;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\View\Page;

/** GET/POST /password/forgot and /password/reset/{token}. The forgot page answers the same whoever is asked for. */
final class PasswordController extends Controller
{
    public function __construct(private readonly PasswordResets $resets, private readonly LoggerInterface $logger) {}

    public function forgotForm(): Page
    {
        return new Page('auth/forgot', ['sent' => false], 'Forgot your password?');
    }

    public function forgot(ServerRequestInterface $request): Page
    {
        $body = (array) $request->getParsedBody();
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        try {
            $this->resets->send($email, self::now());
        } catch (RuntimeException $e) {
            // A mail failure must not reveal that the account exists; it is logged for the administrator.
            $this->logger->error('Could not send a password reset email: {reason}', ['reason' => $e->getMessage()]);
        }

        return new Page('auth/forgot', ['sent' => true], 'Forgot your password?');
    }

    /** @param array<string, string> $params */
    public function resetForm(ServerRequestInterface $request, array $params): Page
    {
        return $this->form($params['token'], $this->resets->find($params['token'], self::now()) !== null);
    }

    /** @param array<string, string> $params */
    public function reset(ServerRequestInterface $request, array $params): Page|ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        if ($password !== ($body['password_confirmation'] ?? null)) {
            return $this->form($params['token'], true, 'The two passwords are not the same.');
        }
        try {
            $user = $this->resets->reset($params['token'], $password, self::now());
        } catch (InvalidArgumentException $e) {
            return $this->form($params['token'], true, $e->getMessage());
        }

        return $user === null ? $this->form($params['token'], false) : $this->redirect('/login?reset=1', 303);
    }

    private function form(string $token, bool $valid, ?string $error = null): Page
    {
        return new Page('auth/reset', ['token' => $token, 'valid' => $valid, 'error' => $error], 'Choose a new password', status: $error === null ? 200 : 422);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
```

`src/Kernel/Auth/PasswordResets.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Auth;

use DateTimeImmutable;
use LogicException;
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\Message;
use Xaraya\Kernel\Routing\UrlGenerator;
use Xaraya\Kernel\View\Translator;

/**
 * Password reset links: {app.url}/password/reset/<token>, where the token is 32 random bytes and only its sha256 is
 * stored. A link works once, for 60 minutes; asking again replaces it. Using it signs the user out everywhere.
 */
final class PasswordResets
{
    public const TTL = 3600;

    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly SessionStore $sessions,
        private readonly Mailer $mailer,
        private readonly UrlGenerator $urls,
        private readonly Translator $translator,
    ) {}

    /** Mails a link when $email belongs to an active user, and does nothing otherwise; the caller never learns which. */
    public function send(string $email, DateTimeImmutable $now): void
    {
        $user = str_contains($email, '@') ? $this->users->findByLogin($email) : null;
        if ($user === null || !$user->isActive()) {
            return;
        }
        $token = Session::newId();
        $url = $this->urls->generate('auth.reset', ['token' => $token], true);
        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new LogicException('Set app.url (APP_URL): password reset links must be absolute');
        }
        $this->db->transaction(function (Connection $db) use ($user, $token, $now): void {
            $db->delete('password_resets', ['user_id' => $user->id]);
            $db->insert('password_resets', [
                'token_hash' => hash('sha256', $token),
                'user_id' => $user->id,
                'expires_at' => $now->modify('+' . self::TTL . ' seconds'),
                'created_at' => $now,
            ]);
        });
        $t = $this->translator;
        $this->mailer->send(new Message($user->email, $t->t('Reset your password'), $t->t(
            "Someone asked to reset the password of the account {handle}. To choose a new password, open this link:\n\n{url}\n\nThe link works once, for 60 minutes. If you did not ask for it, ignore this email.",
            ['handle' => $user->handle, 'url' => $url],
        )));
    }

    /** The active user a valid, unexpired link belongs to. */
    public function find(#[SensitiveParameter] string $token, DateTimeImmutable $now): ?User
    {
        if (preg_match(Session::ID, $token) !== 1) {
            return null;
        }
        $userId = $this->db->fetchValue('SELECT user_id FROM {password_resets} WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), $now]);
        $user = $userId === null ? null : $this->users->find((string) $userId);

        return $user !== null && $user->isActive() ? $user : null;
    }

    /**
     * Sets the new password and spends the link. Returns null when the link is unknown, used or expired.
     *
     * @throws \InvalidArgumentException for a password that is too short, before the link is spent
     */
    public function reset(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password, DateTimeImmutable $now): ?User
    {
        UserRepository::checkPassword($password);
        $user = $this->find($token, $now);
        if ($user === null) {
            return null;
        }
        $spent = $this->db->query('DELETE FROM {password_resets} WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), $now])->rowCount();
        if ($spent !== 1) {
            return null;
        }
        $this->users->setPassword($user, $password);
        $this->db->delete('password_resets', ['user_id' => $user->id]);
        $this->sessions->deleteForUser($user->id);

        return $user;
    }
}
```

`src/Kernel/migrations/2026_10_21_000004_create_password_resets.php`:

```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/** Password reset links: only the sha256 of each link's token is stored. */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $schema->create('password_resets', function (Blueprint $t): void {
            $t->string('token_hash', 64)->primary();
            $t->ulid('user_id');
            $t->datetime('expires_at');
            $t->datetime('created_at');
            $t->index('user_id');
            $t->foreign('user_id', 'users');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->drop('password_resets');
    }
};
```

In `themes/phoenix/assets/phoenix.css`, replace:

```css
.auth-form button, .logout-form button { padding: 0.5rem 1rem; font: inherit; }
.form-error { padding: 0.5rem 1rem; border-left: 4px solid #c5221f; }
.form-notice { padding: 0.5rem 1rem; border-left: 4px solid var(--accent); }
```

with:

```css
.auth-form button, .logout-form button { padding: 0.5rem 1rem; font: inherit; }
.form-error { padding: 0.5rem 1rem; border-left: 4px solid #c5221f; }
.form-hint { display: block; color: var(--muted); font-size: 0.9375rem; }
.form-notice { padding: 0.5rem 1rem; border-left: 4px solid var(--accent); }
```

`themes/phoenix/templates/auth/forgot.php`:

```php
<h1><?= $x->e($x->t('Forgot your password?')) ?></h1>
<?php if ($sent): ?>
<p class="form-notice" role="status"><?= $x->e($x->t('If an account uses that address, we have sent it a link to choose a new password. The link works for 60 minutes.')) ?></p>
<?php else: ?>
<p><?= $x->e($x->t('Enter the email address of your account and we will send you a link to choose a new password.')) ?></p>
<form class="auth-form" method="post" action="<?= $x->e($x->url('auth.forgot.submit')) ?>">
<?= $x->csrf() ?>
<p>
<label for="email"><?= $x->e($x->t('Email')) ?></label>
<input id="email" name="email" type="email" autocomplete="email" required>
</p>
<p><button type="submit"><?= $x->e($x->t('Send the link')) ?></button></p>
</form>
<?php endif ?>
<p><a href="<?= $x->e($x->url('auth.login')) ?>"><?= $x->e($x->t('Back to log in')) ?></a></p>
```

`themes/phoenix/templates/auth/forgot.twig`:

```twig
<h1>{{ t('Forgot your password?') }}</h1>
{% if sent %}
<p class="form-notice" role="status">{{ t('If an account uses that address, we have sent it a link to choose a new password. The link works for 60 minutes.') }}</p>
{% else %}
<p>{{ t('Enter the email address of your account and we will send you a link to choose a new password.') }}</p>
<form class="auth-form" method="post" action="{{ url('auth.forgot.submit') }}">
{{ csrf() }}
<p>
<label for="email">{{ t('Email') }}</label>
<input id="email" name="email" type="email" autocomplete="email" required>
</p>
<p><button type="submit">{{ t('Send the link') }}</button></p>
</form>
{% endif %}
<p><a href="{{ url('auth.login') }}">{{ t('Back to log in') }}</a></p>
```

In `themes/phoenix/templates/auth/login.php`, replace:

```php
<p><button type="submit"><?= $x->e($x->t('Log in')) ?></button></p>
</form>
```

with:

```php
<p><button type="submit"><?= $x->e($x->t('Log in')) ?></button></p>
</form>
<p><a href="<?= $x->e($x->url('auth.forgot')) ?>"><?= $x->e($x->t('Forgot your password?')) ?></a></p>
```

In `themes/phoenix/templates/auth/login.twig`, replace:

```twig
<p><button type="submit">{{ t('Log in') }}</button></p>
</form>
```

with:

```twig
<p><button type="submit">{{ t('Log in') }}</button></p>
</form>
<p><a href="{{ url('auth.forgot') }}">{{ t('Forgot your password?') }}</a></p>
```

`themes/phoenix/templates/auth/reset.php`:

```php
<h1><?= $x->e($x->t('Choose a new password')) ?></h1>
<?php if (!$valid): ?>
<p class="form-error" role="alert"><?= $x->e($x->t('This link has expired or has already been used.')) ?></p>
<p><a href="<?= $x->e($x->url('auth.forgot')) ?>"><?= $x->e($x->t('Ask for a new link')) ?></a></p>
<?php else: ?>
<?php if ($error): ?>
<p class="form-error" id="reset-error" role="alert"><?= $x->e($x->t($error)) ?></p>
<?php endif ?>
<form class="auth-form" method="post" action="<?= $x->e($x->url('auth.reset.submit', ['token' => $token])) ?>">
<?= $x->csrf() ?>
<p>
<label for="password"><?= $x->e($x->t('New password')) ?></label>
<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="password-hint<?= $error ? ' reset-error' : '' ?>">
<span class="form-hint" id="password-hint"><?= $x->e($x->t('At least 8 characters.')) ?></span>
</p>
<p>
<label for="password_confirmation"><?= $x->e($x->t('Repeat the new password')) ?></label>
<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
</p>
<p><button type="submit"><?= $x->e($x->t('Change the password')) ?></button></p>
</form>
<?php endif ?>
```

`themes/phoenix/templates/auth/reset.twig`:

```twig
<h1>{{ t('Choose a new password') }}</h1>
{% if not valid %}
<p class="form-error" role="alert">{{ t('This link has expired or has already been used.') }}</p>
<p><a href="{{ url('auth.forgot') }}">{{ t('Ask for a new link') }}</a></p>
{% else %}
{% if error %}
<p class="form-error" id="reset-error" role="alert">{{ t(error) }}</p>
{% endif %}
<form class="auth-form" method="post" action="{{ url('auth.reset.submit', {token: token}) }}">
{{ csrf() }}
<p>
<label for="password">{{ t('New password') }}</label>
<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required aria-describedby="password-hint{{ error ? ' reset-error' : '' }}">
<span class="form-hint" id="password-hint">{{ t('At least 8 characters.') }}</span>
</p>
<p>
<label for="password_confirmation">{{ t('Repeat the new password') }}</label>
<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" required>
</p>
<p><button type="submit">{{ t('Change the password') }}</button></p>
</form>
{% endif %}
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth tests/Kernel/View`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth tests/Kernel/View`.
Expected: all green (760 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/migrations/2026_10_21_000004_create_password_resets.php src/Kernel/Auth themes/phoenix tests/Kernel/Auth/PasswordResetTest.php tests/Kernel/View/PhoenixThemeTest.php
git commit -m "feat(auth): password reset by a single-use 60-minute emailed link" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 11: `user:create` and `user:password`, with hidden prompts

**Files:**
- Create: `src/Kernel/Cli/Commands/AsksForPassword.php`, `src/Kernel/Cli/Commands/UserCreateCommand.php`, `src/Kernel/Cli/Commands/UserPasswordCommand.php`
- Modify: `src/Kernel/Cli/Output.php` (`ask()` and an optional stdin), `src/Kernel/Cli/Application.php`
- Test: `tests/Kernel/Auth/UserCommandsTest.php` (new); `tests/Support/AuthTestCase.php` gains `xar()`

**Interfaces:**
- **Consumes:** `UserRepository` (Task 1); `SessionStore::deleteForUser()` (Task 3); the `xar_password_resets` table (Task 10).
- **Produces:**
  - `Output::__construct($stdout = null, $stderr = null, $stdin = null)` and `Output::ask(string $question, bool $secret = false): ?string`. It returns null at the end of input, and hides the answer with `stty -echo` only when stdin is a TTY.
  - The trait `AsksForPassword::password(Input, Output, string $option = 'password'): string` uses `--password=` or a prompt typed twice. Otherwise it throws `InvalidArgumentException('Give --password=<password> or type one')` or `('The two passwords are not the same')`.
  - `user:create <handle> --email=<email> [--name=] [--role=A,B] [--status=] [--password=]` prints `Created user '<handle>' (<roles>).`
  - `user:password <handle> [--password=]` prints `Changed the password of '<handle>'[ and ended N session(s)].`
  - `AuthTestCase::xar(App $app, list<string> $args, string $stdin = ''): array{int, string, string}`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/UserCommandsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use DateTimeImmutable;
use Xaraya\Kernel\Auth\PasswordResets;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class UserCommandsTest extends AuthTestCase
{
    public function testCreateWithFlags(): void
    {
        $app = $this->installed();
        [$code, $out, $err] = $this->xar($app, ['user:create', 'ada', '--email=ada@example.com', '--name=Ada Lovelace', '--role=Administrators,Editors', '--password=' . self::PASSWORD]);
        self::assertSame(0, $code, $err);
        self::assertSame("Created user 'ada' (Administrators, Editors).\n", $out);
        $ada = $this->users($app)->findByHandle('ada');
        self::assertNotNull($ada);
        self::assertSame('Ada Lovelace', $ada->name);
        self::assertSame(['Administrators', 'Editors'], $this->users($app)->rolesOf($ada));
        self::assertTrue($this->users($app)->verifyPassword($ada, self::PASSWORD));
    }

    public function testCreateAsksForThePasswordTwice(): void
    {
        $app = $this->installed();
        [$code, $out] = $this->xar($app, ['user:create', 'bob', '--email=bob@example.com'], "a typed password\na typed password\n");
        self::assertSame(0, $code);
        self::assertSame("Password: Password again: Created user 'bob'.\n", $out);
        $bob = $this->users($app)->findByHandle('bob');
        self::assertNotNull($bob);
        self::assertTrue($this->users($app)->verifyPassword($bob, 'a typed password'));
    }

    public function testCreateRefusesBadInput(): void
    {
        $app = $this->installed();
        $this->user($app, 'ada');
        foreach ([
            [['user:create', 'x'], '', 'Usage: xar user:create'],
            [['user:create', 'bob', '--email=bob@example.com'], '', 'Give --password=<password> or type one'],
            [['user:create', 'bob', '--email=bob@example.com'], "one password\nanother one\n", 'The two passwords are not the same'],
            [['user:create', 'ada', '--email=new@example.com', '--password=' . self::PASSWORD], '', "The handle 'ada' is taken"],
            [['user:create', 'bob', '--email=bob@example.com', '--role=Wizards', '--password=' . self::PASSWORD], '', "Unknown role 'Wizards'"],
            [['user:create', 'bob', '--email=bob@example.com', '--password=short'], '', 'A password is at least 8 characters'],
        ] as [$args, $stdin, $message]) {
            [$code, , $err] = $this->xar($app, $args, $stdin);
            self::assertSame(1, $code, implode(' ', $args));
            self::assertStringContainsString($message, $err);
        }
        self::assertSame(1, $this->users($app)->count());
    }

    public function testPasswordSetsThePasswordAndEndsSessionsAndResetLinks(): void
    {
        $app = $this->probeApp(['mail.driver' => 'memory']);
        $ada = $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->login('ada', self::PASSWORD);
        $app->container()->get(PasswordResets::class)->send('ada@example.com', new DateTimeImmutable());

        [$code, $out, $err] = $this->xar($app, ['user:password', 'ada'], "a brand new password\na brand new password\n");
        self::assertSame(0, $code, $err);
        self::assertSame("Password: Password again: Changed the password of 'ada' and ended 1 session(s).\n", $out);
        self::assertTrue($this->users($app)->verifyPassword($ada, 'a brand new password'));
        self::assertNull(json_decode((string) $browser->get('/probe/whoami')->getBody(), true)['user']);
        self::assertSame(0, $this->db($app)->select('password_resets')->count());

        [$code, , $err] = $this->xar($app, ['user:password', 'nobody', '--password=' . self::PASSWORD]);
        self::assertSame(1, $code);
        self::assertStringContainsString("Unknown user 'nobody'", $err);
    }

    public function testHelpListsTheUserCommands(): void
    {
        [, $out] = $this->xar($this->installed(), ['help']);
        self::assertStringContainsString('user:create', $out);
        self::assertStringContainsString('user:password', $out);
    }
}
```

In `tests/Support/AuthTestCase.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;
```

with:

```php
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Application;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;
```

In `tests/Support/AuthTestCase.php` (2 of 2), replace:

```php
    }

    protected function users(App $app): UserRepository
    {
```

with:

```php
    }

    /**
     * Runs a CLI command; $stdin is what ask() reads.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    protected function xar(App $app, array $args, string $stdin = ''): array
    {
        $streams = [];
        foreach (['out', 'err', 'in'] as $name) {
            $streams[$name] = fopen('php://memory', 'w+') ?: throw new \RuntimeException('no memory stream');
        }
        fwrite($streams['in'], $stdin);
        rewind($streams['in']);
        $code = (new Application($app))->run(['xar', ...$args], new Output($streams['out'], $streams['err'], $streams['in']));
        rewind($streams['out']);
        rewind($streams['err']);

        return [$code, (string) stream_get_contents($streams['out']), (string) stream_get_contents($streams['err'])];
    }

    protected function users(App $app): UserRepository
    {
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/UserCommandsTest.php`
Expected: FAIL (5 failures: `Unknown command 'user:create'. Run 'xar help'.`).

- [ ] **Step 3: Implement**

In `src/Kernel/Cli/Application.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Cli\Commands\ModuleListCommand;
use Xaraya\Kernel\Cli\Commands\ServeCommand;
use Xaraya\Kernel\Module\ModuleRegistry;
```

with:

```php
use Xaraya\Kernel\Cli\Commands\ModuleListCommand;
use Xaraya\Kernel\Cli\Commands\ServeCommand;
use Xaraya\Kernel\Cli\Commands\UserCreateCommand;
use Xaraya\Kernel\Cli\Commands\UserPasswordCommand;
use Xaraya\Kernel\Module\ModuleRegistry;
```

In `src/Kernel/Cli/Application.php` (2 of 2), replace:

```php
        HookDisableCommand::class,
        AssetPublishCommand::class,
    ];
```

with:

```php
        HookDisableCommand::class,
        AssetPublishCommand::class,
        UserCreateCommand::class,
        UserPasswordCommand::class,
    ];
```

`src/Kernel/Cli/Commands/AsksForPassword.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use InvalidArgumentException;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

/** --password=<p>, or a hidden prompt typed twice. */
trait AsksForPassword
{
    private function password(Input $input, Output $output, string $option = 'password'): string
    {
        $password = $input->option($option);
        if ($password !== null) {
            return $password;
        }
        $first = $output->ask('Password:', true);
        if ($first === null) {
            throw new InvalidArgumentException("Give --{$option}=<password> or type one");
        }
        if ($output->ask('Password again:', true) !== $first) {
            throw new InvalidArgumentException('The two passwords are not the same');
        }

        return $first;
    }
}
```

`src/Kernel/Cli/Commands/UserCreateCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;

final class UserCreateCommand extends Command
{
    use AsksForPassword;

    public function __construct(private readonly UserRepository $users) {}

    public function name(): string
    {
        return 'user:create';
    }

    public function description(): string
    {
        return 'Create a user (asks for the password unless --password is given)';
    }

    public function usage(): string
    {
        return 'user:create <handle> --email=<email> [--name=<name>] [--role=<Role>,<Role>] [--status=active|pending|disabled] [--password=<password>]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        $email = $input->option('email');
        if ($handle === null || $email === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $roles = array_values(array_filter(array_map('trim', explode(',', $input->option('role') ?? ''))));
        $user = $this->users->create(
            $handle,
            $email,
            $this->password($input, $output),
            $input->option('name') ?? '',
            $input->option('status') ?? 'active',
            $roles,
        );
        $output->line("Created user '{$user->handle}'" . ($roles === [] ? '' : ' (' . implode(', ', $roles) . ')') . '.');

        return 0;
    }
}
```

`src/Kernel/Cli/Commands/UserPasswordCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use InvalidArgumentException;
use Xaraya\Kernel\Auth\SessionStore;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;

/** The password-reset fallback (kernel spec §4.4): also ends the user's sessions and reset links. */
final class UserPasswordCommand extends Command
{
    use AsksForPassword;

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionStore $sessions,
        private readonly Connection $db,
    ) {}

    public function name(): string
    {
        return 'user:password';
    }

    public function description(): string
    {
        return "Set a user's password and sign them out everywhere";
    }

    public function usage(): string
    {
        return 'user:password <handle> [--password=<password>]';
    }

    public function run(Input $input, Output $output): int
    {
        $handle = $input->argument(0);
        if ($handle === null) {
            $output->error('Usage: xar ' . $this->usage());

            return 1;
        }
        $user = $this->users->findByHandle($handle) ?? throw new InvalidArgumentException("Unknown user '{$handle}'");
        $this->users->setPassword($user, $this->password($input, $output));
        $this->db->delete('password_resets', ['user_id' => $user->id]);
        $ended = $this->sessions->deleteForUser($user->id);
        $output->line("Changed the password of '{$user->handle}'" . ($ended > 0 ? " and ended {$ended} session(s)" : '') . '.');

        return 0;
    }
}
```

In `src/Kernel/Cli/Output.php`, replace:

```php
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
```

with:

```php
    private $stderr;

    /** @var resource|null */
    private $stdin;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     * @param resource|null $stdin where ask() reads; STDIN when null
     */
    public function __construct($stdout = null, $stderr = null, $stdin = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->stdin = $stdin;
    }

    /** Prints $question and reads one line: null at the end of input. A secret answer is not echoed on a terminal. */
    public function ask(string $question, bool $secret = false): ?string
    {
        $in = $this->stdin ?? STDIN;
        fwrite($this->stdout, $question . ' ');
        $hide = $secret && stream_isatty($in);
        if ($hide) {
            shell_exec('stty -echo');
        }
        try {
            $line = fgets($in);
        } finally {
            if ($hide) {
                shell_exec('stty echo');
                fwrite($this->stdout, PHP_EOL);
            }
        }

        return $line === false ? null : rtrim($line, "\r\n");
    }
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth/UserCommandsTest.php tests/Kernel/Cli`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth/UserCommandsTest.php`.
Expected: all green (765 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Cli tests/Kernel/Auth/UserCommandsTest.php tests/Support/AuthTestCase.php
git commit -m "feat(cli): user:create and user:password, with hidden password prompts" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 12: `xar install`

**Files:**
- Create: `src/Kernel/Cli/Commands/InstallCommand.php`
- Modify: `src/Kernel/Cli/Application.php`
- Test: `tests/Kernel/Auth/InstallCommandTest.php`

**Interfaces:**
- **Consumes:**
  - `App` (`config()` and `path()`), `Connection`, `Migrator::migrate()`, `ModuleRegistry::{migrationPaths, enabled}`.
  - `Grants::seed()` and `UserRepository::{count, findByHandle, create}` (Task 1).
  - `ThemeRegistry::active()`, `AssetPublisher::publish()`.
  - `AsksForPassword` and `Output::ask()` (Task 11).
- **Produces:**
  - `install [--admin=<handle>] [--email=<email>] [--name=<name>] [--password=<password>] [--force]`.
  - `InstallCommand::EXTENSIONS` and `static requirements(string $phpVersion, callable $loaded): list<string>`.
  - The config key `install.env_file` (default `<root>/.env`).
  - The exact output lines are pinned by `testInstallsAnEmptyDatabaseWithoutQuestions`.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/InstallCommandTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Nyholm\Psr7\ServerRequest;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Cli\Commands\InstallCommand;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;

final class InstallCommandTest extends AuthTestCase
{
    /** @param list<string> $modulePaths */
    private function fresh(array $modulePaths = []): App
    {
        return $this->app(['install.env_file' => $this->tmp . '/.env', 'assets.path' => $this->tmp . '/assets', 'session.gc_probability' => 0], $modulePaths);
    }

    private const FLAGS = ['install', '--admin=admin', '--email=admin@example.com', '--name=Site Admin', '--password=' . self::PASSWORD];

    public function testInstallsAnEmptyDatabaseWithoutQuestions(): void
    {
        $app = $this->fresh();
        [$code, $out, $err] = $this->xar($app, self::FLAGS);
        self::assertSame(0, $code, $err);
        $lines = explode("\n", trim($out));
        self::assertMatchesRegularExpression('/^PHP 8\.\d+\.\d+.* with pdo, mbstring, json\.$/', $lines[0]);
        self::assertSame([
            'Wrote .env from .env.example; edit APP_URL and the database settings there.',
            'Database: ' . $this->db($app)->dialect()->name() . '.',
        ], array_slice($lines, 1, 2));
        self::assertMatchesRegularExpression('/^Ran \d+ migration\(s\)\.$/', $lines[3]);
        self::assertSame([
            'Roles and grants are in place.',
            "Created the administrator 'admin'.",
            "Theme 'phoenix' is active and its assets are published.",
            'Installed. Start it with `bin/xar serve` and log in at http://xar.test/login',
        ], array_slice($lines, 4));
        self::assertFileEquals(dirname(__DIR__, 3) . '/.env.example', $this->tmp . '/.env');
        self::assertFileExists($this->tmp . '/assets/theme/phoenix/phoenix.css');

        $app = $this->fresh();
        $admin = $this->users($app)->findByHandle('admin');
        self::assertNotNull($admin);
        self::assertSame(['Administrators'], $this->users($app)->rolesOf($admin));
        self::assertSame(200, $app->handle(new ServerRequest('GET', '/'))->getStatusCode());
        $browser = new Browser($app);
        self::assertSame(303, $browser->login('admin', self::PASSWORD)->getStatusCode());
    }

    public function testAsksForTheAdministratorWhenFlagsAreMissing(): void
    {
        [$code, $out, $err] = $this->xar($this->fresh(), ['install'], "boss\nboss@example.com\n" . self::PASSWORD . "\n" . self::PASSWORD . "\n");
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("Administrator handle: Administrator email: Password: Password again: Created the administrator 'boss'.", $out);
        [$code, , $err] = $this->xar($this->fresh(), ['install', '--force']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Give --admin=<handle> or type one', $err);
    }

    public function testAnInstalledDatabaseIsRefusedUnlessForced(): void
    {
        $this->xar($this->fresh(), self::FLAGS);
        file_put_contents($this->tmp . '/.env', "APP_URL=http://mine.example\n");
        [$code, $out, $err] = $this->xar($this->fresh(), self::FLAGS);
        self::assertSame(1, $code);
        self::assertStringContainsString('This database is already installed: it has users.', $err);
        self::assertStringNotContainsString('Created', $out);

        [$code, $out, $err] = $this->xar($this->fresh(), [...self::FLAGS, '--force']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString('.env exists; left as it is.', $out);
        self::assertStringContainsString('The database schema is up to date.', $out);
        self::assertStringContainsString("The user 'admin' exists; left as it is.", $out);
        self::assertSame("APP_URL=http://mine.example\n", file_get_contents($this->tmp . '/.env'));
        self::assertSame(1, $this->users($this->fresh())->count());
    }

    public function testInstallingADatabaseThatHasModulesSeedsTheirGrants(): void
    {
        $modules = [dirname(__DIR__, 3) . '/modules'];
        $app = $this->app(['blog.locks' => $this->tmp . '/locks'], $modules);
        $app->container()->get(ModuleRegistry::class)->enable('blog');
        $this->db($app)->query("DELETE FROM {grants} WHERE module = 'blog'");

        [$code, , $err] = $this->xar($this->fresh($modules), self::FLAGS);
        self::assertSame(0, $code, $err);
        self::assertSame(3, $this->db($app)->select('grants')->where('module', '=', 'blog')->count());
    }

    public function testRequirements(): void
    {
        self::assertSame([], InstallCommand::requirements('8.3.0', static fn(): bool => true));
        self::assertSame([
            'PHP 8.3 or later is required; this is PHP 8.2.9.',
            "The PHP extension 'pdo' is missing.",
            "The PHP extension 'json' is missing.",
        ], InstallCommand::requirements('8.2.9', static fn(string $e): bool => $e === 'mbstring'));
    }
}
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/InstallCommandTest.php`
Expected: FAIL. There is 1 error, `Class "Xaraya\Kernel\Cli\Commands\InstallCommand" not found`, and 4 failures with `Unknown command 'install'`.

- [ ] **Step 3: Implement**

The users check comes before any migration, so a refused run changes nothing. A run that fails after the migrations (say, a taken email) leaves a database with no users, which the next run accepts.

In `src/Kernel/Cli/Application.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Cli\Commands\HookDisableCommand;
use Xaraya\Kernel\Cli\Commands\HookEnableCommand;
use Xaraya\Kernel\Cli\Commands\MigrateCommand;
use Xaraya\Kernel\Cli\Commands\MigrateRollbackCommand;
```

with:

```php
use Xaraya\Kernel\Cli\Commands\HookDisableCommand;
use Xaraya\Kernel\Cli\Commands\HookEnableCommand;
use Xaraya\Kernel\Cli\Commands\InstallCommand;
use Xaraya\Kernel\Cli\Commands\MigrateCommand;
use Xaraya\Kernel\Cli\Commands\MigrateRollbackCommand;
```

In `src/Kernel/Cli/Application.php` (2 of 2), replace:

```php
{
    private const KERNEL_COMMANDS = [
        MigrateCommand::class,
        MigrateRollbackCommand::class,
```

with:

```php
{
    private const KERNEL_COMMANDS = [
        InstallCommand::class,
        MigrateCommand::class,
        MigrateRollbackCommand::class,
```

`src/Kernel/Cli/Commands/InstallCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Cli\Commands;

use InvalidArgumentException;
use Throwable;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Assets\AssetPublisher;
use Xaraya\Kernel\Auth\Grants;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
use Xaraya\Kernel\Cli\Output;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Db\Migrations\Migrator;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\View\ThemeRegistry;

/**
 * `xar install` (kernel spec §4.5): checks PHP, writes .env from .env.example when it is missing, checks the
 * database, migrates, seeds roles and grants, creates the first administrator and publishes the theme's assets.
 * A database that already has users is refused unless --force is given.
 */
final class InstallCommand extends Command
{
    use AsksForPassword;

    public const EXTENSIONS = ['pdo', 'mbstring', 'json'];

    public function __construct(
        private readonly App $app,
        private readonly Connection $db,
        private readonly Migrator $migrator,
        private readonly ModuleRegistry $modules,
        private readonly Grants $grants,
        private readonly UserRepository $users,
        private readonly ThemeRegistry $themes,
        private readonly AssetPublisher $assets,
    ) {}

    public function name(): string
    {
        return 'install';
    }

    public function description(): string
    {
        return 'Set up the site: .env, database, roles and grants, the first administrator and the theme';
    }

    public function usage(): string
    {
        return 'install [--admin=<handle>] [--email=<email>] [--name=<name>] [--password=<password>] [--force]';
    }

    /**
     * @param callable(string): bool $loaded
     * @return list<string> what is missing
     */
    public static function requirements(string $phpVersion, callable $loaded): array
    {
        $problems = version_compare($phpVersion, '8.3.0', '>=') ? [] : ["PHP 8.3 or later is required; this is PHP {$phpVersion}."];
        foreach (self::EXTENSIONS as $extension) {
            if (!$loaded($extension)) {
                $problems[] = "The PHP extension '{$extension}' is missing.";
            }
        }

        return $problems;
    }

    public function run(Input $input, Output $output): int
    {
        $problems = self::requirements(PHP_VERSION, 'extension_loaded');
        foreach ($problems as $problem) {
            $output->error($problem);
        }
        if ($problems !== []) {
            return 1;
        }
        $output->line('PHP ' . PHP_VERSION . ' with ' . implode(', ', self::EXTENSIONS) . '.');
        $output->line($this->writeEnv());

        try {
            $this->db->tables();
        } catch (Throwable $e) {
            $output->error("Cannot use the database: {$e->getMessage()}");

            return 1;
        }
        if ($this->users->count() > 0 && !$input->flag('force')) {
            $output->error('This database is already installed: it has users. Run `xar install --force` to repeat the steps.');

            return 1;
        }
        $output->line('Database: ' . $this->db->dialect()->name() . '.');

        $ran = $this->migrator->migrate($this->modules->migrationPaths());
        $output->line($ran === [] ? 'The database schema is up to date.' : 'Ran ' . count($ran) . ' migration(s).');
        foreach ($this->modules->enabled() as $manifest) {
            $this->grants->seed($manifest);
        }
        $output->line('Roles and grants are in place.');

        $handle = $input->option('admin') ?? $output->ask('Administrator handle:') ?? throw new InvalidArgumentException('Give --admin=<handle> or type one');
        if ($input->flag('force') && $this->users->findByHandle($handle) !== null) {
            $output->line("The user '{$handle}' exists; left as it is.");
        } else {
            $email = $input->option('email') ?? $output->ask('Administrator email:') ?? throw new InvalidArgumentException('Give --email=<email> or type one');
            $this->users->create($handle, $email, $this->password($input, $output), $input->option('name') ?? '', 'active', ['Administrators']);
            $output->line("Created the administrator '{$handle}'.");
        }

        $theme = $this->themes->active();
        $this->assets->publish();
        $output->line("Theme '{$theme->name}' is active and its assets are published.");
        $output->line('Installed. Start it with `bin/xar serve` and log in at ' . rtrim((string) $this->app->config()->get('app.url', ''), '/') . '/login');

        return 0;
    }

    private function writeEnv(): string
    {
        $env = (string) $this->app->config()->get('install.env_file', $this->app->path('.env'));
        if (is_file($env)) {
            return '.env exists; left as it is.';
        }
        $example = $this->app->path('.env.example');
        if (!is_file($example) || !copy($example, $env)) {
            throw new InvalidArgumentException("Cannot write {$env} from .env.example");
        }

        return 'Wrote .env from .env.example; edit APP_URL and the database settings there.';
    }
}
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth/InstallCommandTest.php`
Expected: PASS (5 tests). They write only to the test's temp dir: `install.env_file` and `assets.path` point there.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth/InstallCommandTest.php`.
Expected: all green (770 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Cli tests/Kernel/Auth/InstallCommandTest.php
git commit -m "feat(cli): xar install with non-interactive flags and a refusal to reinstall" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 13: Role-aware blocks, the `login` block, and the `user()` and `can()` helpers

**Files:**
- Create: `src/Kernel/Blocks/LoginBlock.php`
- Modify: `src/Kernel/App.php` (the built-in type `login`), `src/Kernel/Auth/Access.php` and `src/Kernel/View/Helpers.php` (`user(): ?User`)
- Test: `tests/Kernel/Auth/BlocksAndHelpersTest.php`

**Interfaces:**
- **Consumes:**
  - `BlockInstance::visibleFor()` and `BlockRenderer`: unchanged, and they already ask `Access::roles()`.
  - `Auth` (Tasks 2 and 4), `Helpers::{csrf, url, t, e}`, `LoginController::safeNext()` (Task 8).
- **Produces:**
  - The block type `login`. A guest gets `<p><a href="/login[?next=…]">Log in</a></p>`. A signed-in user gets `<p>Signed in as {name}</p>`, followed by `<form class="logout-form" method="post" action="/logout">` with the CSRF field and a "Log out" button.
  - `Access::user(): ?User` and `Helpers::user(): ?User`.
  - Display hooks and blocks that need the current user take `Access` (or `Auth`) from the container, as `BlockRenderer` does.

- [ ] **Step 1: Write the failing tests**

`tests/Kernel/Auth/BlocksAndHelpersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel\Auth;

use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\View\View;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Fixtures;
use Xaraya\Tests\Support\Html;

final class BlocksAndHelpersTest extends AuthTestCase
{
    private function blocks(App $app): BlockRepository
    {
        return $app->container()->get(BlockRepository::class);
    }

    /** @return list<string> the text blocks shown on the home page */
    private static function shown(Browser $browser): array
    {
        preg_match_all('/<p class="t">([a-z]+)<\/p>/', $browser->get('/')->getBody()->__toString(), $m);

        return $m[1];
    }

    public function testRoleRulesFollowTheSignedInUser(): void
    {
        $app = $this->probeApp();
        foreach (['everyone' => ['Anonymous'], 'members' => ['Members'], 'staff' => ['Editors', 'Administrators'], 'nobody' => ['Wizards']] as $text => $roles) {
            $this->blocks($app)->create('text', 'sidebar', null, ['body' => "<p class=\"t\">{$text}</p>"], ['roles' => $roles]);
        }
        $this->blocks($app)->create('text', 'sidebar', null, ['body' => '<p class="t">always</p>']);
        $this->user($app, 'mia');
        $this->user($app, 'ed', ['Editors']);

        self::assertSame(['everyone', 'always'], self::shown(new Browser($app)));
        $member = new Browser($app);
        $member->login('mia', self::PASSWORD);
        self::assertSame(['everyone', 'members', 'always'], self::shown($member));
        $editor = new Browser($app);
        $editor->login('ed', self::PASSWORD);
        self::assertSame(['everyone', 'members', 'staff', 'always'], self::shown($editor));
    }

    public function testAMalformedRoleRuleHidesTheBlockFromEveryone(): void
    {
        $app = $this->probeApp();
        $this->db($app)->insert('blocks', ['type' => 'text', 'region' => 'sidebar', 'title' => null, 'config' => ['body' => '<p class="t">broken</p>'], 'sort' => 0, 'visibility' => '{"roles":"Members"}', 'enabled' => true]);
        $this->user($app, 'root', ['Administrators']);
        $admin = new Browser($app);
        $admin->login('root', self::PASSWORD);
        self::assertSame([], self::shown($admin));
        self::assertSame([], self::shown(new Browser($app)));
    }

    public function testTheLoginBlock(): void
    {
        $app = $this->probeApp();
        $this->blocks($app)->create('login', 'header', null);
        $this->user($app, 'ada');
        $browser = new Browser($app);
        $browser->get('/?page=2');
        self::assertStringContainsString('<p><a href="/login?next=%2F%3Fpage%3D2">Log in</a></p>', $browser->body());

        $browser->login('ada', self::PASSWORD);
        $browser->get('/');
        $html = Html::normalize((string) preg_replace('/value="[A-Za-z0-9_-]{43}"/', 'value="T"', $browser->body()));
        self::assertStringContainsString('<p>Signed in as Ada</p><form class="logout-form" method="post" action="/logout"><input type="hidden" name="_csrf" value="T"><button type="submit">Log out</button></form>', $html);
        self::assertSame(303, $browser->post('/logout')->getStatusCode(), 'the block\'s form logs out');
        self::assertStringContainsString('>Log in</a>', $browser->get('/')->getBody()->__toString());
    }

    public function testUserAndCanHelpersInBothEngines(): void
    {
        $theme = Fixtures::theme($this->tmp . '/themes', 'probe', ['engine' => 'php'], [
            'templates/who.php' => "<?= \$x->e(\$x->user()?->handle ?? 'guest') ?> <?= \$x->can('admin', 'hello', 'greeting') ? 'admin' : 'reader' ?> <?= \$x->can('read', 'hello', 'greeting') ? 'reads' : 'blind' ?>",
            'templates/who.twig' => "{{ user() ? user().handle : 'guest' }} {{ can('admin', 'hello', 'greeting') ? 'admin' : 'reader' }} {{ can('read', 'hello', 'greeting') ? 'reads' : 'blind' }}",
        ]);
        foreach (['php', 'twig'] as $engine) {
            $app = $this->installed(['themes.paths' => [dirname($theme)], 'app.theme' => 'probe', 'view.engine' => $engine]);
            $view = $app->container()->get(View::class);
            self::assertSame('guest reader reads', $view->render('who'), $engine);
            $app->container()->get(Auth::class)->actAs($this->users($app)->findByHandle('root') ?? $this->user($app, 'root', ['Administrators']));
            self::assertSame('root admin reads', $view->render('who'), $engine);
        }
    }
}
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Kernel/Auth/BlocksAndHelpersTest.php`
Expected: FAIL (1 failure: `testTheLoginBlock`, because the type `login` is unknown and the block is skipped). The role and helper tests already pass. They pin that the existing visibility rule and helpers now see real roles and users.

- [ ] **Step 3: Implement**

In `src/Kernel/App.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Blocks\BlockTypes;
use Xaraya\Kernel\Blocks\MenuBlock;
use Xaraya\Kernel\Blocks\RecentItemsBlock;
```

with:

```php
use Xaraya\Kernel\Blocks\BlockRepository;
use Xaraya\Kernel\Blocks\BlockTypes;
use Xaraya\Kernel\Blocks\LoginBlock;
use Xaraya\Kernel\Blocks\MenuBlock;
use Xaraya\Kernel\Blocks\RecentItemsBlock;
```

In `src/Kernel/App.php` (2 of 2), replace:

```php
                'text' => TextBlock::class,
                'menu' => MenuBlock::class,
                'recent-items' => RecentItemsBlock::class,
            ]);
```

with:

```php
                'text' => TextBlock::class,
                'menu' => MenuBlock::class,
                'login' => LoginBlock::class,
                'recent-items' => RecentItemsBlock::class,
            ]);
```

In `src/Kernel/Auth/Access.php`, replace:

```php
    /** The signed-in user, or null for a guest. */
    public function user(): ?object;

    /** @return list<string> the current request's role names (block visibility uses them) */
```

with:

```php
    /** The signed-in user, or null for a guest. */
    public function user(): ?User;

    /** @return list<string> the current request's role names (block visibility uses them) */
```

`src/Kernel/Blocks/LoginBlock.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Kernel\Blocks;

use Xaraya\Kernel\Auth\Http\LoginController;

/** The built-in "login" block: a link to the login page for guests; the user's name and a logout button otherwise. */
final class LoginBlock implements Block
{
    public function render(array $config, BlockContext $context): string
    {
        $x = $context->helpers;
        $user = $x->user();
        if ($user === null) {
            $uri = $context->request()?->getUri();
            $next = $uri === null ? '/' : LoginController::safeNext($uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()));

            return '<p><a href="' . $x->e($x->url('auth.login', $next === '/' ? [] : ['next' => $next])) . '">' . $x->e($x->t('Log in')) . '</a></p>';
        }
        return '<p>' . $x->e($x->t('Signed in as {name}', ['name' => $user->displayName()])) . '</p>'
            . '<form class="logout-form" method="post" action="' . $x->e($x->url('auth.logout')) . '">' . $x->csrf()
            . '<button type="submit">' . $x->e($x->t('Log out')) . '</button></form>';
    }
}
```

In `src/Kernel/View/Helpers.php` (1 of 2), replace:

```php
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Config\Config;
```

with:

```php
use Xaraya\Kernel\Auth\Access;
use Xaraya\Kernel\Auth\Session;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Blocks\BlockRenderer;
use Xaraya\Kernel\Config\Config;
```

In `src/Kernel/View/Helpers.php` (2 of 2), replace:

```php
    }

    public function user(): ?object
    {
        return $this->container->get(Access::class)->user();
```

with:

```php
    }

    /** The signed-in user (a guest: null). Templates read ->handle, ->name, ->email or ->displayName(). */
    public function user(): ?User
    {
        return $this->container->get(Access::class)->user();
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/Auth/BlocksAndHelpersTest.php tests/Kernel/Blocks`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/Auth/BlocksAndHelpersTest.php`.
Expected: all green (774 tests).

- [ ] **Step 6: Commit**

```bash
git add src/Kernel/Blocks/LoginBlock.php src/Kernel/App.php src/Kernel/Auth/Access.php src/Kernel/View/Helpers.php tests/Kernel/Auth/BlocksAndHelpersTest.php
git commit -m "feat(auth): role-aware blocks, the login block and typed user()/can() helpers" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 14: Tokens that belong to users, and module scopes

**Files:**
- Create: `modules/tokens/migrations/2026_10_21_200001_add_user_to_tokens.php`
- Modify: `modules/tokens/src/Token.php`, `TokenRepository.php`, `TokenMiddleware.php`, `Cli/TokenCreateCommand.php`, `Cli/TokenListCommand.php`; `src/Kernel/Module/Manifest.php` (`scopes()`), `src/Kernel/Module/ModuleRegistry.php`
- Test: `tests/Module/Tokens/TokenUsersTest.php`

**Interfaces:**
- **Consumes:** `Auth::actAs()` (Task 2); `UserRepository::{find, findByHandle}` (Task 1); `ModuleRegistry::enabled()`; Plan B's `TokensTestCase::{enableTokens, token, xar, db}`.
- **Produces:**
  - `xar_tokens.user_id`: ulid, nullable, FK users, cascade.
  - `Token` gains `?string $userId = null` as its **last** constructor parameter, so Plan B's positional `new Token(...)` calls keep working.
  - `TokenRepository::__construct(Connection, ModuleRegistry)`, `scopes(): list<string>`, and `create(string $name, list<string> $scopes, ?int $blogId, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now, ?string $userId = null)`.
  - `Manifest::scopes(): list<string>`. Each scope must match `<name>:[a-z][a-z0-9_-]{0,31}`; otherwise it throws `ModuleException('{name}: scopes must be strings like "{name}:write"')`.
  - `token:create … [--user=<handle>]` adds `; user <handle>` to the first line. It refuses an unknown user (`Unknown user 'x'`) and an inactive one (`The user 'x' is not active`). `token:list` gains a `user` column.
  - `TokenMiddleware` also takes `Auth` and `UserRepository`. It calls `Auth::actAs($tokenUser or null)`, and answers `TokenError::invalid()` (401) when the token's user is missing or inactive.

- [ ] **Step 1: Write the failing tests**

`tests/Module/Tokens/TokenUsersTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Module\Tokens;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\User;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Http\CallableHandler;
use Xaraya\Kernel\Http\MiddlewareRegistry;
use Xaraya\Kernel\Http\Pipeline;
use Xaraya\Kernel\Module\ModuleException;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Module\Tokens\TokenError;
use Xaraya\Module\Tokens\TokenRepository;
use Xaraya\Tests\Support\Fixtures;

final class TokenUsersTest extends TokensTestCase
{
    /** @param list<string> $roles */
    private function user(string $handle, array $roles = [], string $status = 'active'): User
    {
        return (new UserRepository($this->db($this->app()), ['cost' => 4]))
            ->create($handle, "{$handle}@example.com", 'correct horse battery', '', $status, $roles);
    }

    /** Runs token:blog:write and answers "<user handle or guest> <can delete blog posts: yes/no>". */
    private function identity(string $plaintext, ?User $sessionUser = null): string
    {
        $app = $this->app();
        $auth = $app->container()->get(Auth::class);
        $auth->actAs($sessionUser);
        $middleware = $app->container()->get(MiddlewareRegistry::class)->resolve('token:blog:write');
        $final = new CallableHandler(static fn(): ResponseInterface => new Response(200, [], ($auth->user()->handle ?? 'guest') . ' ' . ($auth->can('delete', 'blog', 'post') ? 'yes' : 'no')));
        $request = (new ServerRequest('POST', '/api/x'))->withHeader('Authorization', 'Bearer ' . $plaintext);

        return (string) (new Pipeline([$middleware], $final))->handle($request)->getBody();
    }

    public function testATokenCanBelongToAUser(): void
    {
        $this->enableTokens();
        $ada = $this->user('ada', ['Editors']);
        [$code, $out, $err] = $this->xar(['token:create', '--name=ci', '--scopes=blog:write', '--user=ada']);
        self::assertSame(0, $code, $err);
        self::assertStringContainsString("'ci' (blog:write; user ada).", $out);
        $lines = explode("\n", trim($out));
        self::assertSame($ada->id, $this->app()->container()->get(TokenRepository::class)->findByPlaintext((string) end($lines))?->userId);
        [, $list] = $this->xar(['token:list']);
        self::assertMatchesRegularExpression('/ci\s+blog:write\s+\*\s+ada\s/', $list);

        $this->user('dee', [], 'disabled');
        foreach ([['--user=nobody', "Unknown user 'nobody'"], ['--user=dee', "The user 'dee' is not active"]] as [$flag, $message]) {
            [$code, , $err] = $this->xar(['token:create', '--name=x', '--scopes=blog:write', $flag]);
            self::assertSame(1, $code);
            self::assertStringContainsString($message, $err);
        }
    }

    public function testAUsersTokenActsAsThatUserAndAPlainTokenAsAGuest(): void
    {
        $this->enableTokens();
        $this->user('ada', ['Editors']);
        $root = $this->user('root', ['Administrators']);
        self::assertSame('ada yes', $this->identity($this->token('blog:write', ['--user=ada'])));
        self::assertSame('guest no', $this->identity($this->token()));
        self::assertSame('guest no', $this->identity($this->token(), $root), 'a session cookie does not lend its user to a token');
    }

    public function testADisabledOrDeletedUsersTokensStopWorking(): void
    {
        $app = $this->enableTokens();
        $ada = $this->user('ada');
        $plaintext = $this->token('blog:write', ['--user=ada']);
        (new UserRepository($this->db($app)))->setStatus($ada, 'disabled');
        try {
            $this->identity($plaintext);
            self::fail('a disabled user\'s token must be refused');
        } catch (TokenError $e) {
            self::assertSame(401, $e->status());
            self::assertSame('Bearer error="invalid_token"', $e->headers()['WWW-Authenticate']);
        }
        $this->db($app)->delete('users', ['id' => $ada->id]);
        self::assertNull($app->container()->get(TokenRepository::class)->findByPlaintext($plaintext), 'deleted with the user');
    }

    public function testEnabledModulesAddScopes(): void
    {
        $this->enableTokens();
        Fixtures::module($this->tmp . '/extra', 'notes', ['scopes' => ['notes:write']]);
        Fixtures::module($this->tmp . '/extra', 'sloppy', ['scopes' => ['write']]);
        $paths = [dirname(__DIR__, 3) . '/modules', $this->tmp . '/extra'];
        $app = fn(): App => $this->boot($paths, ['blog.locks' => $this->tmp . '/locks']);

        [$code, , $err] = $this->xar(['token:create', '--name=x', '--scopes=notes:write'], $app());
        self::assertSame(1, $code);
        self::assertStringContainsString('Scopes must be one or more of: blog:write, media:write', $err);

        $app()->container()->get(ModuleRegistry::class)->enable('notes');
        self::assertSame(['blog:write', 'media:write', 'notes:write'], $app()->container()->get(TokenRepository::class)->scopes());
        [$code, , $err] = $this->xar(['token:create', '--name=x', '--scopes=notes:write,blog:write'], $app());
        self::assertSame(0, $code, $err);

        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('sloppy: scopes must be strings like "sloppy:write"');
        $app()->container()->get(ModuleRegistry::class)->enable('sloppy');
    }

    public function testTheMigrationKeepsExistingTokens(): void
    {
        $app = $this->enableTokens();
        $plaintext = $this->token('blog:write,media:write');
        $migration = require dirname(__DIR__, 3) . '/modules/tokens/migrations/2026_10_21_200001_add_user_to_tokens.php';
        $migration->down($this->db($app)->schema());
        self::assertArrayNotHasKey('user_id', $this->db($app)->select('tokens')->first() ?? []);
        $migration->up($this->db($app)->schema());
        $token = $this->app()->container()->get(TokenRepository::class)->findByPlaintext($plaintext);
        self::assertNotNull($token);
        self::assertSame(['blog:write', 'media:write'], $token->scopes);
        self::assertNull($token->userId);
    }
}
```


- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit tests/Module/Tokens/TokenUsersTest.php`
Expected: FAIL:
- `Call to undefined method Xaraya\Module\Tokens\TokenRepository::scopes()`.
- The migration file is missing.
- `token:create` ignores `--user` (`'Created token … 'ci' (blog:write).'`).
- The identity stays as it was, and the disabled user's token is accepted.

- [ ] **Step 3: Implement**

`modules/tokens/migrations/2026_10_21_200001_add_user_to_tokens.php`:

```php
<?php

declare(strict_types=1);

use Xaraya\Kernel\Db\Migrations\Migration;
use Xaraya\Kernel\Db\Schema\Blueprint;
use Xaraya\Kernel\Db\Schema\Schema;

/**
 * Adds tokens.user_id (FK users, deleted with the user). A column with a foreign key cannot be added in place on
 * every database, so the table is rebuilt and its rows copied; down() rebuilds it without the column.
 */
return new class extends Migration {
    public function up(Schema $schema): void
    {
        $this->rebuild($schema, true);
    }

    public function down(Schema $schema): void
    {
        $this->rebuild($schema, false);
    }

    private function rebuild(Schema $schema, bool $withUser): void
    {
        $schema->create('tokens_next', function (Blueprint $t) use ($withUser): void {
            $t->ulid('id')->primary();
            $t->string('name', 64);
            $t->string('token_hash', 64)->unique();
            $t->json('scopes');
            $t->int('blog_id')->nullable();
            if ($withUser) {
                $t->ulid('user_id')->nullable();
            }
            $t->datetime('last_used_at')->nullable();
            $t->datetime('expires_at')->nullable();
            $t->datetime('created_at');
            $t->foreign('blog_id', 'blogs');
            if ($withUser) {
                $t->foreign('user_id', 'users');
            }
        });
        $columns = 'id, name, token_hash, scopes, blog_id, last_used_at, expires_at, created_at';
        $schema->connection()->query("INSERT INTO {tokens_next} ({$columns}) SELECT {$columns} FROM {tokens}");
        $schema->drop('tokens');
        $schema->rename('tokens_next', 'tokens');
    }
};
```

In `modules/tokens/src/Cli/TokenCreateCommand.php` (1 of 5), replace:

```php
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
```

with:

```php
use DateTimeZone;
use InvalidArgumentException;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
```

In `modules/tokens/src/Cli/TokenCreateCommand.php` (2 of 5), replace:

```php
final class TokenCreateCommand extends Command
{
    public function __construct(private readonly TokenRepository $tokens, private readonly BlogRepository $blogs) {}

    public function name(): string
```

with:

```php
final class TokenCreateCommand extends Command
{
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly BlogRepository $blogs,
        private readonly UserRepository $users,
    ) {}

    public function name(): string
```

In `modules/tokens/src/Cli/TokenCreateCommand.php` (3 of 5), replace:

```php
    public function usage(): string
    {
        return 'token:create --name=<name> --scopes=blog:write,media:write [--blog=<handle>] [--expires=<N>d]';
    }
```

with:

```php
    public function usage(): string
    {
        return 'token:create --name=<name> --scopes=blog:write,media:write [--blog=<handle>] [--user=<handle>] [--expires=<N>d]';
    }
```

In `modules/tokens/src/Cli/TokenCreateCommand.php` (4 of 5), replace:

```php
            $blogId = ($this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'"))->id;
        }
        $expiresAt = null;
        $expires = $input->option('expires');
```

with:

```php
            $blogId = ($this->blogs->find($handle) ?? throw new InvalidArgumentException("Unknown blog '{$handle}'"))->id;
        }
        $userId = null;
        $userHandle = $input->option('user');
        if ($userHandle !== null) {
            $user = $this->users->findByHandle($userHandle) ?? throw new InvalidArgumentException("Unknown user '{$userHandle}'");
            $userId = $user->isActive() ? $user->id : throw new InvalidArgumentException("The user '{$userHandle}' is not active");
        }
        $expiresAt = null;
        $expires = $input->option('expires');
```

In `modules/tokens/src/Cli/TokenCreateCommand.php` (5 of 5), replace:

```php
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
```

with:

```php
            $expiresAt = $now->modify("+{$m[1]} days");
        }
        [$token, $plaintext] = $this->tokens->create($name, array_map('trim', explode(',', $scopes)), $blogId, $expiresAt, $now, $userId);
        $output->line(sprintf(
            "Created token %s '%s' (%s%s%s%s).",
            $token->id,
            $token->name,
            implode(', ', $token->scopes),
            $handle === null ? '' : "; blog {$handle}",
            $userHandle === null ? '' : "; user {$userHandle}",
            $expiresAt === null ? '' : '; expires ' . $expiresAt->format('Y-m-d H:i') . ' UTC',
        ));
```

In `modules/tokens/src/Cli/TokenListCommand.php` (1 of 3), replace:

```php
use DateTimeImmutable;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
```

with:

```php
use DateTimeImmutable;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Kernel\Cli\Command;
use Xaraya\Kernel\Cli\Input;
```

In `modules/tokens/src/Cli/TokenListCommand.php` (2 of 3), replace:

```php
final class TokenListCommand extends Command
{
    public function __construct(private readonly TokenRepository $tokens, private readonly BlogRepository $blogs) {}

    public function name(): string
```

with:

```php
final class TokenListCommand extends Command
{
    public function __construct(
        private readonly TokenRepository $tokens,
        private readonly BlogRepository $blogs,
        private readonly UserRepository $users,
    ) {}

    public function name(): string
```

In `modules/tokens/src/Cli/TokenListCommand.php` (3 of 3), replace:

```php
                implode(',', $token->scopes),
                $token->blogId === null ? '*' : ($this->blogs->findById($token->blogId)->handle ?? '?'),
                $date($token->lastUsedAt),
                $date($token->expiresAt),
            ];
        }
        $output->table(['id', 'name', 'scopes', 'blog', 'last used', 'expires'], $rows);

        return 0;
```

with:

```php
                implode(',', $token->scopes),
                $token->blogId === null ? '*' : ($this->blogs->findById($token->blogId)->handle ?? '?'),
                $token->userId === null ? '-' : ($this->users->find($token->userId)->handle ?? '?'),
                $date($token->lastUsedAt),
                $date($token->expiresAt),
            ];
        }
        $output->table(['id', 'name', 'scopes', 'blog', 'user', 'last used', 'expires'], $rows);

        return 0;
```

In `modules/tokens/src/Token.php` (1 of 3), replace:

```php
use Psr\Http\Message\ServerRequestInterface;

/** A stored API token. The plaintext is never kept: only its sha256 is in xar_tokens. */
final class Token
{
    public const SCOPES = ['blog:write', 'media:write'];
```

with:

```php
use Psr\Http\Message\ServerRequestInterface;

/**
 * A stored API token. The plaintext is never kept: only its sha256 is in xar_tokens. A token with a user acts as that
 * user (Auth::can uses their roles); one without acts only through its scopes, as a guest.
 */
final class Token
{
    /** The base scopes; enabled modules add theirs with "scopes" in module.json (TokenRepository::scopes()). */
    public const SCOPES = ['blog:write', 'media:write'];
```

In `modules/tokens/src/Token.php` (2 of 3), replace:

```php
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
    ) {}
```

with:

```php
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $userId = null,
    ) {}
```

In `modules/tokens/src/Token.php` (3 of 3), replace:

```php
            $date('expires_at'),
            $date('created_at') ?? new DateTimeImmutable('@0'),
        );
    }
```

with:

```php
            $date('expires_at'),
            $date('created_at') ?? new DateTimeImmutable('@0'),
            isset($row['user_id']) ? (string) $row['user_id'] : null,
        );
    }
```

In `modules/tokens/src/TokenMiddleware.php` (1 of 5), replace:

```php
use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Module\Blog\BlogRepository;
```

with:

```php
use Psr\Log\LoggerInterface;
use Throwable;
use Xaraya\Kernel\Auth\Auth;
use Xaraya\Kernel\Auth\UserRepository;
use Xaraya\Module\Blog\BlogRepository;
```

In `modules/tokens/src/TokenMiddleware.php` (2 of 5), replace:

```php
 * Token::class attribute. A route with a {handle} parameter also checks the token's blog pin; routes that reach
 * a blog another way (a post id, a Micropub destination) check Token::allowsBlog() themselves.
 */
final class TokenMiddleware implements MiddlewareInterface
```

with:

```php
 * Token::class attribute. A route with a {handle} parameter also checks the token's blog pin; routes that reach
 * a blog another way (a post id, a Micropub destination) check Token::allowsBlog() themselves.
 * The request's identity becomes the token's user (a guest for a token without one); a token whose user is
 * disabled, pending or deleted is refused like an unknown token.
 */
final class TokenMiddleware implements MiddlewareInterface
```

In `modules/tokens/src/TokenMiddleware.php` (3 of 5), replace:

```php
        private readonly BlogRepository $blogs,
        private readonly LoggerInterface $logger,
        private readonly array $args = [],
    ) {
```

with:

```php
        private readonly BlogRepository $blogs,
        private readonly LoggerInterface $logger,
        private readonly Auth $auth,
        private readonly UserRepository $users,
        private readonly array $args = [],
    ) {
```

In `modules/tokens/src/TokenMiddleware.php` (4 of 5), replace:

```php
            throw TokenError::invalid();
        }
        foreach ($this->args as $scope) {
            if (!$token->hasScope($scope)) {
```

with:

```php
            throw TokenError::invalid();
        }
        $user = $token->userId === null ? null : $this->users->find($token->userId);
        if ($token->userId !== null && ($user === null || !$user->isActive())) {
            throw TokenError::invalid();
        }
        foreach ($this->args as $scope) {
            if (!$token->hasScope($scope)) {
```

In `modules/tokens/src/TokenMiddleware.php` (5 of 5), replace:

```php
        }

        return $handler->handle($request->withAttribute(Token::class, $token));
    }
```

with:

```php
        }

        // The token decides the identity, whatever session cookie came with it.
        $this->auth->actAs($user);

        return $handler->handle($request->withAttribute(Token::class, $token));
    }
```

In `modules/tokens/src/TokenRepository.php` (1 of 5), replace:

```php
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Support\Ulid;
```

with:

```php
use SensitiveParameter;
use Xaraya\Kernel\Db\Connection;
use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Support\Ulid;
```

In `modules/tokens/src/TokenRepository.php` (2 of 5), replace:

```php
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly Connection $db) {}

    /** A new plaintext token: "xar_" and 40 base62 characters from random_bytes, without modulo bias. */
```

with:

```php
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(private readonly Connection $db, private readonly ModuleRegistry $modules) {}

    /** @return list<string> Token::SCOPES plus the scopes of every enabled module */
    public function scopes(): array
    {
        $scopes = Token::SCOPES;
        foreach ($this->modules->enabled() as $manifest) {
            array_push($scopes, ...$manifest->scopes());
        }

        return array_values(array_unique($scopes));
    }

    /** A new plaintext token: "xar_" and 40 base62 characters from random_bytes, without modulo bias. */
```

In `modules/tokens/src/TokenRepository.php` (3 of 5), replace:

```php
     * @return array{0: Token, 1: string} the stored token and its plaintext, which is never stored
     */
    public function create(string $name, array $scopes, ?int $blogId, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now): array
    {
        $name = trim($name);
```

with:

```php
     * @return array{0: Token, 1: string} the stored token and its plaintext, which is never stored
     */
    public function create(string $name, array $scopes, ?int $blogId, ?DateTimeImmutable $expiresAt, DateTimeImmutable $now, ?string $userId = null): array
    {
        $name = trim($name);
```

In `modules/tokens/src/TokenRepository.php` (4 of 5), replace:

```php
        }
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, Token::SCOPES) !== []) {
            throw new InvalidArgumentException('Scopes must be one or more of: ' . implode(', ', Token::SCOPES));
        }
        if ($expiresAt !== null && $expiresAt <= $now) {
```

with:

```php
        }
        $scopes = array_values(array_unique($scopes));
        if ($scopes === [] || array_diff($scopes, $this->scopes()) !== []) {
            throw new InvalidArgumentException('Scopes must be one or more of: ' . implode(', ', $this->scopes()));
        }
        if ($expiresAt !== null && $expiresAt <= $now) {
```

In `modules/tokens/src/TokenRepository.php` (5 of 5), replace:

```php
            'scopes' => $scopes,
            'blog_id' => $blogId,
            'last_used_at' => null,
            'expires_at' => $expiresAt,
```

with:

```php
            'scopes' => $scopes,
            'blog_id' => $blogId,
            'user_id' => $userId,
            'last_used_at' => null,
            'expires_at' => $expiresAt,
```

In `src/Kernel/Module/Manifest.php`, replace:

```php
    }

    /** @return array<string, string> */
    public function requiredModules(): array
```

with:

```php
    }

    /**
     * Bearer-token scopes this module adds to the tokens module's base set, each "<module>:<name>".
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        $out = [];
        foreach ((array) ($this->data['scopes'] ?? []) as $scope) {
            if (!is_string($scope) || preg_match('/^' . preg_quote($this->name, '/') . ':[a-z][a-z0-9_-]{0,31}$/D', $scope) !== 1) {
                throw new ModuleException("{$this->name}: scopes must be strings like \"{$this->name}:write\"");
            }
            $out[] = $scope;
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, string> */
    public function requiredModules(): array
```

In `src/Kernel/Module/ModuleRegistry.php`, replace:

```php
        $manifest->middleware();
        $manifest->grants();
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
```

with:

```php
        $manifest->middleware();
        $manifest->grants();
        $manifest->scopes();
        // Kernel and module migrations run as separate batches so a rollback
        // of the module never takes the kernel tables with it.
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Module/Tokens tests/Module/Blog/Api tests/Module/Micropub`
Expected: PASS. Plan B's token, API and Micropub tests are unchanged and still pass.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Module/Tokens tests/Module/Blog/Api tests/Module/Micropub`.
Expected: all green (779 tests).

- [ ] **Step 6: Commit**

```bash
git add modules/tokens src/Kernel/Module tests/Module/Tokens/TokenUsersTest.php
git commit -m "feat(tokens): tokens can belong to a user; modules declare extra scopes" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 15: The hello admin route, the `hello:write` token route, and the definition of done

**Files:**
- Create: `examples/hello/templates/admin.php`, `examples/hello/templates/admin.twig`
- Modify: `examples/hello/module.json` (`scopes` and `grants`), `examples/hello/src/Routes.php`, `examples/hello/src/HelloController.php`
- Test: `tests/Kernel/KernelAuthDoneTest.php`

**Interfaces:**
- **Consumes:** everything above.
- **Produces:**
  - `hello.admin`: `GET /hello/admin`, with `secureHtml` and `auth:hello.greeting.admin`. It renders `hello::admin` (`count`).
  - `hello.api.create`: `POST /api/hello/greetings` with the JSON body `{"name": "[a-z]{1,64}"}`, using `csrf:off` and `token:hello:write`. It is registered only while `tokens` is enabled (`Routes` takes `ModuleRegistry`). It answers 201 `{"id"}`, or 422 with `fields.name`.
  - `HelloController::{admin, apiCreate}`; the private `insert(string $name): string` is shared with `create()`.
  - The hello manifest declares `"scopes": ["hello:write"]` and the grant Members · greeting · * · comment.

- [ ] **Step 1: Write the failing test**

`tests/Kernel/KernelAuthDoneTest.php`:

```php
<?php

declare(strict_types=1);

namespace Xaraya\Tests\Kernel;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Xaraya\Kernel\App;
use Xaraya\Kernel\Mail\Mailer;
use Xaraya\Kernel\Mail\MemoryMailer;
use Xaraya\Tests\Support\AuthTestCase;
use Xaraya\Tests\Support\Browser;
use Xaraya\Tests\Support\Html;

/** Kernel spec §7, items 1 and 2 and the auth bullets of item 3, end to end through the CLI and App::handle(). */
final class KernelAuthDoneTest extends AuthTestCase
{
    private function site(): App
    {
        return $this->app([
            'install.env_file' => $this->tmp . '/.env',
            'assets.path' => $this->tmp . '/assets',
            'blog.locks' => $this->tmp . '/locks',
            'mail.driver' => 'memory',
            'session.gc_probability' => 0,
        ], [dirname(__DIR__, 2) . '/modules', dirname(__DIR__, 2) . '/examples']);
    }

    private function cli(string ...$args): string
    {
        [$code, $out, $err] = $this->xar($this->site(), array_values($args));
        self::assertSame(0, $code, $out . $err);

        return $out;
    }

    private function api(App $app, ?string $token, string $name): ResponseInterface
    {
        $request = (new ServerRequest('POST', '/api/hello/greetings', ['Content-Type' => 'application/json'], (string) json_encode(['name' => $name])));

        return $app->handle($token === null ? $request : $request->withHeader('Authorization', 'Bearer ' . $token));
    }

    public function testItem1InstallServesThePhoenixHomePage(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $home = $this->site()->handle(new ServerRequest('GET', '/'));
        self::assertSame(200, $home->getStatusCode());
        self::assertStringContainsString('<link rel="stylesheet" href="/assets/theme/phoenix/phoenix.css">', Html::normalize((string) $home->getBody()));
        self::assertFileExists($this->tmp . '/assets/theme/phoenix/phoenix.css');
    }

    public function testItem2LoginThrottlingCsrfResetAndUserPassword(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $app = $this->site();

        $admin = new Browser($app);
        self::assertSame(303, $admin->login('admin', self::PASSWORD)->getStatusCode());
        $admin->get('/login');
        self::assertSame(303, $admin->post('/logout')->getStatusCode());

        self::assertSame(403, (new Browser($app))->post('/login', ['login' => 'admin', 'password' => self::PASSWORD])->getStatusCode(), 'CSRF');

        $attacker = new Browser($app, '198.51.100.66');
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $attacker->login('admin', 'not the password')->getStatusCode());
        }
        self::assertSame(429, $attacker->login('admin', self::PASSWORD)->getStatusCode(), 'throttled');

        $forgot = new Browser($app);
        $forgot->get('/password/forgot');
        $forgot->post('/password/forgot', ['email' => 'admin@example.com']);
        $mailer = $app->container()->get(Mailer::class);
        self::assertInstanceOf(MemoryMailer::class, $mailer);
        self::assertSame(1, preg_match('#http://xar\.test(/password/reset/\S+)#', $mailer->sent[0]->text ?? '', $m));
        $forgot->get($m[1]);
        self::assertSame(303, $forgot->post($m[1], ['password' => 'reset password', 'password_confirmation' => 'reset password'])->getStatusCode());
        self::assertSame(303, (new Browser($app))->login('admin', 'reset password')->getStatusCode());

        $this->cli('user:password', 'admin', '--password=cli password');
        self::assertSame(303, (new Browser($this->site()))->login('admin', 'cli password')->getStatusCode());
    }

    public function testItem3HelloAdminRouteAndTokenScope(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $this->cli('module:enable', 'hello');
        $grant = $this->db($this->site())->fetchOne("SELECT g.component, g.level FROM {grants} g JOIN {roles} r ON r.id = g.role_id WHERE r.name = 'Members' AND g.module = 'hello'");
        self::assertEquals(['component' => 'greeting', 'level' => 300], $grant, 'module:enable seeds the manifest grants');
        $this->cli('user:create', 'mia', '--email=mia@example.com', '--password=' . self::PASSWORD);
        $app = $this->site();

        $member = new Browser($app);
        $member->login('mia', self::PASSWORD);
        self::assertSame(403, $member->get('/hello/admin')->getStatusCode());
        $admin = new Browser($app);
        $admin->login('admin', self::PASSWORD);
        self::assertSame(200, $admin->get('/hello/admin')->getStatusCode());
        self::assertStringContainsString('<h1>Greetings admin</h1>', $admin->body());
        self::assertSame(302, (new Browser($app))->get('/hello/admin')->getStatusCode());

        $this->cli('module:enable', 'blog');
        $this->cli('module:enable', 'tokens');
        $lines = explode("\n", trim($this->cli('token:create', '--name=hello', '--scopes=hello:write')));
        $writer = (string) end($lines);
        $lines = explode("\n", trim($this->cli('token:create', '--name=blog', '--scopes=blog:write')));
        $blogOnly = (string) end($lines);

        $app = $this->site();
        $created = $this->api($app, $writer, 'ada');
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        self::assertSame(403, $this->api($app, $blogOnly, 'bob')->getStatusCode());
        self::assertSame(401, $this->api($app, null, 'bob')->getStatusCode());
        self::assertSame(['greetings' => ['ada']], json_decode((string) $app->handle(new ServerRequest('GET', '/hello.json'))->getBody(), true));
    }

    public function testTheAdminPageIsTheSameInBothEngines(): void
    {
        $this->cli('install', '--admin=admin', '--email=admin@example.com', '--password=' . self::PASSWORD);
        $this->cli('module:enable', 'hello');
        $pages = [];
        foreach (['php', 'twig'] as $engine) {
            $browser = new Browser($this->app([
                'view.engine' => $engine,
                'blog.locks' => $this->tmp . '/locks',
            ], [dirname(__DIR__, 2) . '/modules', dirname(__DIR__, 2) . '/examples']));
            $browser->login('admin', self::PASSWORD);
            $pages[$engine] = Html::normalize($browser->get('/hello/admin')->getBody()->__toString());
        }
        self::assertSame($pages['php'], $pages['twig']);
        self::assertStringContainsString('<p>Greetings so far: 0</p>', $pages['php']);
    }
}
```


- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Kernel/KernelAuthDoneTest.php`
Expected: FAIL. `testItem3HelloAdminRouteAndTokenScope` fails at "module:enable seeds the manifest grants" (`null does not match expected type "array"`). `testTheAdminPageIsTheSameInBothEngines` gets a `405 Method Not Allowed` page, because only `POST /hello/{name}` matches `/hello/admin`. Items 1 and 2 already pass: they pin Tasks 1-12 end to end.

- [ ] **Step 3: Implement**

In `examples/hello/module.json`, replace:

```json
  "routes": "Xaraya\\Module\\Hello\\Routes",
  "migrations": "migrations",
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Hello\\CountGreetings", "priority": 0 }
```

with:

```json
  "routes": "Xaraya\\Module\\Hello\\Routes",
  "migrations": "migrations",
  "scopes": ["hello:write"],
  "grants": [ { "role": "Members", "component": "greeting", "level": "comment" } ],
  "subscribers": [
    { "event": "Xaraya\\Kernel\\Events\\ItemCreated", "listener": "Xaraya\\Module\\Hello\\CountGreetings", "priority": 0 }
```

In `examples/hello/src/HelloController.php` (1 of 3), replace:

```php
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Kernel\View\Page;
```

with:

```php
use Xaraya\Kernel\Hooks\DisplayHooks;
use Xaraya\Kernel\Http\Controller;
use Xaraya\Kernel\Http\Exception\ValidationFailed;
use Xaraya\Kernel\Support\Ulid;
use Xaraya\Kernel\View\Page;
```

In `examples/hello/src/HelloController.php` (2 of 3), replace:

```php
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $id = Ulid::generate();
        $this->db->insert('greetings', ['id' => $id, 'name' => $params['name'], 'created' => new DateTimeImmutable()]);
        $this->events->dispatch(new ItemCreated('hello', 'greeting', $id, ['name' => $params['name']]));
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $input */
```

with:

```php
    public function create(ServerRequestInterface $request, array $params): ResponseInterface
    {
        $id = $this->insert($params['name']);
        $body = $request->getParsedBody();
        /** @var array<string, mixed> $input */
```

In `examples/hello/src/HelloController.php` (3 of 3), replace:

```php
        return $this->json(['id' => $id], 201);
    }
```

with:

```php
        return $this->json(['id' => $id], 201);
    }

    /** Administrators only (route middleware auth:hello.greeting.admin). */
    public function admin(): Page
    {
        return $this->view('hello::admin', ['count' => count($this->greetings())], 'Greetings admin');
    }

    /** POST /api/hello/greetings with {"name": "ada"}: needs a bearer token with the hello:write scope. */
    public function apiCreate(ServerRequestInterface $request): ResponseInterface
    {
        $input = json_decode((string) $request->getBody(), true);
        $name = is_array($input) ? ($input['name'] ?? null) : null;
        if (!is_string($name) || preg_match('/^[a-z]{1,64}$/D', $name) !== 1) {
            throw new ValidationFailed(['name' => 'Must be 1 to 64 lower-case letters']);
        }

        return $this->json(['id' => $this->insert($name)], 201);
    }

    private function insert(string $name): string
    {
        $id = Ulid::generate();
        $this->db->insert('greetings', ['id' => $id, 'name' => $name, 'created' => new DateTimeImmutable()]);
        $this->events->dispatch(new ItemCreated('hello', 'greeting', $id, ['name' => $name]));

        return $id;
    }
```

In `examples/hello/src/Routes.php` (1 of 3), replace:

```php
namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
```

with:

```php
namespace Xaraya\Module\Hello;

use Xaraya\Kernel\Module\ModuleRegistry;
use Xaraya\Kernel\Routing\RouteCollector;
use Xaraya\Kernel\Routing\RouteProvider;
```

In `examples/hello/src/Routes.php` (2 of 3), replace:

```php
final class Routes implements RouteProvider
{
    public function routes(RouteCollector $routes): void
    {
```

with:

```php
final class Routes implements RouteProvider
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function routes(RouteCollector $routes): void
    {
```

In `examples/hello/src/Routes.php` (3 of 3), replace:

```php
        $routes->get('/hello/{id:[0-9a-hjkmnp-tv-z]{26}}', [HelloController::class, 'show'], 'hello.show')->middleware('secureHtml', 'conditional');
        $routes->post('/hello/{name:[a-z]+}', [HelloController::class, 'create'], 'hello.create');
    }
}
```

with:

```php
        $routes->get('/hello/{id:[0-9a-hjkmnp-tv-z]{26}}', [HelloController::class, 'show'], 'hello.show')->middleware('secureHtml', 'conditional');
        $routes->post('/hello/{name:[a-z]+}', [HelloController::class, 'create'], 'hello.create');
        $routes->get('/hello/admin', [HelloController::class, 'admin'], 'hello.admin')->middleware('secureHtml', 'auth:hello.greeting.admin');
        // The token API needs the tokens module, which provides the `token` middleware.
        if ($this->modules->isEnabled('tokens')) {
            $routes->post('/api/hello/greetings', [HelloController::class, 'apiCreate'], 'hello.api.create')->middleware('csrf:off', 'token:hello:write');
        }
    }
}
```

`examples/hello/templates/admin.php`:

```php
<h1><?= $x->e($x->t('Greetings admin')) ?></h1>
<p><?= $x->e($x->t('Greetings so far: {count}', ['count' => $count])) ?></p>
```

`examples/hello/templates/admin.twig`:

```twig
<h1>{{ t('Greetings admin') }}</h1>
<p>{{ t('Greetings so far: {count}', {count: count}) }}</p>
```


- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Kernel/KernelAuthDoneTest.php tests/Kernel/HelloModuleTest.php`
Expected: PASS.

- [ ] **Step 5: Run the checks**

Run: `composer test && composer stan && composer cs`, then the MySQL command on `tests/Kernel/KernelAuthDoneTest.php tests/Kernel/HelloModuleTest.php`.
Expected: all green (783 tests).

- [ ] **Step 6: Commit**

```bash
git add examples/hello tests/Kernel/KernelAuthDoneTest.php
git commit -m "test: pin the kernel Plan 3 definition of done; hello admin and token routes" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

### Task 16: README, `.env.example`, follow-ups and a manual smoke test

**Files:**
- Modify: `README.md`, `.env.example`, `docs/superpowers/plans/2026-10-11-phoenix-blog-plan-b-followups.md`

**Interfaces:**
- **Consumes:** the commands, routes, settings and rules of Tasks 1-15.
- **Produces:** the quick start now runs `bin/xar install`. The README gains a "Users, roles and sign-in" section, the `login` block and block `roles` rules, token users and module scopes, and the upgrade path for an install that has no users.

- [ ] **Step 1: Document**

In `.env.example`, replace:

```sh
# Keep ASSET_URL same-origin: the secureHtml CSP (style-src/script-src 'self') blocks off-origin assets.
# ASSET_URL=/assets
```

with:

```sh
# Keep ASSET_URL same-origin: the secureHtml CSP (style-src/script-src 'self') blocks off-origin assets.
# ASSET_URL=/assets
# MAIL_DRIVER=log
# MAIL_FROM=phoenix@example.com
# SESSION_LIFETIME=120
# TRUSTED_PROXIES=10.0.0.1
```

In `README.md` (1 of 4), replace:

````markdown
```sh
composer install
cp .env.example .env
bin/xar migrate
bin/xar asset:publish
MODULE_PATHS=modules,examples bin/xar module:enable hello   # optional: the example module
bin/xar serve
```

Set `MODULE_PATHS` in `.env` to keep the example module discoverable. With `APP_DEBUG=false`
(production), config and routes are cached under `var/cache`; config is rebuilt when
`config/app.php` or `.env` changes. `bin/xar cache:clear` removes the config and route caches, the file
````

with:

````markdown
```sh
composer install
bin/xar install        # asks for the first administrator; or --admin=<handle> --email=<email> --password=<password>
MODULE_PATHS=modules,examples bin/xar module:enable hello   # optional: the example module
bin/xar serve
```

`bin/xar install` checks PHP, writes `.env` from `.env.example` when it is missing, creates the SQLite file (or
checks `DB_DSN`), runs the migrations, seeds the roles and grants, creates the administrator and publishes the
theme's assets. It refuses a database that already has users; `--force` repeats the steps (an existing
administrator is left as it is). Set `MODULE_PATHS` in `.env` to keep the example module discoverable. With `APP_DEBUG=false`
(production), config and routes are cached under `var/cache`; config is rebuilt when
`config/app.php` or `.env` changes. `bin/xar cache:clear` removes the config and route caches, the file
````

In `README.md` (2 of 4), replace:

```markdown
  Twig has the same helpers as functions and escapes automatically.
- Blocks live in `xar_blocks` and render in theme regions. Modules ship block types (`"blocks"`) and
  seed instances on first install (`"blockDefaults"`). Built-in types: `text`, `menu`, `recent-items`.
  **Security:** `text` blocks with format `html` (the default) output their content as raw, trusted HTML.
  Only trusted administrators may edit block content until users and auth arrive in kernel Plan 3.
- Display hooks: a module's `"displayHooks"` add HTML to other modules' items, bound in `xar_hooks`.
  Toggle a binding with `bin/xar hook:disable <observer> <subject> [itemtype]` / `hook:enable`.
```

with:

```markdown
  Twig has the same helpers as functions and escapes automatically.
- Blocks live in `xar_blocks` and render in theme regions. Modules ship block types (`"blocks"`) and
  seed instances on first install (`"blockDefaults"`). Built-in types: `text`, `menu`, `login`, `recent-items`.
  **Security:** `text` blocks with format `html` (the default) output their content as raw, trusted HTML.
  Only trusted administrators may edit block content.
  A block's `visibility` may list `roles` (`{"roles": ["Members"]}`): it shows only to a request that has one of
  them. Guests have `Anonymous`; signed-in users also have `Members` and their own roles. A malformed rule hides
  the block. The built-in `login` block shows a "Log in" link to guests and the user's name with a logout button
  otherwise.
- Display hooks: a module's `"displayHooks"` add HTML to other modules' items, bound in `xar_hooks`.
  Toggle a binding with `bin/xar hook:disable <observer> <subject> [itemtype]` / `hook:enable`.
```

In `README.md` (3 of 4), replace:

```markdown
Try it with the examples: `MODULE_PATHS=modules,examples bin/xar module:enable hello`, then
`module:enable hello-hooks`, and open `/hello`.

## Development
```

with:

```markdown
Try it with the examples: `MODULE_PATHS=modules,examples bin/xar module:enable hello`, then
`module:enable hello-hooks`, and open `/hello`.

## Users, roles and sign-in

Users sign in at `/login` with their handle or email and leave with the logout button (a POST to `/logout`).
`/password/forgot` mails a link to `/password/reset/<token>` that works once, for 60 minutes; the page answers the
same whether or not the address has an account. `bin/xar user:create <handle> --email=<email> [--role=Editors]`
adds a user and `bin/xar user:password <handle>` sets a password from the shell. Both ask for the password unless
`--password=` is given. Changing a password ends that user's sessions everywhere.

- **Roles:** Administrators (admin on everything), Editors (delete on every module whose manifest sets
  `"content": true`), Authors, Members and Anonymous (read on everything). A role may have a parent and gets its grants.
  Modules seed their own grants on `module:enable` with `"grants": [{"role", "component", "instance", "level"}]`;
  re-enabling restores a deleted default grant but keeps a changed level.
- **Checks:** `$x->can('edit', 'blog', 'post', $item)` in templates, `Auth::can()` in code, `auth` (signed in)
  or `auth:<module>.<component>.<level>` on routes. An `own` grant matches an item whose `owner_id` is the user's
  id. Blog posts have no owner yet, so the Authors grants on `blog.post.own` have no effect until a later plan adds one.
  A guest who fails a route check is sent to `/login?next=…` from an HTML page and gets a 401 elsewhere (JSON on
  `.json`, `/api` and JSON-accepting requests); a signed-in user gets a 403.
- **Sessions:** a database row (`xar_sessions`) keyed by the sha256 of an HttpOnly, SameSite=Lax cookie
  (`xar_session`). The cookie is Secure unless `APP_DEBUG=true` and the request is plain HTTP. Sessions expire
  after `SESSION_LIFETIME` idle minutes (default 120); the id changes at login. Pages that use a session are sent
  with `Cache-Control: private, no-cache`.
- **CSRF:** every POST, PUT, PATCH and DELETE needs the session's token, from `$x->csrf()` (a hidden field) or an
  `X-CSRF-Token` header, unless its route is marked `csrf:off` (bearer-token APIs). A missing or wrong token is a 403.
- **Throttling:** 5 failed logins in 15 minutes lock that IP out of that account (a handle and its email count
  together). `throttle:<key>,<max>,<seconds>` limits any route per IP (429 with `Retry-After`). The IP is
  `REMOTE_ADDR`; behind a reverse proxy, list the proxy addresses in `TRUSTED_PROXIES` so `X-Forwarded-For` is used.
- **Mail:** `MAIL_DRIVER=log` (the default) writes each message to `var/mail/*.eml` instead of sending it;
  `MAIL_DRIVER=mail` sends with PHP's `mail()` from `MAIL_FROM`. There is no SMTP client yet: use the server's MTA.
  `APP_URL` must be set: reset links are absolute.

**Upgrading an install that has no users yet** (one that predates sign-in): run `bin/xar install`. It runs the new
migrations, seeds the roles and the grants of the enabled modules, and creates the administrator; nothing else
changes.

## Development
```

In `README.md` (4 of 4), replace:

```markdown
`token:create` prints the token (`xar_` and 40 letters and digits) once; only its sha256 is stored.
`bin/xar token:list` shows ids, scopes, blog and last use (never a token), and `bin/xar token:revoke <id>`
deletes one. A token made with `--blog` can only write to that blog. Send it as `Authorization: Bearer xar_…`;
a token in a query string is ignored. The two Micropub routes (`/micropub` and `/micropub/media`) also accept it
as an `access_token` form field, but not both that and the header; no other route does.
```

with:

```markdown
`token:create` prints the token (`xar_` and 40 letters and digits) once; only its sha256 is stored.
`bin/xar token:list` shows ids, scopes, blog, user and last use (never a token), and `bin/xar token:revoke <id>`
deletes one. A token made with `--blog` can only write to that blog. A token made with `--user=<handle>` acts as
that user (route checks and `can()` use the user's roles) and stops working when the user is disabled or deleted;
a token without a user acts only through its scopes. Modules add scopes with `"scopes": ["<module>:<name>"]` in
`module.json` (the hello example declares `hello:write`). Send it as `Authorization: Bearer xar_…`;
a token in a query string is ignored. The two Micropub routes (`/micropub` and `/micropub/media`) also accept it
as an `access_token` form field, but not both that and the header; no other route does.
```

In `docs/superpowers/plans/2026-10-11-phoenix-blog-plan-b-followups.md`, replace:

```markdown
## Worth doing next

- **Require `app.url`.** When `tokens` or `micropub` is enabled, require `app.url` at boot. Media `Location`, `media:localize`'s own-upload skip, and new posts' `item_id` all assume it is set.
- **Missing `nosniff`.** The media upload JSON response and Micropub's own JSON errors still don't send `X-Content-Type-Options: nosniff`.
- **Where `ApiAccess` lives.** `ApiAccess` lives in the blog module but is a tokens concern; micropub imports a blog HTTP class to use it. Move it to something like `Token::assertAllows(int $blogId)`.
```

with:

```markdown
## Worth doing next

- **Require `app.url`.** When `tokens` or `micropub` is enabled, require `app.url` at boot. Media `Location`, `media:localize`'s own-upload skip, and new posts' `item_id` all assume it is set. (Kernel Plan 3: password reset links refuse to be sent without an absolute `app.url`; the boot check is still open.)
- **Missing `nosniff`.** The media upload JSON response and Micropub's own JSON errors still don't send `X-Content-Type-Options: nosniff`.
- **Where `ApiAccess` lives.** `ApiAccess` lives in the blog module but is a tokens concern; micropub imports a blog HTTP class to use it. Move it to something like `Token::assertAllows(int $blogId)`.
```


- [ ] **Step 2: Smoke-test in a `/tmp` clone with a throwaway database**

This step never touches the checkout's `var/` and never touches `var/database.sqlite`:
- It runs in a fresh clone under `/tmp`, which has no `var/cache/config.php` to shadow `DB_DSN`.
- `DB_DSN` points every command and the web server at `/tmp/phoenix-auth-smoke.sqlite`.
- `APP_DEBUG=true` stops a config cache being written, and keeps the session cookie usable over plain HTTP.

Record `ls -l var/database.sqlite` before and after.

```bash
ls -l var/database.sqlite
export DB_DSN=sqlite:/tmp/phoenix-auth-smoke.sqlite APP_DEBUG=true APP_URL=http://127.0.0.1:8099 MODULE_PATHS=modules,examples
rm -rf /tmp/phoenix-auth-smoke /tmp/phoenix-auth-smoke.sqlite /tmp/phoenix-auth-smoke.jar
git clone -q . /tmp/phoenix-auth-smoke && cd /tmp/phoenix-auth-smoke && composer install -q
bin/xar install --admin=admin --email=admin@example.com --password='smoke test password'
bin/xar module:enable hello
bin/xar user:create mia --email=mia@example.com --password='member password'
php -S 127.0.0.1:8099 -t public public/index.php >/tmp/phoenix-auth-smoke.log 2>&1 & SERVER=$!; sleep 1
U=http://127.0.0.1:8099; J=/tmp/phoenix-auth-smoke.jar
csrf() { curl -s -c $J -b $J "$U$1" | sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' | head -1; }
curl -s -o /dev/null -w 'home %{http_code}\n' $U/
curl -s -o /dev/null -w 'no-csrf %{http_code}\n' -d login=admin -d 'password=smoke test password' $U/login
T=$(csrf /login); curl -s -c $J -b $J -o /dev/null -w 'login %{http_code} %{redirect_url}\n' -d "_csrf=$T" -d login=admin --data-urlencode 'password=smoke test password' $U/login
curl -s -b $J -o /dev/null -w 'admin-page %{http_code}\n' $U/hello/admin
T=$(csrf /login); curl -s -c $J -b $J -o /dev/null -w 'logout %{http_code}\n' -d "_csrf=$T" $U/logout
curl -s -b $J -o /dev/null -w 'admin-page-after-logout %{http_code} %{redirect_url}\n' $U/hello/admin
T=$(csrf /login); curl -s -c $J -b $J -o /dev/null -w 'member-login %{http_code}\n' -d "_csrf=$T" -d login=mia --data-urlencode 'password=member password' $U/login
curl -s -b $J -o /dev/null -w 'member-admin-page %{http_code}\n' $U/hello/admin
rm -f $J
T=$(csrf /password/forgot); curl -s -c $J -b $J -o /dev/null -w 'forgot %{http_code}\n' -d "_csrf=$T" -d email=admin@example.com $U/password/forgot
LINK=$(grep -ho 'http://127.0.0.1:8099/password/reset/[A-Za-z0-9_-]*' var/mail/*.eml | tail -1); echo "mailed ${LINK%/*}/…"
P=${LINK#$U}; T=$(csrf $P); curl -s -c $J -b $J -o /dev/null -w 'reset %{http_code} %{redirect_url}\n' -d "_csrf=$T" --data-urlencode 'password=a reset password' --data-urlencode 'password_confirmation=a reset password' $U$P
rm -f $J
T=$(csrf /login); curl -s -c $J -b $J -o /dev/null -w 'login-new-password %{http_code}\n' -d "_csrf=$T" -d login=admin --data-urlencode 'password=a reset password' $U/login
rm -f $J
for i in 1 2 3 4 5 6; do T=$(csrf /login); curl -s -c $J -b $J -o /dev/null -w "attempt-$i %{http_code}\n" -d "_csrf=$T" -d login=admin -d password=wrong $U/login; done
kill $SERVER
cd - >/dev/null; rm -rf /tmp/phoenix-auth-smoke /tmp/phoenix-auth-smoke.sqlite /tmp/phoenix-auth-smoke.jar /tmp/phoenix-auth-smoke.log
unset DB_DSN APP_DEBUG APP_URL MODULE_PATHS
ls -l var/database.sqlite
```

Expected output, after the first `ls` line. The PHP version and the number of migrations may differ:

```
PHP 8.x.y with pdo, mbstring, json.
Wrote .env from .env.example; edit APP_URL and the database settings there.
Database: sqlite.
Ran 8 migration(s).
Roles and grants are in place.
Created the administrator 'admin'.
Theme 'phoenix' is active and its assets are published.
Installed. Start it with `bin/xar serve` and log in at http://127.0.0.1:8099/login
Enabled module 'hello'.
Created user 'mia'.
home 200
no-csrf 403
login 303 http://127.0.0.1:8099/
admin-page 200
logout 303
admin-page-after-logout 302 http://127.0.0.1:8099/login?next=%2Fhello%2Fadmin
member-login 303
member-admin-page 403
forgot 200
mailed http://127.0.0.1:8099/password/reset/…
reset 303 http://127.0.0.1:8099/login?reset=1
login-new-password 303
attempt-1 422
attempt-2 422
attempt-3 422
attempt-4 422
attempt-5 422
attempt-6 429
```

Then the last `ls -l var/database.sqlite` line is identical to the first, and `git status --short` in the checkout shows only the three documentation files.

- [ ] **Step 3: Run the checks**

Run: `composer test && composer stan && composer cs`
Expected: all green (783 tests).

- [ ] **Step 4: Commit**

```bash
git add README.md .env.example docs/superpowers/plans/2026-10-11-phoenix-blog-plan-b-followups.md
git commit -m "docs: document users, roles, sessions, CSRF, throttling, mail and install" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Parked

These were found while the plan was built and deliberately left out. Record them in a `2026-10-21-phoenix-kernel-auth-followups.md` when the plan is executed.

- **SMTP.** The `mail.driver` has no `smtp` value yet (decision 11). A minimal socket client is the next step, tested against a fake server on a loopback port.
- **Session changes are lost when the handler throws.** `SessionMiddleware` saves after a normal return only. A session started while rendering an error page (say, a CSRF field in a block on a 403 page) is not stored, so that page's token will not validate.
- **Forgot-password timing.** The response is the same, but sending mail to a real account takes longer than doing nothing (the `log` driver costs microseconds; `mail()` may cost much more). Queue the mail, or always do the same work.
- **A password reset does not lift a login lockout** for the IP and account; the user waits out the 15 minutes.
- **bcrypt reads only the first 72 bytes of a password** (`PASSWORD_DEFAULT` in PHP 8.3/8.4). Document it or pre-hash.
- **`--password=` on `install` and `user:create` shows in the process list.** The prompts do not. Add `--password-file=` or an environment variable for Docker secrets.
- **`tokens` requires `blog`** (the blog pin lives in `TokenMiddleware`), so hello's token route needs blog enabled. Move the pin check into the blog module so tokens can stand alone.
- **Kernel auth paths are reserved:** a module that registers `/login` makes FastRoute fail at boot.
- **Grants are re-seeded on every `module:enable`,** so a default grant an administrator deleted comes back. Track seeded grants if that matters once there is an admin UI.
- **No admin UI** for users, roles or grants. Users have CLI commands; roles, parents and grants are changed in the database.
- **Throttle rows** are collected on 1% of new windows; there is no command.

## Self-review (done while writing this plan)

- **Spec coverage:**
  - §3.3 global Session, Auth and Csrf: Tasks 3, 4 and 5. `auth` and `auth:`: Task 6. `throttle`: Task 7.
  - §4.1 tables: Tasks 1, 3, 7, 10 and 14.
  - §4.2 roles, levels and default grants: Task 1.
  - §4.3 checks: Task 2.
  - §4.4 sessions, password rehash, throttling, bearer tokens and password reset: Tasks 3, 8, 14 and 10.
  - §4.5 install: Task 12.
  - §5.2 helpers `can`, `csrf` and `user`: Tasks 2, 5 and 13. §5.5 `roles` and the `login` block: Task 13.
  - §6.1 `install`, `user:create` and `user:password`: Tasks 11 and 12.
  - §6.2 `mail.*` and `session.*`: Tasks 3 and 9.
  - §6.3 exceptions: Task 6 (they shipped in Plan 1).
  - §7 item 1: Task 15 `testItem1…` and the Task 16 smoke run. Item 2: Task 15 `testItem2…` and the smoke run. Item 3's two bullets: `testItem3…`. Item 4: the checks of every task (Postgres in CI).
- **Step scan:** every code step embeds the file, or the exact replace/with text, from the proof build. Each "replace" block occurs exactly once in its file at that point; the generator checked this by replaying every replacement.
- **Type consistency:** these names are used identically everywhere:
  - `Auth::{actAs, user, check, roles, can}`, `Session::{fresh, loaded, newId, hash, token, login, logout, regenerate, saved}`, `SessionStore::{load, save, touch, forget, deleteForUser, gc}`.
  - `Throttle::{retryAfter, hit, clear}`, `ClientIp::of`, `UserRepository::{create, find, findByHandle, findByLogin, verifyPassword, verifyNothing, setPassword, setStatus, recordLogin, rolesOf, addRole, count, checkPassword}`.
  - `Grants::{ensureRole, setParent, grant, seed, forRoles, isValidPart}`, `PasswordResets::{send, find, reset}`, `Manifest::{grants, scopes}`, `TokenRepository::scopes`, `LoginController::safeNext`.
- **Review Focus:** each of the five lines has a named test in its owning task. The other candidates are covered too:
  - Timing at login: Task 8 `testAnUnknownLoginCostsAPasswordCheckLikeAWrongPassword`.
  - Timing at reset: the same page and status, Task 10 `testTheAnswerIsTheSameWhetherOrNotTheAccountExists`; mail timing is parked.
  - Grant cycles: Task 1 `testForRolesFollowsParentsAndStopsAtACycle`.
  - Rehash on login: Task 1 `testAnOutdatedHashIsReplacedWhenThePasswordIsRight` and Task 8 `testLoginUpgradesAnOutdatedPasswordHash`.
  - Cookie flags under debug: Task 3 `testTheCookieIsSecureExceptInDebugModeOverPlainHttp`.
  - A JSON 401 rather than a redirect: Task 6 `testAGuestGetsAJson401OnJsonRequestsAndA401OnOtherMethods`.
  - A shared cache serving session pages: Task 3, `private, no-cache`.
- **Proportion:** the user asked for Plan B's style, with complete test and implementation code per task, so code blocks are most of this document. The decisions above are what the code alone does not say.
