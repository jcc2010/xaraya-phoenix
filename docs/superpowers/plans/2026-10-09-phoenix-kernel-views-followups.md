# Phoenix kernel views follow-ups

Deferred findings from the Plan 2 reviews (SDD ledger). Items fixed by the final fix wave are excluded.

## Blog pages plan: decide or carry

- [Final] Controllers have no asset-URL service. `Page::$styles` needs URLs, but the asset-URL logic lives only in `Helpers::asset`. Extract an `AssetUrls` service before blog controllers build `styles`.
- [Final] `layout.php`/`layout.twig` hardcode `phoenix.css`, so a child theme inheriting the layout cannot add its own sheet without an override. The `href="/"` home link breaks subdirectory installs. `url('home')` is not safe as a replacement, because a module may claim `/` under another name.
- [Final] ErrorPages always uses `app.locale`, so a blog 404 can't carry `blog.language`.
- [Final] Overridable partials pulled with `include` must ship both engines, or callers must use `render()` (documented in README). The blog plan must pick a convention.

## Kernel

- [Final] DisplayHooks swallows observer failures even in debug mode, while BlockRenderer rethrows in debug. Pick one policy.
- [Final] Blocks of built-in types seeded by a module's `blockDefaults` keep rendering after that module is disabled.
- [Final] AssetPublisher publishes discovered but disabled modules, and links the whole `assets/` dir, so any `.php` dropped there becomes reachable under `public/`. Restrict publishing to enabled modules, or document the behaviour.
- [Final] RecentItems: a listener that slices its own results to exactly `limit` and includes an unsafe URL can still leave the block one short.
- [Final] No test pins "a route-level CSP on an HttpException beats the ErrorHandler default". The merge order makes it true today.
- [Task 1] Cache::set can throw if `clear()` races mkdir/rename (make writes best-effort or retry once). `clear()` counts orphan temp files. Entries never expire. Overwrite, forget-corrupt and unwritable cases are untested.
- [Task 2] Settings: JSON object key order is not preserved on MySQL/PG (document this). PG rejects NUL in values. One corrupt row breaks a whole scope. Edge values (stdClass, >int64) are untested.
- [Task 3] ruling: "one parent" means single inheritance, not a depth limit. Errors for an unknown theme or parent don't list the available themes or the referring theme. Casts on regions and settings are lenient. Setting schemas are unvalidated. These cases are untested: no theme.json, self-parent, missing parent, multi-level.
- [Task 4] Translator: a catalog ParseError is not wrapped as ViewException. A bad `app.locale` only fails at first use. Locale case is not normalised. Non-string catalog values are dropped silently. Stringable/non-scalar params and no-resubstitution are untested.
- [Task 5] `item.form.save` has no validation-error channel and no id guarantee for new items. `seed()` does select-then-insert without atomicity. `enabled()` caches `[]` before the modules table exists. Failing observers log on every render, with no rate limit. A listener failure in `enable()` can leave a half-enabled module.
- [Task 6] TemplateLocator follows symlinks (document this). Negative lookups are memoised for the locator's lifetime. Templates of disabled modules stay resolvable. `find(twig)` returns `.twig` files even when Twig is absent.
- [Task 7] The data key `x` is dropped in PHP but visible in Twig (reserve it or document it). `asset()` only rejects a literal `..`. `View::exists` throws on invalid names, whereas TwigLoader returns false. `strict_variables` behaves differently from PHP warnings. Twig helper args are positional-only.
- [Task 8] Twig unwrap walks the whole previous chain; limit it to one wrap level. These are untested: non-Http exceptions through Twig, hostile metadata values, and Page with a conditional 304. The depth guard doesn't cover pure Twig include recursion.
- [Task 9] The BlockTypes snapshot goes stale after an in-process enable/disable. `wrap()` runs outside isolation, so a broken theme block template gives a 500. `BlockInstance::visibility()`/`strings()` may be dead code. The defaults docblock shape is duplicated. These are untested: tie-break by id, and a nested/unicode config round-trip.
- [Task 10] Scheme matching is case-sensitive (`HTTPS://` is rejected). Menu has no `mailto`. An empty title gives `aria-label=""`. RecentItem has no itemtype. The menu route-skip warning is not asserted. `***x***` misnests (cosmetic).
- [Task 11] SecureHtml/Cors replace the HttpException subclass, so the trace points at the middleware. A DB-caused 500 logs a second error.
- [Task 12] asset:publish replaces non-atomically (partial copy / no-assets window). `copyTree` follows symlinks inside the source. Stale entries are not pruned. A `copy()` warning is unsuppressed. The removal-failure test skips on Windows and when run as root.
- [Task 13] The hostile-message needle is loose (assert it inside `<p>`). Message omission on the 405 page is unchecked.
- [Task 14] hello examples: there is no hostile-note test, and the NoteHook `item.form` branch is untested. `create` is not atomic with `item.form.save` (plan-mandated, example only). An empty note can't clear a stored note.
