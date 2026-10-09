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
MODULE_PATHS=modules,examples bin/xar module:enable hello   # optional: the example module
bin/xar serve
```

Set `MODULE_PATHS` in `.env` to keep the example module discoverable. With `APP_DEBUG=false`
(production), config and routes are cached under `var/cache`; config is rebuilt when
`config/app.php` or `.env` changes, and `bin/xar cache:clear` clears both caches.

## Development

```sh
composer test   # PHPUnit (XAR_TEST_DSN selects the database)
composer stan   # PHPStan level 8
composer cs     # coding style check
```

License: GPL-2.0-or-later.

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
*/10 * * * * cd /path/to/phoenix && bin/xar blog:sync --all
15 3 * * *   cd /path/to/phoenix && bin/xar blog:sync --all --full
```

A quick sync stops at the first unchanged page. A full sync also catches edits to old posts and
deletions, and refuses to delete anything if the source suddenly shows under half of the posts.
Add `--media=local` at creation to download images and serve them from `/media/…`.
`bin/xar blog:mode <handle> native` stops mirroring; post ids never change.
