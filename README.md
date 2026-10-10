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
`config/app.php` or `.env` changes, and `bin/xar cache:clear` clears both caches.

## Themes, templates and blocks

Pages render through the active theme (`APP_THEME`, default `phoenix`, found under `THEME_PATHS`).
Templates exist as `.php` or `.twig` (Twig is optional: `composer require twig/twig`); the theme's
`engine` setting, or `VIEW_ENGINE`, picks one when both exist.

- `module::name` is a module template: `themes/<theme>/templates/modules/<module>/<name>.*` overrides
  `modules/<module>/templates/<name>.*`. Plain names (`layout`, `block`, `home`, `error/404`) are theme templates.
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

License: GPL-2.0-or-later.
