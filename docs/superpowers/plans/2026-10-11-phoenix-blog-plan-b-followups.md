# Phoenix blog Plan B follow-ups

These are findings from the Plan B reviews that were deliberately deferred (see the SDD ledger). Items fixed in a review fix round or in the final fix wave are left out. Those were:
- the U+0000 rejection;
- `q=source` returning `{}`;
- the computed `_phoenix` status;
- the mirror check running before body decoding;
- `FormToken` being POST-only;
- `nosniff` on API and error JSON;
- the README gaps;
- tag reconciliation;
- image sizes and the `highlight` kind kept on Micropub updates;
- UTF-8 checks at every entry point.

## Worth doing next

- [Done] **Require `app.url`.** `tokens` and `micropub` declare `"requires": {"appUrl": true}`; `module:enable` and every boot refuse without an absolute http(s) `app.url`.
- **Missing `nosniff`.** The media upload JSON response and Micropub's own JSON errors still don't send `X-Content-Type-Options: nosniff`.
- **Where `ApiAccess` lives.** `ApiAccess` lives in the blog module but is a tokens concern; micropub imports a blog HTTP class to use it. Move it to something like `Token::assertAllows(int $blogId)`.
- **Item-id lookup across blogs.** The Micropub item-id lookup takes the first blog that matches. When the token is pinned to a blog, it should prefer that blog.
- [Done] **Listener failures after commit.** PostWriter now uses `EventDispatcher::notify()`: each listener runs on its own, and a failure is logged without failing the write or skipping later listeners.

## Behaviour

**Tokens and auth**
- A non-Bearer `Authorization` scheme gets an `invalid_token` challenge (RFC 6750 says no error code). The `wrongBlog` body says `forbidden` while the challenge says `insufficient_scope`.
- A pinned token can tell handles and post ids in other blogs from missing ones (403 vs 404/400). The ids are ULIDs and a valid token is needed.
- A module alias conflict is only logged at runtime, not refused at enable.

**Writes**
- `assertLive` reads a stale snapshot, so a delete racing an edit can let the edit land on a tombstone.
- A PATCH that sets the title to exactly its derived value is a no-op, so the title stays derived.
- `1` vs `1.0` counts as a change.
- Mirror-synced posts with blank tag names or empty raw tags can't round-trip GET→PUT after a cut-over.
- `TAG_SLUG` allows hyphen-only slugs.
- `array_is_list` rejects `{"0":"a"}` as a non-object.
- Non-UTC offsets and fractional seconds are normalised to UTC seconds, the same as sync.

**Uploads**
- [Done] AVIF is held to the 50 MP cap (getimagesize reads it without GD).
- A file is orphaned if the DB insert fails after the write.
- File permissions follow the umask.
- [Done] An image over 50 MP is reported as too large, with its size.

**`media:localize`**
- It exits 0 and sets `media=local` even when every download fails.
- Its counts are per occurrence, not per distinct URL.

**Micropub**
- Photo `alt` text is discarded.
- An empty-string `mp-destination` is a 400.
- Unknown properties are ignored, but `date_modified` is still bumped.
- The ULID regex in `PostLocator` is lowercase-only.
- `keepImages` matches on the exact URL, and duplicate URLs share the first copy's metadata.
- A repeated bare `properties=` key is last-wins.
- Route-level 404/405 errors on `/micropub` use the kernel error shape, not the Micropub one (documented in the README).

## Tests to add

- Module-vs-module alias collision, and the invalid-map catch branch.
- Token CLI edge cases: `--expires=100000d` and `-1d`, trailing-comma scopes, a whitespace name, a past expiry.
- `touch` throwing; multiple `Authorization` headers; a tab separator; a bare `Bearer`.
- A reindex after replace/patch; a patch that changes `id` or `url`.
- GET/PUT by id on a mirror post; `q=source` on mirror and scheduled posts.
- `media:localize` on a mirror blog, and a second run.
- The cut-over test should also assert `item_id` directly and pin its fixture dates.

## Docs and wording

- `token_hash` is VARCHAR(64), not the spec's `char(64)` (there is no Blueprint char type). The README notes this.
- `token:create --name` echoes control characters.
- The `hash_equals` after the indexed lookup is a no-op; comment it as defence in depth.
