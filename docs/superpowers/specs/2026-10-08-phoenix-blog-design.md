# Xaraya Phoenix — Blog, Athena drop-in (design)

- **Date:** 2026-10-08
- **Status:** approved in brainstorming; awaiting written-spec review
- **Builds on:** the kernel foundation (Plan 1, `docs/superpowers/specs/2026-10-08-xaraya-phoenix-kernel-design.md`)
- **Reference contract:** Athena's blog feed
  - Code: `athena-portable-blog/app/Support/BlogFeedItem.php` and `PublicFeedController.php`
  - Consumer view: `wyome-portable-blog/docs/athenana-feed-parity.md`

## 0. Goal and scope

Phoenix serves a blog in Athena's format: JSON Feed 1.1 with Athena's extensions. Any consumer written for Athena should be able to switch to Phoenix by changing only the hostname.

Each blog has one of two **modes**:

| Mode | Where posts come from |
|---|---|
| **mirror** | Synced from any JSON editor that publishes JSON Feed. A per-source *adapter* reads the source; `athena` is the first adapter. |
| **native** | Phoenix is the editor. Posts are written through a JSON API and Micropub. |

A blog can switch between modes without its item ids changing.

**Delivery** is one spec and two plans:

- **Plan A:** blogs, posts, adapters, sync and serving. When this is done, wyome.com can point at Phoenix.
- **Plan B:** tokens, the post-write service, the JSON API, Micropub and media upload.

**Not in scope:** HTML blog pages and themes (kernel Plan 2), users and roles (kernel Plan 3), the subscribe page and email, ActivityPub, tag pages, HTML sanitising (listed as a follow-up in §6).

## 1. Modules

| Module | Plan | Responsibility |
|---|---|---|
| `blog` | A | `blogs`/`posts`/`post_tags`/`media` tables, adapters, sync, serialiser, public feeds, CLI |
| `tokens` | B | `tokens` table, bearer middleware `token:<scope>`, `xar token:create\|revoke` |
| `blog` additions | B | post-write service, JSON API, media upload |
| `micropub` | B | Micropub and media endpoints mapped onto the post-write service; `requires: blog, tokens` |

All three live under `modules/` and are enabled with `xar module:enable`.

## 2. Data model

### 2.1 Tables

**`xar_blogs`**

- `id` int increments
- `handle` string(32), unique, matching `[a-z0-9_-]{2,32}`
- `title` string; `description_html` text, nullable
- `author_name` string; `author_url` string, nullable
- `home_page_url` string, nullable
- `post_url_pattern` string, nullable
  - must contain `{id}` exactly once
  - when null, posts use Phoenix's own `{app.url}/s/{id}`
- `language` string(16), default `en`; `icon` and `favicon` string, nullable
- `mode` string(16): `mirror` or `native`
- `source_url` string, nullable (required for mirror)
- `source_format` string(32), nullable: `jsonfeed` or `athena`
- `media` string(16), default `remote`: `remote` or `local`
- `pinned_item_id` string, nullable
- `source_etag` string, nullable; `last_synced_at` and `last_full_sync_at` datetime, nullable
- `extra` json, nullable: feed-level source fields we don't model (e.g. `_athenana.subscribe_url`)
- `created_at`, `updated_at` datetime

**`xar_posts`**

- `id` ulid, primary key. It is the source item's ULID when the source id ends in a valid lower-case ULID; otherwise a newly generated one.
- `blog_id` int, foreign key to blogs, cascade
- `item_id` string(512): the feed `id`, verbatim, never changed
- `kind` string(16): `note`, `photo`, `link` or `highlight`
- `title` text
- `url` string(1024), nullable: the source's item URL, kept for parity
- `external_url` string(2048), nullable
- `content_html` text, nullable
- `image` string(2048), nullable
- `date_published` datetime; `date_modified` datetime, nullable
- `status` string(16): `published` or `deleted`
- `source_hash` char(40), nullable: sha1 of the canonical source item JSON
- `doc` json: every other item field, verbatim
  - `attachments`, `_video`, `_source`
  - the full `_athenana` object minus the keys held in columns
