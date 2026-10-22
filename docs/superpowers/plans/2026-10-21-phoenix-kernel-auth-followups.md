# Kernel Plan 3 (users, roles, auth) follow-ups

Loose ends from building and reviewing `2026-10-21-phoenix-kernel-auth-plan.md`. Nothing here blocks the plan's
definition of done. Items tagged (final wave) were flagged as candidates for the final review.

## Worth doing next

- **SMTP.** `mail.driver` has no `smtp` value yet. A minimal socket client is the next step, tested against a fake server on a loopback port.
- **Blog delete path must revoke pinned tokens.** Tokens pinned with `--blog` keep a plain nullable `blog_id` (the FK was dropped when tokens were decoupled from blog), and ids are never reused. Blog has no delete path today; when one is added it must revoke the tokens pinned to that blog, or they linger until revoked by hand.
- **Move the blog pin check fully into the blog module.** Tokens no longer require blog, but a pinned token with blog disabled is not failed closed (ruling: the pin is a blog concept; scopes still gate routes).
- **Session changes are lost when the handler throws.** `SessionMiddleware` saves after a normal return only. A session started while rendering an error page (say, a CSRF field in a block on a 403 page) is not stored, so that page's token will not validate. Related (F11): a disabled user's session row lingers when a route throws.
- **Forgot-password timing.** The response is the same, but active accounts cost a DB write plus the mail send. Send after the response, or queue the mail. Also: a DB failure in `send()` 500s only for active accounts; the uniform 500 for a missing `app.url` is not pinned for unknown inputs; there is no per-address forgot throttle; a bad password on a dead link shows the form, not the expired page.
- **A password reset does not lift a login lockout** for the IP and account; the user waits out the 15 minutes.
- **Handle-to-email 429 pairing oracle** at login (inherent to the hit-then-check ruling): a handle and its email share a bucket.
- **Password from files or the environment.** `--password-stdin` exists; add `--password-file=` or an environment variable for Docker secrets. `--password-stdin` on a TTY echoes; `user:create` prompts for the password before validating handle and email; the real `stty` path is untested.
- **Trusted proxies.** No CIDR support (exact IPs only); an `X-Forwarded-For` hop with a port or zone id ends the walk. Secure cookie is off behind a TLS proxy in debug mode (honour a trusted `X-Forwarded-Proto` later).
- **No admin UI** for users, roles or grants.
- **Kernel auth paths are reserved:** a module that registers `/login` makes FastRoute fail at boot. Give it a clear error.
- **Grants are re-seeded on every `module:enable`** (accepted ruling), so a default grant an administrator deleted comes back. Track seeded grants if that matters once there is an admin UI.
- **Throttle rows** are collected on 1% of new windows; there is no command. Session GC runs on dirty saves only (about 1 in 100); there is no GC command either.
- **Upstream (Plan B): require `app.url` at boot** when `tokens` or `micropub` is enabled (see the Plan B follow-ups).

## Sessions and caching

- No `Vary: Cookie` on public pages; optional `__Host-` cookie prefix.
- Last write wins on session data (two tabs can race a CSRF token).
- A stale tab's failed save sends an expiring cookie that can clobber a fresh login cookie from another tab (no worse than before).
- (final wave) Error responses thrown after `Authenticate` get no private Cache-Control: have `ErrorHandler` set `private, no-store` when an identity or session is present. 401/403 lack `no-store`, and 401 lacks `WWW-Authenticate`.
- Auth pages are `private, no-cache`, not `no-store`.
- Token routes: no `no-store` on errors after token acceptance (accepted; RFC 9111).

## Auth and routes

- (final wave) `auth:<module>.<component>.none` admits everyone: reject `none`. Spec segments are not validated as identifiers, and a bad spec is only caught on the first request (parse statically at route compile).
- (final wave) Grants memo key uses `implode("\0")`: switch to `json_encode`. A guest's `own` grant matches with no item (fail when the user is null). Confirm the installer never calls `can()` before install (pre-install guests fail `can('read')`).
- `csrf:` marker accepts any argument (reject anything but `off`); `'_csrf'` is duplicated (use `Session::TOKEN_KEY`); `withCsrf` hard-codes names; the body field beats the header (documented).
- Login: throttle key mixes a numeric user id and a raw login (prefix `u:`/`n:`); a rehash is written on an inactive account; the docblock "(no login CSRF)" is misleading.
- `whoami` uses `json_decode` without `THROW`.
- Pre-existing: a spurious 403 `wrongBlog` when a non-blog `{handle}` equals a blog handle.

## Users, roles and install

- Dummy hash uses the current cost, not the stored cost. Check-then-insert races raise `PDOException`.
- Role-name regex is duplicated (plan-mandated); the `isValidPart` docblock says lower-case; a typo in a manifest role creates a new role.
- `setParent`'s ancestor walk has no visited set (an infinite loop if a cycle already exists from direct SQL). The seed-rollback cache reset is untested.
- `install --force` leaves an existing non-admin `--admin` user unrepaired (ensure the role, or say so). `.env` is written after the database is chosen and migrated (say so in the message). A rerun with a missing email or password is untested. `ConnectionFactory` emits a raw `mkdir` warning (pre-existing).

## Tokens

- The rebuild migrations are fragile on a manual re-run or a MySQL mid-failure (guard the `tokens_next` table and the column). MySQL keeps the `_next_` index names.
- A manifest module named `media` could co-own `media:write`: reserve the base prefixes.
- No test that a user token is limited to that user's roles; blog-files-absent is untested; no expired-token case.
- MySQL keeps the `tokens_next_*` index and FK names after the rename. FK names are schema-global, so a future rebuild that creates `tokens_next` again could collide with them.
- Route rule: `token:` must come before `auth`/`auth:` on a route, because the token sets the identity. No route combines them today (README tokens section says so).

## Mail

- Long subjects fold with CRLF plus a space (legal; only short subjects are tested). The real `mail()` header-array shape is untested (seam).
- (final wave) `testAFailedLogWriteLeavesNoFileBehind` needs a root skip (`is_writable` check).

## Tests

- Duplicate-insert retry branch in `Throttle` is untested end to end (needs a final `Connection`). The JSON 429 is untested. `testADeletedUserIsAGuest` never reaches the missing-user branch (the FK cascades). No tests for `_csrf[]` arrays, the JSON header, or body-over-header precedence; none for HEAD redirects, logout cookie replay, or the private Cache-Control on the signed-in login block page. The enumeration test drops Set-Cookie (mask the value instead).
- Task 13 and 15 tests passed before their implementation existed, so they act as pins, not RED proofs (F9).
- `SessionTest.php` was seen as binary by diff: check for a stray byte.
- No test for an integer `owner_id` (`Auth::field` casts ints; only string owners are tested).
- `Authenticate` checks `isActive()` itself and `Auth::actAs()` checks it again (duplicate check).
- The blog Plan B follow-ups still list `nosniff` on media and Micropub JSON errors and where `ApiAccess` lives.
