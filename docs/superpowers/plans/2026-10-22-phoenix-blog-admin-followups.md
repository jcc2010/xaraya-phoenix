# Phoenix blog admin: follow-ups

Deferred from the blog admin plan (`2026-10-22-phoenix-blog-admin-plan.md`). Items marked **final wave candidate** may
be fixed by the final review instead of waiting here.

## Scope not built

- No user, role, grant or token UI; no bulk actions; no revision history.
- Mode switching and mirror creation stay on `bin/xar blog:mode` and `bin/xar blog:create`.
- Authors see every post in the published tab (they can already read them publicly) and act only on their own.
- The pin is per blog and needs `edit` on `blog · blog`, so Authors cannot pin.
- Mirror blogs' source-set fields are read-only so edits can't be lost; the form cannot change them.
- The spec's open questions remain open.

## Data and migration

- The owner migration has no `down()`, and re-running it after a manual rollback fails.
- [Done] **Owner backfill CLI**: `bin/xar blog:owner <handle> --user=<user> [--all | --ids=…] [--dry-run]`.
- No `replace()` owner pin; no Micropub owner test; PostApiTest mixes container handles; no migration-level test that
  real posts, `post_tags` and `post_search` survive the owner migration.
- Tag names cannot contain commas in the form.
- Deleting the pinned post and clearing the pin are not atomic; the blog is read before the delete and may be stale; the
  flash has no "(unpinned)" note. Creating a post with the pin is not atomic either.
- **Orphan-media sweep.** An upload is stored before validation, so a post that then fails validation, an abandoned 422,
  or a replaced image leaves a media row no post uses. It is reused by `upload_id` or a re-upload but never collected.
  Add a sweep of unreferenced media (same bytes share one file, each upload adds a row).

## Search and lists

- Admin title search matches non-ASCII titles only as typed on SQLite and PG-C (`LOWER()` is ASCII-only there, so an
  unfolded `LIKE` is OR-ed in): "Été" finds "Été", but "été" does not.
- Untested: the dashboard Posts link and a page past the end. `COUNT` runs redundantly when `q` is empty.
- No unit test for `Paging::pageNumber`.

## Post form

- A kind that is always sent, or a trimmed title, causes a false "Saved".
- A post with only `content_text` shows an empty content box.
- The preview still links its tags to the public tag pages, which leaves the form (the permalink is gone).

From the image upload:

- No test for a non-existent or malformed `upload_id`.
- The remove box is shown with a carried upload.

Other:

- A bad `app.timezone` 500s on a date submit (add a boot check); no DST gap or fall-back tests.

## Mirrors and sync

- A web "Sync now" of a large first sync can hit `max_execution_time` (add a CLI note or `set_time_limit`).
- A quick sync in a web request is bounded only by `Syncer::MAX_PAGES`, and the safety-valve branch is unreachable
  from the web.

## Platform

- `adminLinks` re-parses manifests on every signed-in page (cache it).
- A bad admin link logs on every render; there is no enable-time validation, and routes are not registered at enable.
- `NoStore` and `SecureHtml` rethrow loses the `HttpException` subclass (for example the fields of `ValidationFailed`);
  admin uses inline 422 pages so this is not hit today.
- `DashboardController` re-evaluates `canCreate` and `canAdminBlogs` per blog.
- A tautological `item()` assertion inside the Forbidden catch in the AdminAccess test.
- The mode sentence in settings is split into two translatable fragments.
- **Consolidate the controller helpers.** The admin controllers each still carry their own `nativeBlog()`/`target()`
  style loaders and 404/403 ordering, and the JSON API and Micropub each locate a post their own way; one shared
  "post for a write" helper (load, pin, item check, mirror check) would keep the rules in one place. `flashTitle()` and
  the form-body parsing are already shared on `AdminPage`.
- **CSS.** The admin tables scroll sideways on narrow screens rather than reflowing; a card layout below ~40rem would
  read better. The admin stylesheet hard-codes its border colours instead of using the theme's `--border`.

## From the final re-review

- User-bound tokens can create posts through API/Micropub on `blog:write` scope alone; the admin also requires `can(add, blog, post)`. Add the role-level add check for user-bound tokens, or document that create is scope-gated (tokens are minted by admins only).