- `created_at`, `updated_at` datetime
- Indexes:
  - unique `(blog_id, item_id)`
  - `(blog_id, status, date_published, id)` for keyset paging

**`xar_post_tags`**

- `post_id` ulid (foreign key, cascade), `blog_id` int, `name` string(128), `slug` string(128)
- Primary key `(post_id, slug)`; index `(blog_id, slug)`

**`xar_media`**

- `id` ulid, primary key; `blog_id` int (foreign key)
- `source_url` string(2048), nullable
- `path` string(512): relative to `var/uploads/`
- `mime` string(64); `bytes` bigint; `sha256` char(64)
- `width` and `height` int, nullable
- `created_at` datetime
- Indexes: unique `(blog_id, sha256)`; `(blog_id, source_url)`

### 2.2 Round-trip invariant

Serialising a synced post must reproduce the source item exactly. Two fields may differ, and only when the blog's configuration asks for it:

- `url`, when `post_url_pattern` is set;
- media URLs, when `media = local`.

Comparison is of decoded JSON, so key order doesn't matter. Empty values (null, `""`, `[]`) are always left out, which is Athena's `clean()` rule.

## 3. Mirror mode: sync

### 3.1 Adapters

```php
interface SourceAdapter {
    /** @param array<string,mixed> $item one JSON Feed item */
    public function toRecord(array $item): PostRecord;   // columns + doc + tags
    /** @param array<string,mixed> $feed the page envelope */
    public function feedMeta(array $feed): array;        // blog fields + extra
}
```

`JsonFeedAdapter` (`jsonfeed`) handles any JSON Feed 1.1 source.

- **`kind`** is inferred: an item with `external_url` is a link; one with an `image` but no text is a photo; anything else is a note.
- **`date_published`** falls back to `date_modified`, then to the time of the sync, when the item has none.
- **`title`**, when absent, is derived as in Athena: a note uses the first 80 characters of its plain text; a link uses its host plus path.
- **Tags** come from `tags`.

`AthenaAdapter` (`athena`) extends `JsonFeedAdapter`.

- **`kind`** comes from `_athenana.kind`.
- **Tags** come from `_athenana.tags[{name, slug}]`.
- **Extensions:** every `_`-prefixed key is kept verbatim in `doc`.

New sources plug in as an adapter class registered under a `source_format` key.

### 3.2 Fetching

`HttpFetcher` is an interface. The default implementation uses PHP streams, so there's no new dependency.

- Requests send `User-Agent: XarayaPhoenix/<version>` and use a 15 s timeout.
- Responses must be 200 and parse as JSON with `version` starting `https://jsonfeed.org/version/1`.
- The first page is requested with `If-None-Match` using the stored `source_etag`. A 304 ends a quick sync immediately.
- Tests use a `FixtureFetcher` that maps URLs to files.

### 3.3 Commands

- **`xar blog:create <handle> --mode=mirror --source=<url> --format=athena [--post-url=<pattern>] [--media=remote|local]`** and **`xar blog:create <handle> --mode=native --title=…`**.
- **`xar blog:sync <handle>|--all`** (quick sync).
  - Walks pages newest first via `next_url`.
  - Upserts every item whose `source_hash` changed, keyed on `(blog_id, item_id)`.
  - Stops after the first page in which no item changed.
- **`xar blog:sync <handle> --full`.**
  - Walks every page and upserts.
  - Tombstones live posts whose `item_id` didn't appear in the walk (`status=deleted`). If a tombstoned item reappears, it comes back to life.
  - **Safety valve:** if the walk saw fewer than 50% of the currently live items, nothing is tombstoned. The run exits 1 with a message.
