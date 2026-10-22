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
bin/xar install        # asks for the first administrator; or --admin=<handle> --email=<email> --password-stdin
MODULE_PATHS=modules,examples bin/xar module:enable hello   # optional: the example module
bin/xar serve
```

`bin/xar install` checks PHP, writes `.env` from `.env.example` when it is missing (mode 0600; an existing `.env` is
never touched), creates the SQLite file (or checks `DB_DSN`), runs the migrations, seeds the roles and grants,
creates the administrator and publishes the theme's assets. It refuses a database that already has users; `--force`
repeats the steps (an existing administrator is left as it is). Give the password with `--password-stdin`;
`--password=` works but shows in the process list and shell history. Set `MODULE_PATHS` in `.env` to keep the example module discoverable. With `APP_DEBUG=false`
(production), config and routes are cached under `var/cache`; config is rebuilt when
`config/app.php` or `.env` changes. `bin/xar cache:clear` removes the config and route caches, the file
cache entries (`var/cache/data`) and the compiled Twig templates (`var/cache/twig`).

## Themes, templates and blocks

Pages render through the active theme (`APP_THEME`, default `phoenix`, found under `THEME_PATHS`).
Templates exist as `.php` or `.twig` (Twig is optional: `composer require twig/twig`); the theme's
`engine` setting, or `VIEW_ENGINE`, picks one when both exist.

- `module::name` is a module template: `themes/<theme>/templates/modules/<module>/<name>.*` overrides
  `modules/<module>/templates/<name>.*`. Plain names (`layout`, `block`, `home`, `error/404`) are theme templates.
- Overrides and engines: `$x->render()` / Twig `render()` find an override in either engine, but `$x->include()`
  sees only `.php` files and Twig `{% include %}` only `.twig` files, so an override written in the other engine is
  silently skipped. A partial that themes may override should be pulled with `render()`, or every override of it
  must ship both `.php` and `.twig`.
- PHP templates get variables directly and helpers as `$x`: `$x->e()` (escape: always use it), `$x->t()`,
  `$x->url()`, `$x->asset()`, `$x->blocks('sidebar')`, `$x->hooks('item.display', $item)`, `$x->render()`.
  Twig has the same helpers as functions and escapes automatically.
- Blocks live in `xar_blocks` and render in theme regions. Modules ship block types (`"blocks"`) and
  seed instances on first install (`"blockDefaults"`). Built-in types: `text`, `menu`, `login`, `recent-items`.
  **Security:** `text` blocks with format `html` (the default) output their content as raw, trusted HTML.
  Only trusted administrators may edit block content.
  A block's `visibility` may list `roles` (`{"roles": ["Members"]}`): it shows only to a request that has one of
  them. Guests have `Anonymous`; signed-in users also have `Members` and their own roles. A malformed rule hides
  the block, and `"roles": []` means no restriction. The built-in `login` block shows a "Log in" link to guests and
  the user's name with a logout button otherwise.
- Admin links: a module's manifest may list `"admin": [{"route", "label", "component", "level"}]`. `Helpers::adminLinks()`
  (`$x->adminLinks()` in templates) returns the links the signed-in user may use, and the theme header shows them as a
  nav. The route must take no parameters; a link whose route does not resolve is skipped and logged.
- Display hooks: a module's `"displayHooks"` add HTML to other modules' items, bound in `xar_hooks`.
  Toggle a binding with `bin/xar hook:disable <observer> <subject> [itemtype]` / `hook:enable`.
- `bin/xar asset:publish` links `modules/*/assets` and `themes/*/assets` into `public/assets`.
  `ASSET_URL` (default `/assets`) sets their base URL. `secureHtml` pages send `style-src 'self'` and
  `script-src 'self'`, so an off-origin `ASSET_URL` (a CDN) leaves those pages unstyled unless the route
  sends its own `Content-Security-Policy`.

Try it with the examples: `MODULE_PATHS=modules,examples bin/xar module:enable hello`, then
`module:enable hello-hooks`, and open `/hello`.

## Users, roles and sign-in

Users sign in at `/login` with their handle or email and leave with the logout button (a POST to `/logout`).
`/password/forgot` mails a link to `/password/reset/<token>` that works once, for 60 minutes; the page answers the
same whether or not the address has an account. `bin/xar user:create <handle> --email=<email> [--role=Editors]`
adds a user and `bin/xar user:password <handle>` sets a password from the shell. Both ask for the password; use
`--password-stdin` to read it from a pipe. `--password=<password>` is accepted but unsafe: it shows in the process
list and shell history. A password is at least 8 characters and at most 72 bytes (bcrypt ignores the rest, so longer ones are
refused) and cannot contain a NUL character. Changing a password ends that user's sessions everywhere. The paths `/login`,
`/logout` and `/password/*` belong to the kernel; a module cannot register them.

- **Roles:** Administrators (admin on everything), Editors (delete on every module whose manifest sets
  `"content": true`), Authors, Members and Anonymous. Anonymous has read on everything, and every request has
  Anonymous, signed in or not. Authors and Members hold no seeded grants beyond blog's Authors grants (below).
  A role may have a parent and gets its grants.
  Modules seed their own grants on `module:enable` with `"grants": [{"role", "component", "instance", "level"}]`;
  re-enabling restores a deleted default grant but keeps a changed level. There is no admin UI for users, roles or grants yet: roles, parents
  and grants are changed in the database. (The blog has its own admin screens, below.)
- **Checks:** `$x->can('edit', 'blog', 'post', $item)` in templates, `Auth::can()` in code, `auth` (signed in)
  or `auth:<module>.<component>.<level>` on routes. An `own` grant matches an item whose `owner_id` is the user's
  id. Blog posts now have an owner (see Admin screens), so the Authors grants on `blog.post.own` take effect.
  **`auth:<module>.<component>.<level>` checks the component only, with no item,** so an `own`-only user passes it for
  every item. A controller that loads an item must check again with that item (`can(level, module, component,
  $item)`), or one user can act on another's items. A guest who fails a route check is sent to `/login?next=…`
  from an HTML page and gets a 401 elsewhere (JSON on `.json`, `/api` and JSON-accepting requests); a signed-in
  user gets a 403.
- **Sessions:** a database row (`xar_sessions`) keyed by the sha256 of an HttpOnly, SameSite=Lax cookie
  (`xar_session`), so the cookie value is never stored. The cookie is Secure unless `APP_DEBUG=true` and the request
  is plain HTTP. A session expires after `SESSION_LIFETIME` idle minutes (default 120) and, whatever its activity,
  `SESSION_MAX_LIFETIME` seconds after creation or the last login (default 86400, so users sign in daily). The id
  changes at login and logout. A session whose row is gone (logout, expiry, password change) is dead: it is never
  resurrected, even by a request that was already in flight. Any response that sets a session cookie is sent with
  `Cache-Control: private, no-cache`, and an error page for a request with a session cookie or a signed-in user
  (session or token) with `Cache-Control: private, no-store`. An error page saves nothing to the session, but its
  forms (the logout button) carry the session's token.
- **Flash messages:** `Session::flash($message, $kind = 'status')` queues a plain-text message for the next page, and
  `Session::takeFlash()` returns and clears the queue. The page that shows it escapes it. Admin actions answer with a
  303 redirect and a flash message.
- **CSRF:** every POST, PUT, PATCH and DELETE needs the session's token, from `$x->csrf()` (a hidden `_csrf` field)
  or an `X-CSRF-Token` header (the field wins if both are sent), unless its route is marked `csrf:off`. Token
  routes are marked `csrf:off`, because a bearer token is not sent by the browser on its own. A missing or wrong
  token is a 403.
- **Throttling:** 5 failed logins in 15 minutes lock a client out of that account. The key is the client's IP plus
  the account (a handle and its email count together); an IPv6 address counts as its /64. `throttle:<key>,<max>,<seconds>`
  limits any route per IP (429 with `Retry-After`). The IP is `REMOTE_ADDR`. **Behind a reverse proxy, set
  `TRUSTED_PROXIES`** to the proxy's address so `X-Forwarded-For` is used; without it every client shares the
  proxy's IP and therefore one throttle bucket, so a few failed logins lock everyone out of an account. Only exact
  IP addresses are supported, not CIDR ranges.
- **Mail:** `MAIL_DRIVER=log` (the default) writes each message to `var/mail/*.eml` instead of sending it;
  `MAIL_DRIVER=mail` sends with PHP's `mail()`. **Set `MAIL_DRIVER=mail` in production:** the `log` driver stores
  live reset links on disk. `MAIL_FROM` defaults to `phoenix@<host of APP_URL>`, or `phoenix@example.invalid` when
  that host has no dot. There is no SMTP client yet: use the server's MTA. `APP_URL` must be set, because reset links
  are built from it; without it the `/password/*` pages answer 500. A failure to send is logged and the page answers
  as usual.

Migrations: `Schema::table()` can add a foreign key, but only on a new nullable column added by the same call.

**Upgrading an install that has no users yet** (one that predates sign-in): run `bin/xar install`. It runs the new
migrations, seeds the roles and the grants of the enabled modules, and creates the administrator; nothing else
changes.

## Development

```sh
composer test   # PHPUnit (XAR_TEST_DSN selects the database)
composer stan   # PHPStan level 8
composer cs     # coding style check
```

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
*/10 * * * * cd /path/to/phoenix && timeout 20m bin/xar blog:sync --all
15 3 * * *   cd /path/to/phoenix && timeout 2h bin/xar blog:sync --all --full
```

A run that finds a blog still locked by an earlier run skips it, and logs a warning once the lock
is over 30 minutes old (the earlier run may be hung).

A quick sync stops at the first unchanged page. A full sync also catches edits to old posts and
deletions, and refuses to delete anything if the source suddenly shows under half of the posts.
Add `--media=local` at creation to download images and serve them from `/media/…`. This downloads
third-party files referenced by the posts onto your server. Fetches only go to public hosts: loopback,
private, link-local and other internal addresses are refused unless `blog.fetch_allow_private` is
set to true in the config.
`bin/xar blog:mode <handle> native` stops mirroring; post ids never change.
`bin/xar media:localize <handle>` downloads a blog's remote media now and switches it to `media=local`.

### Blog pages

Every blog also has HTML pages in the active theme (`phoenix` by default):

- `/blog/{handle}`: posts, newest first, 20 a page. The pinned post sits in its own section above page 1 (the pinned badge appears only there)
- `/blog/{handle}/tag/{slug}` and `/blog/{handle}/tags` (the tag cloud). A tag slug over 128 bytes, or containing `/`, is not linked and is left out of the cloud
- `/blog/{handle}/about` and `/blog/{handle}/search?q=`
- `/s/{id}`: one post. A mirror with `--post-url` links its cards to that canonical address instead. The page's `<link rel="canonical">` and `og:url` use the item's URL when it passes `SafeUrl`, otherwise `{app.url}/s/{id}`. A mention whose date is not ISO 8601 is shown without a date.

Run `bin/xar asset:publish` once so `/assets/module/blog/blog.css` exists. Post HTML is sanitised when it
is rendered (and cached under `var/cache/data`); stored posts and the JSON/RSS feeds are never changed. Pages
send a strict `Content-Security-Policy` and load no scripts or iframes: a video is a link to YouTube.
Images (in post HTML, photos, galleries, video thumbnails and podcast covers) are requested with no `Referer`
(`referrerpolicy="no-referrer"`), because some hosts such as Athena block hotlinking. A podcast's `<audio>` cannot carry
that policy. The sanitiser cache key is versioned, so upgrading needs no `cache:clear`.

New installs get four sidebar blocks (search, about, recent posts, tags) on every `blog.*` page. An install
that enabled the blog module before they existed creates them with `bin/xar blog:blocks`.

Themes override any blog template at `themes/<theme>/templates/modules/blog/<path>.php` (or `.twig`), for
example `partials/kinds/link`, without touching the module. The blog templates pull every overridable
partial with `render()`, so an override in either engine is found.

Search uses its own index (`xar_post_search`), kept current by sync. `bin/xar blog:reindex <handle>|--all`
rebuilds it. **Upgrading an install that already has blogs:** run `bin/xar migrate`, then
`bin/xar blog:reindex --all` once, or older posts won't be found.

### Admin screens

The blog module has admin screens at `/admin/blog`. They need a login (a guest is sent to `/login?next=…`), and the
header shows a "Blog admin" link to anyone who may edit posts. Admin pages are never cached: every admin response is
`Cache-Control: private, no-store`, and every admin form carries a CSRF token.

| Role | Can do |
| --- | --- |
| Administrators | Everything: all posts, pinning, blog settings, new native blogs, "Sync now" on mirrors |
| Editors | Create, edit, delete and pin any post |
| Authors | Create posts; edit or delete their own. The published tab lists all posts, but the scheduled and deleted tabs show only their own. They cannot pin |
| Members, guests | No access |

Pinning a post needs Editor (edit on `blog · blog`). Blog settings and new blogs need Administrator.

Posts have an owner, `owner_id`: the signed-in user who created the post in the admin, or the user a token acts as for
the JSON API and Micropub. It decides which posts an Author may change. It never appears in the JSON or RSS feeds.

The post form covers:

- the kind, a title, the content, and a tags field (comma separated, so a tag name cannot contain a comma). HTML is
  stored as given and sanitised when it is rendered;
- the date, a `datetime-local` field read in `app.timezone` (`APP_TIMEZONE`); a date in the future schedules the post;
- the pin checkbox, shown to Editors and Administrators only;
- an image upload: images only, up to 10 MB. PHP's `post_max_size` must be larger than 10 MB, for example `12M`
  (and `upload_max_filesize` likewise), or PHP drops the whole form;
- a remove-image option on a post that has an image;
- Preview, which renders the post as it would appear and never saves it (a file chosen on a preview is ignored).

Deleting the pinned post unpins it; undeleting it does not pin it again, so pin it again by hand.

Mirror blogs are read-only: no new-post link and no edit or delete controls. An Administrator gets a "Sync now"
button. A mirror's settings show the fields the source sets (title, description, author, language) read-only; only the
post URL pattern and the media setting can be edited, so a sync never silently undoes an edit. Administrators create
native blogs and edit blog settings in the admin. Switching a blog's mode and creating a mirror stay with
`bin/xar blog:mode` and `bin/xar blog:create`.

### Writing natively: tokens, the JSON API and Micropub

A native blog is written in Phoenix itself. Enable the two modules, create the blog and a token:

```sh
bin/xar module:enable tokens
bin/xar module:enable micropub
bin/xar blog:create notes --mode=native --title=Notes
bin/xar token:create --name=phone --scopes=blog:write,media:write --blog=notes --expires=365d
```

`token:create` prints the token (`xar_` and 40 letters and digits) once; only its sha256 is stored.
`bin/xar token:list` shows ids, scopes, blog, user and last use (never a token), and `bin/xar token:revoke <id>`
deletes one. A token made with `--blog` can only write to that blog (the pin is checked only while the blog module is
enabled; the `tokens` module no longer requires blog). A token made with `--user=<handle>` acts as that user (route
checks and `can()` use the user's roles) and is refused (401) once the user is disabled or deleted; a token without a
user acts as a guest, only through its scopes. A token's scopes decide which token routes it may call; `can()`
applies only where a controller calls it. Modules add scopes with `"scopes": ["<module>:<name>"]` in `module.json`
(the hello example declares `hello:write`). A request authenticated by a token never creates or saves a session (no
cookie) and its response is sent with `Cache-Control: private, no-store`. Send it as `Authorization: Bearer xar_…`;
a token in a query string is ignored. On a route, `token:` must come before `auth` or `auth:…`, because the token
sets the identity those check. The two Micropub routes (`/micropub` and `/micropub/media`) also accept it
as an `access_token` form field, but not both that and the header; no other route does.

The JSON API takes and returns JSON Feed 1.1 items (scope `blog:write`):

- `POST /api/blogs/{handle}/posts`: 201 with `Location: /api/posts/{id}`
- `GET /api/posts/{id}`: the item, with `_phoenix.status` set to `published`, `scheduled` or `deleted`
- `PUT /api/posts/{id}` replaces; `PATCH /api/posts/{id}` merges (RFC 7396). POST and PUT accept only
  `application/json` or `application/feed+json`; PATCH also accepts `application/merge-patch+json`. Any other
  content type is a 415, and a body that is not valid JSON or not a JSON object is a 400.
- `DELETE /api/posts/{id}`: 204; `POST /api/posts/{id}/undelete`: 200
- `POST /api/blogs/{handle}/media` (scope `media:write`): the multipart field `file`; 201 with an absolute
  `Location` (`{app.url}/media/<ulid>.<ext>`) and the body `{"url", "type", "bytes", "width", "height"}`.
  `width` and `height` are null for MP3 (and for any image whose size cannot be read).

Errors are JSON. An unknown or invalid field (including a string or key that is not UTF-8 or holds U+0000) is
a 422 whose `error.fields` names it, and a write to a mirror blog is a 409. JSON bodies are capped at 1,000,000
bytes (413 beyond that). Tags are normalised, and a tag slug must match `^[a-z0-9-]{1,128}$`. A `date_published` in the future schedules the post: feeds, pages and search show
it from then on. `content_html` is stored as sent. Pages sanitise it when they render, but the JSON and RSS feeds
serve it unchanged, so give tokens only to people you trust with the blog's HTML.

Uploads may be JPEG, PNG, GIF, WebP, AVIF or MP3, at most 10,000,000 bytes (413 beyond that). The type is read
from the bytes, never the file name, and the file is stored as `var/uploads/<handle>/<ulid>.<ext>` (the
`blog.uploads` setting replaces `var/uploads`). Identical bytes uploaded to the same blog share one stored file.
PHP's `upload_max_filesize` and `post_max_size` must allow 10 MB.

Micropub clients use `{app.url}/micropub` and the media endpoint `{app.url}/micropub/media`. Phoenix has no
IndieAuth yet, so give the client a token from `token:create`. Creating, updating, deleting, undeleting and the
`q=config`, `q=source` and `q=syndicate-to` queries need `blog:write`; the media endpoint needs `media:write`.
Files go to the media endpoint only: a file uploaded to `/micropub` is a 400. Every Micropub error other than
401 and 403 (including an oversized body or a wrong content type) is a 400 `invalid_request`, and `q=source`
for a deleted post is a 400 too. Errors from the router itself (a 404 or 405 on `/micropub`) come before
Micropub's handling, so they use the kernel's error format (`{"error": {"status", "message"}}` for a client that
accepts JSON), not Micropub's. A create is a 201 with `Location` set to the post's URL; `action=update`,
`delete` and `undelete` are a 204; the media endpoint is a 201 with `Location` set to the file's absolute URL.
`action=update` must be sent as JSON; `delete` and `undelete` may be form-encoded or JSON. Updates keep a
post's image sizes and a `highlight` kind.
`mp-destination` is a blog handle (`q=config` lists them); without it the token's blog, or the only native blog,
is used. `content` becomes `content_html` (plain text is escaped into paragraphs), `name` the title,
`bookmark-of` a link post, `photo` a photo post (upload files to the media endpoint first and send their URLs),
`category` the tags and `published` the date. Other properties are ignored.

With curl (`$TOKEN` holds a token from `token:create`):

```sh
# Create a post
curl -i https://example.com/api/blogs/notes/posts \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"title": "Hello", "content_html": "<p>First post</p>", "tags": ["hello"]}'

# Change its title (merge patch)
curl -i -X PATCH https://example.com/api/posts/<id> \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/merge-patch+json' \
  -d '{"title": "Hello again"}'

# Upload a file
curl -i https://example.com/api/blogs/notes/media \
  -H "Authorization: Bearer $TOKEN" -F 'file=@photo.jpg'

# Post a note through Micropub
curl -i https://example.com/micropub \
  -H "Authorization: Bearer $TOKEN" -d h=entry --data-urlencode 'content=Hello from Micropub'
```

Switching a mirror with `bin/xar blog:mode <handle> native` keeps every post's id and URL; the old posts can then
be edited through the API or Micropub by their URL.

Known deviation: the design spec (§5.1) gives `token_hash` as `char(64)`. The migration uses `string(64)`
(VARCHAR(64)) because Blueprint has no char type; the values are always 64 hex characters.

License: GPL-2.0-or-later.
