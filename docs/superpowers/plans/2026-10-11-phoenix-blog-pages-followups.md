# Phoenix blog pages follow-ups

These are findings from the blog pages reviews (SDD ledger) that were deliberately deferred. Items fixed by a review fix round or the final fix wave are left out. These were fixed: the sanitiser fails closed on bad UTF-8 and ignores meta charsets, canonical URLs pass SafeUrl, mention dates are strict, the pinned badge appears only above page 1, every `<img>` is fetched with no Referer, and the sanitiser cache key is versioned.

## Behaviour

- [Final] Podcast `<audio>` still sends a Referer (`referrerpolicy` isn't defined for media elements), so audio hosted on a hotlink-protected origin like Athena would 403. If that happens, set a page-level `Referrer-Policy: same-origin` from `BlogPage::page()`.
- [Final] Theme overrides that emit their own `<img>` must add `referrerpolicy="no-referrer"` themselves (documented in the README).
- [Final] Search highlights matches inside words (`speci<mark>fi</mark>c`), as LIKE substring semantics allow. Consider highlighting only at word starts.
- [Task 12] A page past the last search result links back one page at a time, when it could link to the last real page. `findMany` has no status filter, so a post deleted between the index query and the fetch can still show. The `q` length is checked after `trim()`.
- [Task 13] `CurrentBlog::of` isn't memoised, so a page runs four lookups (more on `/s/{id}`). It also resolves the blog from posts that aren't live, which is safe only because those pages 404 first. The `blog:blocks` check and seed aren't atomic. `tagCounts` applies its LIMIT before `TagCloud::withUrls` filters out unroutable slugs, so the cloud can come up one short.
- [Task 10] `TagCloud` sorts with `<=>`, which compares numeric-looking names numerically. When a tag is spelled with different cases, `MIN(t.name)` picks one in a way that varies by database.
- [Task 6] Comment de-duplication `ltrim`s whitespace after the prefix.
- [Task 5] `blog:reindex <handle> --all` lets the handle win silently.
- [Task 3] Snippets can start mid-word when a word is longer than 50 characters. An NFD combining mark can fall outside `<mark>`. Each hit folds the whole text.
- [Task 2] Sanitiser edge cases:
  - whitespace-only output isn't idempotent (`' '` → `''`);
  - content after an injected `</body>` is dropped;
  - libxml leaves raw `<>` inside a `<!--…-->` substring of URL attributes (quotes are still escaped).

  Re-check the meta/UTF-7 vectors if CI moves to libxml 2.14+ and its HTML5 tokenizer.
- [Preflight F9] The search index keeps script text, so a hit inside it shows no highlight.

## Styling and accessibility

- [Task 14] The `.post-tags a::before { content: "#" }` is read aloud by screen readers. The pinned label colours aren't theme variables.
- [Task 7] `link.php` echoes the literal `$rel`, and `post.php` echoes `' post-pinned'`. Both are safe literals, but they are raw echoes.

## Tests to add

- [Task 4] `SearchIndexer` swallowing errors and ignoring foreign events; `clear()`; `index()` removing a post that is no longer published.
- [Task 6] A failed cache set; unsafe thumbnail, audio, cover and image URLs.
- [Task 7] Highlight `cite`, via without a URL, link without `externalUrl`, highlight without a quote, null podcast and song fields, video without a thumbnail.
- [Task 8] `descriptionHtml` memoisation.
- [Task 11] An unsafe `author_url`.
- [Task 12] `q=%FF`.
- [Task 13] No sidebar on `/s/{hidden}`, clamping of the block `limit`, HTML in recent-post titles.
- [Task 15] The smoke test picks a song post but doesn't assert the song markup.

## Docs and wording

- [Task 15] The README's upgrade steps are split across two paragraphs.
- [Task 1] The "Invalid asset path" message shows the ltrimmed path.
- [Preflight] The plan says "first 200 characters" where it means 199 plus an ellipsis. `maxlength="200"` is a literal in templates while the controller uses a constant.

## Process

- The kernel Plan 2 Task 14 implementer replaced `var/database.sqlite` during a "fresh sqlite" manual smoke run, which wiped the local dev data (re-synced on 2026-10-11). Future briefs must tell implementers to smoke-test against a scratch database (`DB_DSN=sqlite:/tmp/…`) and never the dev database.
