# Phoenix blog Plan A follow-ups

Deferred and parked findings from the Plan A reviews (SDD ledger, 2026-10-09). Triage before Plan B and before production use of `media=local`.

## Before Plan B (native authoring)

- Native creates need their own insert path: `PostRepository::save()` swaps a colliding ULID silently, but `item_id` must embed the final id.
- If `PostWriter` reuses adapter validation: reject relative dates, bound title length, reject non-http(s) URL schemes, reject unknown top-level fields (spec §5.2).
- Map column-length / unique violations to 422/409 instead of raw PDOException (BlogRepository, PostRepository).
- Make `PostRepository::tombstone()` atomic; add cross-blog isolation tests (tokens are blog-pinned).
- Media: reference-count shared files before any delete; use a dedicated 10 MB fetcher for uploads/localize.
- Decide listener-exception policy in `Syncer` before adding subscribers.
- Inline `<img src>` in `content_html` is not rewritten by local media (matters after cut-over).

## Parked

- StreamFetcher deadline is not total during the header phase (`fopen` blocks while a server drips headers). Mitigated by `timeout` in the documented cron lines. Fix: `stream_socket_client` + own HTTP/1.0 parsing inside the deadline loop, or ext-curl.

## Full deferred list (from the ledger)

- [Task 1] find/load/findById duplication; check-then-insert race on handle; no column-length validation (raw PDOException on >1024/255 or null author_name)
- [Task 1] empty --title=/--language= override defaults; list count only tested at 0; JSONB key reorder note for later tasks
- [Task 2] date() accepts relative formats ('tomorrow') and odd years; title unbounded; url/image schemes not checked (javascript:) — sanitize at render if HTML pages added (Plan 2)
- [Task 3] paging test doesn't assert order/tie-break; no cross-blog isolation tests; restore doesn't check tags rewrite
- [Task 3] tombstone() non-atomic N updates; PostRecord ulid not re-validated in repository; liveItemIds unbounded
- [Task 3] registerAutoloader twice leaves stale no-op; tearDown unset breaks future readonly props in test subclasses
- [Task 4] serializer adds tags/_athenana.tags/_athenana.kind when absent in non-Athena sources; adapter drops extra keys on _athenana.tags entries; dates normalised to +00:00; mirror w/o url invents {app}/s/{id}; cursor accepts non-canonical trailing base64 bits
- [Task 5] no internal-host/SSRF guard on fetch targets or redirects (track as follow-up); timeout is per-read not total; redirect exhaustion returns 302 result; error_get_last may be stale
- [Task 6] listener exception mid-page aborts walk; --all + handle silently prefers handle; changing next_url can walk 10k pages on quick sync; duplicate item across pages double-counted
- [Task 7] feed.xml 404 is HTML; tests lack empty blog, exactly-50 boundary, cursor at deleted post, hidden posts in RSS, hostile XML, If-Modified-Since; Last-Modified only reflects page posts
- [Task 8] media fetch reads up to 20MB before 10MB check (dedicated fetcher); SSRF to internal hosts via media URLs/redirects (track for final review); shared-file dedup needs refcount before any future delete; rewrite only tested for image field
- [Task 8] localize() pre-select outside try; GD branch untested (pixel cap masks it); orphaned file if insert fails; DECISION for user: require ext-gd? (without GD, small-header polyglots pass validation; nosniff/CSP are backstop)
- [Task 9] native-post guard depends on current app.url prefix; --source/--format accepted when switching to native; --force path consequences untested
- [Task 10] parity uses assertEquals (loose types/order) — consider assertSame after recursive ksort; README Blog section placed after License line
- [FINAL] STALE_LOCK 30m < legit 2h full sync → spurious "may be hung" warnings; DNS lookup outside deadline; NAT64 local-use/Teredo/6to4-relay ranges unblocked; TLS mismatch error text vague; no test for native null-hash re-save → UPDATED; AAAA-only hosts refused (document)
