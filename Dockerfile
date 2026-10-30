# syntax=docker/dockerfile:1

# One image for every process: the web server (default command),
# the Messenger worker and the migration job (both override the command).
FROM dunglas/frankenphp:1.13-php8.5-trixie

WORKDIR /app

# --- System layer: changes rarely, so it stays cached between builds ---
# OPcache is already part of the base image
RUN install-php-extensions intl pdo_pgsql zip redis amqp \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    # Port 8080 needs no privileges; without this file capability the binary
    # also starts where privilege escalation is forbidden (Kubernetes securityContext)
    && setcap -r /usr/local/bin/frankenphp
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    # Address Caddy listens on; no host name means plain HTTP, TLS is done in front of the app
    SERVER_NAME=:8080

# --- Dependencies: rebuilt only when composer.json or composer.lock change ---
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/root/.composer/cache \
    composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist

# --- Application code: changes on every commit ---
COPY . .
RUN --mount=type=cache,target=/root/.composer/cache \
    composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    && php bin/console cache:warmup \
    # The Tailwind binary is only needed to build the stylesheet
    && rm -rf var/tailwind

# --- Run as an unprivileged user ---
RUN useradd --uid 1000 --create-home app \
    && mkdir -p var/invoices/prod \
    && chown -R app:app var /config/caddy /data/caddy
USER app

EXPOSE 8080