- **`xar blog:mode <handle> mirror|native [--force]`.**
  - Switching to native stops syncing.
  - Switching to mirror warns that native-only posts will be tombstoned by the next full sync, and requires `--force`.
- **`xar blog:list`.**

### 3.4 Sync rules

- **Feed metadata.** Fields from page 1 update the blog on every sync: title, description, author, home page, icon, favicon, language, `_athenana.pinned_id` (as `pinned_item_id`), and any other `_athenana` feed keys (into `extra`).
- **Transactions.** Each page is upserted in one `transaction()`, so a crash keeps the pages already done.
- **Locking.** A per-blog lock (`flock` on `var/locks/blog-<handle>.lock`) prevents overlapping runs. A second run exits 0 with "already running".
- **Errors.**
  - An HTTP or parse error on a page aborts the run with exit 1 and tombstones nothing.
  - A malformed item is logged and skipped; the rest of the page continues.
- **Media (`media=local`).** After a page is upserted, the referenced URLs are downloaded into `xar_media`. Those URLs are `image`, `_athenana.images[].url`, `_video.thumbnail`, `_athenana.podcast.cover` and `attachments[].url`, but only for allowed types (§5.4). Downloads are deduplicated by `source_url`, then by `sha256`. A failed download is logged, and that URL stays remote.
- **Events.** Sync fires the kernel's `ItemCreated`, `ItemUpdated` and `ItemDeleted` events with module `blog` and itemtype `post`.
- **Scheduling.** Phoenix ships no scheduler. The README documents cron lines: a quick sync every 10 min and a `--full` nightly.

## 4. Serving

### 4.1 Routes

Every serving route uses the `cors` and `conditional` middleware.

| Route | Name | Content-Type |
|---|---|---|
| `GET /blog/{handle}/feed.json` | `blog.feed.json` | `application/feed+json` |
| `GET /blog/{handle}/feed.xml` | `blog.feed.xml` | `application/rss+xml; charset=utf-8` |
| `GET /s/{id:[0-9a-hjkmnp-tv-z]{26}}.json` | `blog.post.json` | `application/json` |
| `GET /media/{id}.{ext}` | `blog.media` | the stored mime type. Cached `public, max-age=31536000, immutable`, with no `conditional` middleware. |

Status codes:

- An unknown handle, unknown post, deleted post, or post scheduled for the future returns 404.
- A malformed `before` cursor returns 400.
- Error bodies are JSON with CORS headers, as provided by the kernel.

### 4.2 Feed envelope

Fields are emitted in this order:

1. `version`: `https://jsonfeed.org/version/1.1`
2. `title`
3. `description` (`description_html`)
4. `home_page_url`
5. `feed_url`: the absolute Phoenix URL for `blog.feed.json`
6. `next_url`
7. `language`
8. `icon`
9. `favicon`
10. `authors`: `[{name, url}]`
11. `_athenana`: `{pinned_id, subscribe_url, …extra}`
12. `items`

Empty values are left out.

### 4.3 Paging

- Pages hold 50 items.
- Items are `status = 'published' AND date_published <= now`, ordered `date_published DESC, id DESC`.
- **Cursor:** `before = base64url_nopad("Y-m-d H:i:s|<id>")` in UTC. It is applied with `Select::cursor(['date_published' => …, 'id' => …], '<')`.
- `next_url` is present only when an older item exists.

### 4.4 Item serialisation

The item is built from `doc`, then the columns are overlaid:

| Field | Value |
|---|---|
| `id` | `item_id` |
| `url` | `post_url_pattern` with `{id}` replaced by the post id; otherwise the stored source `url` (mirror) or `{app.url}/s/{id}` (native) |
| `external_url`, `title`, `content_html`, `image`, `date_published`, `date_modified` | from the columns. Dates are ISO 8601 with `+00:00`. |
| `tags` | tag names |
| `_athenana.kind` | `kind` |
| `_athenana.tags` | `[{name, slug}]` |

