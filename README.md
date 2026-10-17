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
cp .env.example .env
bin/xar migrate
bin/xar asset:publish
MODULE_PATHS=modules,examples bin/xar module:enable hello   # optional: the example module
bin/xar serve
```

Set `MODULE_PATHS` in `.env` to keep the example module discoverable. With `APP_DEBUG=false`
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
  seed instances on first install (`"blockDefaults"`). Built-in types: `text`, `menu`, `recent-items`.
  **Security:** `text` blocks with format `html` (the default) output their content as raw, trusted HTML.
  Only trusted administrators may edit block content until users and auth arrive in kernel Plan 3.
- Display hooks: a module's `"displayHooks"` add HTML to other modules' items, bound in `xar_hooks`.
  Toggle a binding with `bin/xar hook:disable <observer> <subject> [itemtype]` / `hook:enable`.
- `bin/xar asset:publish` links `modules/*/assets` and `themes/*/assets` into `public/assets`.
  `ASSET_URL` (default `/assets`) sets their base URL. `secureHtml` pages send `style-src 'self'` and
  `script-src 'self'`, so an off-origin `ASSET_URL` (a CDN) leaves those pages unstyled unless the route
  sends its own `Content-Security-Policy`.

Try it with the examples: `MODULE_PATHS=modules,examples bin/xar module:enable hello`, then
`module:enable hello-hooks`, and open `/hello`.

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
a token in a query string is ignored. The two Micropub routes (`/micropub` and `/micropub/media`) also accept it
as an `access_token` form field, but not both that and the header; no other route does.

The JSON API takes and returns JSON Feed 1.1 items (scope `blog:write`):

- `POST /api/blogs/{handle}/posts`: 201 with `Location: /api/posts/{id}`
- `GET /api/posts/{id}`: the item, with `_phoenix.status` set to `published`, `scheduled` or `deleted`
- `PUT /api/posts/{id}` replaces; `PATCH /api/posts/{id}` merges (RFC 7396). PATCH accepts
  `application/merge-patch+json`, `application/json` and `application/feed+json`.
- `DELETE /api/posts/{id}`: 204; `POST /api/posts/{id}/undelete`: 200
- `POST /api/blogs/{handle}/media` (scope `media:write`): the multipart field `file`; 201 with `Location` set to its `/media/…` URL

Errors are JSON. An unknown or invalid field is a 422 whose `error.fields` names it, and a write to a mirror
blog is a 409. JSON bodies are capped at 1,000,000 bytes (413 beyond that). Tags are normalised, and a tag slug
must match `^[a-z0-9-]{1,128}$`. A `date_published` in the future schedules the post: feeds, pages and search show
it from then on. `content_html` is stored as sent. Pages sanitise it when they render, but the JSON and RSS feeds
serve it unchanged, so give tokens only to people you trust with the blog's HTML.

Uploads may be JPEG, PNG, GIF, WebP, AVIF or MP3, at most 10 MB. The type is read from the bytes, never the file
name, and the file is stored as `var/uploads/<handle>/<ulid>.<ext>`. PHP's `upload_max_filesize` and
`post_max_size` must allow 10 MB.

Micropub clients use `{app.url}/micropub` and the media endpoint `{app.url}/micropub/media`. Phoenix has no
IndieAuth yet, so give the client a token from `token:create`. Creating, updating, deleting, undeleting and the
`q=config`, `q=source` and `q=syndicate-to` queries need `blog:write`; the media endpoint needs `media:write`.
Files go to the media endpoint only: a file uploaded to `/micropub` is a 400. Every Micropub error other than
401 and 403 (including an oversized body or a wrong content type) is a 400 `invalid_request`, and `q=source`
for a deleted post is a 400 too. Updates keep a post's image sizes and a `highlight` kind.
`mp-destination` is a blog handle (`q=config` lists them); without it the token's blog, or the only native blog,
is used. `content` becomes `content_html` (plain text is escaped into paragraphs), `name` the title,
`bookmark-of` a link post, `photo` a photo post (upload files to the media endpoint first and send their URLs),
`category` the tags and `published` the date. Other properties are ignored.

Switching a mirror with `bin/xar blog:mode <handle> native` keeps every post's id and URL; the old posts can then
be edited through the API or Micropub by their URL.

Known deviation: the design spec (§5.1) gives `token_hash` as `char(64)`. The migration uses `string(64)`
(VARCHAR(64)) because Blueprint has no char type; the values are always 64 hex characters.

License: GPL-2.0-or-later.
