# Xaraya Phoenix: PHP 8.4 + Apache. Everything the app writes (the SQLite database, uploads, cache, logs, mail) lives
# under /app/var, which is a volume. See docs/install.md.
FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev sqlite3 unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql pdo_pgsql opcache \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers \
    && echo 'ServerTokens Prod' > /etc/apache2/conf-available/phoenix-security.conf \
    && echo 'ServerSignature Off' >> /etc/apache2/conf-available/phoenix-security.conf \
    && a2enconf phoenix-security

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-phoenix.ini"

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-autoloader --no-scripts

COPY . .
# An empty .env, so `xar install` never writes one: configuration comes from the container's environment.
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && rm /usr/bin/composer \
    && : > .env \
    && mkdir -p var public/assets \
    && chown -R www-data:www-data var public/assets \
    && chmod +x docker/entrypoint.sh

ENV APP_URL=http://localhost:8080 \
    DB_DSN=sqlite:/app/var/database.sqlite \
    MAIL_DRIVER=log

VOLUME /app/var
EXPOSE 80
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