When `media=local`, every media URL that has a `xar_media` row is rewritten to the absolute `blog.media` URL.

### 4.5 RSS

RSS mirrors Athena's twin:

- `<channel>` takes its title, link and description from the blog.
- Each `<item>` has `title`, `link` (the item url), and `guid isPermaLink="true"` (the item url).
- `description` is `content_html` followed by `<p><a href="url">Permalink</a></p>`.
- `dc:creator` is the author.
- Each tag becomes `category domain="<home>/tag/<slug>"`.
- `media:content url=image` is included when an image exists.
- Dates use RFC 822 `pubDate`.

### 4.6 Caching

- `Last-Modified` is the newest `date_modified` (falling back to `date_published`) on the page.
- `Cache-Control: public, max-age=60`.
- The kernel's ETag middleware answers `If-None-Match` with 304.

## 5. Native mode: write path (Plan B)

### 5.1 Tokens

`xar_tokens` columns:

- `id` ulid
- `name` string(64)
- `token_hash` char(64), unique (sha256 of the token)
- `scopes` json
- `blog_id` int, nullable
- `last_used_at`, `expires_at` (nullable) and `created_at` datetime

Commands:

- `xar token:create --name=<n> --scopes=blog:write,media:write [--blog=<handle>] [--expires=<N>d]` prints `xar_` followed by 40 base62 characters. The token is shown once and only its hash is stored.
- `xar token:revoke <id>` deletes a token.

The `token:<scope>` middleware:

- reads `Authorization: Bearer <t>`, or the form field `access_token` on Micropub routes;
- returns 401 when the token is missing, unknown or expired;
- returns 403 when the scope is missing, or when the token is pinned to a different blog;
- sets the `Token` request attribute;
- updates `last_used_at` at most once a minute.

Write routes also carry `csrf:off`.

### 5.2 Post-write service

`PostWriter` has these methods:

- `create(Blog, array $item)`
- `replace(Post, array $item)`
- `patch(Post, array $partial)`
- `delete(Post)` (tombstone)
- `undelete(Post)`

Rules:

- Writes are allowed on native blogs only. A write to a mirror blog throws `HttpException(409)`.
- A new post gets a fresh ULID, and its `item_id` is `{app.url}/s/{id}`.
- Input is a JSON Feed item. Unknown top-level fields are rejected. `_`-prefixed extension objects are stored in `doc`.
- Validation errors produce 422 with `fields: {name: message}`.
- Titles are derived as in §3.1 when absent. `date_published` defaults to now; a future date means scheduled.
- `content_html` is stored as given, because writers hold trusted tokens. Sanitising is a follow-up.
- Each write fires the kernel's `ItemCreated`, `ItemUpdated` or `ItemDeleted` event.

### 5.3 JSON API

All JSON API routes use the `csrf:off` and `token:blog:write` middleware.

| Route | Behaviour |
|---|---|
| `POST /api/blogs/{handle}/posts` | 201, `Location: /api/posts/{id}`, body is the serialised item |
| `GET /api/posts/{id}` | the item, including scheduled or deleted ones, flagged with `_phoenix.status` |
| `PUT /api/posts/{id}` | replace |
| `PATCH /api/posts/{id}` | merge-patch (RFC 7396) |
| `DELETE /api/posts/{id}` | 204 |
| `POST /api/posts/{id}/undelete` | 200 |

### 5.4 Media

Endpoints:

- `POST /api/blogs/{handle}/media` (scope `media:write`)
- `POST /micropub/media`

Both accept `multipart/form-data` with a field named `file` and store the file at `var/uploads/<handle>/<ulid>.<ext>`. The response is 201 with a `Location` header pointing at the absolute `blog.media` URL.

Limits:

