# Xaraya Phoenix: Blog Admin Screens (design)

- **Date:** 2026-10-22
- **Status:** written from the controller's decisions; waiting for review
- **Builds on:**
  - kernel Plan 3 (users, roles, grants, `Auth::can`, sessions, CSRF, `auth:` route middleware)
  - blog Plans A and B (mirror and native modes, `PostWriter`, `MediaUploader`) and the blog pages
- **Request:** the user asked for "admin screens for blogging" and said "just start, use your best judgement".
  Every decision below marked **(controller's call)** was made by the controller on that mandate, not by the user.
  Decisions marked **(refined)** are the controller's call adjusted because the code disagreed; §8 lists why.

## 0. Scope

Signed-in users manage blogs and posts in server-rendered HTML pages under `/admin/blog`. JSON, RSS and the public
pages stay byte-identical, so `AthenaParityTest` keeps passing.

**Not in scope (controller's call):** a user management UI (the CLI is enough), a token management UI, comments,
WYSIWYG or Markdown editors, JavaScript, bulk actions, revision history. Mirror creation and mode switching stay
CLI-only.

## 1. Ownership (controller's call)

- A blog migration adds `xar_posts.owner_id`: a nullable ulid, foreign key to `xar_users`, `ON DELETE SET NULL`.
- `PostWriter::create()` sets the owner to the request's acting user (`Access::user()`): the signed-in admin user,
  or the user a bearer token acts as (JSON API and Micropub). A guest or a scopes-only token leaves it null.
  `replace`, `patch`, `delete` and `undelete` never change it.
- Mirror-synced posts (`PostRepository::save()`) have no owner.
- `Post` gains `?string $ownerId`. `ItemSerializer`, `PostView`, the JSON API, Micropub, JSON Feed and RSS never
  expose it.
- With owners in place, the seeded Authors grant `blog · post · own` (delete) starts to match.
- **(refined)** `Schema::table()` refuses foreign keys, and `xar_posts` cannot be rebuilt safely: `post_tags` and
  `post_search` reference it, and on SQLite a `DROP TABLE` cascades into them. So `Schema::table()` learns to add a
  foreign key on a **new nullable column** in place (`ADD COLUMN … REFERENCES … ON DELETE SET NULL` on SQLite and
  Postgres; `ADD COLUMN …, ADD FOREIGN KEY …` on MySQL). Both forms were run on SQLite and MySQL 8 while writing this
  spec. The migration has no `down()` (the `Migration` default no-op).

## 2. Access (controller's call)

- Admin routes live under `/admin/blog`. Every one uses `secureHtml` and answers `Cache-Control: private, no-store`
  (a `noStore` route middleware the blog module registers, so redirects and 422 pages get it too).
- Route names start `admin.blog` **(refined)**: names under `blog.*` would match the public sidebar blocks'
  `{"routes": ["blog.*"]}` visibility.
- Routes check the component with `auth:blog.post.<level>` or `auth:blog.blog.admin`. Controllers then call
  `Access::can($level, 'blog', 'post', $item)` with the post. This is the documented IDOR rule (README, `RequireAuth`).
- **(refined)** `Auth::can()` reads `owner_id` as an array key or property; `Post` names it `ownerId`. One policy
  class, `AdminAccess`, passes `['id' => $post->id, 'owner_id' => $post->ownerId]`, so no controller builds it.
- Levels:

| Action | Route check | Item check |
|---|---|---|
| Dashboard, posts list | `blog.post.edit` | none |
| New post | `blog.post.add` | none |
| Edit, preview, save | `blog.post.edit` | `edit` on the post |
| Delete, undelete | `blog.post.delete` | `delete` on the post |
| Pin a post (§3.3) | (inside the form) | `edit` on `blog · blog` |
| Sync now, settings, new blog | `blog.blog.admin` | none |

- Result with the seeded grants (no manifest change needed):
  - Administrators (`* · * · *` admin) can do everything.
  - Editors (`blog · * · *` delete, from `"content": true`) manage every post and may pin, but cannot open settings,
    create blogs or sync.
  - Authors (`blog · post · own` delete, `blog · post · *` read) create posts, see every post in the list, and edit,
    delete or undelete only their own.
  - Members and guests have no admin access. A guest is sent to `/login?next=…`; a signed-in user gets 403.

## 3. Screens (controller's call)

Server-rendered in the phoenix theme, every template as `.php` and `.twig` with identical HTML, no JavaScript.

### 3.1 Dashboard `GET /admin/blog`

Every blog, with title, handle, mode, and published, scheduled and deleted counts. Links: Posts, New post (native
blogs, `blog.post.add`), Settings (`blog.blog.admin`), View blog. A "New blog" link for `blog.blog.admin`.

### 3.2 Posts list `GET /admin/blog/{handle}/posts`

- Tabs `?tab=published|scheduled|deleted` (default published), each with its count:
  - published: `status = published` and `date_published <= now`;
  - scheduled: `status = published` and `date_published > now`;
  - deleted: `status = deleted`.
- `?q=` searches titles, case-insensitively, with `LikeSearchIndex::escapeLike()` and `ESCAPE '!'`
  **(refined:** `LOWER(title) LIKE ?`, because MySQL tables use `utf8mb4_bin` and Postgres `LIKE` is case-sensitive).
- `?page=N`, 20 per page, newest `date_published` first, with the blog pager partial.
- Each row: title, kind, date, status, owner handle ("—" when none), and the actions the user may take on that
  post: Edit (a link), Delete or Undelete (a POST form with the CSRF token).

### 3.3 New and edit post form

- New: `GET|POST /admin/blog/{handle}/posts/new`. Edit: `GET|POST /admin/blog/post/{id}`.
- Fields:
  - `kind`: note, link, photo or highlight (`_athenana.kind`);
  - `title` (empty: derived from the content, as the API does);
  - `content`: an HTML textarea, stored as given (`content_html`) and sanitised when pages render;
  - `external_url` for links;
  - `quote` (`_athenana.quote`), and `source_name` and `source_url` (`_source`), for highlights;
  - `tags`, comma-separated;
  - `date_published`, a `datetime-local` read in `app.timezone` **(refined)** and stored in UTC; empty means now on
    create and "unchanged" on edit; a future date makes the post scheduled;
  - `pinned` checkbox **(refined)**: the pin is the blog's `pinned_item_id`, not a post field, so the checkbox sets or
    clears it after a successful save, and only users who may edit `blog · blog` (Editors, Administrators) see it;
  - an image upload, §3.4.
- **Preview:** a "Preview" submit button runs the same mapping and validation as Save, writes nothing, and shows the
  form again with the post rendered beneath it through `PostViewFactory` and `blog::partials/post`, so its HTML is
  the sanitised HTML the public page would show. **(refined)** Previews skip the sanitiser cache, whose entries never
  expire.
- **Validation:** `PostWriter`'s 422 `fields` are mapped back to form fields and shown inline: a `role="alert"`
  message with an id, and `aria-invalid` plus `aria-describedby` on the field. Errors with no field are listed above
  the form. **(refined)** `SecureHtml` drops `ValidationFailed::fields()`, so the controller catches
  `ValidationFailed` and renders the 422 page itself.
- **Saving** goes through `PostWriter`: `create()` for new posts and `patch()` for edits **(refined:** a merge patch
  keeps the fields the form does not show, such as Micropub images, mentions and authors, and keeps a derived title
  derived). Nothing writes posts to the database directly.

### 3.4 Image upload

- The form is `multipart/form-data`. The file field is `file` (`MediaUploader::FIELD`).
- **(refined)** `MediaUploader` also accepts `audio/mpeg`, so it gains an allowed-types argument; the form allows
  `MediaUploader::IMAGE_TYPES` only. Size limits and sniffing stay in `MediaUploader` and `MediaStore`.
- A stored image becomes `image` and `_athenana.images: [{url, width, height}]`. A "Remove image" checkbox clears
  both. When a later field fails validation, the stored upload is kept as a hidden `upload_id` (the media row's id,
  checked to belong to the blog), so the user does not choose the file again.

### 3.5 Mirror blogs

Read-only. The posts list shows "Mirror of <source>; edits come from the source" and no Edit, Delete or New post
actions. Every post form and post action on a mirror answers 409 (`PostWriter::MIRROR_MESSAGE`). A "Sync now" POST
button (`blog.blog.admin`) runs a quick sync (`Syncer::sync($blog, false, $now)`) and reports
`SyncReport::summary()` as a flash message; a fetch failure is an error flash.

### 3.6 Blog settings `GET|POST /admin/blog/{handle}/settings` (`blog.blog.admin`)

Title, description_html, author name and URL, language, post_url_pattern, media mode (remote or local). Saved with
`BlogRepository::update()`, whose `InvalidArgumentException` message is shown above the form. The author URL must be
http(s). Mode switching stays CLI-only: the page shows the mode and the `bin/xar blog:mode` command. A mirror's
title, description and author come from its source and a sync may overwrite them; the page says so.

### 3.7 New blog `GET|POST /admin/blog/new` (`blog.blog.admin`)

Handle and title; the mode is native (shown, not editable). The author name defaults to the user's display name.
Created with `BlogRepository::create()`; mirror creation stays `bin/xar blog:create`.

## 4. Navigation (refined)

The controller's call was an "Admin" link in the login block or the phoenix header, shown only when
`can('edit', 'blog', 'post')`. No login block instance exists by default and the kernel block must not name the
blog, so:

- a module manifest may declare `"admin": [{"route", "label", "component", "level"}]`;
- a kernel helper `adminLinks()` returns the links whose route exists and whose `can(level, module, component)`
  passes, and nothing (without touching the session) for a guest;
- the phoenix header renders them in `<nav class="admin-links" aria-label="Admin">`;
- the blog declares `{"route": "admin.blog", "label": "Blog admin", "component": "post", "level": "edit"}`.

A guest's page is unchanged, so public caching is unaffected.

## 5. Safety (controller's call)

- Every form carries the CSRF token; every POST is checked by the global `VerifyCsrf`.
- Every admin response is `private, no-store`.
- Every value in templates is escaped (`$x->e()` in PHP, autoescape in Twig). The only raw HTML is the preview's
  sanitised `PostView` through the existing post partial.
- After a successful POST: a 303 redirect with a flash message stored in the session. **(refined)** `Session` has no
  flash support today; the kernel gains `Session::flash()` and `Session::takeFlash()`.
- No open redirects: every redirect target is built server-side from route names; a `tab` posted back is checked
  against the three tab names and `page` against `[1-9][0-9]{0,5}`.
- Upload limits and types come from `MediaUploader` and `MediaStore`.
- No throttle beyond the kernel's login throttle.
- Never touch `var/database.sqlite`; smoke runs use a `/tmp` clone with `DB_DSN=sqlite:/tmp/…`.

## 6. Flash messages

Stored in the session under `_flash` as `{kind: status|error, message}`, read and removed by the next admin page.
Status messages render in `role="status"`, errors in `role="alert"`. Messages are plain text and escaped.

## 7. Definition of done

- An Author signs in, sees "Blog admin" in the header, creates a scheduled post with an image and tags, previews
  it, edits it, deletes and undeletes it, and cannot touch an Editor's post by id.
- An Administrator creates a native blog, edits its settings, and syncs a mirror from the browser.
- JSON, RSS and the public pages are unchanged (`AthenaParityTest`), and `owner_id` appears in none of them.
- `composer test && composer stan && composer cs` pass; the new tests pass on MySQL.

## 8. Where the code disagreed with the brief

1. Pinned is per blog (`xar_blogs.pinned_item_id`), not per post: §3.3.
2. `Auth::can()` reads `owner_id`, `Post` has `ownerId`: §2.
3. `Schema::table()` refuses foreign keys and a rebuild of `xar_posts` would cascade on SQLite: §1.
4. No default login block, and the kernel block cannot name the blog: §4.
5. Route names under `blog.*` would pull in the public sidebar blocks: §2.
6. `MediaUploader` also accepts MP3: §3.4.
7. `Session` has no flash: §5.
8. `SecureHtml` drops validation fields: §3.3.
9. A `replace()` from the form would drop fields the form does not show: §3.3 (patch).
10. MySQL's `utf8mb4_bin` makes `LIKE` case-sensitive: §3.2.

## 9. Open questions

- Authors see every post in the list (they hold `blog · post · *` read) but can act only on their own. Should the
  list show Authors only their own posts?
- Pinning needs `edit` on `blog · blog`, so Authors cannot pin. Is that the intended line?
- Should mirror settings be editable at all, given that a sync may overwrite the source-driven fields?
- Rolling back the owner migration is a no-op; re-running it after a manual rollback fails on the existing column.
