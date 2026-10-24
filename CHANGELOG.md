# Changelog

## 3.0.0 *Cyclops* (2026-10-23)

The first release of Xaraya Phoenix, a small, modern fork of Xaraya. It is a blogging engine that speaks Athena's
JSON Feed natively. It is numbered 3.0, not 1.0, so that it sorts after upstream Xaraya's 2.x tags, which the fork
carries.

**Kernel**

- PHP 8.3+, PSR-7/15 HTTP, FastRoute, and a small DI container.
- Portable migrations and a query builder for SQLite, MySQL 8 / MariaDB 10.6+ and Postgres 14+.
- Modules with manifests that declare routes, migrations, middleware, commands, blocks, grants and hooks.
- Themes in PHP or Twig with overridable templates, blocks and regions; the `phoenix` theme.
- Users, roles and grants with "own" instances, plus:
  - database sessions;
  - CSRF protection;
  - login throttling;
  - password reset by email.
- Security headers on HTML pages, including a strict CSP.
- Mail drivers: `log`, `mail`, `smtp` (STARTTLS or TLS, AUTH PLAIN/LOGIN) and `memory`.
- The `bin/xar` CLI: `install`, `migrate`, `module:*`, `user:*`, `asset:publish`, `cache:clear` and `serve`.

**Blog**

- Mirrors Athena (or any JSON Feed 1.1 source) with quick and full syncs, and serves an identical `feed.json`,
  `feed.xml` and `/s/{id}.json`. Checked against all 2,944 posts of a live Athena blog.
- Public pages: home, post, tag, tag cloud, about and search, with sidebar blocks.
- Native writing:
  - an admin with posts, preview, media upload, pinning, blog settings and tags (rename, merge, remove, filter);
  - a JSON API with bearer tokens;
  - Micropub, including its media endpoint.
- Post ownership, with Authors limited to their own posts; `blog:owner` to assign existing posts.
- `blog:mode` switches a blog between mirror and native without changing any post id.

**Packaging**

- A Docker image (PHP 8.4 + Apache) with a single data volume, and a first-start install from environment variables.
  CI builds it and smoke-tests it.
- Guides: [installing](docs/install.md), [upgrading and backups](docs/upgrade.md), and
  [Athena mirroring and cut-over](docs/athena.md).

**Not in 3.0**

- Dynamic data and categories (tags cover categories).
- The generic JSON importer.
- IndieAuth: Micropub clients use tokens from `token:create`.
- Smaller deferred items are listed in `docs/superpowers/plans/*-followups.md`.

The original Xaraya code was removed from the tree. It remains in git history and in the upstream repository.
