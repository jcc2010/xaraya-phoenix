# Xaraya Phoenix: Blog HTML Pages (design)

- **Date:** 2026-10-09
- **Status:** approved in brainstorming; waiting for review of the written spec
- **Builds on:**
  - the kernel views (kernel spec §5, delivered as kernel Plan 2)
  - the blog module (Plan A, mirror/sync/serve)
- **Decisions:**
  - a neutral `phoenix` theme only (a wyome theme can come later)
  - pages: home, post, tags, tag cloud, about and search
  - full-fidelity post kinds, each in its own overridable partial
  - HTML is sanitised at render time, plus a strict CSP

## 0. Scope

Phoenix renders the blogs it already stores as themable, server-side HTML pages. JSON and RSS output stays exactly as it is, so the parity guarantee still holds.

**Not in scope:**
- comments and Webmention receiving
- email subscribe
- admin and editing (that needs users, in kernel Plan 3)
- a wyome theme
- full-text search engines other than the portable one defined here

## 1. Routes and pages

| Route | Name | Content |
|---|---|---|
| `GET /blog/{handle}` | `blog.home` | Posts newest first, 20 per page, with a `?before=` cursor (the same codec as the feed). The pinned post sits above page 1. |
| `GET /blog/{handle}/tag/{slug}` | `blog.tag` | The blog's published posts carrying that tag, paged the same way. |
| `GET /blog/{handle}/tags` | `blog.tags` | Every tag that has at least one published post, with its count. Sized in 5 steps by count quantile and sorted by name. |
| `GET /blog/{handle}/about` | `blog.about` | Title, sanitised `description_html`, author (linked when `author_url` is set), JSON and RSS feed links, and the subscribe link when `extra._athenana.subscribe_url` exists. |
| `GET /blog/{handle}/search?q=` | `blog.search` | Search results with highlighted snippets. Offset paging with `?page=N` (1-based), 20 per page, shown as "N results". |
| `GET /s/{id}` | `blog.post` | One post rendered in full, with tags, mentions, and previous/next links within its blog by `(date_published, id)`. |

**Handle and id patterns:** the same as the feed routes, i.e. `[a-z0-9_-]{2,32}` and the ULID regex.

**Tag slug pattern:** `[^/]{1,128}`, URL-decoded and compared exactly.

**Middleware:** every page route uses `conditional` (ETag) and `secureHtml` (§3.4).

**Post links on pages:**
- A mirror blog with `post_url_pattern` links to the pattern URL, the canonical address. This keeps drop-in deployments correct.
- Every other blog links to `/s/{id}`.
- `/s/{id}` is always served. Its `<link rel="canonical">` is the item `url` that `ItemSerializer::url()` produces.

**HTML head:**
- `<html lang>` comes from `blog.language`.
- `<title>` is the page name followed by " · " and the blog title.
- `<meta name="description">`:
  - on post pages, the first 160 characters of the plain text;
  - on other pages, the blog description as plain text.
