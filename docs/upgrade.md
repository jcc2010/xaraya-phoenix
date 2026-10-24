# Upgrading and backups

## What to back up

Everything Phoenix keeps is in two places:

- **The database.** With SQLite this is the file `DB_DSN` points at, `var/database.sqlite` by default. With MySQL or
  Postgres, dump it with `mysqldump --single-transaction` or `pg_dump`.
- **`var/uploads/`**, the media uploaded through the admin, the API or Micropub, or downloaded by
  `media:localize`.

Also keep a copy of `.env` (or your compose file), because it holds your settings. Caches (`var/cache`), logs
(`var/logs`) and sync locks (`var/locks`) can be thrown away.

To copy a live SQLite database safely, use SQLite's backup command rather than `cp`. It takes a consistent snapshot
even while the site is writing:

```sh
sqlite3 var/database.sqlite ".backup 'backup/phoenix-$(date +%F).sqlite'"
tar czf backup/uploads-$(date +%F).tgz -C var uploads
```

With Docker, everything above is in the `phoenix-var` volume:

```sh
docker compose exec -T app sqlite3 /app/var/database.sqlite ".backup '/app/var/backup.sqlite'"
docker compose cp app:/app/var/backup.sqlite "phoenix-$(date +%F).sqlite"
docker compose cp app:/app/var/uploads ./uploads-backup
```

Or stop the site and archive the whole volume:

```sh
docker compose stop app
docker compose run --rm --no-deps -T --entrypoint tar app czf - -C /app/var . > phoenix-var.tgz
docker compose start app
```

## Upgrading

Back up first. Then:

**Docker:**

```sh
git pull
docker compose build
docker compose up -d
```

On start, the container runs `bin/xar migrate`, clears the config cache and republishes assets.

**Your own server:**

```sh
git pull
composer install --no-dev --optimize-autoloader
bin/xar migrate
bin/xar asset:publish
bin/xar cache:clear
```

If you run PHP-FPM with `opcache.validate_timestamps=0`, reload FPM afterwards as well.

`bin/xar migrate:status` shows what has run, and `bin/xar migrate:rollback` undoes the last batch.

### Notes for specific upgrades

- **From an install that predates sign-in (no users table):** run `bin/xar install` once. It runs the new
  migrations, seeds the roles and creates the administrator, and leaves everything else alone.
- **Posts from before ownership existed** have no owner, so only Editors and Administrators can edit them. Assign
  them with `bin/xar blog:owner <blog> --user=<handle>`, and try it first with `--dry-run`.
- **Search** uses its own index. After upgrading an install that has blogs, run `bin/xar blog:reindex --all` once, or
  older posts won't be found.
- **The `tokens` and `micropub` modules** need an absolute `APP_URL`. Set it before upgrading, or the site refuses to
  boot while they are enabled. If that happens, `bin/xar` falls back to safe mode, so `module:disable` still works.