- 10 MB per file.
- The mime type is detected by `finfo`. Allowed types: `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `image/avif`, `audio/mpeg`.
- Image width and height are read with `getimagesize`.

`xar media:localize <handle>` downloads every remote media URL of a blog using the §3.4 rules, then sets `media=local`.

### 5.5 Micropub

`POST /micropub` accepts both `application/x-www-form-urlencoded` and mf2 JSON. `GET /micropub` answers these queries:

- `q=config` returns `{media-endpoint, destination: [{uid: handle, name: title}…]}`, listing native blogs only.
- `q=source` returns mf2 for `?url=`.
- `q=syndicate-to` returns `[]`.

The blog is chosen by `mp-destination`, or the token's blog, or the only native blog. Otherwise the request fails with 400.

**h-entry mapping:**

| Micropub property | Becomes |
|---|---|
| `content` (string) | `<p>…</p>`, escaped |
| `content.html` | `content_html` |
| `name` | `title` |
| `bookmark-of` | `external_url`, kind `link` |
| `photo` (one or more) | `image` = first, `_athenana.images[]`, kind `photo` |
| `category` | tags |
| `published` | `date_published` |

Actions:

- `action=update` supports `replace`, `add` and `delete`.
- `action=delete` and `action=undelete` are supported.
- A successful create returns 201 with `Location` set to the item url.

## 6. Error handling and edge cases

- Mirror writes return 409. Sync never touches native blogs, and the commands refuse with exit 1.
- **Duplicate ids:** the same `item_id` twice on one page is processed once, last one wins, and the duplicate is logged.
- **Clock skew:** source dates are stored as given, converted to UTC.
- **Large feeds:** sync streams page by page, so memory use is bounded by the 50-item page.
- **Follow-ups (not built now):** HTML sanitising for native writes; tag pages; per-blog HTML; ActivityPub; email subscribe; IndieAuth (tokens only for now).

## 7. Testing

- **Unit tests:**
  - `JsonFeedAdapter` and `AthenaAdapter`, covering each kind, the extensions and title derivation;
  - serialiser round trip;
  - cursor codec;
  - Micropub mapping;
  - RSS rendering.
- **Sync tests** use `FixtureFetcher`:
  - quick-sync stop;
  - edit detection;
  - full-sync tombstones and resurrection;
  - the 50% safety valve;
  - lock contention;
  - per-page transactions;
  - 304 on the first page;
  - media localise with a fake download.
- **HTTP tests** go through `App::handle`:
  - feed, paging, 400 on a bad cursor, 304s, CORS headers on 404;
  - single item, scheduled posts hidden, RSS;
  - every JSON API status;
  - token scope, expiry and blog pinning;
  - media limits;
  - Micropub create, update, delete and the `q` queries.
- **Acceptance (parity) test.**
  - A fixture recorded from the live `https://athenana.com/blog/wyome/feed.json`: 3 pages plus one `/s/{id}.json`, stored under `modules/blog/tests/fixtures/athena/`.
  - Sync that fixture into a mirror blog, then serve it.
  - Every served page must equal its fixture page after normalising `feed_url`, `next_url` and the cursor values. `url` stays identical by configuring the wyome `post_url_pattern`. The single item must also match.
- CI runs the full matrix: SQLite, MySQL 8 and Postgres 16.

## 8. Definition of done

**Plan A:**

1. `xar blog:create wyome --mode=mirror --format=athena --source=… --post-url='https://www.wyome.com/blog/post.html?id={id}'` followed by `xar blog:sync wyome --full` mirrors the live feed.
2. `/blog/wyome/feed.json`, `/blog/wyome/feed.xml` and `/s/{id}.json` serve the mirrored feed.
3. The parity test passes.
4. Quick sync, the safety valve and tombstones are tested.
5. CI is green.

**Plan B:**

1. With a native blog and a token from `token:create`, an item `POST`ed to the API appears in the feed.
2. A Micropub client can create a note, a link and a photo (via the media endpoint), then update and delete them.
3. `blog:mode` cut-over keeps every id stable.
4. CI is green.