- `<link rel="alternate">` points to both feeds.
- Open Graph tags: `og:title`, `og:type` (`article` for posts, otherwise `website`), `og:url` (canonical) and `og:image` (the post's `image`, when set).

**Errors:**
- 404 for an unknown blog, a post that is unknown, deleted or scheduled, or a tag with no published posts.
- 400 for a malformed cursor, a `page` below 1, or a `q` longer than 200 characters.
- Both render through the theme's `error/{status}` template, using the kernel's renderHtml hook.

**Caching:** `Cache-Control: public, max-age=60` plus the body ETag. No `Last-Modified`, matching the feeds.

**Sidebar blocks.** These are block instances in the theme's `sidebar` region, seeded when the module is enabled, with visibility limited to the `blog.*` routes:
- `blog.info`: about snippet plus feed links.
- `blog.tagcloud`: the top 30 tags.
- `blog.searchbox`: a GET form to `blog.search`.
- `blog.recent`: the 5 newest posts.

A block works out its blog from the current route's `handle`. On `/s/{id}` it uses the post's blog.

## 2. View model and templates

### 2.1 PostView

`PostViewFactory::make(Blog, Post, array $media): PostView` builds the template data. It is engine-neutral: plain readonly properties holding scalars, arrays and nested value objects, and never HTML that hasn't been sanitised.

| Field | Contents |
|---|---|
| `id`, `kind`, `title` | |
| `url` | the link rule from §1 |
| `canonicalUrl` | |
| `contentHtml` | sanitised |
| `excerpt` | plain text, 160 characters |
| `image` | after the media map |
| `publishedIso`, `publishedDisplay` | `publishedDisplay` uses the format `j M Y` |
| `tags` | `list<{name, slug, url}>` |
| `source` | `{name, url}`, or null |
| `quote` | plain text, or null |
| `commentHtml` | sanitised, or null |
| `images` | `list<{url, width, height}>` |
| `video` | `{provider, id, thumbnail, watchUrl}`, or null. `watchUrl` is `https://www.youtube.com/watch?v={id}` for youtube. |
| `podcast` | `{show, episode, durationDisplay, audioUrl, cover}`, or null |
| `song` | `{channel, durationDisplay}`, or null |
| `mentions` | `list<{source, title, date}>` |
| `pinned` | bool |

Media URLs go through the same `MediaUrls` map the feeds use.

For link posts that carry an owner comment, the comment duplicates the start of `content_html`. The factory removes that copy from `contentHtml` before sanitising, when the comment is a byte-for-byte prefix of it (the wyome rule). The comment and the description then render separately.

### 2.2 Templates

All templates live in `modules/blog/templates/`. Each exists as both `.twig` and `.php`, and the same name in a theme overrides it.

- **Pages:** `blog/home`, `blog/post`, `blog/tag`, `blog/tags`, `blog/about`, `blog/search`
- **Post card and full post:** `blog/partials/post`, used for both via a `full` flag
- **Kinds:** `blog/partials/kinds/{note,link,highlight,photo}`
- **Extras:** `blog/partials/extras/{video,podcast,song,gallery,mentions,tags}`
- **Paging:** `blog/partials/pager`

How each kind and extra renders:

| Partial | Output |
|---|---|
| link | The title links to `external_url`, followed by "via {source.name}". The comment comes first, then the description. |
| highlight | `<blockquote>` holding the quote, then the source line, then the comment. |
| photo | `image`, or the gallery when there is more than one image. |
| note | `contentHtml`. |
| video | A thumbnail `<a href=watchUrl>` with a play glyph. No iframe and no third-party script. |
| podcast | Cover, show name, then `<audio controls preload="none" src=audioUrl>`. |
| gallery | A responsive grid of `<img loading="lazy" width height alt="">`. |

**Theme:** the neutral `phoenix` theme from kernel spec §5.6, with the styles for these partials added in `themes/phoenix/assets/blog.css`. It is accessible: landmarks, focus styles, `prefers-color-scheme` and reduced motion. It ships no JavaScript.

## 3. HTML safety

### 3.1 HtmlSanitizer

`HtmlSanitizer::clean(string $html): string` is built on `DOMDocument` and adds `ext-dom` to `composer.json`'s require list. The input is parsed as a UTF-8 fragment.

**Allowed elements:** `p br strong em b i u s blockquote q cite code pre ul ol li a img figure figcaption h2 h3 h4 hr sup sub span`

**Allowed attributes:**
- `a[href title]`
- `img[src alt width height]`
- `q[cite]`, `blockquote[cite]`
- No `class`, `id` or `style` on any element.

**URLs** (`href`, `src`, `cite`):
- Before checking, entity-decode the value and strip whitespace and control characters.
- Allowed: `http:`, `https:`, `mailto:` (href only), and scheme-less relative URLs.
- Anything else, such as `javascript:`, `data:` or `vbscript:`, removes the attribute.
- `img` without a valid `src` is removed.

**Removed together with their content:** `script style iframe frame object embed form input button textarea select svg math template noscript`

**Also removed:** comments, processing instructions, every other attribute (including all `on*` handlers), and `h1` (demoted to `h2`).

**Unknown elements** are unwrapped: their children are kept and the tag is dropped.

**Links:** every `<a>` gets `rel="nofollow noopener ugc"`. Links to external hosts also get `target="_blank"`.

**Output:** serialised HTML. It is idempotent: `clean(clean(x)) === clean(x)`.

### 3.2 Where sanitising happens

- Only `PostViewFactory` and the about page call the sanitiser, at render time.
- Stored data and the JSON and RSS output are never touched.

### 3.3 Cache

- Sanitised output is cached in the kernel's `var/cache` file cache under the key `sanitized/{post_id}-{updated_at}-{field}`.
- The cache is per process; no database is involved.
- `cache:clear` removes it.

### 3.4 `secureHtml` middleware

This is a new kernel middleware alias. It is added to the kernel in kernel Plan 2 and is generic. It sets:

- `Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; media-src 'self' https:; style-src 'self'; script-src 'self'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'self'`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`

## 4. Search

**Table** `xar_post_search`, created by a blog-module migration:
- `post_id` ulid, primary key, foreign key to posts with cascade
- `blog_id` int
- `body` text

Index `(blog_id)`.

**Normalisation:** `SearchText::normalize(string): string`.
1. `mb_strtolower`.
2. Transliterate to ASCII with `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE')`, falling back to `Normalizer` NFD plus stripping combining marks.
3. Map non-alphanumerics to spaces and collapse runs of spaces.

**`body` contents:** title, plain-text content, quote, plain-text comment, tag names, `_source.name`, the `external_url` host, podcast show and episode, and song channel.

**Interface** `SearchIndex`:
- `index(Post $post): void`
- `remove(string $postId): void`
- `search(int $blogId, string $query, int $limit, int $offset): array{total: int, ids: list<string>}`

**`LikeSearchIndex`** is the provider's binding.
- **Terms:** split with `normalize`; drop terms shorter than 2 characters; keep at most 8.
- **Query:** for each term, `body LIKE ? ESCAPE '!'` with `!`, `%` and `_` escaped using `!`.
  - It is joined to `posts` on `status = 'published' AND date_published <= now` and the blog.
  - It is ordered by `date_published DESC, id DESC`.
  - `total` comes from a `COUNT(*)` using the same predicates.
- **No usable terms:** return `{total: 0, ids: []}`.

**Keeping the index current:**
- Blog-module subscribers re-index on `ItemCreated`/`ItemUpdated` with itemtype `post`, and remove on `ItemDeleted`.
- `xar blog:reindex <handle>|--all` rebuilds the index and prints counts.
- The plan's README update tells existing installs to run `blog:reindex --all` once.

**Snippets:** `SearchText::snippet(string $plain, list<string> $terms, int $width = 160): string`.
- Find the first match in the normalised text and map it back to the same position in the original.
- Take a window of `$width` characters, trimmed to word boundaries, with `…` at cut ends.
- HTML-escape the window, then wrap the matched terms in `<mark>`.

## 5. Testing

**Unit tests:**
- **`HtmlSanitizer`:**
  - a vector set: `<script>`, `<img onerror>`, `javascript:` (plain, entity-encoded and whitespace-split), `<svg onload>`, `<a href="data:…">`, `style` attributes, `<iframe>`, `<form>`, nested and broken markup, comments;
  - unknown-element unwrapping, `rel`/`target` on links, idempotency, and UTF-8 round-trips.
- **`SearchText`:** normalisation (case, accents, punctuation) and snippets, including hostile input that must come back escaped.
- **`PostViewFactory`:** every kind, every extra, the media map, and comment de-duplication.
- **`LikeSearchIndex`:** AND semantics, wildcard escaping (a search for `100%` matches literally), and hidden posts excluded.

**HTTP tests, through `App::handle`:**
- Every page returns 200 with the expected content.
- Home paging across the cursor, with the pinned post first.
- Tag pages, cloud counts, and the about page with the subscribe link.
- Search hits, misses and paging.
- 404 and 400 rendered through the theme.
- Security headers present on every HTML route.
- Scheduled and deleted posts absent from every page, search included.
- Canonical and link URLs follow the post URL pattern.

**Engine parity:** every page and partial renders the same normalised HTML through Twig and through PHP.

**Fixture smoke test:**
- Sync the recorded Athena fixture (150 posts).
- Render the home page, one post of each kind present, a tag page, the cloud and a search.
- Each returns 200 and parses with `DOMDocument::loadHTML` with zero libxml errors.

**CI:** the full matrix.

## 6. Definition of done

1. After `blog:reindex --all`, a mirrored blog can be browsed end to end at `/blog/{handle}` in the `phoenix` theme: paging, every post kind, tags, the cloud, about and search.
2. No page loads third-party scripts or iframes, and every HTML page sends the §3.4 headers.
3. Two overrides need no module changes: a theme overriding `blog/partials/kinds/link`, and switching the theme's engine preference between Twig and PHP.
4. JSON and RSS output are byte-for-byte unchanged, and `AthenaParityTest` still passes.
5. CI is green.

## 7. Delivery

1. **Kernel Plan 2:** kernel spec §5 (view layer, themes, blocks, display hooks, translation, settings, assets, the `phoenix` theme), plus the `secureHtml` middleware and the renderHtml error-page hook.
2. **Blog pages plan:** everything in this spec.
