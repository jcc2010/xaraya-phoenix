# Xaraya Phoenix

A small, modern fork of Xaraya: a PHP 8.3+ blogging engine that serves Athena's JSON Feed as a drop-in
(github.com/jcc2010/xaraya-phoenix). `next` is the trunk; releases are tagged `vX.Y.Z` (3.x, numbered after
upstream's 2.x tags) with a `CHANGELOG.md` section. Specs and plans live in `docs/superpowers/`; deferred items in
`docs/superpowers/plans/*-followups.md`.

## Traps

- `var/database.sqlite` holds the owner's live synced wyome blog. Never write to it, migrate it or run `bin/xar`
  or a server against it. Smoke tests use a scratch copy (`DB_DSN=sqlite:<scratchpad>/x.sqlite`); schema upgrades
  of the dev DB are the owner's to run, so give them the commands. Read-only `sqlite3 -readonly` queries are fine.
- A stale `var/cache/config.php` can shadow `DB_DSN`.
- PHP and Twig templates must render identically (`Html::normalize` parity tests).
- Gate every commit on the suites with `&&`, never `;`.

## Workflow

Follow `~/.claude/dev-workflow.md` (stages, skills, and how to evaluate a newly added skill). Substitutions here:
- Stage 2: branch (or worktree) off the next branch, the trunk; there is no main.
- Stage 5: the full suites are `composer test`, `composer stan` and `composer cs`; CI runs PHP 8.3/8.4 × SQLite,
  MySQL and Postgres, plus static and docker.
- Stage 6: `security-review` also covers the fetchers (`StreamFetcher`, media) and the token, Micropub and upload
  endpoints.
- Stage 7: no deploy block; merging to the next branch with CI green is the ship. A release is an annotated tag plus a GitHub
  release from the `CHANGELOG.md` section.
