# Installing Xaraya Phoenix

Phoenix runs anywhere PHP 8.3+ does. The quickest way to get it running is Docker. You can also install it on a server
you already run.

## With Docker

You need Docker with the compose plugin.

```sh
git clone https://github.com/jcc2010/xaraya-phoenix.git
cd xaraya-phoenix
printf '%s\n' 'a long password of your choice' > admin_password.txt
docker compose up -d
```

Then open http://localhost:8080/login and sign in as `admin` with the password you saved.

What happens on the first start:

- The image is PHP 8.4 with Apache, serving `public/`.
- Everything Phoenix writes lives in the `phoenix-var` volume (Compose prefixes its name with the project's),
  mounted at `/app/var`. That covers the SQLite database,
  uploaded media, caches, logs and the `log` mail driver's messages.
- The entrypoint installs the site from the environment:
  - `XAR_ADMIN` and `XAR_ADMIN_EMAIL` name the administrator.
  - `XAR_ADMIN_PASSWORD`, or a file named by `XAR_ADMIN_PASSWORD_FILE` (as with Docker secrets), gives the password.
  - It enables the modules listed in `XAR_MODULES` (default `blog`).
  - It then records `var/.installed`, so these variables are only read once.
- Later starts run `bin/xar migrate` instead, so upgrading the image upgrades the database.
- Every start clears the config cache, so a changed environment takes effect, and republishes the assets.

All configuration comes from environment variables in `compose.yaml`. The keys are the ones in `.env.example`:
`APP_URL`, `DB_DSN`, `MAIL_DRIVER`, `MAIL_HOST` and so on. **Set `APP_URL` to the address people will use.** Feeds,
media links and password-reset emails are built from it.

Run `bin/xar` commands as the web user inside the container:

```sh
docker compose exec app runuser -u www-data -- php bin/xar blog:list
```

**Postgres or MySQL.** Set `DB_DSN`, `DB_USER` and `DB_PASSWORD` on the `app` service. To use the bundled Postgres
service, start it with `docker compose --profile pgsql up -d` and point the DSN at
`pgsql:host=db;port=5432;dbname=phoenix`.

**HTTPS.** Put a TLS-terminating proxy (Caddy, Traefik, nginx) in front of port 8080 and set `TRUSTED_PROXIES` to its
address. Session cookies are marked `Secure` unless `APP_DEBUG=true`. Browsers accept them on `http://localhost`, but
on any other host you need HTTPS to sign in.

## On your own server

You need:

- PHP 8.3 or later with the `pdo`, `mbstring`, `json`, `dom` and `fileinfo` extensions, plus `pdo_sqlite`,
  `pdo_mysql` or `pdo_pgsql` for your database.
- SQLite (the default), MySQL 8 or MariaDB 10.6+ (utf8mb4), or Postgres 14+.
- Composer.

```sh
git clone https://github.com/jcc2010/xaraya-phoenix.git /srv/phoenix
cd /srv/phoenix
composer install --no-dev --optimize-autoloader
bin/xar install                 # writes .env, creates the database and asks for the administrator
bin/xar module:enable blog
```

`bin/xar install` writes `.env` from `.env.example` the first time; edit it afterwards. The settings that matter in
production:

| Setting | Why |
|---|---|
| `APP_URL` | The site's absolute URL. Feeds, media links and reset emails use it, and the `tokens` and `micropub` modules refuse to run without it. |
| `APP_DEBUG=false` | Caches config and routes, hides error details, and marks cookies `Secure`. |
| `MAIL_DRIVER` | `smtp` (with `MAIL_HOST` and friends) or `mail`. The default, `log`, only writes `.eml` files to `var/mail`. |
| `TRUSTED_PROXIES` | Your proxy's IP addresses, if there is one, so login throttling sees real client addresses. |
| `DB_DSN`, `DB_USER`, `DB_PASSWORD` | When you're not on the default SQLite file. |

The web server's user must be able to write to `var/`. Point the document root at `public/`, and send every request
that isn't a file to `public/index.php`.

**Apache:**

```apache
DocumentRoot /srv/phoenix/public
<Directory /srv/phoenix/public>
    Options FollowSymLinks
    AllowOverride None
    Require all granted
    FallbackResource /index.php
</Directory>
<Directory /srv/phoenix/public/assets>
    <FilesMatch "\.(php|phar|phtml)$">
        Require all denied
    </FilesMatch>
</Directory>
```

**nginx with PHP-FPM:**

```nginx
root /srv/phoenix/public;

location / {
    try_files $uri /index.php$is_args$args;
}

location ^~ /assets/ {
    try_files $uri =404;
}

location = /index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root/index.php;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
}

location ~ \.php$ {
    return 404;
}
```

`public/assets` holds links into `modules/*/assets` and `themes/*/assets`. `bin/xar asset:publish` creates them,
and `install` runs it for you. Only `index.php` should ever run as PHP.

For a quick local look without a web server, `bin/xar serve` starts PHP's built-in server on
http://127.0.0.1:8080.

## Next steps

- [Upgrading and backups](upgrade.md)
- [Mirroring an Athena blog, and moving off Athena](athena.md)
- The [README](../README.md) covers themes, blocks, users and roles, the blog admin, the JSON API and Micropub.
