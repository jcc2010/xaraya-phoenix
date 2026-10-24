#!/bin/sh
# Prepares /app/var, installs on the first start (when XAR_ADMIN is set), migrates on later ones, then runs the CMD.
set -eu
cd /app

mkdir -p var
chown www-data:www-data var
# Only fix ownership when it is wrong, so a large uploads tree isn't walked on every start.
if [ -n "$(find var ! -user www-data -print -quit)" ]; then
    chown -R www-data:www-data var
fi

xar() {
    runuser -u www-data -- php bin/xar "$@"
}

# The value of $1, or the contents of the file named by ${1}_FILE (Docker secrets).
secret() {
    file=$(eval "printf '%s' \"\${${1}_FILE:-}\"")
    if [ -n "$file" ]; then
        cat "$file"
    else
        eval "printf '%s' \"\${${1}:-}\""
    fi
}

# Config is cached in var/cache; drop it so a changed environment (APP_URL, DB_DSN, …) takes effect.
xar cache:clear >/dev/null

if [ -f var/.installed ]; then
    xar migrate
elif [ -n "${XAR_ADMIN:-}" ]; then
    password=$(secret XAR_ADMIN_PASSWORD)
    if [ -z "$password" ] || [ -z "${XAR_ADMIN_EMAIL:-}" ]; then
        echo "Set XAR_ADMIN_EMAIL and XAR_ADMIN_PASSWORD (or XAR_ADMIN_PASSWORD_FILE) with XAR_ADMIN." >&2
        exit 1
    fi
    if output=$(printf '%s\n' "$password" | xar install --admin="$XAR_ADMIN" --email="$XAR_ADMIN_EMAIL" --password-stdin 2>&1); then
        printf '%s\n' "$output"
        for module in $(printf '%s' "${XAR_MODULES:-blog}" | tr ',' ' '); do
            xar module:enable "$module"
        done
    elif printf '%s' "$output" | grep -q 'already installed'; then
        echo "The database is already installed; migrating."
        xar migrate
    else
        printf '%s\n' "$output" >&2
        exit 1
    fi
    unset password
    touch var/.installed
    chown www-data:www-data var/.installed
else
    echo "Phoenix is not installed yet. Set XAR_ADMIN, XAR_ADMIN_EMAIL and XAR_ADMIN_PASSWORD and restart," >&2
    echo "or run: docker compose exec app runuser -u www-data -- php bin/xar install" >&2
fi

# Links module and theme assets into public/assets (PHP never runs there: see docker/apache.conf).
xar asset:publish >/dev/null

exec "$@"
